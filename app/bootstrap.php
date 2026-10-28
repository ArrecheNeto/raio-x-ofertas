<?php
declare(strict_types=1);

define('APP_DIR', __DIR__);
define('DATA_DIR', __DIR__ . '/data');

require __DIR__ . '/lib/Config.php';
require __DIR__ . '/lib/Db.php';
require __DIR__ . '/lib/Custos.php';
require __DIR__ . '/lib/Aisa.php';
require __DIR__ . '/lib/Fila.php';
require __DIR__ . '/lib/Coletor.php';
require __DIR__ . '/lib/Analytics.php';
require __DIR__ . '/lib/Escala.php';
require __DIR__ . '/lib/RaioX.php';
require __DIR__ . '/lib/Robo.php';
require __DIR__ . '/lib/Helpers.php';

Config::carregar(APP_DIR . '/.env');
date_default_timezone_set(Config::str('TIMEZONE', 'America/Sao_Paulo'));

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0750, true);
}
