<?php
declare(strict_types=1);

/** Fila simples no banco. Cada execucao do cron pega poucos itens e termina rapido. */
final class Fila
{
    public const PRIORIDADE = ['coleta' => 1, 'concorrente_busca' => 1, 'concorrente' => 1, 'analytics' => 2, 'raiox' => 3];
    public const ORDEM_SQL = "CASE tipo WHEN 'coleta' THEN 1 WHEN 'concorrente_busca' THEN 1 WHEN 'concorrente' THEN 1 WHEN 'analytics' THEN 2 ELSE 3 END";

    public static function adicionar(string $tipo, string $ref, ?string $data = null): void
    {
        $agora = date('Y-m-d H:i:s');
        Db::upsert('fila', [
            'tipo' => $tipo, 'ref' => $ref, 'data' => $data ?? date('Y-m-d'),
            'status' => 'pendente', 'tentativas' => 0, 'criado_em' => $agora, 'atualizado_em' => $agora,
        ], ['tipo', 'ref', 'data'], []);
    }

    public static function proximo(?string $tipo = null): ?array
    {
        $sql = "SELECT * FROM fila WHERE status = 'pendente' AND data = ?";
        $p = [date('Y-m-d')];
        if ($tipo) {
            $sql .= ' AND tipo = ?';
            $p[] = $tipo;
        }
        $sql .= ' ORDER BY ' . self::ORDEM_SQL . ', id LIMIT 1';
        return Db::one($sql, $p);
    }

    public static function marcar(int $id, string $status, ?string $erro = null): void
    {
        Db::q(
            'UPDATE fila SET status = ?, erro = ?, tentativas = tentativas + ?, atualizado_em = ? WHERE id = ?',
            [$status, $erro, $status === 'rodando' ? 1 : 0, date('Y-m-d H:i:s'), $id]
        );
    }

    public static function pendentes(string $tipo): int
    {
        return (int)Db::val("SELECT COUNT(*) FROM fila WHERE status IN ('pendente','rodando') AND tipo = ? AND data = ?", [$tipo, date('Y-m-d')]);
    }

    /** Itens que ficaram "rodando" por mais de 10 min (execucao morta) viram erro, sem repetir sozinhos. */
    public static function limparTravados(): void
    {
        Db::q(
            "UPDATE fila SET status = 'erro', erro = 'Execucao interrompida (limite de tempo do servidor). Use Tentar de novo no painel.' WHERE status = 'rodando' AND atualizado_em < ?",
            [date('Y-m-d H:i:s', time() - 600)]
        );
    }

    public static function reabrir(int $id): void
    {
        Db::q("UPDATE fila SET status = 'pendente', erro = NULL, data = ?, atualizado_em = ? WHERE id = ?", [date('Y-m-d'), date('Y-m-d H:i:s'), $id]);
    }
}
