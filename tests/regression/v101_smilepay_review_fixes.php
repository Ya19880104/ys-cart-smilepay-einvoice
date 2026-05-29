<?php
/**
 * Regression coverage for review fixes introduced after v1.0.1.
 *
 * These checks intentionally use source inspection because the regression
 * harness runs without a loaded WordPress/YS CART runtime.
 */

declare( strict_types=1 );

if ( PHP_SAPI !== 'cli' && ! defined( 'ABSPATH' ) ) {
	exit;
}

$root = dirname( __DIR__, 2 );
$pass = 0;
$fail = 0;

function v101_check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) {
		echo "[PASS] {$label}\n";
		$pass++;
		return;
	}
	echo "[FAIL] {$label}\n";
	$fail++;
}

function v101_read( string $relative ): string {
	global $root;
	$path = $root . '/' . ltrim( $relative, '/' );
	return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
}

$admin   = v101_read( 'src/Admin/YSSmilePayAdmin.php' );
$provider = v101_read( 'src/Providers/YSSmilePayInvoiceProvider.php' );
$manifest = v101_read( 'manifest.php' );
$template = v101_read( 'templates/admin/settings.php' );
$plugin_main = v101_read( 'ys-cart-smilepay-einvoice.php' );
$plugin_bootstrap = v101_read( 'src/YSSmilePayPlugin.php' );

v101_check(
	'Admin test connection reads YSSmilePayApiResponse via methods, not array access',
	false === strpos( $admin, "\$response['Status']" )
		&& false === strpos( $admin, "\$response['Desc']" )
		&& false !== strpos( $admin, '$response->status()' )
		&& false !== strpos( $admin, '$response->desc()' )
);

v101_check(
	'SmilePay manifest places settings under invoice admin group',
	false !== strpos( $manifest, "'admin_group'        => 'invoice'" )
);

v101_check(
	'Verify_key is encrypted on admin save and decrypted before API usage',
	false !== strpos( $admin, 'YSCrypto::encrypt_for_storage' )
		&& false !== strpos( $provider, 'YSCrypto::decrypt_from_storage' )
		&& false !== strpos( $provider, 'decrypt_verify_key' )
);

v101_check(
	'Admin test payload uses PosSystemID and no stale PosBarCode field',
	false !== strpos( $admin, "'PosSystemID'" )
		&& false === strpos( $admin, "'PosBarCode'" )
);

v101_check(
	'Manifest health check has an executable callback',
	false !== strpos( $manifest, "'callback'  => '__return_true'" )
		|| false !== strpos( $manifest, '"callback"  => "__return_true"' )
);

v101_check(
	'Plugin bootstraps before YS CART invoice registry initializes',
	1 === preg_match(
		"/add_action\\s*\\(\\s*['\"]plugins_loaded['\"].*YSSmilePayPlugin::instance\\(\\).*?,\\s*0\\s*\\)/s",
		$plugin_main
	)
);

v101_check(
	'SmilePay data_id includes pending invoice row id so reissue after void can succeed',
	false !== strpos( $provider, "'data_id'       => \$this->build_data_id( \$invoice_data )" )
		&& false !== strpos( $provider, 'function build_data_id' )
		&& false !== strpos( $provider, 'function find_current_pending_invoice_id' )
		&& false !== strpos( $provider, "YSCART-%d-%d" )
		&& false !== strpos( $provider, 'status = %s ORDER BY id DESC LIMIT 1' )
);

v101_check(
	'Provider plugin injects SmilePay print/PDF links into admin order invoice rows without core edits',
	false !== strpos( $plugin_bootstrap, "add_action( 'admin_footer', [ \$this, 'render_admin_order_invoice_print_links' ] )" )
		&& false !== strpos( $plugin_bootstrap, 'function render_admin_order_invoice_print_links' )
		&& false !== strpos( $plugin_bootstrap, 'ys-ec-invoice-admin-table' )
		&& false !== strpos( $plugin_bootstrap, 'data.provider !== \'smilepay\'' )
		&& false !== strpos( $plugin_bootstrap, 'SmilePay 列印 / PDF' )
		&& false !== strpos( $plugin_bootstrap, '/ys-ecommerce-headless/v1/account/invoices/' )
		&& false !== strpos( $plugin_bootstrap, "url.searchParams.set('token', ORDER_KEY)" )
);

v101_check(
	'SmilePay provider settings URL filter points invoice settings card to API setup page',
	false !== strpos( $plugin_bootstrap, 'ys_ec_invoice_provider_settings_url' )
		&& false !== strpos( $plugin_bootstrap, 'function filter_invoice_provider_settings_url' )
		&& false !== strpos( $plugin_bootstrap, "admin.php?page=ys-provider-smilepay" )
);

v101_check(
	'Settings UI uses PayUni-style tabs instead of legacy settings table',
	false !== strpos( $template, 'ysca-tabs--navigation' )
		&& false !== strpos( $template, 'API 設定' )
		&& false !== strpos( $template, '開立規則' )
		&& false !== strpos( $template, '載具與捐贈' )
		&& false !== strpos( $template, '測試連線' )
		&& false === strpos( $template, 'ysca-settings-table' )
);

v101_check(
	'Settings UI keeps provider enablement as a right-side CTA switch',
	false !== strpos( $template, 'ysca-settings-panel--master' )
		&& false !== strpos( $template, '啟用供應商' )
		&& false !== strpos( $template, 'ys-ec-smilepay-enabled' )
		&& false !== strpos( $template, 'ysca-switch-label--trailing' )
);

v101_check(
	'Settings UI does not expose raw English action labels',
	false === strpos( $template, 'Save PayNow settings' )
		&& false === strpos( $template, 'Enable PayNow logistics' )
		&& false === strpos( $template, 'Sandbox mode' )
		&& false === strpos( $template, 'Save SmilePay settings' )
);

echo "PASS={$pass} FAIL={$fail}\n";
exit( $fail > 0 ? 1 : 0 );
