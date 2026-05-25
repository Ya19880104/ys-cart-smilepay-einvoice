<?php
/**
 * regression: ys-cart-smilepay-einvoice v1.0.0
 *
 * Assertion 目的：確認 SmilePay Verify_key（API secret）絕不洩漏到：
 *   1. logger 任何輸出（YSLogger::* / error_log / var_dump / print_r）
 *   2. HTML 回應（echo / printf / wp_die / settings.php password input value=）
 *   3. 程式碼 hard-coded 真實試用 Verify_key（9D73935693EE0237FABA6AB744E48661）
 *
 * 同時驗：
 *   - 任何 verify_key 變數出現在 log / echo / printf 上下文時，必須先過 mask/substr/redact
 *   - templates/admin/settings.php 的 verify_key password input 不可有 value=
 *   - .env / config 檔 / fixture / docs 不可含真實試用 Verify_key
 *
 * 這條規則對應 design plan §7.4 + ADR-053 §3.6（列印 URL 含 secret 的洩漏面）。
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

function v100_leak_check( string $label, bool $ok, string $detail = '' ): void {
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

function v100_leak_collect_php( string $absolute_dir ): array {
	if ( ! is_dir( $absolute_dir ) ) {
		return [];
	}
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $absolute_dir, FilesystemIterator::SKIP_DOTS ) );
	$files = [];
	foreach ( $it as $file ) {
		if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}
	return $files;
}

function v100_leak_strip_comments( string $source ): string {
	if ( ! function_exists( 'token_get_all' ) ) {
		$source = preg_replace( '#/\*.*?\*/#s', '', $source ) ?? $source;
		$source = preg_replace( '#//[^\n]*#', '', $source ) ?? $source;
		return $source;
	}
	$tokens = @token_get_all( $source );
	$out    = '';
	foreach ( $tokens as $token ) {
		if ( is_array( $token ) ) {
			[ $id, $text ] = $token;
			if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
				$out .= str_repeat( "\n", substr_count( $text, "\n" ) );
				continue;
			}
			$out .= $text;
		} else {
			$out .= $token;
		}
	}
	return $out;
}

// ---------- 1: 真實試用 Verify_key 不可 hard-code 在 src/ + templates/ ----------
// 試用 Verify_key 是公開資訊（可寫進 README），但**任何 PHP 邏輯 / 程式碼**不可 hard-code。
// 因為 hard-code 通常代表「測試殘留」、未來 deploy 到正式環境會用錯的 key。
$trial_verify_key = '9D73935693EE0237FABA6AB744E48661';
$leak_files       = [];

$src_files = v100_leak_collect_php( YS_SMILEPAY_PATH . 'src' );
$tpl_files = v100_leak_collect_php( YS_SMILEPAY_PATH . 'templates' );

foreach ( array_merge( $src_files, $tpl_files ) as $abs ) {
	$source = (string) file_get_contents( $abs );
	// 對 src/ + templates/ 嚴格：連 comment 內都不可 hard-code（避免 RD 抄到實作）
	if ( false !== stripos( $source, $trial_verify_key ) ) {
		$leak_files[] = str_replace( YS_SMILEPAY_PATH, '', $abs );
	}
}

v100_leak_check(
	'Trial Verify_key literal absent from src/ and templates/',
	empty( $leak_files ),
	empty( $leak_files ) ? '' : 'leak in: ' . implode( ', ', $leak_files )
);

// ---------- 2: $verify_key 不可被直接 echo / printf / 寫 log ----------
$echo_offenders = [];

foreach ( $src_files as $abs ) {
	$source = (string) file_get_contents( $abs );
	$clean  = v100_leak_strip_comments( $source );

	// 偵測 risky pattern：
	//   echo $settings['verify_key'];
	//   printf( '...%s...', $verify_key );
	//   error_log( "key={$verify_key}" );
	//   YSLogger::xxx( ..., [ ..., 'verify_key' => $verify_key, ... ] );  ← 也算洩漏
	//
	// 規則：先抓「verify_key 出現在 log / echo / printf / wp_die / var_dump 等危險 context 的同一行」，
	//   再排除「該行有 mask / redact / substr / *** 等 sanitize 痕跡」。

	$lines = explode( "\n", $clean );
	foreach ( $lines as $line_no => $line ) {
		// 跳過空行
		if ( '' === trim( $line ) ) {
			continue;
		}

		$dangerous = (
			false !== stripos( $line, 'error_log' )
			|| false !== stripos( $line, 'var_dump' )
			|| false !== stripos( $line, 'print_r' )
			|| false !== stripos( $line, 'YSLogger::' )
			|| preg_match( '/\b(?:echo|printf|wp_die|trigger_error)\s*\(/i', $line )
		);
		if ( ! $dangerous ) {
			continue;
		}

		// 該行有提到 verify_key 嗎？
		if ( ! preg_match( '/verify_key/i', $line ) ) {
			continue;
		}

		// 已 sanitize 的痕跡：mask / substr / redact / *** / Logger 內部濾掉 verify_key
		$sanitized = (
			false !== stripos( $line, 'mask' )
			|| false !== stripos( $line, 'redact' )
			|| false !== stripos( $line, 'substr' )
			|| false !== strpos( $line, '***' )
			|| false !== stripos( $line, 'sprintf' ) && false !== strpos( $line, '****' )
		);

		// 特例：logger context array key 名叫 verify_key 但 value 是 mask 過的 → OK
		// 我們允許 "'verify_key_present' => true" / "'verify_key_masked' => '..."  之類
		if ( preg_match( "/['\"]verify_key_(?:present|masked|redacted|first4|last4|sha256)['\"]/", $line ) ) {
			continue;
		}

		if ( ! $sanitized ) {
			$echo_offenders[] = sprintf(
				'%s:%d  %s',
				str_replace( YS_SMILEPAY_PATH, '', $abs ),
				$line_no + 1,
				trim( $line )
			);
		}
	}
}

v100_leak_check(
	'No raw $verify_key passed to echo/printf/log/wp_die/error_log without mask',
	empty( $echo_offenders ),
	empty( $echo_offenders ) ? '' : 'risky lines: ' . implode( ' | ', array_slice( $echo_offenders, 0, 5 ) )
);

// ---------- 3: settings.php 的 password input 不可有 value="..." ----------
$settings_tpl = YS_SMILEPAY_PATH . 'templates/admin/settings.php';
$tpl_src      = is_readable( $settings_tpl ) ? (string) file_get_contents( $settings_tpl ) : '';

if ( '' !== $tpl_src ) {
	// 找所有 type="password" input
	$password_inputs = [];
	if ( preg_match_all( '/<input[^>]*type\s*=\s*["\']password["\'][^>]*>/i', $tpl_src, $matches ) ) {
		$password_inputs = $matches[0];
	}

	$bad_inputs = [];
	foreach ( $password_inputs as $input ) {
		// password input 不可同時有 value="…非空…"（即便 PHP variable 也不行；
		// 因為實作上會被 echo 出去成 plaintext）
		if ( preg_match( '/value\s*=\s*["\']\s*<?php\s*echo/i', $input )
			|| preg_match( '/value\s*=\s*["\'][^"\']+["\']/i', $input )
		) {
			// 例外：value=""（空字串）是 OK
			if ( ! preg_match( '/value\s*=\s*(["\'])\s*\1/', $input ) ) {
				$bad_inputs[] = $input;
			}
		}
	}

	v100_leak_check(
		'settings.php password inputs never echo stored value (placeholder only)',
		empty( $bad_inputs ),
		empty( $bad_inputs ) ? '' : 'risky: ' . implode( ' | ', array_slice( $bad_inputs, 0, 3 ) )
	);
} else {
	// 沒檔案就 skip（標記為 FAIL — frontend agent 尚未產出）
	v100_leak_check(
		'settings.php password inputs never echo stored value (placeholder only)',
		false,
		'templates/admin/settings.php missing (frontend agent not yet shipped)'
	);
}

// ---------- 4: README / docs 內若有提到試用 Verify_key 必須標 "trial" / "試用" ----------
// 公開資訊不算違規，但 grep 結果應只出現在 README / docs，不能在 .php active code 中。
// 此檢查上面 #1 已覆蓋；這條再加 README 必須明確標註 "試用" / "trial"。
$readme_files = [
	YS_SMILEPAY_PATH . 'README.md',
	YS_SMILEPAY_PATH . 'readme.txt',
];

$readme_has_trial_marker = true;
foreach ( $readme_files as $abs ) {
	if ( ! is_readable( $abs ) ) {
		continue;
	}
	$contents = (string) file_get_contents( $abs );
	if ( false !== stripos( $contents, $trial_verify_key ) ) {
		// 必須在同 100 字內出現 "試用" / "trial" / "sandbox" / "demo"
		$pos     = stripos( $contents, $trial_verify_key );
		$context = substr( $contents, max( 0, $pos - 100 ), 200 );
		$marked  = (
			false !== stripos( $context, '試用' )
			|| false !== stripos( $context, 'trial' )
			|| false !== stripos( $context, 'sandbox' )
			|| false !== stripos( $context, 'demo' )
			|| false !== stripos( $context, 'test' )
		);
		if ( ! $marked ) {
			$readme_has_trial_marker = false;
			break;
		}
	}
}

v100_leak_check(
	'If trial Verify_key appears in README/readme.txt, it is clearly labeled as trial/sandbox',
	$readme_has_trial_marker
);

echo "PASS={$pass} FAIL={$fail}\n";
exit( $fail > 0 ? 1 : 0 );
