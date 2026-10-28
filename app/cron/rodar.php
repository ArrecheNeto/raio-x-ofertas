<?php
declare(strict_types=1);

// Tarefa Cron: php /home/USUARIO/raiox_app/cron/rodar.php
// Opcoes (para testes): --tempo=300 --coletas=5
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Somente pela linha de comando.');
}
require dirname(__DIR__) . '/bootstrap.php';

$opts = getopt('', ['tempo::', 'coletas::']);
$r = Robo::rodar('cron', isset($opts['tempo']) ? (int)$opts['tempo'] : null, isset($opts['coletas']) ? (int)$opts['coletas'] : null);

echo '[' . date('Y-m-d H:i:s') . '] ' . ($r['msg'] ?? ('tarefas=' . $r['jobs'] . ' tempo=' . $r['segundos'] . 's')) . PHP_EOL;
foreach ($r['log'] ?? [] as $l) {
    echo '  ' . $l . PHP_EOL;
}
echo '  gasto hoje: ' . Custos::usd(Custos::gastoDia(), 4) . ' | mes: ' . Custos::usd(Custos::gastoMes(), 4) . PHP_EOL;
