<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sdk = (string) file_get_contents($root . '/sdk/ys-cart-smilepay-einvoice-headless.js');
$skill = (string) file_get_contents($root . '/skills/ys-cart-smilepay-einvoice-headless.md');

$checks = [
    'SDK exports provider id' => str_contains($sdk, "YS_CART_SMILEPAY_INVOICE_PROVIDER_ID = 'smilepay'"),
    'SDK supports personal and company payload helpers' => str_contains($sdk, 'withSmilePayInvoice') && str_contains($sdk, 'withSmilePayCompanyInvoice'),
    'Skill states invoice-only boundary' => str_contains($skill, 'invoice-only') && str_contains($skill, 'must not register payment gateways or shipping methods'),
    'Skill forbids frontend credentials' => str_contains($skill, 'Do not send `verify_key`, `grvc`') && str_contains($skill, 'Do not call SmilePay API endpoints directly'),
];

$pass = 0;
$fail = 0;
foreach ($checks as $label => $ok) {
    if ($ok) {
        echo "[PASS] {$label}\n";
        $pass++;
    } else {
        echo "[FAIL] {$label}\n";
        $fail++;
    }
}

echo "v104_sdk_skill_contract PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
