<?php
declare(strict_types=1);

/**
 * Cliente REST da AIsa (cURL). Toda chamada paga leva X-AISA-Max-Price-USD e Idempotency-Key,
 * e o custo real vem no header X-AISA-Customer-Cost-Micros-USD. Nenhuma chamada e repetida aqui.
 */
final class Aisa
{
    public const BASE = 'https://api.aisa.one';

    public static function temChave(): bool
    {
        return Config::str('AISA_API_KEY') !== '';
    }

    /** Busca de anuncios (Foreplay discovery). */
    public static function buscarAnuncios(array $params, string $ref): array
    {
        $limite = (int)($params['limit'] ?? 10);
        return self::chamarPago('coleta', 'GET', '/apis/v1/foreplay/discovery/ads', $params, null, $ref, $limite);
    }

    /** Contagem diaria de anuncios ativos de um anunciante. Janela maxima de 30 dias. */
    public static function analyticsMarca(string $brandId, string $inicio, string $fim, int $diasEsperados): array
    {
        $p = ['id' => $brandId, 'start_date' => $inicio, 'end_date' => $fim];
        return self::chamarPago('analytics', 'GET', '/apis/v1/foreplay/brand/analytics', $p, null, $brandId, $diasEsperados);
    }

    /** Marcas candidatas para um dominio (Foreplay getBrandsByDomain, max 10). */
    public static function marcasPorDominio(string $dominio, int $limite, string $ref): array
    {
        $p = ['domain' => $dominio, 'limit' => $limite, 'order' => 'most_ranked'];
        return self::chamarPago('concorrente', 'GET', '/apis/v1/foreplay/brand/getBrandsByDomain', $p, null, $ref, $limite);
    }

    /** Anuncios ativos de uma marca, os mais antigos primeiro (Foreplay getAdsByBrandId). */
    public static function anunciosDaMarca(string $brandId, int $limite, string $ref): array
    {
        $p = ['brand_ids' => $brandId, 'live' => 'true', 'order' => 'longest_running', 'limit' => $limite];
        return self::chamarPago('concorrente', 'GET', '/apis/v1/foreplay/brand/getAdsByBrandId', $p, null, $ref, $limite);
    }

    /** Chat completions compativel com OpenAI. */
    public static function chat(array $corpo, string $ref): array
    {
        return self::chamarPago('raiox', 'POST', '/v1/chat/completions', [], $corpo, $ref, 0);
    }

    /** Checagem de conta (gratis). Com $chave, testa uma chave nova antes de salvar. */
    public static function saldo(?string $chave = null): array
    {
        return self::http('GET', '/v1/credits/balance', [], null, [], 15, $chave);
    }

    private static function chamarPago(string $tipo, string $metodo, string $caminho, array $query, ?array $corpo, string $ref, int $itensEsperados): array
    {
        $headers = [
            'X-AISA-Max-Price-USD: ' . number_format(Config::float('MAX_PRICE_PER_CALL_USD', 0.90), 4, '.', ''),
            'Idempotency-Key: ' . bin2hex(random_bytes(16)),
        ];
        $r = self::http($metodo, $caminho, $query, $corpo, $headers, $tipo === 'raiox' ? 90 : 60);

        // Custo: header da AIsa; sem header, estima e marca a fonte.
        $micros = 0;
        $fonte = 'header';
        if ($r['custo_micros'] !== null) {
            $micros = $r['custo_micros'];
        } elseif ($r['http'] >= 400 || $r['http'] === 0) {
            $fonte = 'sem_header_erro';
        } elseif ($tipo === 'raiox') {
            $u = $r['json']['usage'] ?? [];
            $micros = (int)round(
                (($u['prompt_tokens'] ?? 0) * Config::float('AI_PRICE_IN', 0.10)
                + ($u['completion_tokens'] ?? 0) * Config::float('AI_PRICE_OUT', 0.50))
            );
            $fonte = 'estimado_tokens';
        } else {
            $itens = count($r['json']['data'] ?? []);
            $micros = (int)round($itens * Config::float('PRECO_ITEM_USD', 0.0175) * 1_000_000);
            $fonte = 'estimado_itens';
        }
        $itens = is_array($r['json']['data'] ?? null) ? count($r['json']['data']) : null;
        Custos::registrar($tipo, $caminho, $micros, $fonte, $r['http'], $itens, $ref);
        $r['micros'] = $micros;
        return $r;
    }

    private static function http(string $metodo, string $caminho, array $query, ?array $corpo, array $extra, int $timeout, ?string $chave = null): array
    {
        $url = self::BASE . $caminho;
        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $headers = array_merge([
            'Authorization: Bearer ' . ($chave ?? Config::str('AISA_API_KEY')),
            'Accept: application/json',
        ], $extra);
        if ($corpo !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $respHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HEADERFUNCTION => function ($ch, $linha) use (&$respHeaders) {
                $p = explode(':', $linha, 2);
                if (count($p) === 2) {
                    $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
                }
                return strlen($linha);
            },
        ]);
        $ca = Config::str('CURL_CA_BUNDLE');
        if ($ca !== '' && is_file($ca)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        if ($corpo !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE));
        }
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $json = is_string($body) ? json_decode($body, true) : null;
        $custo = $respHeaders['x-aisa-customer-cost-micros-usd'] ?? null;

        $r = [
            'ok' => $errno === 0 && $http >= 200 && $http < 300 && is_array($json),
            'http' => $http,
            'json' => is_array($json) ? $json : null,
            'custo_micros' => ($custo !== null && ctype_digit($custo)) ? (int)$custo : null,
            'retry_after' => $respHeaders['retry-after'] ?? null,
            'erro' => null,
        ];
        if (!$r['ok']) {
            $r['erro'] = self::explicarErro($errno, $errmsg, $http, $json, $body);
        }
        return $r;
    }

    private static function explicarErro(int $errno, string $errmsg, int $http, ?array $json, $body): string
    {
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            return 'Tempo esgotado falando com a AIsa. A chamada nao foi repetida.';
        }
        if ($errno !== 0) {
            return "Falha de conexao (cURL $errno): $errmsg";
        }
        $codigo = $json['code'] ?? $json['error']['code'] ?? '';
        $msg = $json['message'] ?? $json['error']['message'] ?? (is_string($json['error'] ?? null) ? $json['error'] : '');
        $base = match (true) {
            $http === 401 => 'Chave da AIsa invalida ou ausente (401). Confira AISA_API_KEY no .env.',
            $http === 402 => 'Sem saldo, limite da chave ou teto de preco excedido (402).',
            $http === 429 => 'Limite de requisicoes da AIsa (429). Tente mais tarde.',
            $http >= 500 => "Erro do lado da AIsa ou do provedor ($http).",
            default => "Resposta inesperada da AIsa (HTTP $http).",
        };
        $det = trim($codigo . ' ' . $msg);
        if ($det === '' && is_string($body)) {
            $det = mb_substr($body, 0, 200);
        }
        return $base . ($det !== '' ? " Detalhe: $det" : '');
    }
}
