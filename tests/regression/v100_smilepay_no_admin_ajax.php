<?php
/**
 * regression: ys-cart-smilepay-einvoice v1.0.0
 *
 * Assertion 目的：強制執行 ADR-052 第 4 原則「零 admin AJAX」：
 *   src/ + templates/ 內**不可**出現 wp_ajax_* / wp_ajax_nopriv_* hook，
 *   也不可有 add_action() 註冊 admin-ajax handler。
 *
 * 方法：line-by-line 掃描所有 .php 檔，逐行先 strip PHP 註解再 grep，
 *   避免註解中提到 "wp_ajax_" 造成 false-positive。
 *
 * 注意：本 test 只信「真正會被 PHP 執行到」的程式碼；註解 / docblock /
 *   string literal 內提到 wp_ajax 為錯（過嚴 = silent false fail），
 *   故掃描器在 comment-strip 後才比對。
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

function v100_naa_check( string $label, bool $ok, string $detail = '' ): void {
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

/**
 * 列出某目錄下所有 .php 檔（遞迴），缺目錄回空 array（不算錯）。
 *
 * @return string[] absolute paths
 */
function v100_naa_collect_php( string $absolute_dir ): array {
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

/**
 * Strip PHP 註解（行內 // / 區段 /* ... *​/ / shell-style #）以避免 grep 註解 false-positive。
 *
 * 用 token_get_all() 直接拿 PHP parser 的結果，比 regex 安全。
 */
function v100_naa_strip_comments( string $source ): string {
	if ( ! function_exists( 'token_get_all' ) ) {
		// Fallback 給沒有 tokenizer extension 的環境（極罕見）：
		// 純 regex strip comment（不完美、但已涵蓋 99% case）。
		$source = preg_replace( '#/\*.*?\*/#s', '', $source ) ?? $source;
		$source = preg_replace( '#//[^\n]*#', '', $source ) ?? $source;
		$source = preg_replace( '/^\s*#[^\n]*/m', '', $source ) ?? $source;
		return $source;
	}
	$tokens = @token_get_all( $source );
	$out    = '';
	foreach ( $tokens as $token ) {
		if ( is_array( $token ) ) {
			[ $id, $text ] = $token;
			if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
				// 保留換行讓行號訊息對齊
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

// ---------- 掃描 src/ ----------
$src_files     = v100_naa_collect_php( YS_SMILEPAY_PATH . 'src' );
$src_offenders = [];

foreach ( $src_files as $abs ) {
	$source = (string) file_get_contents( $abs );
	$clean  = v100_naa_strip_comments( $source );

	// 任何形式的 wp_ajax_ hook 都該失敗
	if ( preg_match( '/wp_ajax_(?:nopriv_)?[a-z0-9_]/i', $clean )
		|| preg_match( '/add_action\s*\(\s*[\'"]wp_ajax_/i', $clean )
		|| preg_match( '/\$wp_filter\s*\[\s*[\'"]wp_ajax_/i', $clean )
	) {
		$src_offenders[] = str_replace( YS_SMILEPAY_PATH, '', $abs );
	}
}

v100_naa_check(
	'src/*.php contains zero wp_ajax_* / wp_ajax_nopriv_* references (post-comment-strip)',
	empty( $src_offenders ),
	empty( $src_offenders ) ? '' : 'offending files: ' . implode( ', ', $src_offenders )
);

// ---------- 掃描 templates/ ----------
$tpl_files     = v100_naa_collect_php( YS_SMILEPAY_PATH . 'templates' );
$tpl_offenders = [];

foreach ( $tpl_files as $abs ) {
	$source = (string) file_get_contents( $abs );
	$clean  = v100_naa_strip_comments( $source );

	if ( preg_match( '/wp_ajax_(?:nopriv_)?[a-z0-9_]/i', $clean )
		|| preg_match( '/add_action\s*\(\s*[\'"]wp_ajax_/i', $clean )
	) {
		$tpl_offenders[] = str_replace( YS_SMILEPAY_PATH, '', $abs );
	}
}

v100_naa_check(
	'templates/*.php contains zero wp_ajax_* references',
	empty( $tpl_offenders ),
	empty( $tpl_offenders ) ? '' : 'offending files: ' . implode( ', ', $tpl_offenders )
);

// ---------- 額外確認：沒有 admin-ajax.php URL 字串 ----------
$ajax_url_offenders = [];
foreach ( array_merge( $src_files, $tpl_files ) as $abs ) {
	$source = (string) file_get_contents( $abs );
	$clean  = v100_naa_strip_comments( $source );

	// 任何在 active 程式碼中提到 admin-ajax.php 都該檢討
	// （即使是 fetch URL、admin_url('admin-ajax.php') 也算）
	if ( false !== strpos( $clean, 'admin-ajax.php' )
		|| false !== strpos( $clean, "admin_url( 'admin-ajax.php'" )
		|| false !== strpos( $clean, 'admin_url("admin-ajax.php"' )
	) {
		$ajax_url_offenders[] = str_replace( YS_SMILEPAY_PATH, '', $abs );
	}
}

v100_naa_check(
	'No admin-ajax.php URL references in src/ or templates/',
	empty( $ajax_url_offenders ),
	empty( $ajax_url_offenders ) ? '' : 'offending files: ' . implode( ', ', $ajax_url_offenders )
);

echo "PASS={$pass} FAIL={$fail}\n";
exit( $fail > 0 ? 1 : 0 );
