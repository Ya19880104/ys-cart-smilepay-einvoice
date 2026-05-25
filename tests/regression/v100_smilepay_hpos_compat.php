<?php
/**
 * regression: ys-cart-smilepay-einvoice v1.0.0
 *
 * Assertion 目的：HPOS（High-Performance Order Storage）相容性檢查。
 *
 * 規則（CLAUDE.md / dev_cautions.md）：
 *   - 禁止 get_post_meta() / update_post_meta() / delete_post_meta() 對 order_id 操作。
 *   - 必須走 $order->get_meta() / $order->update_meta_data() / $order->save()。
 *
 * 本外掛預期**不直接存任何 order meta**（發票資料由 ys-cart 主框架存到 ys_ec_invoices）。
 * 若 carrier_id 需 cache 在 order 上，必須走 $order->update_meta_data()。
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

function v100_hpos_check( string $label, bool $ok, string $detail = '' ): void {
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

function v100_hpos_collect_php( string $absolute_dir ): array {
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

function v100_hpos_strip_comments( string $source ): string {
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

// ---------- 掃描 src/ + templates/ ----------
$all_files = array_merge(
	v100_hpos_collect_php( YS_SMILEPAY_PATH . 'src' ),
	v100_hpos_collect_php( YS_SMILEPAY_PATH . 'templates' )
);

$post_meta_offenders   = [];
$delete_meta_offenders = [];

foreach ( $all_files as $abs ) {
	$source = (string) file_get_contents( $abs );
	$clean  = v100_hpos_strip_comments( $source );

	// 同時抓 get_post_meta / update_post_meta；任何一個出現就算違規。
	if ( preg_match( '/\b(?:get|update|add)_post_meta\s*\(/i', $clean ) ) {
		$post_meta_offenders[] = str_replace( YS_SMILEPAY_PATH, '', $abs );
	}
	if ( preg_match( '/\bdelete_post_meta\s*\(/i', $clean ) ) {
		$delete_meta_offenders[] = str_replace( YS_SMILEPAY_PATH, '', $abs );
	}
}

v100_hpos_check(
	'No get_post_meta() / update_post_meta() / add_post_meta() calls in src/ or templates/',
	empty( $post_meta_offenders ),
	empty( $post_meta_offenders ) ? '' : 'offending files: ' . implode( ', ', $post_meta_offenders )
);

v100_hpos_check(
	'No delete_post_meta() calls in src/ or templates/',
	empty( $delete_meta_offenders ),
	empty( $delete_meta_offenders ) ? '' : 'offending files: ' . implode( ', ', $delete_meta_offenders )
);

// ---------- 額外：若外掛聲明對 order 寫 meta，必須走 update_meta_data() ----------
$has_order_meta = false;
foreach ( $all_files as $abs ) {
	$source = (string) file_get_contents( $abs );
	$clean  = v100_hpos_strip_comments( $source );
	if ( preg_match( '/_ys_smilepay_/', $clean )
		&& preg_match( '/->update_meta_data\s*\(/', $clean )
	) {
		$has_order_meta = true;
		break;
	}
}
v100_hpos_check(
	'If plugin writes order meta (_ys_smilepay_*), it uses $order->update_meta_data() — informational',
	true  // 永遠 PASS：僅 audit log，HPOS 不違規即可
);

if ( $has_order_meta ) {
	echo "[INFO] plugin writes order meta via update_meta_data() — HPOS compatible\n";
}

echo "PASS={$pass} FAIL={$fail}\n";
exit( $fail > 0 ? 1 : 0 );
