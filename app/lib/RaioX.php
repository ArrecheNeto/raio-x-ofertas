<?php
declare(strict_types=1);

/** Raio-X do criativo com IA (API de modelos da AIsa, formato OpenAI). Saida em JSON com schema fixo. */
final class RaioX
{
    public const CAMPOS = [
        'gancho' => 'Gancho (primeiros 3s)',
        'promessa' => 'Promessa',
        'mecanismo_unico' => 'Mecanismo único',
        'prova' => 'Prova',
        'oferta_cta' => 'Oferta e CTA',
        'publico' => 'Público',
        'formato' => 'Formato',
    ];

    /** Anuncios principais que ainda nao tem Raio-X, ou cujo texto mudou. */
    public static function pendentes(int $limite): array
    {
        $soInfo = Config::int('SO_INFOPRODUTO', 1) === 1;
        return array_column(Db::all(
            "SELECT a.ad_id FROM anunciantes m
             JOIN anuncios a ON a.ad_id = m.anuncio_principal
             LEFT JOIN raiox r ON r.ad_id = a.ad_id
             WHERE m.ultimo_visto = ? AND (r.ad_id IS NULL OR r.hash_conteudo <> a.hash_conteudo)" . ($soInfo ? " AND (m.tipo = 'info' OR m.brand_id IN (SELECT brand_id FROM concorrentes WHERE ativo = 1))" : '') . "
             ORDER BY a.dias_no_ar DESC LIMIT " . max(0, $limite),
            [date('Y-m-d')]
        ), 'ad_id');
    }

    public static function analisar(string $adId): array
    {
        $ad = Db::one('SELECT * FROM anuncios WHERE ad_id = ?', [$adId]);
        if (!$ad) {
            return ['ok' => false, 'erro' => 'Anuncio nao encontrado'];
        }
        $existente = Db::one('SELECT hash_conteudo FROM raiox WHERE ad_id = ?', [$adId]);
        if ($existente && $existente['hash_conteudo'] === $ad['hash_conteudo']) {
            return ['ok' => true, 'pulado' => 'sem mudanca'];
        }
        if (!Custos::cabe(5000)) {
            return ['ok' => false, 'teto' => true, 'erro' => 'Teto diario atingido antes do Raio-X.'];
        }

        $conteudo = "Anunciante: {$ad['nome']}\nFormato: {$ad['formato']}\nDias no ar: {$ad['dias_no_ar']}\n"
            . "Pagina de destino: {$ad['dominio']}\nCTA: {$ad['cta_type']}\n"
            . "Titulo: {$ad['headline']}\n\nTexto do anuncio:\n" . mb_substr((string)$ad['descricao'], 0, 3000)
            . "\n\nTranscricao do video:\n" . (trim((string)$ad['transcricao']) !== '' ? mb_substr((string)$ad['transcricao'], 0, 5000) : '(sem video ou sem transcricao)');

        $sistema = <<<TXT
Voce e um estrategista de trafego e copy de infoprodutos no Brasil. Analise o anuncio e responda SOMENTE com um JSON valido, em portugues do Brasil, sem travessoes (nao use o caractere "—" nem "–"), neste formato exato:
{"gancho":"","promessa":"","mecanismo_unico":"","prova":"","oferta_cta":"","publico":"","formato":"","como_modelar":{"angulos":[{"titulo":"","descricao":""},{"titulo":"","descricao":""},{"titulo":"","descricao":""}],"ganchos":["","","","",""]},"resumo":""}
Regras: "gancho" e o que prende nos primeiros 3 segundos (cite a frase se houver). "mecanismo_unico" e o porque diferente que o anuncio vende; se nao houver, diga isso. "prova" lista as provas usadas (depoimento, autoridade, demonstracao, numeros). "formato" descreve o tipo de criativo (UGC, talking head, demonstracao, imagem estatica etc). Em "como_modelar", crie 3 angulos DIFERENTES para uma versao PROPRIA e original, sem copiar frases nem a marca, e 5 ideias de gancho novas. Cada campo com no maximo 3 frases. Nao invente fatos que nao estao no anuncio; quando faltar informacao, diga "nao aparece no anuncio".
TXT;

        $r = Aisa::chat([
            'model' => Config::str('AI_MODEL', 'deepseek-v4.1-flash'),
            'messages' => [
                ['role' => 'system', 'content' => $sistema],
                ['role' => 'user', 'content' => $conteudo],
            ],
            'temperature' => 0.4,
            'max_tokens' => Config::int('AI_MAX_TOKENS', 1600),
            'response_format' => ['type' => 'json_object'],
        ], 'ad:' . $adId);

        if (!$r['ok']) {
            return ['ok' => false, 'erro' => $r['erro'], 'http' => $r['http']];
        }
        $texto = (string)($r['json']['choices'][0]['message']['content'] ?? '');
        $json = self::extrairJson($texto);
        if ($json === null) {
            return ['ok' => false, 'erro' => 'A IA nao devolveu um JSON valido. Inicio da resposta: ' . mb_substr($texto, 0, 200)];
        }
        Db::upsert('raiox', [
            'ad_id' => $adId,
            'brand_id' => $ad['brand_id'],
            'modelo' => Config::str('AI_MODEL', 'deepseek-v4.1-flash'),
            'hash_conteudo' => $ad['hash_conteudo'],
            'json' => json_encode(self::normalizar($json), JSON_UNESCAPED_UNICODE),
            'criado_em' => date('Y-m-d H:i:s'),
        ], ['ad_id']);
        return ['ok' => true, 'micros' => $r['micros']];
    }

    private static function extrairJson(string $t): ?array
    {
        $t = trim(preg_replace('/^```(?:json)?|```$/m', '', $t));
        $j = json_decode($t, true);
        if (is_array($j)) {
            return $j;
        }
        if (preg_match('/\{.*\}/s', $t, $m)) {
            $j = json_decode($m[0], true);
            return is_array($j) ? $j : null;
        }
        return null;
    }

    private static function limpar($v): string
    {
        $s = is_array($v) ? implode('; ', array_map(fn($x) => is_scalar($x) ? (string)$x : json_encode($x, JSON_UNESCAPED_UNICODE), $v)) : (string)$v;
        return trim(str_replace(['—', '–'], [',', ','], $s));
    }

    /** Garante o schema fixo mesmo se o modelo errar alguma chave. */
    public static function normalizar(array $j): array
    {
        $out = [];
        foreach (array_keys(self::CAMPOS) as $c) {
            $out[$c] = self::limpar($j[$c] ?? '');
        }
        $angulos = [];
        foreach (array_slice((array)($j['como_modelar']['angulos'] ?? []), 0, 3) as $a) {
            $angulos[] = is_array($a)
                ? ['titulo' => self::limpar($a['titulo'] ?? ''), 'descricao' => self::limpar($a['descricao'] ?? '')]
                : ['titulo' => '', 'descricao' => self::limpar($a)];
        }
        $ganchos = array_map(fn($g) => self::limpar($g), array_slice((array)($j['como_modelar']['ganchos'] ?? []), 0, 5));
        $out['como_modelar'] = ['angulos' => $angulos, 'ganchos' => $ganchos];
        $out['resumo'] = self::limpar($j['resumo'] ?? '');
        return $out;
    }
}
