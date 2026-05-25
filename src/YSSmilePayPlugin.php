<?php
/**
 * YS CART 速買配電子發票 — Singleton bootstrap orchestrator
 *
 * 仿 ys-cart `YSEcommerce` 與 ys-cart-affiliate `YSAffiliateSuite` 架構，本類別負責：
 *   1. 載入 i18n（init hook，priority 0，比預設早，避免 sub-system 字串先讀）
 *   2. 註冊 SmilePay Invoice Provider 到 YSInvoiceRegistry
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

		// 註冊 SmilePay provider 到 YSInvoiceRegistry。
		//
		// ys-cart 的 YSInvoiceRegistry::ensure_third_party_loaded() 同時觸發：
		//   - do_action('ys_ec_register_invoice_providers')        (line 184)
		//   - apply_filters('ys_ec_register_invoice_providers',[]) (line 195)
		//
		// 採用 ys-cart 原生 action 模式（其文件 @example），避免 add_filter callback 在
		// do_action 路徑下被傳入意外型別參數造成 TypeError。
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

		// 後台子選單與設定儲存 — 僅當 admin class 已被 frontend agent 提交時掛 hook
		// （避免 fatal：類別不存在時不可呼叫 ::register_menu / ::handle_save）
		if ( class_exists( '\YangSheep\SmilePayEInvoice\Admin\YSSmilePayAdmin' ) ) {
			// 子選單：用 ys_ec_admin_payment_menus hook（v2.14.0+ 開放給第三方註冊），
			// 第一個參數是金物流 parent slug（'ys-cart'），第二個是 cap。
			// 雖然語意上 SmilePay 是「電子發票」，但 ys-cart 目前只開放這一個 hook 給 add-on
			// 註冊到頂層選單系統。frontend agent 的 register_menu 簽名遵循該契約。
			add_action(
				'ys_ec_admin_payment_menus',
				[ '\YangSheep\SmilePayEInvoice\Admin\YSSmilePayAdmin', 'register_menu' ],
				10,
				2
			);

			// admin_post handler：設定表單 POST 儲存（REST-first 規範禁止 admin-ajax）
			add_action(
				'admin_post_ys_ec_save_smilepay_settings',
				[ '\YangSheep\SmilePayEInvoice\Admin\YSSmilePayAdmin', 'handle_save' ]
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
		// 同 host、test/prod path 不同 → 只需要一個 host
		if ( ! in_array( 'einvoice.smilepay.net', $hosts, true ) ) {
			$hosts[] = 'einvoice.smilepay.net';
		}
		return $hosts;
	}

	/**
	 * 取得外掛版本（給其他子系統參照）
	 */
	public function get_version(): string {
		return defined( 'YS_SMILEPAY_VERSION' ) ? YS_SMILEPAY_VERSION : '1.0.0';
	}
}
