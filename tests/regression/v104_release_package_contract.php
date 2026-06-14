<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$artifacts = glob($root . '/artifacts/ys-cart-smilepay-einvoice-*.zip') ?: [];

if (!$artifacts) {
    echo "v104_release_package_contract skipped: no release zip built yet\n";
    exit(0);
}

rsort($artifacts);
$zipPath = $artifacts[0];

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive extension is required to inspect {$zipPath}\n");
    exit(1);
}

$zip = new ZipArchive();
if (true !== $zip->open($zipPath)) {
    fwrite(STDERR, "Unable to open release zip: {$zipPath}\n");
    exit(1);
}

$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $names[] = (string) $zip->getNameIndex($i);
}
$zip->close();

$mustHave = [
    'ys-cart-smilepay-einvoice/ys-cart-smilepay-einvoice.php',
    'ys-cart-smilepay-einvoice/manifest.php',
    'ys-cart-smilepay-einvoice/README.md',
    'ys-cart-smilepay-einvoice/LICENSE',
    'ys-cart-smilepay-einvoice/sdk/ys-cart-smilepay-einvoice-headless.js',
    'ys-cart-smilepay-einvoice/skills/ys-cart-smilepay-einvoice-headless.md',
    'ys-cart-smilepay-einvoice/templates/admin/settings.php',
    'ys-cart-smilepay-einvoice/languages/ys-cart-smilepay-einvoice-zh_TW.po',
    'ys-cart-smilepay-einvoice/vendor/autoload.php',
    'ys-cart-smilepay-einvoice/vendor/yangsheep/ys-plugin-hub-client/ys-plugin-hub-client.php',
];

foreach ($mustHave as $entry) {
    if (!in_array($entry, $names, true)) {
        fwrite(STDERR, "Release zip missing required entry: {$entry}\n");
        exit(1);
    }
}

$forbiddenPatterns = [
    '#^ys-cart-smilepay-einvoice/\\.git/#',
    '#^ys-cart-smilepay-einvoice/\\.github/#',
    '#^ys-cart-smilepay-einvoice/artifacts/#',
    '#^ys-cart-smilepay-einvoice/bin/#',
    '#^ys-cart-smilepay-einvoice/outputs/#',
    '#^ys-cart-smilepay-einvoice/tests/#',
    '#^ys-cart-smilepay-einvoice/tmp/#',
    '#^ys-cart-smilepay-einvoice/node_modules/#',
    '#^ys-cart-smilepay-einvoice/\\.env(\\..*)?$#',
    '#\\.log$#',
    '#\\.tmp$#',
    '#^ys-cart-smilepay-einvoice/composer\\.(json|lock)$#',
];

foreach ($names as $entry) {
    foreach ($forbiddenPatterns as $pattern) {
        if (preg_match($pattern, $entry)) {
            fwrite(STDERR, "Release zip includes forbidden entry: {$entry}\n");
            exit(1);
        }
    }
}

echo "v104_release_package_contract passed\n";
