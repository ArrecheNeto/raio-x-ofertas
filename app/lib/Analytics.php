<?php
declare(strict_types=1);

/** Contagem real de anuncios ativos por dia (Foreplay brand/analytics), so para os maiores anunciantes. */
final class Analytics
{
    /** Escolhe os anunciantes vistos hoje que recebem analytics. */
    public static function escolherTop(): array
    {
        $n = max(0, Config::int('ANALYTICS_TOP', 20));
        if ($n === 0) {
            return Concorrente::marcasParaAnalytics();
        }
        $soInfo = Config::int('SO_INFOPRODUTO', 1) === 1;
        $sql = "SELECT v.brand_id, COUNT(*) AS vistos, MAX(a.dias_no_ar) AS dias
                FROM vistos v
                JOIN anuncios a ON a.ad_id = v.ad_id
                JOIN anunciantes m ON m.brand_id = v.brand_id
                WHERE v.data = ? AND v.brand_id NOT LIKE 'sem-marca-%'" . ($soInfo ? " AND m.tipo = 'info'" : '') . "
                GROUP BY v.brand_id
                ORDER BY vistos DESC, dias DESC
                LIMIT " . ($n * 2);
        $limite30 = date('Y-m-d', strtotime('-30 days'));
        $marcas = [];
        foreach (Db::all($sql, [date('Y-m-d')]) as $l) {
            $sem = Db::getConfig('sem_analytics_' . $l['brand_id']);
            if ($sem === null || $sem < $limite30) {
                $marcas[] = $l['brand_id'];
            }
        }
        // Concorrentes sempre entram, na frente e fora da conta do top.
        $fixos = array_values(array_filter(Concorrente::marcasParaAnalytics(), function ($b) use ($limite30) {
            $sem = Db::getConfig('sem_analytics_' . $b);
            return $sem === null || $sem < $limite30;
        }));
        return array_values(array_unique(array_merge($fixos, array_slice(array_diff($marcas, $fixos), 0, $n))));
    }

    public static function atualizarMarca(string $brandId): array
    {
        $ultimo = Db::val('SELECT MAX(data) FROM analytics WHERE brand_id = ?', [$brandId]);
        $ontem = date('Y-m-d', strtotime('-1 day'));
        if ($ultimo !== null && $ultimo >= $ontem) {
            return ['ok' => true, 'pulado' => 'ja atualizado'];
        }
        $backfill = max(1, min(30, Config::int('ANALYTICS_BACKFILL_DAYS', 7)));
        $inicio = $ultimo !== null
            ? max(date('Y-m-d', strtotime($ultimo . ' +1 day')), date('Y-m-d', strtotime("-$backfill days")))
            : date('Y-m-d', strtotime("-$backfill days"));
        $fim = date('Y-m-d');
        $dias = (int)((strtotime($fim) - strtotime($inicio)) / 86400) + 1;

        $estimativa = (int)round($dias * Config::float('PRECO_ITEM_USD', 0.0175) * 1_000_000);
        if (!Custos::cabe($estimativa)) {
            return ['ok' => false, 'teto' => true, 'erro' => 'Teto diario atingido antes do analytics.'];
        }

        // Para concorrente achado pelo dominio usa o page_id (ad_library_id), que e o que a doc pede.
        $r = Aisa::analyticsMarca(Concorrente::pageIdDe($brandId) ?? $brandId, $inicio, $fim, $dias);
        if (!$r['ok']) {
            return ['ok' => false, 'erro' => $r['erro'], 'http' => $r['http']];
        }
        $linhas = array_filter($r['json']['data'] ?? [], fn($l) => !empty($l['date']));
        // Serie toda zerada com anuncios ativos vistos = o provedor nao acompanha esta marca. Nao salva e nao paga de novo.
        if ($linhas && !array_filter($linhas, fn($l) => (int)($l['active_count'] ?? 0) > 0)) {
            Db::setConfig('sem_analytics_' . $brandId, date('Y-m-d'));
            return ['ok' => true, 'linhas' => count($linhas), 'pulado' => 'contagem zerada, usando anuncios vistos', 'micros' => $r['micros']];
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
        return ['ok' => true, 'linhas' => count($r['json']['data'] ?? []), 'micros' => $r['micros']];
    }
}
