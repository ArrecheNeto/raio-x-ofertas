<?php
declare(strict_types=1);

/** Le o .env (formato CHAVE=valor) sem depender de Composer. */
final class Config
{
    private static array $vals = [];

    public static function carregar(string $arquivo): void
    {
        if (!is_file($arquivo)) {
            return;
        }
        foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
            $linha = trim($linha);
            if ($linha === '' || $linha[0] === '#' || !str_contains($linha, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $linha, 2);
            $v = trim($v);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                $v = substr($v, 1, -1);
            }
            self::$vals[trim($k)] = $v;
        }
    }

    /**
     * Grava chaves no .env (cria a partir do .env.example se faltar), mantendo comentarios e a ordem.
     * Retorna mensagem de erro ou null.
     */
    public static function salvar(string $arquivo, array $novos): ?string
    {
        $base = is_file($arquivo) ? $arquivo : dirname($arquivo) . '/.env.example';
        $linhas = is_file($base) ? file($base, FILE_IGNORE_NEW_LINES) : [];
        foreach ($novos as $k => $v) {
            $v = str_replace(["\r", "\n"], '', trim((string)$v));
            $achou = false;
            foreach ($linhas as $i => $l) {
                if (preg_match('/^\s*' . preg_quote($k, '/') . '\s*=/', $l)) {
                    $linhas[$i] = "$k=$v";
                    $achou = true;
                    break;
                }
            }
            if (!$achou) {
                $linhas[] = "$k=$v";
            }
            self::$vals[$k] = $v;
        }
        $tmp = $arquivo . '.tmp';
        if (@file_put_contents($tmp, implode("\n", $linhas) . "\n", LOCK_EX) === false || !@rename($tmp, $arquivo)) {
            @unlink($tmp);
            return 'Não consegui gravar o arquivo .env em ' . dirname($arquivo) . '. Ajuste a permissão da pasta para 755.';
        }
        @chmod($arquivo, 0600);
        return null;
    }

    public static function str(string $k, string $padrao = ''): string
    {
        // Variavel de ambiente vence o .env (util para testes pontuais).
        $env = getenv($k);
        $v = ($env !== false && $env !== '') ? $env : (self::$vals[$k] ?? null);
        return ($v === false || $v === null || $v === '') ? $padrao : (string)$v;
    }

    public static function int(string $k, int $padrao): int
    {
        $v = self::str($k);
        return is_numeric($v) ? (int)$v : $padrao;
    }

    public static function float(string $k, float $padrao): float
    {
        $v = self::str($k);
        return is_numeric($v) ? (float)$v : $padrao;
    }
}
