<?php
declare(strict_types=1);

/**
 * Modo Concorrente: anunciantes escolhidos a dedo, acompanhados todo dia.
 * Entra por dominio (getBrandsByDomain acha a marca) ou direto pelo brand_id de uma oferta ja coletada.
 */
final class Concorrente
{
    public static function ativo(): bool
    {
        return Config::int('MODO_CONCORRENTE', 1) === 1;
    }

    /** Aceita "https://www.site.com.br/pagina" ou "site.com.br" e devolve "site.com.br". */
    public static function limparDominio(string $entrada): string
    {
        $entrada = trim(mb_strtolower($entrada));
        if ($entrada === '') {
            return '';
        }
        $host = parse_url(preg_match('#^https?://#', $entrada) ? $entrada : 'https://' . $entrada, PHP_URL_HOST) ?: '';
        $host = preg_replace('/^www\./', '', $host);
        return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host) ? $host : '';
    }

    public static function total(): int
    {
        return (int)Db::val('SELECT COUNT(*) FROM concorrentes');
    }

    /** Retorna mensagem de erro ou null. */
    public static function adicionarPorDominio(string $entrada, string $nome): ?string
    {
        $dominio = self::limparDominio($entrada);
        if ($dominio === '') {
            return 'Domínio inválido. Use algo como meusite.com.br.';
        }
        if (self::total() >= Config::int('MAX_CONCORRENTES', 10)) {
            return 'Limite de concorrentes atingido (MAX_CONCORRENTES no .env).';
        }
        if (Db::val('SELECT id FROM concorrentes WHERE dominio = ?', [$dominio])) {
            return 'Este domínio já está na lista.';
        }
        Db::q(
            "INSERT INTO concorrentes (nome, dominio, ativo, status, criado_em) VALUES (?,?,1,'buscando',?)",
            [trim($nome) ?: $dominio, $dominio, date('Y-m-d H:i:s')]
        );
        Fila::adicionar('concorrente_busca', (string)Db::pdo()->lastInsertId());
        return null;
    }

    /** Pelo botao da pagina da oferta: a marca ja e conhecida, nao precisa de busca paga. */
    public static function adicionarPorMarca(string $brandId): ?string
    {
        $m = Db::one('SELECT brand_id, nome FROM anunciantes WHERE brand_id = ?', [$brandId]);
        if (!$m || str_starts_with($brandId, 'sem-marca-')) {
            return 'Anunciante não encontrado.';
        }
        if (Db::val('SELECT id FROM concorrentes WHERE brand_id = ?', [$brandId])) {
            return 'Este anunciante já é acompanhado.';
        }
        if (self::total() >= Config::int('MAX_CONCORRENTES', 10)) {
            return 'Limite de concorrentes atingido (MAX_CONCORRENTES no .env).';
        }
        $dominio = (string)Db::val("SELECT dominio FROM anuncios WHERE brand_id = ? AND dominio <> '' ORDER BY dias_no_ar DESC LIMIT 1", [$brandId]);
        Db::q(
            "INSERT INTO concorrentes (nome, dominio, brand_id, ativo, status, criado_em) VALUES (?,?,?,1,'ok',?)",
            [$m['nome'], preg_replace('/^www\./', '', $dominio), $brandId, date('Y-m-d H:i:s')]
        );
        Fila::adicionar('concorrente', (string)Db::pdo()->lastInsertId());
        return null;
    }

    /** Troca a marca escolhida por outra candidata devolvida na busca do dominio. */
    public static function escolherCandidato(int $id, string $brandId): void
    {
        $c = Db::one('SELECT * FROM concorrentes WHERE id = ?', [$id]);
        foreach (json_decode((string)($c['candidatos'] ?? ''), true) ?: [] as $cand) {
            if (($cand['brand_id'] ?? '') === $brandId) {
                Db::q("UPDATE concorrentes SET brand_id = ?, ad_library_id = ?, status = 'ok', erro = NULL WHERE id = ?", [$brandId, $cand['ad_library_id'] ?? null, $id]);
                Fila::adicionar('concorrente', (string)$id);
                return;
            }
        }
    }

    /** Fase 1 do dia: busca de marca para os pendentes e coleta para os prontos. */
    public static function enfileirarDia(): void
    {
        if (!self::ativo()) {
            return;
        }
        foreach (Db::all("SELECT id, status FROM concorrentes WHERE ativo = 1 AND status IN ('ok','buscando')") as $c) {
            Fila::adicionar($c['status'] === 'ok' ? 'concorrente' : 'concorrente_busca', (string)$c['id']);
        }
    }

    /** Marcas acompanhadas que recebem analytics todo dia, na frente do top dos nichos. */
    public static function marcasParaAnalytics(): array
    {
        if (!self::ativo()) {
            return [];
        }
        return array_column(Db::all("SELECT brand_id FROM concorrentes WHERE ativo = 1 AND status = 'ok' AND brand_id IS NOT NULL"), 'brand_id');
    }

    public static function pageIdDe(string $brandId): ?string
    {
        $v = Db::val("SELECT ad_library_id FROM concorrentes WHERE brand_id = ? AND ad_library_id IS NOT NULL AND ad_library_id <> '' LIMIT 1", [$brandId]);
        return $v === null ? null : (string)$v;
    }

    public static function buscarMarca(int $id): array
    {
        $c = Db::one('SELECT * FROM concorrentes WHERE id = ?', [$id]);
        if (!$c || (int)$c['ativo'] !== 1) {
            return ['ok' => true, 'pulado' => 'concorrente removido ou pausado'];
        }
        if ($c['status'] === 'ok') {
            return ['ok' => true, 'pulado' => 'marca ja encontrada'];
        }
        $limite = max(1, min(10, Config::int('CONCORRENTE_CANDIDATOS', 3)));
        if (!Custos::cabe((int)round($limite * Config::float('PRECO_ITEM_USD', 0.0175) * 1_000_000))) {
            return ['ok' => false, 'teto' => true, 'erro' => 'Teto diario atingido antes da busca do concorrente.'];
        }
        $r = Aisa::marcasPorDominio((string)$c['dominio'], $limite, 'concorrente:' . $id);
        if (!$r['ok']) {
            Db::q("UPDATE concorrentes SET status = 'erro', erro = ? WHERE id = ?", [$r['erro'], $id]);
            return ['ok' => false, 'erro' => $r['erro'], 'http' => $r['http']];
        }
        $cands = [];
        foreach ($r['json']['data'] ?? [] as $b) {
            if (!empty($b['brand_id'])) {
                $cands[] = ['brand_id' => (string)$b['brand_id'], 'ad_library_id' => (string)($b['ad_library_id'] ?? ''), 'nome' => (string)($b['name'] ?? '')];
            }
        }
        if (!$cands) {
            Db::q("UPDATE concorrentes SET status = 'nao_encontrado', erro = 'Nenhum anunciante encontrado para este domínio.' WHERE id = ?", [$id]);
            return ['ok' => true, 'pulado' => 'nenhuma marca para o dominio', 'micros' => $r['micros']];
        }
        $p = $cands[0];
        Db::q(
            "UPDATE concorrentes SET brand_id = ?, ad_library_id = ?, candidatos = ?, nome = CASE WHEN nome = dominio THEN ? ELSE nome END, status = 'ok', erro = NULL WHERE id = ?",
            [$p['brand_id'], $p['ad_library_id'], json_encode($cands, JSON_UNESCAPED_UNICODE), $p['nome'] ?: $c['dominio'], $id]
        );
        Fila::adicionar('concorrente', (string)$id);
        return ['ok' => true, 'linhas' => count($cands), 'micros' => $r['micros']];
    }

    public static function coletar(int $id): array
    {
        $c = Db::one('SELECT * FROM concorrentes WHERE id = ?', [$id]);
        if (!$c || (int)$c['ativo'] !== 1 || $c['status'] !== 'ok') {
            return ['ok' => true, 'pulado' => 'concorrente removido, pausado ou sem marca'];
        }
        $limite = max(1, min(250, Config::int('CONCORRENTE_ADS', 20)));
        if (!Custos::cabe((int)round($limite * Config::float('PRECO_ITEM_USD', 0.0175) * 1_000_000))) {
            return ['ok' => false, 'teto' => true, 'erro' => 'Teto diario atingido antes da coleta do concorrente "' . $c['nome'] . '".'];
        }
        $r = Aisa::anunciosDaMarca((string)$c['brand_id'], $limite, 'concorrente:' . $id);
        if (!$r['ok']) {
            Db::q('UPDATE concorrentes SET erro = ? WHERE id = ?', [$r['erro'], $id]);
            return ['ok' => false, 'erro' => $r['erro'], 'http' => $r['http']];
        }
        $hoje = date('Y-m-d');
        $novos = 0;
        $vistos = [];
        foreach ($r['json']['data'] ?? [] as $ad) {
            $adId = (string)($ad['ad_id'] ?? $ad['id'] ?? '');
            if ($adId === '' || isset($vistos[$adId])) {
                continue;
            }
            $vistos[$adId] = true;
            $ad['brand_id'] = $ad['brand_id'] ?? $c['brand_id'];
            $novos += Coletor::salvarAnuncio($ad, null, $hoje) ? 1 : 0;
        }
        Db::q('UPDATE concorrentes SET ultima_coleta = ?, erro = NULL WHERE id = ?', [date('Y-m-d H:i:s'), $id]);
        return ['ok' => true, 'unicos' => count($vistos), 'novos' => $novos, 'micros' => $r['micros']];
    }
}
