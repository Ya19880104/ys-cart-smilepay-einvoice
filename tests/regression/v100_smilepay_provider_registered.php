<?php
/**
 * regression: ys-cart-smilepay-einvoice v1.0.0
 *
 * Assertion 目的：確認 SmilePay 走 manifest-first provider lifecycle，
 * 並且 Invoice Provider class 完整實作 YSInvoiceProviderInterface。
 *
 * 雙路徑設計：
 *   - WP loaded（preferred）：實際 do_action + YSInvoiceRegistry::get('smilepay') 取 instance。
 *   - CLI fallback：source-grep 驗 hook、class、interface implements、carrier_types 宣告。
 *
 * 注意：本 test 不假設 ys-cart 已 active；所有 class_exists() 失敗自動 fall back 到 grep。
 *
 * @package YangSheep\SmilePayEInvoice\Tests\Regression
 */

declare( strict_types = 1 );

if ( PHP_SAPI !== 'cli' && ! defined( 'ABSPATH' ) ) {
	exit;
}

$root = dirname( __DIR__, 2 );
$pass = 0;
$fail = 0;

if ( ! defined( 'YS_SMILEPAY_PATH' ) ) {
	define( 'YS_SMILEPAY_PATH', $root . '/' );
}

function v100_pr_check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) {
		echo "[PASS] {$label}\n";
		$pass++;
		return;
	}
	echo "[FAIL] {$label}\n";
	$fail++;
}

function v100_pr_read( string $relative ): string {
	$path = YS_SMILEPAY_PATH . ltrim( $relative, '/' );
	return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
}

$provider_loaded = class_exists( '\\YangSheep\\SmilePayEInvoice\\Providers\\YSSmilePayInvoiceProvider' );
$registry_loaded = class_exists( '\\YangSheep\\Ecommerce\\Invoice\\YSInvoiceRegistry' );

if ( $provider_loaded && $registry_loaded ) {
	// --- Runtime path：實際透過 do_action 註冊 → registry 取出 ---
	\YangSheep\Ecommerce\Invoice\YSInvoiceRegistry::reset();
	do_action( 'ys_ec_register_invoice_providers' );

	$provider = \YangSheep\Ecommerce\Invoice\YSInvoiceRegistry::get( 'smilepay' );

	v100_pr_check(
		'YSInvoiceRegistry::get("smilepay") returns non-null instance',
		null !== $provider
	);

	v100_pr_check(
		'Registered provider is YSSmilePayInvoiceProvider class',
		is_object( $provider )
			&& get_class( $provider ) === 'YangSheep\\SmilePayEInvoice\\Providers\\YSSmilePayInvoiceProvider'
	);

	v100_pr_check(
		'Provider implements YSInvoiceProviderInterface contract',
		is_object( $provider )
			&& $provider instanceof \YangSheep\Ecommerce\Invoice\YSInvoiceProviderInterface
	);

	v100_pr_check(
		'Provider get_id() returns "smilepay"',
		is_object( $provider )
			&& method_exists( $provider, 'get_id' )
			&& 'smilepay' === $provider->get_id()
	);

	if ( is_object( $provider ) && method_exists( $provider, 'get_carrier_types' ) ) {
		$carrier_types = (array) $provider->get_carrier_types();
		v100_pr_check(
			'get_carrier_types() declares mobile / cdc / member / donate',
			isset( $carrier_types['mobile'] )
				&& isset( $carrier_types['cdc'] )
				&& isset( $carrier_types['member'] )
				&& isset( $carrier_types['donate'] )
		);
	} else {
		v100_pr_check( 'get_carrier_types() declares mobile / cdc / member / donate', false );
	}
} else {
	// --- CLI fallback：source-grep 確認預期結構存在 ---
	$plugin_main  = v100_pr_read( 'ys-cart-smilepay-einvoice.php' );
	$bootstrap    = v100_pr_read( 'src/YSSmilePayPlugin.php' );
	$provider_src = v100_pr_read( 'src/Providers/YSSmilePayInvoiceProvider.php' );
	$manifest     = v100_pr_read( 'manifest.php' );

	v100_pr_check(
		'Bootstrap registers provider manifest',
		false !== strpos( $bootstrap, "ys_ec_provider_manifests" )
			&& false !== strpos( $bootstrap, 'register_manifest' )
			&& false !== strpos( $bootstrap, 'manifest.php' )
	);

	v100_pr_check(
		'Manifest declares SmilePay invoice provider and lifecycle admin slug',
		false !== strpos( $manifest, "'id'                 => 'ys_smilepay'" )
			&& false !== strpos( $manifest, "'legacy_setting_key' => 'ys_ec_smilepay_enabled'" )
			&& false !== strpos( $manifest, "'domains'            => [ 'invoice' ]" )
			&& false !== strpos( $manifest, "'providers'" )
			&& false !== strpos( $manifest, "'slug'                => 'ys-provider-smilepay'" )
	);

	v100_pr_check(
		'Bootstrap does not use legacy external menu hook',
		false === strpos( $bootstrap, "ys_ec_admin_payment_menus" )
			&& false === strpos( $bootstrap, 'add_submenu_page' )
	);

	v100_pr_check(
		'Invoice registration and file host are lifecycle-gated',
		false !== strpos( $bootstrap, 'is_invoice_enabled()' )
			&& 1 === preg_match( "/is_method_enabled\s*\(\s*['\"]invoice['\"]/s", $bootstrap )
			&& false !== strpos( $bootstrap, 'YSSmilePayInvoiceProvider::ID' )
	);

	v100_pr_check(
		'YSSmilePayInvoiceProvider class declared with namespace',
		false !== strpos( $provider_src, 'namespace YangSheep\\SmilePayEInvoice\\Providers' )
			&& false !== strpos( $provider_src, 'class YSSmilePayInvoiceProvider' )
	);

	v100_pr_check(
		'Provider implements YSInvoiceProviderInterface',
		( false !== strpos( $provider_src, 'implements YSInvoiceProviderInterface' )
			|| false !== strpos( $provider_src, 'implements \\YangSheep\\Ecommerce\\Invoice\\YSInvoiceProviderInterface' ) )
	);

	v100_pr_check(
		'Provider get_id() returns "smilepay"',
		false !== strpos( $provider_src, "return 'smilepay'" )
			|| false !== strpos( $provider_src, "const ID = 'smilepay'" )
			|| false !== strpos( $provider_src, "public const ID = 'smilepay'" )
	);

	v100_pr_check(
		'get_carrier_types() declares mobile / cdc / member / donate keys',
		false !== strpos( $provider_src, "'mobile'" )
			&& false !== strpos( $provider_src, "'cdc'" )
			&& false !== strpos( $provider_src, "'member'" )
			&& false !== strpos( $provider_src, "'donate'" )
			&& false !== strpos( $provider_src, 'function get_carrier_types' )
	);
}

echo "PASS={$pass} FAIL={$fail}\n";
exit( $fail > 0 ? 1 : 0 );
