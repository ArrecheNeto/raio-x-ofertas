<?php
declare(strict_types=1);

/** Coleta de anuncios por nicho e gravacao no banco (sem duplicados). */
final class Coletor
{
    public const DOMINIOS_INFO = [
        'hotmart', 'kiwify', 'eduzz', 'monetizze', 'ticto', 'braip', 'perfectpay', 'kirvano', 'greenn',
        'cakto', 'lastlink', 'hubla', 'yampi', 'pepper', 'doppus', 'sun.eduzz', 'go.hotmart', 'pay.hotmart',
        'pay.kiwify', 'memberkit', 'cademi', 'curseduca', 'herospark', 'greatpages', 'lpages',
    ];
    private const PALAVRAS_INFO = '/\b(curso|cursos|aula|aulas|m[eé]todo|ebook|e-book|mentoria|treinamento|masterclass|webin[aá]rio|webinar|workshop|imers[aã]o|desafio de \d+|apostila|guia completo|programa online|comunidade|forma[cç][aã]o|certificado|online course|courses?|coaching|digital product|info ?produto|online education|e-?learning|exam preparation|ead)\b/iu';
    // Categorias que contem "course" mas nao sao infoproduto (app, faculdade).
    private const NAO_INFO = '/\b(app|application|higher education|university|universidade)\b/iu';

    public static function coletarNicho(array $nicho): array
    {
        $limite = max(1, min(250, Config::int('ADS_PER_NICHE', 20)));
        $estimativa = (int)round($limite * Config::float('PRECO_ITEM_USD', 0.0175) * 1_000_000);
        if (!Custos::cabe($estimativa)) {
            return ['ok' => false, 'teto' => true, 'erro' => 'Teto diario atingido. Coleta do nicho "' . $nicho['nome'] . '" nao foi feita.'];
        }

        $base = [
            'query' => $nicho['termo'],
            'languages' => 'pt',
            'live' => 'true',
            'publisher_platform' => 'facebook',
            'order' => 'longest_running',
            'limit' => $limite,
        ];
        $r = Aisa::buscarAnuncios($base, 'nicho:' . $nicho['id']);
        if (!$r['ok']) {
            return ['ok' => false, 'erro' => $r['erro'], 'http' => $r['http']];
        }
        $dados = $r['json']['data'] ?? [];
        $micros = $r['micros'];

        // Segunda busca so com anuncios marcados como educacao: traz poucos itens (cobra por item) e quase todos infoproduto.
        if (Config::int('BUSCA_EDUCACAO', 1) === 1 && Custos::cabe($estimativa)) {
            $r2 = Aisa::buscarAnuncios($base + ['niches' => 'education'], 'nicho-edu:' . $nicho['id']);
            if ($r2['ok']) {
                $dados = array_merge($dados, $r2['json']['data'] ?? []);
                $micros += $r2['micros'];
            } else {
                Db::erro('coleta-edu:' . $nicho['id'], $r2['erro']);
            }
        }

        $hoje = date('Y-m-d');
        $novos = 0;
        $vistosAgora = [];
        foreach ($dados as $ad) {
            $adId = (string)($ad['ad_id'] ?? $ad['id'] ?? '');
            if ($adId === '' || isset($vistosAgora[$adId])) {
                continue; // a API as vezes repete o mesmo anuncio
            }
            $vistosAgora[$adId] = true;
            $novos += self::salvarAnuncio($ad, (int)$nicho['id'], $hoje) ? 1 : 0;
        }
        return ['ok' => true, 'recebidos' => count($dados), 'unicos' => count($vistosAgora), 'novos' => $novos, 'micros' => $micros];
    }

    /** Retorna true se o anuncio e novo no banco. Sem nicho (concorrente), mantem o nicho que ja estava gravado. */
    public static function salvarAnuncio(array $ad, ?int $nichoId, string $hoje): bool
    {
        $adId = (string)($ad['ad_id'] ?? $ad['id']);
        $brandId = (string)($ad['brand_id'] ?? ('sem-marca-' . $adId));
        $link = (string)($ad['link_url'] ?? '');
        $dominio = strtolower((string)(parse_url($link, PHP_URL_HOST) ?? ''));
        $transcricao = (string)($ad['full_transcription'] ?? '');
        $descricao = (string)($ad['description'] ?? '');
        $headline = (string)($ad['headline'] ?? '');
        $tipo = self::classificar($dominio, $link, $descricao . ' ' . $headline . ' ' . $transcricao, (string)($ad['product_category'] ?? ''));
        $iniciou = isset($ad['started_running']) ? date('Y-m-d', (int)($ad['started_running'] / 1000)) : null;
        $dias = (int)($ad['running_duration']['days'] ?? 0);

        $existe = Db::one('SELECT ad_id, primeiro_visto, nicho_id FROM anuncios WHERE ad_id = ?', [$adId]);

        Db::upsert('anuncios', [
            'ad_id' => $adId,
            'fp_id' => (string)($ad['id'] ?? ''),
            'brand_id' => $brandId,
            'nicho_id' => $nichoId ?? ($existe['nicho_id'] ?? null),
            'nome' => (string)($ad['name'] ?? $ad['brand_name'] ?? ''),
            'headline' => $headline,
            'descricao' => $descricao,
            'transcricao' => $transcricao,
            'link_url' => $link,
            'dominio' => $dominio,
            'cta_type' => (string)($ad['cta_type'] ?? ''),
            'formato' => strtolower((string)($ad['display_format'] ?? $ad['type'] ?? '')),
            'plataformas' => json_encode($ad['publisher_platform'] ?? []),
            'categoria' => implode(', ', (array)($ad['categories'] ?? [])),
            'product_category' => (string)($ad['product_category'] ?? ''),
            'tipo' => $tipo,
            'dias_no_ar' => $dias,
            'iniciou_em' => $iniciou,
            'thumbnail' => (string)($ad['thumbnail'] ?? ''),
            'video' => (string)($ad['video'] ?? ''),
            'imagem' => (string)($ad['image'] ?? ''),
            'live' => !empty($ad['live']) ? 1 : 0,
            'hash_conteudo' => sha1($headline . '|' . $descricao . '|' . $transcricao),
            'primeiro_visto' => $existe['primeiro_visto'] ?? $hoje,
            'ultimo_visto' => $hoje,
        ], ['ad_id']);

        Db::upsert('vistos', ['data' => $hoje, 'ad_id' => $adId, 'brand_id' => $brandId], ['data', 'ad_id'], []);

        $marca = Db::one('SELECT brand_id, primeiro_visto, tipo, nicho_id FROM anunciantes WHERE brand_id = ?', [$brandId]);
        Db::upsert('anunciantes', [
            'brand_id' => $brandId,
            'nome' => (string)($ad['name'] ?? $ad['brand_name'] ?? ''),
            'avatar' => (string)($ad['avatar'] ?? ''),
            'nicho_id' => $nichoId ?? ($marca['nicho_id'] ?? null),
            'tipo' => ($marca['tipo'] ?? '') === 'info' ? 'info' : $tipo,
            'primeiro_visto' => $marca['primeiro_visto'] ?? $hoje,
            'ultimo_visto' => $hoje,
            'atualizado_em' => date('Y-m-d H:i:s'),
        ], ['brand_id']);

        return $existe === null;
    }

    public static function classificar(string $dominio, string $link, string $texto, string $categoria): string
    {
        foreach (self::DOMINIOS_INFO as $d) {
            if ($dominio !== '' && str_contains($dominio, $d)) {
                return 'info';
            }
        }
        if (preg_match(self::NAO_INFO, $categoria) || str_contains($dominio, 'itunes.apple.com') || str_contains($dominio, 'play.google.com')) {
            return 'outro';
        }
        if (preg_match(self::PALAVRAS_INFO, $categoria)) {
            return 'info';
        }
        // No texto exige 2 termos diferentes, para "metodo" sozinho (clinica, suplemento) nao contar.
        preg_match_all(self::PALAVRAS_INFO, $texto, $m);
        $distintos = array_unique(array_map(fn($s) => mb_strtolower($s), $m[0] ?? []));
        return count($distintos) >= 2 ? 'info' : 'outro';
    }
}
