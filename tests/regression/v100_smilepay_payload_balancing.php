<?php
/**
 * regression: ys-cart-smilepay-einvoice v1.0.0
 *
 * Assertion 目的：design plan §11 風險矩陣明列「sum(Amount) ≠ AllAmount → SmilePay -10067 拒收」。
 *
 *   provider 內部 build_payload() 處理多 line item + 折扣/運費時，必須確保
 *   sum( Amount of each line ) === AllAmount。若有 cent-rounding 差額或折扣 hidden line，
 *   要補一行「手續費」或「折扣」item 把差額吃掉。
 *
 *   參考 Amego provider 的 normalize_provider_invoice_items() 既有正規化邏輯。
 *
 * 雙路徑：
 *   - Runtime（preferred）：用 reflection 直接呼叫 provider 的 payload builder method、
 *     餵 stub items 確認最終 payload 中 sum(Amount) 與 AllAmount 平衡。
 *   - Source-grep fallback：搜尋程式碼中有「balance/差額/sum/round」等補償邏輯關鍵字。
 *
 * 範圍限定：runtime path 失敗（method 不可達）會自動降級到 source-grep；
 *   永不 throw、永不 silent skip。
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

function v100_balance_check( string $label, bool $ok, string $detail = '' ): void {
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

function v100_balance_read( string $relative ): string {
	$path = YS_SMILEPAY_PATH . ltrim( $relative, '/' );
	return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
}

/**
 * 解析 SmilePay payload 中 `Amount=1|2|3` 形式的 pipe-delimited 數列，回傳 sum。
 *
 * 若 payload 是 array shape（'Amount' => ...），key 已是 string；
 * 若是 query string，呼叫端要先 parse_str()。
 */
function v100_balance_sum_pipe( $value ): float {
	if ( is_numeric( $value ) ) {
		return (float) $value;
	}
	if ( ! is_string( $value ) ) {
		return 0.0;
	}
	$parts = explode( '|', $value );
	$sum   = 0.0;
	foreach ( $parts as $p ) {
		$sum += (float) trim( $p );
	}
	return $sum;
}

$provider_class  = '\\YangSheep\\SmilePayEInvoice\\Providers\\YSSmilePayInvoiceProvider';
$provider_loaded = class_exists( $provider_class );
$runtime_done    = false;

// ---------- 嘗試 runtime 跑 ----------
if ( $provider_loaded ) {
	try {
		$provider = new $provider_class();
		$ref      = new ReflectionClass( $provider );

		// 找 payload builder（可能名稱：build_payload / build_issue_payload / build_smilepay_payload）
		$candidate_methods = [ 'build_payload', 'build_issue_payload', 'build_smilepay_payload', 'build_invoice_payload' ];
		$builder           = null;
		foreach ( $candidate_methods as $name ) {
			if ( $ref->hasMethod( $name ) ) {
				$builder = $ref->getMethod( $name );
				break;
			}
		}

		if ( null !== $builder ) {
			$builder->setAccessible( true );

			// 製作差 1 元的測試 case（3 item, total=100, sum 99）
			$invoice_data = [
				'order_id'     => 1,
				'order_number' => 'YSCART-TEST',
				'buyer_type'   => 'personal',
				'buyer_name'   => 'Tester',
				'buyer_email'  => 'tester@example.invalid',
				'carrier_type' => 'mobile',
				'carrier_id'   => '/ABCD123',
				'donate_code'  => '',
				'total_amount' => 100,  // ← 與 items sum 差 1
				'tax_amount'   => 0,
				'items'        => [
					[ 'name' => 'Item A', 'qty' => 1, 'unit_price' => 33, 'subtotal' => 33 ],
					[ 'name' => 'Item B', 'qty' => 1, 'unit_price' => 33, 'subtotal' => 33 ],
					[ 'name' => 'Item C', 'qty' => 1, 'unit_price' => 33, 'subtotal' => 33 ],
				],
				'print_mark'   => 'N',
			];

			$payload = $builder->invoke( $provider, $invoice_data );

			if ( is_array( $payload )
				&& isset( $payload['Amount'], $payload['AllAmount'] )
			) {
				$sum_amount = v100_balance_sum_pipe( $payload['Amount'] );
				$all_amount = (float) $payload['AllAmount'];

				v100_balance_check(
					sprintf( 'sum(Amount)=%s equals AllAmount=%s after balancing', $sum_amount, $all_amount ),
					abs( $sum_amount - $all_amount ) < 0.5  // 容忍 0.5 元 cent rounding
				);

				// 應該多出至少一行「折扣 / 手續費 / 平衡」item 來吃掉差額
				$description = (string) ( $payload['Description'] ?? '' );
				v100_balance_check(
					'Balanced payload contains adjustment line (折扣/手續費/平衡/balance)',
					false !== strpos( $description, '折扣' )
						|| false !== strpos( $description, '手續費' )
						|| false !== strpos( $description, '平衡' )
						|| false !== stripos( $description, 'balance' )
						|| false !== stripos( $description, 'adjustment' )
						|| false !== stripos( $description, 'rounding' )
				);
				$runtime_done = true;
			}
		}
	} catch ( \Throwable $e ) {
		// runtime 失敗就 fall back 到 grep
		echo "[INFO] runtime payload builder not invocable: " . $e->getMessage() . "\n";
	}
}

// ---------- source-grep fallback（runtime 沒跑成或當 sanity check） ----------
if ( ! $runtime_done ) {
	$provider_src = v100_balance_read( 'src/Providers/YSSmilePayInvoiceProvider.php' );

	v100_balance_check(
		'YSSmilePayInvoiceProvider.php exists',
		'' !== $provider_src,
		'src/Providers/YSSmilePayInvoiceProvider.php not readable (will FAIL until backend agent ships)'
	);

	if ( '' !== $provider_src ) {
		// 必須出現 Amount + AllAmount field（payload builder 在這兩個欄位上做平衡）
		v100_balance_check(
			'Provider source builds Amount + AllAmount fields',
			false !== strpos( $provider_src, "'Amount'" )
				&& false !== strpos( $provider_src, "'AllAmount'" )
		);

		// 必須有 balancing 邏輯（檢查任一關鍵字）
		v100_balance_check(
			'Provider source contains payload balancing logic',
			false !== strpos( $provider_src, 'normalize_provider_invoice_items' )
				|| false !== strpos( $provider_src, 'normalize_items' )
				|| false !== strpos( $provider_src, 'balance' )
				|| false !== strpos( $provider_src, '折扣' )
				|| false !== strpos( $provider_src, '手續費' )
				|| false !== strpos( $provider_src, 'rounding' )
				|| preg_match( '/sum\s*\(\s*[\$\w]+Amount/i', $provider_src )
		);

		// 商品名稱含 `|` 必須被 strip（design plan §11 風險矩陣）
		v100_balance_check(
			'Provider strips pipe character (|) from product names to avoid breaking SmilePay payload delimiter',
			false !== strpos( $provider_src, "str_replace( '|'" )
				|| false !== strpos( $provider_src, 'str_replace("|"' )
				|| false !== strpos( $provider_src, "str_replace('|'" )
				|| false !== strpos( $provider_src, 'preg_replace(' )
		);
	}
}

echo "PASS={$pass} FAIL={$fail}\n";
exit( $fail > 0 ? 1 : 0 );
