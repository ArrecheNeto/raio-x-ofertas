<?php
declare(strict_types=1);

/** Registro do custo de cada chamada e controle do teto diario. Valores em micro-USD (1000000 = US$ 1). */
final class Custos
{
    public static function registrar(string $tipo, string $endpoint, int $micros, string $fonte, ?int $http, ?int $itens, ?string $ref): void
    {
        Db::q(
            'INSERT INTO custos (criado_em, data, tipo, endpoint, micros, fonte, http, itens, ref) VALUES (?,?,?,?,?,?,?,?,?)',
            [date('Y-m-d H:i:s'), date('Y-m-d'), $tipo, $endpoint, $micros, $fonte, $http, $itens, $ref]
        );
    }

    public static function gastoDia(?string $data = null): int
    {
        return (int)Db::val('SELECT COALESCE(SUM(micros),0) FROM custos WHERE data = ?', [$data ?? date('Y-m-d')]);
    }

    public static function gastoMes(?string $mes = null): int
    {
        $mes ??= date('Y-m');
        return (int)Db::val('SELECT COALESCE(SUM(micros),0) FROM custos WHERE data >= ? AND data <= ?', [$mes . '-01', $mes . '-31']);
    }

    public static function tetoMicros(): int
    {
        return (int)round(Config::float('DAILY_BUDGET_USD', 2.0) * 1_000_000);
    }

    /** true se ainda cabe uma chamada com esta estimativa dentro do teto de hoje. */
    public static function cabe(int $estimativaMicros): bool
    {
        return self::gastoDia() + $estimativaMicros <= self::tetoMicros();
    }

    public static function usd(int $micros, int $casas = 3): string
    {
        return 'US$ ' . number_format($micros / 1_000_000, $casas, ',', '.');
    }
}
