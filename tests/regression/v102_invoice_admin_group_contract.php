<?php
declare(strict_types=1);

$root     = dirname(__DIR__, 2);
$manifest = (string) file_get_contents($root . '/manifest.php');
$main     = (string) file_get_contents($root . '/ys-cart-smilepay-einvoice.php');
$pass     = 0;
$fail     = 0;

function v102_invoice_check(string $label, bool $ok): void {
    global $pass, $fail;
    if ($ok) {
        echo "[PASS] {$label}\n";
        $pass++;
        return;
    }

    echo "[FAIL] {$label}\n";
    $fail++;
}

v102_invoice_check(
    'SmilePay invoice provider is grouped under the invoice provider admin surface',
    (bool) preg_match("/'admin_group'\\s*=>\\s*'invoice'/", $manifest)
        && str_contains($manifest, "'domains'            => [ 'invoice' ]")
);

v102_invoice_check(
    'SmilePay invoice provider version bumped for invoice grouping fix',
    preg_match('/Version:\s*([0-9.]+)/', $main, $versionMatch)
        && preg_match("/YS_SMILEPAY_VERSION', '([0-9.]+)'/", $main, $constantMatch)
        && version_compare((string) ($versionMatch[1] ?? ''), '1.0.2', '>=')
        && version_compare((string) ($constantMatch[1] ?? ''), '1.0.2', '>=')
);

echo "REGRESSION v102_invoice_admin_group_contract PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
