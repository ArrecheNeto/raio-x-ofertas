<?php
declare(strict_types=1);
require __DIR__ . '/_app.php';

if (!admin_logado()) {
    header('Location: admin.php');
    exit;
}

$checks = [];
$add = function (string $nome, bool $ok, string $detalhe, bool $critico = true) use (&$checks) {
    $checks[] = ['nome' => $nome, 'ok' => $ok, 'detalhe' => $detalhe, 'critico' => $critico];
};

$add('Versão do PHP', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION . ' (precisa 8.0 ou mais)');
$add('Extensão cURL', extension_loaded('curl'), extension_loaded('curl') ? (curl_version()['version'] ?? 'ok') : 'Ative em cPanel > Selecionar versão do PHP > Extensões > curl');
$temSqlite = in_array('sqlite', PDO::getAvailableDrivers(), true);
$add('PDO SQLite', $temSqlite, $temSqlite ? 'disponível' : 'indisponível: use DB_DRIVER=mysql no .env', Config::str('DB_DRIVER', 'sqlite') === 'sqlite');
$add('PDO MySQL (reserva)', in_array('mysql', PDO::getAvailableDrivers(), true), 'usado só se DB_DRIVER=mysql', false);
$add('Extensão mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'ok' : 'Ative mbstring no cPanel');

$arq = DATA_DIR . '/teste_escrita.txt';
$gravou = @file_put_contents($arq, 'ok ' . date('c')) !== false;
if ($gravou) {
    @unlink($arq);
}
$add('Gravação em disco', $gravou, DATA_DIR . ($gravou ? ' (ok)' : ' sem permissão de escrita: ajuste para 755 no Gerenciador de Arquivos'));

try {
    $add('Banco de dados', true, Db::driver() . ', ' . (int)Db::val('SELECT COUNT(*) FROM anuncios') . ' anúncios salvos');
} catch (Throwable $e) {
    $add('Banco de dados', false, $e->getMessage());
}

$add('Arquivo .env', is_file(APP_DIR . '/.env'), is_file(APP_DIR . '/.env') ? 'encontrado em ' . APP_DIR : 'crie o .env a partir do .env.example');
$add('Chave da AIsa no .env', Aisa::temChave(), Aisa::temChave() ? 'preenchida (não exibida)' : 'AISA_API_KEY vazia');

$saldoTxt = 'não testado';
$saldoOk = false;
if (Aisa::temChave() && extension_loaded('curl')) {
    $r = Aisa::saldo();
    $saldoOk = $r['ok'];
    $saldoTxt = $r['ok']
        ? 'conectado. Saldo disponível: ' . Custos::usd((int)($r['json']['available_balance_micros_usd'] ?? 0), 2)
        : $r['erro'];
}
$add('Conexão com a AIsa (checagem de conta, grátis)', $saldoOk, $saldoTxt);

$publicoBloqueado = !str_starts_with(realpath(APP_DIR) ?: '', (realpath(__DIR__) ?: '') . DIRECTORY_SEPARATOR);
$add('App fora da pasta pública', $publicoBloqueado, $publicoBloqueado ? 'ok' : 'a pasta do app está dentro da pasta pública; mova para fora', false);

$verCurta = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
$cron = '/usr/local/bin/php ' . APP_DIR . '/cron/rodar.php';
$cronAlt = '/opt/cpanel/ea-php' . $verCurta . '/root/usr/bin/php ' . APP_DIR . '/cron/rodar.php';

layout_topo('Saúde | Raio-X de Ofertas', 'saude');
$okTudo = !array_filter($checks, fn($c) => !$c['ok'] && $c['critico']);
?>
<h1>Saúde do sistema</h1>
<p class="<?= $okTudo ? 'aviso' : 'alerta' ?>"><?= $okTudo ? 'Tudo certo para rodar.' : 'Há itens para corrigir antes de rodar o robô.' ?></p>
<section class="painel">
  <ul class="checks">
  <?php foreach ($checks as $c): ?>
    <li class="<?= $c['ok'] ? 'ok' : ($c['critico'] ? 'falha' : 'atencao') ?>"><b><?= e($c['nome']) ?></b><span><?= e($c['detalhe']) ?></span></li>
  <?php endforeach; ?>
  </ul>
</section>
<section class="painel">
  <h2>Comando da Tarefa Cron</h2>
  <p class="mut">No cPanel, em Tarefas Cron, use um destes (o primeiro costuma funcionar na HostGator):</p>
  <pre class="log"><?= e($cron) ?></pre>
  <pre class="log"><?= e($cronAlt) ?></pre>
  <p class="mut small">Agendamento sugerido: minuto */15, hora 6-9 (de 15 em 15 minutos, das 6h às 9h45). O cron usa o relógio do servidor; o app usa o fuso <?= e(date_default_timezone_get()) ?> (agora <?= e(date('H:i')) ?>). Se o horário do servidor for diferente, ajuste a hora do cron.</p>
</section>
<?php layout_rodape();
