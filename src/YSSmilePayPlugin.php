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
		}
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
