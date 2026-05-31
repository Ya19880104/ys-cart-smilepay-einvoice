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
	'Plugin version is bumped for early bootstrap fix',
	str_contains( $main, 'Version: 1.0.3' )
		&& str_contains( $main, "define( 'YS_SMILEPAY_VERSION', '1.0.3' );" )
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
