<?php
/**
 * regression: ys-cart-smilepay-einvoice v1.0.0
 *
 * Assertion 目的：ADR-053 §4.2 強制要求 — SmilePay v1.0 ship 時必須透過
 *   add_filter( 'ys_ec_invoice_file_allowed_hosts', ... ) 把 einvoice.smilepay.net
 *   加進 allowlist，否則 YSInvoiceStorefrontController 會回 502 拒絕列印。
 *
 * 兩個檢查：
 *   1. add_filter call site 存在於 src/（不一定要在主 bootstrap，但必須在外掛載入時生效）。
 *   2. 'einvoice.smilepay.net' 字串出現在 filter callback 的範圍內。
 *
 * 雙路徑：
 *   - WP loaded：直接 apply_filters() 確認 hook 真有把 host 加進去。
 *   - CLI fallback：source-grep。
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

function v100_host_check( string $label, bool $ok, string $detail = '' ): void {
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

function v100_host_collect_php( string $absolute_dir ): array {
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

function v100_host_strip_comments( string $source ): string {
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

// Runtime path: 如果 WP + 外掛都 loaded、實際 apply_filters 確認 host 被 push 進來
if ( function_exists( 'apply_filters' )
	&& class_exists( '\\YangSheep\\SmilePayEInvoice\\YSSmilePayPlugin' )
) {
	$hosts = apply_filters( 'ys_ec_invoice_file_allowed_hosts', [] );
	$hosts = is_array( $hosts ) ? array_map( 'strtolower', array_filter( $hosts, 'is_string' ) ) : [];

	v100_host_check(
		'ys_ec_invoice_file_allowed_hosts filter contains "einvoice.smilepay.net" at runtime',
		in_array( 'einvoice.smilepay.net', $hosts, true )
	);
} else {
	// Source-grep fallback
	$src_files       = v100_host_collect_php( YS_SMILEPAY_PATH . 'src' );
	$has_filter      = false;
	$has_host_string = false;
	$details         = [];

	foreach ( $src_files as $abs ) {
		$source = (string) file_get_contents( $abs );
		$clean  = v100_host_strip_comments( $source );

		if ( preg_match( '/add_filter\s*\(\s*[\'"]ys_ec_invoice_file_allowed_hosts[\'"]/', $clean ) ) {
			$has_filter = true;
			$details[]  = str_replace( YS_SMILEPAY_PATH, '', $abs );
		}
		if ( false !== strpos( $clean, 'einvoice.smilepay.net' ) ) {
			$has_host_string = true;
		}
	}

	v100_host_check(
		'add_filter("ys_ec_invoice_file_allowed_hosts", ...) registered in src/',
		$has_filter,
		$has_filter ? 'in ' . implode( ', ', $details ) : 'missing call site'
	);

	v100_host_check(
		'"einvoice.smilepay.net" hostname literal present in src/',
		$has_host_string,
		$has_host_string ? '' : 'host string missing (will trigger 502 invoice_file_url_blocked at runtime)'
	);
}

// 額外確認：list URL 必須走 HTTPS（ADR-053 §2.1 強制）
$src_files = v100_host_collect_php( YS_SMILEPAY_PATH . 'src' );
$has_http  = false;
foreach ( $src_files as $abs ) {
	$source = (string) file_get_contents( $abs );
	$clean  = v100_host_strip_comments( $source );
	if ( preg_match( "#['\"]http://einvoice\\.smilepay\\.net#i", $clean ) ) {
		$has_http = true;
		break;
	}
}
v100_host_check(
	'SmilePay print/API hostnames never use http:// scheme (HTTPS-only per ADR-053)',
	! $has_http
);

echo "PASS={$pass} FAIL={$fail}\n";
exit( $fail > 0 ? 1 : 0 );
