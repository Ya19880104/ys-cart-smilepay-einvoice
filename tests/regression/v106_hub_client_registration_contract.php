<?php
/**
 * Regression: SmilePay invoice provider participates in YS Hub lifecycle.
 */

declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$main = (string) file_get_contents( $root . '/ys-cart-smilepay-einvoice.php' );

$pass = 0;
$fail = 0;

function v106_hub_check( string $label, bool $ok ): void {
	global $pass, $fail;

	if ( $ok ) {
		$pass++;
		echo "PASS {$label}\n";
		return;
	}

	$fail++;
	echo "FAIL {$label}\n";
}

v106_hub_check(
	'Runtime loads bundled Hub Client autoloader before plugin bootstrap',
	str_contains( $main, "\$ys_smilepay_vendor = YS_SMILEPAY_DIR . 'vendor/autoload.php';" )
		&& str_contains( $main, 'require_once $ys_smilepay_vendor;' )
		&& is_file( $root . '/vendor/autoload.php' )
		&& is_file( $root . '/vendor/yangsheep/ys-plugin-hub-client/ys-plugin-hub-client.php' )
);

v106_hub_check(
	'Provider registers itself with the Hub Client registry',
	str_contains( $main, 'YSPluginHubClient::register' )
		&& str_contains( $main, "'slug'        => 'ys-cart-smilepay-einvoice'" )
		&& str_contains( $main, "'version'     => YS_SMILEPAY_VERSION" )
		&& str_contains( $main, "'plugin_file' => __FILE__" )
);

echo "REGRESSION v106_hub_client_registration_contract PASS={$pass} FAIL={$fail}\n";
exit( $fail > 0 ? 1 : 0 );
