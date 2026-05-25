<?php
/**
 * PSR-4 手寫 autoloader
 *
 * 對應規則：
 *   YangSheep\SmilePayEInvoice\Foo\Bar  →  YS_SMILEPAY_DIR . 'src/Foo/Bar.php'
 *
 * 本外掛不依賴 composer dependencies，使用此 autoloader 取代 vendor/autoload.php。
 * 將來若引入 composer，改為 require __DIR__ . '/../vendor/autoload.php' 即可。
 *
 * 註冊在 spl_autoload_register 主鏈、不掛 throw=true，避免污染其他外掛的
 * autoload 連鎖（例如 Admin namespace 在 frontend agent 提交前嘗試載入會默默 skip）。
 *
 * @package YangSheep\SmilePayEInvoice
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register( static function ( string $class ): void {
	$prefix   = 'YangSheep\\SmilePayEInvoice\\';
	$base_dir = YS_SMILEPAY_DIR . 'src/';

	$len = strlen( $prefix );
	if ( 0 !== strncmp( $prefix, $class, $len ) ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
} );
