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
            return [];
        }
        $soInfo = Config::int('SO_INFOPRODUTO', 1) === 1;
        $sql = "SELECT v.brand_id, COUNT(*) AS vistos, MAX(a.dias_no_ar) AS dias
                FROM vistos v
                JOIN anuncios a ON a.ad_id = v.ad_id
                JOIN anunciantes m ON m.brand_id = v.brand_id
                WHERE v.data = ? AND v.brand_id NOT LIKE 'sem-marca-%'" . ($soInfo ? " AND m.tipo = 'info'" : '') . "
                GROUP BY v.brand_id
                ORDER BY vistos DESC, dias DESC
                LIMIT " . $n;
        return array_column(Db::all($sql, [date('Y-m-d')]), 'brand_id');
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

        $r = Aisa::analyticsMarca($brandId, $inicio, $fim, $dias);
        if (!$r['ok']) {
            return ['ok' => false, 'erro' => $r['erro'], 'http' => $r['http']];
        }
        foreach ($r['json']['data'] ?? [] as $linha) {
            if (empty($linha['date'])) {
                continue;
            }
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
