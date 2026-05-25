<?php
/**
 * regression: ys-cart-smilepay-einvoice v1.0.0
 *
 * Assertion 目的：強制執行 ADR-052 5 個核心原則中的「統一 Chrome」+「統一 CSS」：
 *
 *   原則 #2：所有 admin page 必須走 YSAdminApp::open() / YSAdminApp::close() 包裝，
 *           不可直接 echo '<div class="wrap">' 或 WP 原生 admin HTML。
 *   原則 #5：admin template 用 ys-cart `.ysca-*` primitives + token，
 *           不可宣告自家 CSS class（例如 ys-smilepay-card / ys-smilepay-btn）。
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

function v100_chrome_check( string $label, bool $ok, string $detail = '' ): void {
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

function v100_chrome_read( string $relative ): string {
	$path = YS_SMILEPAY_PATH . ltrim( $relative, '/' );
	return is_readable( $path ) ? (string) file_get_contents( $path ) : '';
}

function v100_chrome_strip_comments( string $source ): string {
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

// ---------- 1: Admin class 使用 YSAdminApp shell ----------
$admin_src   = v100_chrome_read( 'src/Admin/YSSmilePayAdmin.php' );
$admin_clean = '' !== $admin_src ? v100_chrome_strip_comments( $admin_src ) : '';

v100_chrome_check(
	'YSSmilePayAdmin.php exists',
	'' !== $admin_src,
	'src/Admin/YSSmilePayAdmin.php not readable (will FAIL until frontend agent ships)'
);

if ( '' !== $admin_src ) {
	v100_chrome_check(
		'YSSmilePayAdmin invokes YSAdminApp::open()',
		preg_match( '/YSAdminApp\s*::\s*open\s*\(/', $admin_clean )
			|| preg_match( '/YangSheep\\\\Ecommerce\\\\Admin\\\\YSAdminApp\s*::\s*open\s*\(/', $admin_clean )
	);

	v100_chrome_check(
		'YSSmilePayAdmin invokes YSAdminApp::close()',
		preg_match( '/YSAdminApp\s*::\s*close\s*\(/', $admin_clean )
			|| preg_match( '/YangSheep\\\\Ecommerce\\\\Admin\\\\YSAdminApp\s*::\s*close\s*\(/', $admin_clean )
	);

	// Admin class **不可** 自己 echo '<div class="wrap">'（那是 WP 原生 chrome、YSAdminApp 取代）
	v100_chrome_check(
		'YSSmilePayAdmin does NOT bypass YSAdminApp with raw `<div class="wrap">`',
		false === strpos( $admin_clean, '<div class="wrap">' )
			&& ! preg_match( '/echo\s+[\'"]<div\s+class\s*=\s*"wrap"/', $admin_clean )
	);
}

// ---------- 2: Settings template 用 .ysca-* primitives ----------
$tpl_src   = v100_chrome_read( 'templates/admin/settings.php' );
$tpl_clean = '' !== $tpl_src ? v100_chrome_strip_comments( $tpl_src ) : '';

v100_chrome_check(
	'templates/admin/settings.php exists',
	'' !== $tpl_src,
	'templates/admin/settings.php not readable (will FAIL until frontend agent ships)'
);

if ( '' !== $tpl_src ) {
	// Template 也不可繞過 YSAdminApp 自開 .wrap
	v100_chrome_check(
		'settings.php does NOT contain raw `<div class="wrap">`',
		false === strpos( $tpl_clean, '<div class="wrap">' )
	);

	// Template 必須用 .ysca-* primitives（card / form-row / etc）
	v100_chrome_check(
		'settings.php uses .ysca-* primitives',
		preg_match( '/class\s*=\s*["\'][^"\']*\bysca-[a-z_-]+/i', $tpl_clean )
			|| preg_match( '/class\s*=\s*["\'][^"\']*ysca-card/', $tpl_clean )
	);

	// Template **禁止** 宣告自家 `ys-smilepay-*` CSS class（會破壞統一 design system）
	v100_chrome_check(
		'settings.php does NOT declare custom ys-smilepay-* CSS classes',
		! preg_match( '/class\s*=\s*["\'][^"\']*\bys-smilepay-[a-z_-]+/i', $tpl_clean )
	);

	// 確認不用 WP form-table（ADR-049 已淘汰）
	v100_chrome_check(
		'settings.php does NOT use WordPress legacy <table class="form-table">',
		false === strpos( $tpl_clean, 'class="form-table"' )
			&& false === strpos( $tpl_clean, "class='form-table'" )
	);
}

// ---------- 3: 外掛**不註冊**自家 CSS / JS framework ----------
$bootstrap = v100_chrome_read( 'src/YSSmilePayPlugin.php' );
$boot_clean = '' !== $bootstrap ? v100_chrome_strip_comments( $bootstrap ) : '';

if ( '' !== $boot_clean ) {
	v100_chrome_check(
		'Bootstrap does NOT enqueue custom ys-smilepay CSS framework',
		! preg_match( '/wp_enqueue_style\s*\([^)]*[\'"]ys-smilepay-(?:admin|app|framework)/i', $boot_clean )
	);
}

echo "PASS={$pass} FAIL={$fail}\n";
exit( $fail > 0 ? 1 : 0 );
