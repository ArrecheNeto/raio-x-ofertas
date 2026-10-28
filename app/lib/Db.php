<?php
declare(strict_types=1);

/** PDO com SQLite (padrao) ou MySQL do cPanel. */
final class Db
{
    private static ?PDO $pdo = null;
    private static string $driver = 'sqlite';

    public static function pdo(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        self::$driver = strtolower(Config::str('DB_DRIVER', 'sqlite'));
        if (self::$driver === 'sqlite' && !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('PDO SQLite nao esta disponivel neste servidor. Use DB_DRIVER=mysql no .env.');
        }
        if (self::$driver === 'mysql') {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', Config::str('DB_HOST', 'localhost'), Config::str('DB_NAME'));
            $pdo = new PDO($dsn, Config::str('DB_USER'), Config::str('DB_PASS'));
        } else {
            $pdo = new PDO('sqlite:' . DATA_DIR . '/raiox.sqlite');
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec('PRAGMA busy_timeout=5000');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$pdo = $pdo;
        self::migrar();
        return $pdo;
    }

    public static function driver(): string
    {
        self::pdo();
        return self::$driver;
    }

    private static function migrar(): void
    {
        $sql = file_get_contents(APP_DIR . '/sql/schema.sql');
        $sql = preg_replace('/^--.*$/m', '', $sql);
        $troca = self::$driver === 'mysql'
            ? ['{ID}' => 'INTEGER PRIMARY KEY AUTO_INCREMENT', '{K}' => 'VARCHAR(191)', '{TXT}' => 'MEDIUMTEXT', '{IFNE}' => '']
            : ['{ID}' => 'INTEGER PRIMARY KEY AUTOINCREMENT', '{K}' => 'TEXT', '{TXT}' => 'TEXT', '{IFNE}' => 'IF NOT EXISTS'];
        foreach (array_filter(array_map('trim', explode(';', strtr($sql, $troca)))) as $stmt) {
            try {
                self::$pdo->exec($stmt);
            } catch (PDOException $e) {
                // No MySQL o indice ja existente gera erro 1061; pode ignorar.
                if (!str_contains($e->getMessage(), 'Duplicate key name')) {
                    throw $e;
                }
            }
        }
        // Bancos criados antes de uma coluna existir: CREATE TABLE IF NOT EXISTS nao adiciona colunas.
        foreach (self::COLUNAS_NOVAS as $tabela => $colunas) {
            $existentes = self::$driver === 'mysql'
                ? array_column(self::$pdo->query("SHOW COLUMNS FROM $tabela")->fetchAll(), 'Field')
                : array_column(self::$pdo->query("PRAGMA table_info($tabela)")->fetchAll(), 'name');
            foreach ($colunas as $col => $tipo) {
                if (!in_array($col, $existentes, true)) {
                    self::$pdo->exec("ALTER TABLE $tabela ADD COLUMN $col " . strtr($tipo, $troca));
                }
            }
        }
    }

    private const COLUNAS_NOVAS = [
        'concorrentes' => [
            'ad_library_id' => '{K}', 'candidatos' => '{TXT}', 'ativo' => 'INTEGER DEFAULT 1',
            'status' => '{K}', 'erro' => '{TXT}', 'ultima_coleta' => '{K}',
        ],
    ];

    public static function q(string $sql, array $p = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st;
    }

    public static function all(string $sql, array $p = []): array
    {
        return self::q($sql, $p)->fetchAll();
    }

    public static function one(string $sql, array $p = []): ?array
    {
        $r = self::q($sql, $p)->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $p = [])
    {
        $v = self::q($sql, $p)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** INSERT ou UPDATE pela chave unica, nos dois bancos. */
    public static function upsert(string $tabela, array $dados, array $chaves, ?array $atualizar = null): void
    {
        $cols = array_keys($dados);
        $atualizar ??= array_values(array_diff($cols, $chaves));
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $sql = "INSERT INTO $tabela (" . implode(',', $cols) . ") VALUES ($ph)";
        if (self::driver() === 'mysql') {
            $sql .= $atualizar
                ? ' ON DUPLICATE KEY UPDATE ' . implode(',', array_map(fn($c) => "$c=VALUES($c)", $atualizar))
                : ' ON DUPLICATE KEY UPDATE ' . $chaves[0] . '=' . $chaves[0];
        } else {
            $sql .= ' ON CONFLICT(' . implode(',', $chaves) . ') DO '
                . ($atualizar ? 'UPDATE SET ' . implode(',', array_map(fn($c) => "$c=excluded.$c", $atualizar)) : 'NOTHING');
        }
        self::q($sql, array_values($dados));
    }

    public static function getConfig(string $k, ?string $padrao = null): ?string
    {
        $v = self::val('SELECT valor FROM config WHERE chave = ?', [$k]);
        return $v === null ? $padrao : (string)$v;
    }

    public static function setConfig(string $k, string $v): void
    {
        self::upsert('config', ['chave' => $k, 'valor' => $v], ['chave']);
    }

    public static function erro(string $contexto, string $msg): void
    {
        self::q('INSERT INTO erros (criado_em, contexto, mensagem) VALUES (?,?,?)', [date('Y-m-d H:i:s'), $contexto, mb_substr($msg, 0, 2000)]);
    }
}
