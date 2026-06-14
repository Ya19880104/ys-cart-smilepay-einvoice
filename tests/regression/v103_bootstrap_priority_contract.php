<?php
/**
 * regression: ys-cart-smilepay-einvoice v1.0.3
 *
 * SmilePay must register its manifest and invoice-provider hook before YS CART
 * core initializes YSInvoiceRegistry on plugins_loaded priority 10.
 */

declare( strict_types=1 );

$root = dirname( __DIR__, 2 );
$main = (string) file_get_contents( $root . '/ys-cart-smilepay-einvoice.php' );

$pass = 0;
$fail = 0;

function v103_boot_check( string $label, bool $ok ): void {
	global $pass, $fail;

	if ( $ok ) {
		$pass++;
		echo "PASS {$label}\n";
		return;
	}

	$fail++;
	echo "FAIL {$label}\n";
}

v103_boot_check(
	'Plugin version is bumped for early bootstrap fix (>= 1.0.3)',
	(bool) preg_match( '/Version:\s*([0-9.]+)/', $main, $hm )
		&& version_compare( $hm[1], '1.0.3', '>=' )
		&& (bool) preg_match( "/define\(\s*'YS_SMILEPAY_VERSION',\s*'([0-9.]+)'\s*\)/", $main, $cm )
		&& version_compare( $cm[1], '1.0.3', '>=' )
);

v103_boot_check(
	'Bootstrap runs before YS CART core plugins_loaded priority 10',
	(bool) preg_match( "/add_action\\(\\s*'plugins_loaded'[\\s\\S]*?,\\s*0\\s*\\);/m", $main )
);

v103_boot_check(
	'Comment documents manifest and invoice registry hook ordering',
	str_contains( $main, 'manifest' )
		&& str_contains( $main, 'invoice registry hooks' )
		&& str_contains( $main, 'priority 10' )
);

echo "REGRESSION v103_bootstrap_priority_contract PASS={$pass} FAIL={$fail}\n";
exit( $fail > 0 ? 1 : 0 );
