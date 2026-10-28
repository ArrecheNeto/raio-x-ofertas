<?php
declare(strict_types=1);

/** Curva de escala: compara anuncios ativos de hoje com 7 e 14 dias atras. */
final class Escala
{
    public const ROTULOS = [
        'escalando' => 'Escalando',
        'estavel' => 'Estável',
        'caindo' => 'Caindo',
        'coletando' => 'Coletando histórico',
    ];

    /** Serie diaria [data => valor]. Usa analytics (real) quando existe; senao, anuncios vistos na coleta. */
    public static function serie(string $brandId, int $dias = 30): array
    {
        $desde = date('Y-m-d', strtotime("-$dias days"));
        $real = Db::all('SELECT data, ativos FROM analytics WHERE brand_id = ? AND data >= ? ORDER BY data', [$brandId, $desde]);
        if (count($real) >= 2) {
            return ['fonte' => 'real', 'pontos' => array_column($real, 'ativos', 'data')];
        }
        $vistos = Db::all('SELECT data, COUNT(*) AS n FROM vistos WHERE brand_id = ? AND data >= ? GROUP BY data ORDER BY data', [$brandId, $desde]);
        $pontos = array_map('intval', array_column($vistos, 'n', 'data'));
        if (count($real) === 1 && !$pontos) {
            return ['fonte' => 'real', 'pontos' => array_column($real, 'ativos', 'data')];
        }
        return ['fonte' => 'vistos', 'pontos' => $pontos];
    }

    /** Valor no dia mais proximo de $alvo (ate 2 dias de folga). */
    private static function valorEm(array $pontos, string $alvo): ?int
    {
        $t = strtotime($alvo);
        foreach ([0, -1, 1, -2, 2] as $f) {
            $d = date('Y-m-d', $t + $f * 86400);
            if (isset($pontos[$d])) {
                return (int)$pontos[$d];
            }
        }
        return null;
    }

    public static function classificar(string $brandId): array
    {
        $s = self::serie($brandId);
        $pontos = $s['pontos'];
        if (!$pontos) {
            return ['status' => 'coletando', 'atual' => null, 'fonte' => $s['fonte'], 'd7' => null, 'd14' => null];
        }
        $ultimaData = array_key_last($pontos);
        $atual = (int)$pontos[$ultimaData];
        $v7 = self::valorEm($pontos, date('Y-m-d', strtotime($ultimaData . ' -7 days')));
        $v14 = self::valorEm($pontos, date('Y-m-d', strtotime($ultimaData . ' -14 days')));
        $d7 = $v7 !== null ? ($atual - $v7) / max($v7, 1) : null;
        $d14 = $v14 !== null ? ($atual - $v14) / max($v14, 1) : null;

        $limiar = Config::float('ESCALA_LIMIAR', 0.30);
        $ref = $d7 ?? $d14;
        if ($ref === null) {
            $status = 'coletando';
        } elseif ($ref >= $limiar) {
            $status = 'escalando';
        } elseif ($ref <= -$limiar) {
            $status = 'caindo';
        } else {
            $status = 'estavel';
        }
        return ['status' => $status, 'atual' => $atual, 'fonte' => $s['fonte'], 'd7' => $d7, 'd14' => $d14];
    }

    /** Recalcula status, anuncio principal e dias no ar de quem apareceu nos ultimos 30 dias. Nao custa nada. */
    public static function recalcularTodos(): int
    {
        $desde = date('Y-m-d', strtotime('-30 days'));
        $marcas = Db::all('SELECT brand_id FROM anunciantes WHERE ultimo_visto >= ?', [$desde]);
        foreach ($marcas as $m) {
            $b = $m['brand_id'];
            $c = self::classificar($b);
            $principal = Db::one(
                "SELECT ad_id, dias_no_ar FROM anuncios WHERE brand_id = ?
                 ORDER BY live DESC, CASE tipo WHEN 'info' THEN 0 ELSE 1 END, dias_no_ar DESC LIMIT 1",
                [$b]
            );
            Db::q(
                'UPDATE anunciantes SET status = ?, ativos_atual = ?, ativos_fonte = ?, delta7 = ?, delta14 = ?, anuncio_principal = ?, max_dias = ?, atualizado_em = ? WHERE brand_id = ?',
                [$c['status'], $c['atual'], $c['fonte'], $c['d7'], $c['d14'], $principal['ad_id'] ?? null, (int)($principal['dias_no_ar'] ?? 0), date('Y-m-d H:i:s'), $b]
            );
        }
        return count($marcas);
    }
}
