<?php
/**
 * Plugin Name: YS CART 速買配電子發票
 * Plugin URI: https://github.com/Ya19880104/ys-cart-smilepay-einvoice
 * Description: 速買配（SmilePay / 訊航科技）電子發票整合，作為 YS CART 的 invoice provider。提供 B2C / B2B 發票開立、作廢、查詢、載具、捐贈愛心碼與發票列印。
 * Version: 1.0.4
 * Author: YANGSHEEP DESIGN
 * Author URI: https://yangsheep.com.tw
 * Text Domain: ys-cart-smilepay-einvoice
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Requires Plugins: ys-cart
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * 本檔案僅做：
 *   1. 防直接訪問
 *   2. 定義外掛常數
 *   3. 載入 PSR-4 手寫 autoloader
 *   4. 在 plugins_loaded 後檢查 ys-cart 依賴 + 啟動 Singleton bootstrap
 *
 * 所有實際邏輯在 src/YSSmilePayPlugin.php
 *
 * @package YangSheep\SmilePayEInvoice
 */

defined( 'ABSPATH' ) || exit;

// 外掛常數
define( 'YS_SMILEPAY_VERSION', '1.0.4' );
define( 'YS_SMILEPAY_FILE', __FILE__ );
define( 'YS_SMILEPAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'YS_SMILEPAY_URL', plugin_dir_url( __FILE__ ) );
define( 'YS_SMILEPAY_BASENAME', plugin_basename( __FILE__ ) );
define( 'YS_SMILEPAY_MIN_YS_CART_VERSION', '2.51.0' );

// PSR-4 手寫 autoloader（不依賴 composer）
$ys_smilepay_vendor = YS_SMILEPAY_DIR . 'vendor/autoload.php';
if ( is_readable( $ys_smilepay_vendor ) ) {
	require_once $ys_smilepay_vendor;
}

require_once YS_SMILEPAY_DIR . 'src/autoload.php';

/**
 * Bootstrap 入口
 *
 * 在 plugins_loaded priority 0 先掛上 manifest 與 invoice registry hooks，
 * 再交由 YS CART core（priority 10）初始化 registry。
 *
 * 若 ys-cart 未啟用或版本低於 YS_SMILEPAY_MIN_YS_CART_VERSION，會顯示 admin notice
 * 並中止啟動，避免 fatal error。
 */
add_action(
	'plugins_loaded',
	static function (): void {
		// ys-cart 未啟用 / 未載入 → 顯示提示後中止
		if ( ! defined( 'YS_ECOMMERCE_VERSION' ) ) {
			add_action( 'admin_notices', static function (): void {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__(
					'YS CART 速買配電子發票需要 YS CART 主外掛已啟用。',
					'ys-cart-smilepay-einvoice'
				);
				echo '</p></div>';
			} );
			return;
		}

		// ys-cart 版本過舊 → 顯示升級提示後中止
		if ( version_compare( YS_ECOMMERCE_VERSION, YS_SMILEPAY_MIN_YS_CART_VERSION, '<' ) ) {
			add_action( 'admin_notices', static function (): void {
				echo '<div class="notice notice-error"><p>';
				printf(
					/* translators: %s: 最低 YS CART 版本號 */
					esc_html__(
						'YS CART 速買配電子發票需要 YS CART %s 以上版本，請先升級主外掛。',
						'ys-cart-smilepay-einvoice'
					),
					esc_html( YS_SMILEPAY_MIN_YS_CART_VERSION )
				);
				echo '</p></div>';
			} );
			return;
		}

		// 啟動 Singleton bootstrap
		if ( class_exists( \YangSheep\PluginHubClient\YSPluginHubClient::class ) ) {
			\YangSheep\PluginHubClient\YSPluginHubClient::register( [
				'slug'        => 'ys-cart-smilepay-einvoice',
				'version'     => YS_SMILEPAY_VERSION,
				'plugin_file' => __FILE__,
				'name'        => 'YS CART SmilePay E-Invoice',
			] );
		}

		\YangSheep\SmilePayEInvoice\YSSmilePayPlugin::instance();
	},
	0
);
