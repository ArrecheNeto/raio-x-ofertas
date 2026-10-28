<?php
declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** So aceita links http(s), para nao abrir javascript: vindo de dados externos. */
function url_segura(?string $u): string
{
    $u = trim((string)$u);
    return preg_match('#^https?://#i', $u) ? $u : '';
}

function data_br(?string $d, bool $hora = false): string
{
    if (!$d) {
        return '';
    }
    $t = strtotime($d);
    return $t ? date($hora ? 'd/m/Y \à\s H:i' : 'd/m/Y', $t) : '';
}

function pct(?float $v): string
{
    if ($v === null) {
        return 'sem dado';
    }
    return ($v > 0 ? '+' : '') . number_format($v * 100, 0, ',', '.') . '%';
}

function iniciar_sessao(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('raiox_adm');
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_start();
    }
}

function admin_logado(): bool
{
    iniciar_sessao();
    return !empty($_SESSION['admin']);
}

function csrf(): string
{
    iniciar_sessao();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_ok(): bool
{
    iniciar_sessao();
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

/** Tenta logar. Retorna mensagem de erro ou null. */
function tentar_login(): ?string
{
    iniciar_sessao();
    $senha = Config::str('ADMIN_PASSWORD');
    if ($senha === '' || $senha === 'troque-esta-senha') {
        return 'Defina ADMIN_PASSWORD no arquivo .env antes de entrar.';
    }
    if (!csrf_ok()) {
        return 'Sessão expirada. Tente de novo.';
    }
    if (hash_equals($senha, (string)($_POST['senha'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        return null;
    }
    sleep(1);
    return 'Senha incorreta.';
}

/** Mini grafico SVG da curva. */
function sparkline(array $pontos, string $status, int $w = 120, int $h = 34): string
{
    $vals = array_values(array_map('intval', $pontos));
    if (count($vals) < 2) {
        return '<div class="spark-vazio">coletando</div>';
    }
    $vals = array_slice($vals, -14);
    $min = min($vals);
    $max = max($vals);
    $amp = max($max - $min, 1);
    $n = count($vals);
    $xy = [];
    foreach ($vals as $i => $v) {
        $x = round($i * ($w - 4) / ($n - 1) + 2, 1);
        $y = round($h - 3 - ($v - $min) * ($h - 6) / $amp, 1);
        $xy[] = "$x,$y";
    }
    $linha = implode(' ', $xy);
    $area = "2,$h $linha " . ($w - 2) . ",$h";
    return '<svg class="spark spark-' . e($status) . '" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
        . '<polygon points="' . $area . '" class="spark-area"/><polyline points="' . $linha . '" class="spark-linha"/></svg>';
}

function selo(string $status): string
{
    $rot = Escala::ROTULOS[$status] ?? 'Coletando histórico';
    return '<span class="selo selo-' . e($status ?: 'coletando') . '">' . e($rot) . '</span>';
}

function layout_topo(string $titulo, string $ativo = 'ofertas'): void
{
    $admin = admin_logado();
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?></title>
<meta name="robots" content="index,follow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=3">
</head>
<body>
<header class="topo">
  <div class="wrap topo-in">
    <a class="marca" href="index.php"><span class="marca-ic" aria-hidden="true"></span>Raio-X <b>de Ofertas</b></a>
    <nav class="menu">
      <a href="index.php" class="<?= $ativo === 'ofertas' ? 'on' : '' ?>">Ofertas</a>
      <?php if ($admin): ?>
        <a href="concorrentes.php" class="<?= $ativo === 'concorrentes' ? 'on' : '' ?>">Concorrentes</a>
        <a href="admin.php" class="<?= $ativo === 'admin' ? 'on' : '' ?>">Painel</a>
        <a href="saude.php" class="<?= $ativo === 'saude' ? 'on' : '' ?>">Saúde</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="wrap">
<?php
}

function layout_rodape(): void
{
    ?>
</main>
<footer class="rodape wrap">
  <p>Dias no ar e número de anúncios são sinais de escala, não comprovação de vendas.</p>
  <p class="mut">Dados de anúncios públicos da Biblioteca de Anúncios da Meta, via AIsa. Use para estudar e criar a sua própria versão, nunca para copiar.</p>
</footer>
<script src="assets/app.js?v=3"></script>
</body>
</html>
<?php
}
