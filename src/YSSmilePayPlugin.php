<?php
/**
 * YS CART 速買配電子發票 — Singleton bootstrap orchestrator
 *
 * 仿 ys-cart `YSEcommerce` 與 ys-cart-affiliate `YSAffiliateSuite` 架構，本類別負責：
 *   1. 載入 i18n（init hook，priority 0，比預設早，避免 sub-system 字串先讀）
 *   2. 註冊 SmilePay manifest 與 lifecycle-gated Invoice Provider
 *   3. （ADR-053 強制）把 einvoice.smilepay.net 加入 ys_ec_invoice_file_allowed_hosts
 *   4. 註冊後台子選單到「電子發票」群組（透過 admin_menu hook，跟 ys-cart YSAdminMenu 同 phase）
 *   5. 註冊 admin_post handler 處理設定儲存
 *
 * 注意：admin class（YSSmilePayAdmin）由 frontend agent 提交；本類別會在它存在時才掛上
 * admin_menu / admin_post hook，缺席時不影響 provider 註冊與 host allowlist。
 *
 * @package YangSheep\SmilePayEInvoice
 * @since   1.0.0
 */

namespace YangSheep\SmilePayEInvoice;

defined( 'ABSPATH' ) || exit;

use YangSheep\SmilePayEInvoice\Providers\YSSmilePayInvoiceProvider;

final class YSSmilePayPlugin {

	/** @var self|null Singleton 實例 */
	private static ?self $instance = null;

	/**
	 * 取得 Singleton 實例
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 建構子 — 註冊所有 hook
	 *
	 * 不在這裡執行任何業務邏輯；所有實際工作由 hook callback 在對的 phase 觸發。
	 */
	private function __construct() {
		// i18n（早於其他 sub-system）
		add_action( 'init', [ $this, 'load_textdomain' ], 0 );

		add_filter( 'ys_ec_provider_manifests', [ $this, 'register_manifest' ], 10, 1 );
		add_action(
			'ys_ec_register_invoice_providers',
			[ $this, 'register_provider' ]
		);

		// ADR-053 §4.2 強制：列印 URL 走 server-side proxy 前，
		// YSInvoiceStorefrontController::is_allowed_invoice_file_url() 會 host allowlist 驗證；
		// 必須把 einvoice.smilepay.net 加進去否則買家點列印會被 502 拒絕。
		add_filter(
			'ys_ec_invoice_file_allowed_hosts',
			[ $this, 'register_invoice_file_host' ]
		);
		add_filter(
			'ys_ec_invoice_provider_settings_url',
			[ $this, 'filter_invoice_provider_settings_url' ],
			10,
			3
		);

		// 設定儲存與測試連線 — menu 由 provider manifest lifecycle 掛載。
		// 類別不存在時不掛 handler，避免 fatal。
		if ( class_exists( '\YangSheep\SmilePayEInvoice\Admin\YSSmilePayAdmin' ) ) {
			add_action(
				'admin_post_ys_ec_save_smilepay_settings',
				[ '\YangSheep\SmilePayEInvoice\Admin\YSSmilePayAdmin', 'handle_save' ]
			);
			add_action(
				'admin_post_ys_ec_test_smilepay_connection',
				[ '\YangSheep\SmilePayEInvoice\Admin\YSSmilePayAdmin', 'handle_test_connection' ]
			);

			// 驗證碼無法解密（搬站 / 安全金鑰變動）時，後台引導重新輸入。
			add_action( 'admin_notices', [ $this, 'maybe_render_verify_key_notice' ] );
		}

		add_action( 'admin_footer', [ $this, 'render_admin_order_invoice_print_links' ] );
	}

	/**
	 * 載入 i18n
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'ys-cart-smilepay-einvoice',
			false,
			dirname( YS_SMILEPAY_BASENAME ) . '/languages/'
		);
	}

	/**
	 * 後台提示：驗證碼無法解密時，明確引導使用者重新輸入。
	 *
	 * 觸發：smilepay 已啟用、且 verify_key 為加密信封卻以本站金鑰解不開
	 * （網站搬遷 / 安全金鑰變動）。屬營收影響（無法開立發票），用 error 級提示。
	 */
	public function maybe_render_verify_key_notice(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( '1' !== (string) get_option( 'ys_ec_smilepay_enabled', '0' ) ) {
			return;
		}

		$provider_class = '\YangSheep\SmilePayEInvoice\Providers\YSSmilePayInvoiceProvider';
		if ( ! class_exists( $provider_class ) ) {
			return;
		}

		$provider = new $provider_class();
		if ( ! method_exists( $provider, 'verify_key_needs_reentry' ) || ! $provider->verify_key_needs_reentry() ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=' . \YangSheep\SmilePayEInvoice\Admin\YSSmilePayAdmin::MENU_SLUG );

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong><br>%s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
			esc_html__( '速買配電子發票：驗證碼無法解密，目前無法開立發票。', 'ys-cart-smilepay-einvoice' ),
			esc_html__( '常見原因為網站搬遷或 WordPress 安全金鑰（SECURE_AUTH_KEY 等）變動，使先前加密儲存的驗證碼無法以目前金鑰還原。請至設定頁重新輸入「速買配驗證碼（Verify_key）」並儲存即可恢復；其餘設定不受影響。', 'ys-cart-smilepay-einvoice' ),
			esc_url( $settings_url ),
			esc_html__( '前往重新輸入驗證碼 →', 'ys-cart-smilepay-einvoice' )
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $manifests
	 * @return array<int,array<string,mixed>>
	 */
	public function register_manifest( array $manifests ): array {
		$manifests[] = self::manifest();

		return $manifests;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function manifest(): array {
		static $manifest = null;

		if ( null === $manifest ) {
			$manifest = require YS_SMILEPAY_DIR . 'manifest.php';
		}

		return $manifest;
	}

	/**
	 * 註冊 SmilePay provider 到 ys-cart invoice registry
	 *
	 * 由 YSInvoiceRegistry::init() 在 boot 時觸發 apply_filters。
	 * 我們不會主動 new provider 一定要 idempotent：YSInvoiceRegistry 內部會以 id 為 key
	 * 處理重複註冊（後到的會覆蓋；但 array_merge 模式下我們的 id 只會出現一次）。
	 *
	 * @param array<string, mixed> $providers id => YSInvoiceProviderInterface 實例
	 * @return array<string, mixed>
	 */
	public function register_provider(): void {
		if ( ! $this->is_invoice_enabled() ) {
			return;
		}

		// 用 ys-cart 原生 action 模式：在 callback 內直接呼叫 ::register()
		// （參照 YSInvoiceRegistry.php:180-182 @example）
		\YangSheep\Ecommerce\Invoice\YSInvoiceRegistry::register( new YSSmilePayInvoiceProvider() );
	}

	/**
	 * 加入 einvoice.smilepay.net 到 host allowlist（ADR-053 §3.6 強制）
	 *
	 * @param array<int, string> $hosts
	 * @return array<int, string>
	 */
	public function register_invoice_file_host( array $hosts ): array {
		if ( ! $this->is_invoice_enabled() ) {
			return $hosts;
		}

		// 同 host、test/prod path 不同 → 只需要一個 host
		if ( ! in_array( 'einvoice.smilepay.net', $hosts, true ) ) {
			$hosts[] = 'einvoice.smilepay.net';
		}
		return $hosts;
	}

	/**
	 * Point provider-card settings links to the SmilePay API credential page.
	 */
	public function filter_invoice_provider_settings_url( string $url, string $provider_id, object $provider ): string {
		unset( $provider );

		if ( YSSmilePayInvoiceProvider::ID !== $provider_id ) {
			return $url;
		}

		return admin_url( 'admin.php?page=ys-provider-smilepay' );
	}

	/**
	 * Add SmilePay's own invoice print/PDF link to the YS CART order invoice rows.
	 *
	 * This stays inside the provider plugin and uses the core invoice file proxy,
	 * so YS CART core does not need an order-detail template change.
	 */
	public function render_admin_order_invoice_print_links(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) || ! $this->is_invoice_enabled() ) {
			return;
		}

		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';
		if ( 'ys-ec-orders' !== $page || 'view' !== $action ) {
			return;
		}

		$rest_root  = esc_url_raw( rest_url( 'ys-ecommerce-headless/v1' ) );
		$rest_nonce = wp_create_nonce( 'wp_rest' );
		$site_root  = esc_url_raw( home_url( '/' ) );
		$order_id   = isset( $_GET['id'] ) ? absint( wp_unslash( (string) $_GET['id'] ) ) : 0;
		$order_key  = '';
		if ( $order_id > 0 && class_exists( '\YangSheep\Ecommerce\Models\YSOrder' ) ) {
			$order = \YangSheep\Ecommerce\Models\YSOrder::find( $order_id );
			if ( $order ) {
				$order_key = \YangSheep\Ecommerce\Models\YSOrder::generate_order_key(
					$order_id,
					(string) ( $order->order_number ?? '' )
				);
			}
		}
		?>
		<script>
		(function () {
			'use strict';

			var REST_ROOT = <?php echo wp_json_encode( $rest_root ); ?>;
			var REST_NONCE = <?php echo wp_json_encode( $rest_nonce ); ?>;
			var SITE_ROOT = <?php echo wp_json_encode( $site_root ); ?>;
			var ORDER_KEY = <?php echo wp_json_encode( $order_key ); ?>;

			function invoiceFileUrl(invoiceId) {
				var url = new URL(SITE_ROOT, window.location.origin);
				url.searchParams.set('rest_route', '/ys-ecommerce-headless/v1/account/invoices/' + invoiceId + '/file');
				if (ORDER_KEY) {
					url.searchParams.set('token', ORDER_KEY);
				}
				return url.toString();
			}

			function addPrintLink(row, invoiceId) {
				if (row.querySelector('.ys-smilepay-admin-invoice-print-link')) {
					return;
				}

				var refreshButton = row.querySelector('.ys-ec-invoice-refresh-btn[data-invoice-id]');
				if (!refreshButton) {
					return;
				}

				var actionsCell = refreshButton.closest('td') || refreshButton.parentNode;
				var link = document.createElement('a');
				link.className = 'button button-small ys-smilepay-admin-invoice-print-link';
				link.href = invoiceFileUrl(invoiceId);
				link.target = '_blank';
				link.rel = 'noopener noreferrer';
				link.textContent = 'SmilePay 列印 / PDF';

				actionsCell.insertBefore(link, actionsCell.firstChild);
				actionsCell.insertBefore(document.createTextNode(' '), link.nextSibling);
			}

			function hydrateInvoiceRow(row) {
				if (row.dataset.smilepayPrintChecked === '1') {
					return;
				}
				row.dataset.smilepayPrintChecked = '1';

				var refreshButton = row.querySelector('.ys-ec-invoice-refresh-btn[data-invoice-id]');
				if (!refreshButton) {
					return;
				}

				var invoiceId = refreshButton.getAttribute('data-invoice-id');
				if (!invoiceId) {
					return;
				}

				fetch(REST_ROOT + '/admin/invoices/' + encodeURIComponent(invoiceId), {
					credentials: 'same-origin',
					headers: {
						'X-WP-Nonce': REST_NONCE
					}
				})
					.then(function (response) {
						if (!response.ok) {
							throw new Error('HTTP ' + response.status);
						}
						return response.json();
					})
					.then(function (json) {
						var data = json && json.data ? json.data : json;
						if (!data || data.provider !== 'smilepay' || data.status !== 'issued' || !data.invoice_number) {
							return;
						}
						addPrintLink(row, invoiceId);
					})
					.catch(function () {
						// Keep the core invoice controls untouched if provider lookup fails.
					});
			}

			function hydrate() {
				document.querySelectorAll('.ys-ec-invoice-admin-table tbody tr').forEach(hydrateInvoiceRow);
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', hydrate);
			} else {
				hydrate();
			}
		})();
		</script>
		<?php
	}

	private function is_invoice_enabled(): bool {
		if ( class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
			return \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::is_method_enabled(
				'invoice',
				YSSmilePayInvoiceProvider::ID,
				self::manifest()
			);
		}

		return '1' === (string) get_option( YSSmilePayInvoiceProvider::OPTION_ENABLED, '0' );
	}

	/**
	 * 取得外掛版本（給其他子系統參照）
	 */
	public function get_version(): string {
		return defined( 'YS_SMILEPAY_VERSION' ) ? YS_SMILEPAY_VERSION : '1.0.1';
	}
}
