<?php
declare(strict_types=1);

/**
 * Contagem real de anuncios ativos por dia (Foreplay brand/analytics), so para os maiores anunciantes.
 * A rota so funciona com o page_id da pagina no Facebook (ad_library_id): com o brand_id ela devolve tudo zerado.
 * O page_id sai do getBrandsByDomain, pelo dominio proprio do anunciante, uma vez so, e fica gravado.
 */
final class Analytics
{
    // Dominios de terceiros (checkout, encurtador, rede social): nao identificam o anunciante.
    private const DOMINIOS_GENERICOS = [
        'whatsapp', 'wa.me', 'fb.me', 'facebook.com', 'instagram.com', 'youtube.com', 'youtu.be', 'bit.ly',
        'linktr.ee', 't.me', 'google.com', 'forms.gle', 'apple.com', 'linkin.bio', 'beacons.ai', 'tiktok.com',
    ];

    /** Escolhe os anunciantes vistos hoje que recebem analytics. */
    public static function escolherTop(): array
    {
        $n = max(0, Config::int('ANALYTICS_TOP', 20));
        // Concorrentes sempre entram, na frente e fora da conta do top.
        $fixos = array_values(array_filter(Concorrente::marcasParaAnalytics(), [self::class, 'elegivel']));
        if ($n === 0) {
            return $fixos;
        }
        $soInfo = Config::int('SO_INFOPRODUTO', 1) === 1;
        $sql = "SELECT v.brand_id, COUNT(*) AS vistos, MAX(a.dias_no_ar) AS dias
                FROM vistos v
                JOIN anuncios a ON a.ad_id = v.ad_id
                JOIN anunciantes m ON m.brand_id = v.brand_id
                WHERE v.data = ? AND v.brand_id NOT LIKE 'sem-marca-%'" . ($soInfo ? " AND m.tipo = 'info'" : '') . "
                GROUP BY v.brand_id
                ORDER BY vistos DESC, dias DESC
                LIMIT " . ($n * 3);
        $marcas = [];
        foreach (Db::all($sql, [date('Y-m-d')]) as $l) {
            if (self::elegivel($l['brand_id'])) {
                $marcas[] = $l['brand_id'];
            }
        }
        return array_values(array_unique(array_merge($fixos, array_slice(array_diff($marcas, $fixos), 0, $n))));
    }

    /** Fica de fora quem deu serie zerada ou nao teve page_id achado nos ultimos 30 dias, e quem nao tem como achar. */
    public static function elegivel(string $brandId): bool
    {
        $limite30 = date('Y-m-d', strtotime('-30 days'));
        foreach (['sem_analytics_', 'sem_page_id_'] as $flag) {
            $v = Db::getConfig($flag . $brandId);
            if ($v !== null && $v >= $limite30) {
                return false;
            }
        }
        return self::pageIdConhecido($brandId) !== null || self::dominioProprio($brandId) !== null;
    }

    public static function pageIdConhecido(string $brandId): ?string
    {
        $v = Concorrente::pageIdDe($brandId) ?? Db::val("SELECT page_id FROM anunciantes WHERE brand_id = ? AND page_id IS NOT NULL AND page_id <> ''", [$brandId]);
        return $v === null ? null : (string)$v;
    }

    /** O dominio que mais aparece nos anuncios da marca, ignorando checkout, encurtador e rede social. */
    public static function dominioProprio(string $brandId): ?string
    {
        $rows = Db::all("SELECT dominio, COUNT(*) AS n FROM anuncios WHERE brand_id = ? AND dominio <> '' GROUP BY dominio ORDER BY n DESC", [$brandId]);
        foreach ($rows as $r) {
            $d = preg_replace('/^www\./', '', strtolower((string)$r['dominio']));
            $generico = false;
            foreach (array_merge(self::DOMINIOS_GENERICOS, Coletor::DOMINIOS_INFO) as $g) {
                if (str_contains($d, $g)) {
                    $generico = true;
                    break;
                }
            }
            if (!$generico) {
                return $d;
            }
        }
        return null;
    }

    /** Procura o page_id pelo dominio e so aceita se a marca devolvida for a mesma. */
    private static function descobrirPageId(string $brandId): array
    {
        $dominio = self::dominioProprio($brandId);
        if ($dominio === null) {
            Db::setConfig('sem_page_id_' . $brandId, date('Y-m-d'));
            return ['ok' => true, 'pulado' => 'sem dominio proprio para achar o page_id'];
        }
        $limite = max(1, min(10, Config::int('CONCORRENTE_CANDIDATOS', 3)));
        if (!Custos::cabe((int)round($limite * Config::float('PRECO_ITEM_USD', 0.0175) * 1_000_000))) {
            return ['ok' => false, 'teto' => true, 'erro' => 'Teto diario atingido antes de achar o page_id.'];
        }
        $r = Aisa::marcasPorDominio($dominio, $limite, 'pageid:' . $brandId);
        if (!$r['ok']) {
            return ['ok' => false, 'erro' => $r['erro'], 'http' => $r['http'], 'micros' => 0];
        }
        foreach ($r['json']['data'] ?? [] as $b) {
            // A resposta real traz o id da marca em "id"; a doc mostra "brand_id".
            if ((string)($b['brand_id'] ?? $b['id'] ?? '') === $brandId && !empty($b['ad_library_id'])) {
                Db::q('UPDATE anunciantes SET page_id = ? WHERE brand_id = ?', [(string)$b['ad_library_id'], $brandId]);
                return ['ok' => true, 'page_id' => (string)$b['ad_library_id'], 'micros' => $r['micros']];
            }
        }
        Db::setConfig('sem_page_id_' . $brandId, date('Y-m-d'));
        return ['ok' => true, 'pulado' => "page_id nao encontrado em $dominio", 'micros' => $r['micros']];
    }

    public static function atualizarMarca(string $brandId): array
    {
        $ultimo = Db::val('SELECT MAX(data) FROM analytics WHERE brand_id = ?', [$brandId]);
        $ontem = date('Y-m-d', strtotime('-1 day'));
        if ($ultimo !== null && $ultimo >= $ontem) {
            return ['ok' => true, 'pulado' => 'ja atualizado'];
        }

        $micros = 0;
        $pageId = self::pageIdConhecido($brandId);
        if ($pageId === null) {
            $p = self::descobrirPageId($brandId);
            $micros += $p['micros'] ?? 0;
            if (!$p['ok'] || empty($p['page_id'])) {
                return $p + ['micros' => $micros];
            }
            $pageId = $p['page_id'];
        }

        $backfill = max(1, min(30, Config::int('ANALYTICS_BACKFILL_DAYS', 7)));
        $inicio = $ultimo !== null
            ? max(date('Y-m-d', strtotime($ultimo . ' +1 day')), date('Y-m-d', strtotime("-$backfill days")))
            : date('Y-m-d', strtotime("-$backfill days"));
        $fim = date('Y-m-d');
        $dias = (int)((strtotime($fim) - strtotime($inicio)) / 86400) + 1;

        $estimativa = (int)round($dias * Config::float('PRECO_ITEM_USD', 0.0175) * 1_000_000);
        if (!Custos::cabe($estimativa)) {
            return ['ok' => false, 'teto' => true, 'erro' => 'Teto diario atingido antes do analytics.', 'micros' => $micros];
        }

        $r = Aisa::analyticsMarca($pageId, $inicio, $fim, $dias);
        $micros += $r['micros'];
        if (!$r['ok']) {
            return ['ok' => false, 'erro' => $r['erro'], 'http' => $r['http'], 'micros' => $micros];
        }
        $linhas = array_filter($r['json']['data'] ?? [], fn($l) => !empty($l['date']));
        // Serie toda zerada mesmo com o page_id: o provedor nao acompanha esta pagina. Nao salva e nao paga de novo por 30 dias.
        if ($linhas && !array_filter($linhas, fn($l) => (int)($l['active_count'] ?? 0) > 0)) {
            Db::setConfig('sem_analytics_' . $brandId, date('Y-m-d'));
            return ['ok' => true, 'linhas' => count($linhas), 'pulado' => 'contagem zerada, usando anuncios vistos', 'micros' => $micros];
        }
        foreach ($linhas as $linha) {
            Db::upsert('analytics', [
                'brand_id' => $brandId,
                'data' => substr((string)$linha['date'], 0, 10),
                'ativos' => (int)($linha['active_count'] ?? 0),
                'inativos' => (int)($linha['inactive_count'] ?? 0),
                'videos' => (int)($linha['video'] ?? 0),
                'imagens' => (int)($linha['image'] ?? 0),
            ], ['brand_id', 'data']);
        }
        return ['ok' => true, 'linhas' => count($r['json']['data'] ?? []), 'micros' => $micros];
    }
}
