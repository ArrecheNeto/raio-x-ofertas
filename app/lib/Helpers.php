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

/** Sem senha de painel definida, o admin.php mostra a primeira configuração no lugar do login. */
function precisa_instalar(): bool
{
    $senha = Config::str('ADMIN_PASSWORD');
    return $senha === '' || $senha === 'troque-esta-senha';
}

/** Confere uma chave na rota gratuita de saldo. Retorna mensagem de erro ou null. */
function testar_chave(string $chave): ?string
{
    if (!preg_match('/^[\x21-\x7E]{16,200}$/', $chave)) {
        return 'A chave parece incompleta. Copie de novo no console da AIsa.';
    }
    $r = Aisa::saldo($chave);
    if ($r['ok']) {
        return null;
    }
    return $r['http'] === 401 ? 'A AIsa recusou esta chave (401). Confira se copiou inteira.' : (string)$r['erro'];
}

/** Só os 4 últimos caracteres, para reconhecer qual chave está salva sem expor. */
function chave_mascarada(): string
{
    $k = Config::str('AISA_API_KEY');
    return $k === '' ? 'nenhuma chave salva' : 'salva, termina em ' . substr($k, -4);
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
    if ($bloqueio = login_bloqueado()) {
        return $bloqueio;
    }
    if (hash_equals($senha, (string)($_POST['senha'] ?? ''))) {
        Db::q('DELETE FROM config WHERE chave = ?', ['login_ip_' . sha1(ip_cliente())]);
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        return null;
    }
    registrar_falha_login();
    sleep(1);
    return login_bloqueado() ?? 'Senha incorreta.';
}

function ip_cliente(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

// Tentativas erradas: 5 do mesmo IP ou 30 no total em 15 minutos bloqueiam o login por 15 minutos.
const LOGIN_JANELA = 900;
const LOGIN_MAX_IP = 5;
const LOGIN_MAX_GERAL = 30;

/** Mensagem de bloqueio, ou null se pode tentar. */
function login_bloqueado(): ?string
{
    foreach (['login_ip_' . sha1(ip_cliente()), 'login_geral'] as $k) {
        $d = json_decode((string)Db::getConfig($k), true) ?: [];
        if (($d['ate'] ?? 0) > time()) {
            return 'Muitas tentativas erradas. O login está bloqueado por ' . (int)ceil(($d['ate'] - time()) / 60) . ' min.';
        }
    }
    return null;
}

function registrar_falha_login(): void
{
    foreach (['login_ip_' . sha1(ip_cliente()) => LOGIN_MAX_IP, 'login_geral' => LOGIN_MAX_GERAL] as $k => $max) {
        $d = json_decode((string)Db::getConfig($k), true) ?: [];
        if (($d['desde'] ?? 0) < time() - LOGIN_JANELA) {
            $d = ['n' => 0, 'desde' => time()];
        }
        $d['n']++;
        if ($d['n'] >= $max) {
            $d = ['n' => 0, 'desde' => time(), 'ate' => time() + LOGIN_JANELA];
        }
        Db::setConfig($k, json_encode($d));
    }
}

/** Com SITE_PRIVADO=1, as páginas de ofertas só abrem para quem está logado. */
function exigir_acesso(): void
{
    if (Config::int('SITE_PRIVADO', 0) === 1 && !admin_logado()) {
        header('Location: admin.php');
        exit;
    }
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

/** Icones de linha da barra lateral (SVG inline, sem dependencia). */
function icone(string $nome): string
{
    $d = [
        'ofertas' => '<path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/>',
        'concorrentes' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="4"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>',
        'admin' => '<path d="M4 6h16M4 12h10M4 18h16"/><circle cx="17" cy="12" r="2"/>',
        'saude' => '<path d="M3 12h4l3-7 4 14 3-7h4"/>',
        'entrar' => '<path d="M10 17l5-5-5-5M15 12H3M14 4h5a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-5"/>',
    ][$nome] ?? '';
    return '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true">' . $d . '</svg>';
}

function layout_topo(string $titulo, string $ativo = 'ofertas'): void
{
    $admin = admin_logado();
    try {
        $navNichos = Db::all('SELECT id, nome FROM nichos WHERE ativo = 1 ORDER BY nome');
        $ultima = Db::getConfig('ultima_atualizacao') ?? Db::val('SELECT MAX(fim) FROM execucoes WHERE jobs > 0');
    } catch (Throwable $e) {
        $navNichos = [];
        $ultima = null;
    }
    $nichoAtual = $ativo === 'ofertas' ? (int)($_GET['nicho'] ?? 0) : -1;
    $item = fn(string $chave, string $href, string $rotulo) => '<a href="' . $href . '" title="' . e($rotulo) . '" aria-label="' . e($rotulo) . '" class="nav-item' . ($ativo === $chave ? ' on' : '') . '">' . icone($chave) . '<span>' . e($rotulo) . '</span></a>';
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?></title>
<meta name="robots" content="index,follow">
<meta name="theme-color" content="#0e0f11">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=6">
</head>
<body>
<div class="app">
<aside class="lateral">
  <a class="marca" href="index.php"><span class="marca-ic" aria-hidden="true">RX</span><span class="marca-txt">Raio-X<small>de Ofertas</small></span></a>
  <nav class="nav" aria-label="Menu principal">
    <p class="nav-titulo">Explorar</p>
    <?= $item('ofertas', 'index.php', 'Ofertas') ?>
    <?php if ($navNichos): ?>
    <div class="nav-sub">
      <?php foreach ($navNichos as $n): ?>
        <a href="index.php?nicho=<?= (int)$n['id'] ?>" class="<?= $nichoAtual === (int)$n['id'] ? 'on' : '' ?>"><?= e(mb_convert_case($n['nome'], MB_CASE_TITLE)) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($admin): ?>
      <p class="nav-titulo">Gestão</p>
      <?= $item('concorrentes', 'concorrentes.php', 'Concorrentes') ?>
      <?= $item('admin', 'admin.php', 'Painel') ?>
      <?= $item('saude', 'saude.php', 'Saúde') ?>
    <?php elseif (Config::int('ESCONDER_ENTRAR', 0) !== 1): ?>
      <?= str_replace('nav-item', 'nav-item nav-entrar', $item('entrar', 'admin.php', 'Entrar')) ?>
    <?php endif; ?>
  </nav>
  <div class="lateral-pe">
    <span>Atualizado</span>
    <b><?= $ultima ? e(data_br($ultima, true)) : 'ainda não' ?></b>
  </div>
</aside>
<div class="conteudo">
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
</div>
</div>
<script src="assets/app.js?v=5"></script>
</body>
</html>
<?php
}
