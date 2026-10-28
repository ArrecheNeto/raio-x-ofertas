<?php
// Encontra a pasta do app (fora da pasta publica). Na HostGator: /home/USUARIO/raiox_app
foreach ([__DIR__ . '/../raiox_app', __DIR__ . '/../app', dirname(__DIR__, 2) . '/raiox_app'] as $dir) {
    if (is_file($dir . '/bootstrap.php')) {
        require $dir . '/bootstrap.php';
        return;
    }
}
http_response_code(500);
exit('Pasta raiox_app nao encontrada. Veja o README, passo "Subir os arquivos".');
