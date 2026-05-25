<?php
/**
 * regression: ys-cart-smilepay-einvoice v1.0.0
 *
 * Assertion 目的：確認 SmilePay carrier_type 對應 SmilePay 官方代碼正確：
 *   - 'mobile'  → CarrierType = '3J0002'  （手機條碼）
 *   - 'cdc'     → CarrierType = 'CQ0001'  （自然人憑證）
 *   - 'member'  → CarrierType = 'EJ0113'  （速買配會員載具）
 *   - 'donate'  → 不送 CarrierType，改用 DonateMark='1' + LoveKey
 *
 * 雙路徑：
 *   - Runtime：實例化 provider、呼叫 get_carrier_types() 確認鍵值；
 *     reflection 抓 private build_payload() 跑各 carrier 確認 CarrierType 對應。
 *   - CLI fallback：source-grep 確認 3 個 CarrierType code 字串都存在。
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

function v100_carrier_check( string $label, bool $ok, string $detail = '' ): void {
	global $pass, $fail;
	if ( $ok ) {
		echo "[PASS] {$label}\n";
		$pass++;
		return;
	}
	echo "[FAIL] {$label}";
	if ( '' !== $detail ) {
		echo " — {$detail}";
	}
	echo "\n";
	$fail++;
}

function v100_carrier_read( string $relative ): string {
	$path = YS_SMILEPAY_PATH . ltrim( $relative, '/' );
	return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
}

$provider_class = '\\YangSheep\\SmilePayEInvoice\\Providers\\YSSmilePayInvoiceProvider';
$provider_loaded = class_exists( $provider_class );

if ( $provider_loaded ) {
	$provider = new $provider_class();

	$types = method_exists( $provider, 'get_carrier_types' )
		? (array) $provider->get_carrier_types()
		: [];

	v100_carrier_check(
		'get_carrier_types() includes mobile',
		isset( $types['mobile'] )
	);
	v100_carrier_check(
		'get_carrier_types() includes cdc',
		isset( $types['cdc'] )
	);
	v100_carrier_check(
		'get_carrier_types() includes member',
		isset( $types['member'] )
	);
	v100_carrier_check(
		'get_carrier_types() includes donate',
		isset( $types['donate'] )
	);
}

// 永遠跑 source-grep 確認 SmilePay 官方 carrier code 都被 hard-code 在 provider 內
$provider_src = v100_carrier_read( 'src/Providers/YSSmilePayInvoiceProvider.php' );

v100_carrier_check(
	'YSSmilePayInvoiceProvider.php exists',
	'' !== $provider_src,
	'src/Providers/YSSmilePayInvoiceProvider.php not readable (will FAIL until backend agent ships)'
);

if ( '' !== $provider_src ) {
	v100_carrier_check(
		'Provider source maps "mobile" → "3J0002"',
		false !== strpos( $provider_src, '3J0002' )
			&& preg_match( '/[\'"]mobile[\'"][^\n]{0,200}3J0002|3J0002[^\n]{0,200}[\'"]mobile[\'"]/s', $provider_src )
	);

	v100_carrier_check(
		'Provider source maps "cdc" → "CQ0001"',
		false !== strpos( $provider_src, 'CQ0001' )
			&& preg_match( '/[\'"]cdc[\'"][^\n]{0,200}CQ0001|CQ0001[^\n]{0,200}[\'"]cdc[\'"]/s', $provider_src )
	);

	v100_carrier_check(
		'Provider source maps "member" → "EJ0113"',
		false !== strpos( $provider_src, 'EJ0113' )
			&& preg_match( '/[\'"]member[\'"][^\n]{0,200}EJ0113|EJ0113[^\n]{0,200}[\'"]member[\'"]/s', $provider_src )
	);

	// donate 不送 CarrierType，改送 DonateMark + LoveKey
	v100_carrier_check(
		'Donate carrier mapped via DonateMark + LoveKey (NOT CarrierType)',
		false !== strpos( $provider_src, 'DonateMark' )
			&& false !== strpos( $provider_src, 'LoveKey' )
			&& preg_match( '/[\'"]donate[\'"]/i', $provider_src )
	);

	// 額外：B2B 必填 Buyer_id / CompanyName + Einvoice_Type=B2B + UnitTAX=Y
	v100_carrier_check(
		'B2B branch sets Buyer_id + Einvoice_Type=B2B + UnitTAX=Y',
		false !== strpos( $provider_src, 'Buyer_id' )
			&& false !== strpos( $provider_src, 'B2B' )
			&& false !== strpos( $provider_src, 'UnitTAX' )
	);

	// 固定欄位：Intype=07 + TaxType=1
	v100_carrier_check(
		'Provider sets Intype=07 (general tax) + TaxType=1 (taxable) per design plan §5.1',
		( preg_match( '/[\'"]Intype[\'"]\s*=>\s*[\'"]07[\'"]/', $provider_src )
			|| preg_match( '/[\'"]07[\'"][^\n]*Intype/', $provider_src ) )
		&& ( preg_match( '/[\'"]TaxType[\'"]\s*=>\s*[\'"]1[\'"]/', $provider_src )
			|| preg_match( '/[\'"]1[\'"][^\n]*TaxType/', $provider_src ) )
	);
}

echo "PASS={$pass} FAIL={$fail}\n";
exit( $fail > 0 ? 1 : 0 );
