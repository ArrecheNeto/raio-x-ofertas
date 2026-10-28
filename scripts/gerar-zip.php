<?php
declare(strict_types=1);

// Gera dist/raio-x-ofertas-hostgator.zip pronto para o cPanel:
//   public_html/  -> conteudo publico (index.php, oferta.php, admin.php, saude.php, assets)
//   raiox_app/    -> codigo, cron e banco (fica FORA da pasta publica)
// Nunca inclui o .env nem o banco local.
// Uso: php scripts/gerar-zip.php

$raiz = dirname(__DIR__);
$dist = $raiz . '/dist';
@mkdir($dist, 0755, true);
$saida = $dist . '/raio-x-ofertas-hostgator.zip';
@unlink($saida);

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "A extensao zip do PHP nao esta ativa.\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($saida, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Nao consegui criar $saida\n");
    exit(1);
}

$ignorar = function (string $rel): bool {
    $rel = str_replace('\\', '/', $rel);
    if (preg_match('#(^|/)\.env$#', $rel)) {
        return true;
    }
    if (str_starts_with($rel, 'data/') && $rel !== 'data/.gitkeep') {
        return true;
    }
    return (bool)preg_match('#(\.sqlite.*|\.log|\.lock)$#', $rel);
};

$adicionar = function (string $origem, string $destino) use ($zip, $ignorar): int {
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($origem, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $arq) {
        $rel = substr($arq->getPathname(), strlen($origem) + 1);
        if ($arq->isDir() || $ignorar($rel)) {
            continue;
        }
        $nome = $destino . '/' . str_replace('\\', '/', $rel);
        $zip->addFile($arq->getPathname(), $nome);
        // Sem isto, o zip feito no Windows extrai como 0666 e a HostGator recusa rodar o PHP.
        $zip->setExternalAttributesName($nome, ZipArchive::OPSYS_UNIX, 0100644 << 16);
        $n++;
    }
    return $n;
};

$n = $adicionar($raiz . '/public', 'public_html');
$n += $adicionar($raiz . '/app', 'raiox_app');
$zip->addFromString('raiox_app/data/.gitkeep', '');
$zip->addFile($raiz . '/README.md', 'LEIA-ME.md');
$zip->close();

printf("Pronto: %s (%d arquivos, %s KB)\n", $saida, $n + 1, number_format(filesize($saida) / 1024, 1, ',', '.'));
