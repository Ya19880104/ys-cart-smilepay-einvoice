<?php
/**
 * 速買配 SmilePay 電子發票 — 後台設定頁（ADR-052 規範）
 *
 * 設計原則（嚴格遵守）：
 *   1. Manifest-first — 後台設定頁只由 YS CART provider manifest lifecycle 掛載。
 *   2. 統一 Chrome — render() 必須以 `YSAdminApp::open()` / `YSAdminApp::close()` 包裝，
 *      禁止直接 echo 原生 <div class="wrap">。
 *   3. 統一 REST — 設定儲存與「測試連線」皆走 admin-post（form submit + PRG redirect），
 *      不開自家 REST namespace、不發 wp_ajax_*。
 *   4. 零 admin AJAX — 整頁無 JS form submit、無 wp_ajax_* handler。
 *   5. 統一 CSS — 只使用 ys-cart 既有的 `.ysca-*` primitive class，不寫自家 framework。
 *
 * 設定 keys（option：ys_ec_smilepay_settings，由 YSSmilePayInvoiceProvider 定義）：
 *   - grvc                    (string) 速買配電子發票帳號
 *   - verify_key              (string) 驗證碼（純 SECRET、永不回顯）
 *   - sandbox                 ('1'/'0') 使用測試環境
 *   - auto_issue              (off/auto_processing/auto_completed) 自動開立時機
 *   - enable_member_carrier   ('1'/'0') 速買配會員載具 EJ0113
 *   - enable_phone_carrier    ('1'/'0') 手機條碼載具 3J0002
 *   - enable_cdc_carrier      ('1'/'0') 自然人憑證載具 CQ0001
 *   - enable_donate           ('1'/'0') 捐贈愛心碼
 *   - pos_system_id           (string)  POS System ID（速買配後台識別來源）
 *
 * 啟用 toggle：option `ys_ec_smilepay_enabled` ('1'/'0')。
 *
 * @package YangSheep\SmilePayEInvoice\Admin
 * @since   1.0.0
 */

namespace YangSheep\SmilePayEInvoice\Admin;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Admin\YSAdminApp;
use YangSheep\SmilePayEInvoice\Api\YSSmilePayApiClient;
use YangSheep\SmilePayEInvoice\Providers\YSSmilePayInvoiceProvider;
use YangSheep\SmilePayEInvoice\YSSmilePayPlugin;

/**
 * 速買配電子發票後台設定頁。
 *
 * 對外契約：
 *   - register_admin_post_handlers(): void
 *   - render(): void
 *   - handle_save(): void
 *   - handle_test_connection(): void
 */
final class YSSmilePayAdmin {

	/** 設定 option key。 */
	public const OPTION_SETTINGS = 'ys_ec_smilepay_settings';

	/** 啟用 toggle option key。 */
	public const OPTION_ENABLED = 'ys_ec_smilepay_enabled';

	/** Nonce action（同時用於儲存與測試連線）。 */
	public const NONCE_ACTION = 'ys_ec_save_smilepay_settings';

	/** admin-post action — 儲存設定。 */
	public const ADMIN_POST_ACTION = 'ys_ec_save_smilepay_settings';

	/** admin-post action — 測試連線。 */
	public const TEST_CONNECTION_ACTION = 'ys_ec_test_smilepay_connection';

	/** Submenu slug。 */
	public const MENU_SLUG = 'ys-provider-smilepay';

	/** Capability。 */
	public const CAP = 'manage_options';

	/**
	 * 註冊 admin-post 處理器（儲存設定 + 測試連線）。
	 *
	 * ADR-052 原則 4：所有 form 走 admin-post（form submit + PRG redirect），
	 * 絕對禁止 wp_ajax_*。
	 */
	public static function register_admin_post_handlers(): void {
		add_action( 'admin_post_' . self::ADMIN_POST_ACTION, [ self::class, 'handle_save' ] );
		add_action( 'admin_post_' . self::TEST_CONNECTION_ACTION, [ self::class, 'handle_test_connection' ] );
	}

	/**
	 * 渲染設定頁。
	 *
	 * 嚴格遵守 ADR-052 原則 2：以 YSAdminApp::open()/close() 包裝整個頁面，
	 * 不直接 echo <div class="wrap">。
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足', 'ys-cart-smilepay-einvoice' ) );
		}

		// 取得 provider（若 Provider class 尚未載入，使用 fallback 內建欄位定義避免 fatal）
		$provider = self::resolve_provider();
		$fields   = self::resolve_settings_fields( $provider );
		$settings = self::resolve_settings( $provider );
		$enabled  = self::is_provider_enabled();

		// 解析 PRG redirect 帶回的 notice
		$notice = self::resolve_notice();

		// ADR-052 原則 2：YSAdminApp 包裝
		YSAdminApp::open(
			__( '速買配電子發票（SmilePay）', 'ys-cart-smilepay-einvoice' ),
			__( '電商系統 / 電子發票 / 速買配 SmilePay', 'ys-cart-smilepay-einvoice' )
		);

		include YS_SMILEPAY_DIR . 'templates/admin/settings.php';

		YSAdminApp::close();
	}

	/**
	 * 處理「儲存設定」POST。
	 *
	 * 嚴格流程：cap → nonce → 依 fields 迴圈 sanitize → password 留空保留原值
	 * → update_option → PRG redirect 帶 ?ys_ec_smilepay_saved=1。
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足', 'ys-cart-smilepay-einvoice' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$provider  = self::resolve_provider();
		$fields    = self::resolve_settings_fields( $provider );
		$existing  = self::resolve_settings( $provider );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce 已由 check_admin_referer 驗證
		$raw       = wp_unslash( $_POST );
		// phpcs:enable

		$sanitized = self::sanitize_settings_payload( $fields, $raw, $existing );

		update_option( self::OPTION_SETTINGS, $sanitized, true );

		// 獨立的啟用 toggle option
		$enabled = ! empty( $raw[ self::OPTION_ENABLED ] ) ? '1' : '0';
		update_option( self::OPTION_ENABLED, $enabled, true );
		self::sync_provider_lifecycle( '1' === $enabled );

		// PRG redirect
		$redirect = add_query_arg(
			[
				'page'                 => self::MENU_SLUG,
				'ys_ec_smilepay_saved' => '1',
			],
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * 處理「測試連線」POST。
	 *
	 * 用當前儲存的 grvc + verify_key + sandbox（強制 sandbox=1，無論設定值為何）
	 * 呼叫 SmilePay API 嘗試開立一筆 NT$1 假發票，回傳結果以 PRG redirect 帶回頁面。
	 *
	 * 強制 sandbox：即便設定為 production，測試連線也只打 api_test 端點以避免產生真實發票。
	 */
	public static function handle_test_connection(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( '權限不足', 'ys-cart-smilepay-einvoice' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$result_code    = 'fail';
		$result_message = '';

		// API client class 是否就緒（backend agent 必須提供）
		if ( ! class_exists( YSSmilePayApiClient::class ) ) {
			$result_code    = 'fail';
			$result_message = __( 'API client 尚未載入，請先確認 backend agent 已提供 YSSmilePayApiClient。', 'ys-cart-smilepay-einvoice' );
		} else {
			$provider = self::resolve_provider();
			$settings = self::resolve_settings( $provider );

			$grvc       = trim( (string) ( $settings['grvc'] ?? '' ) );
			$verify_key = trim( (string) ( $settings['verify_key'] ?? '' ) );

			if ( '' === $grvc || '' === $verify_key ) {
				$result_code    = 'fail';
				$result_message = __( '請先填入「電子發票帳號 (Grvc)」與「驗證碼 (Verify_key)」並儲存設定後再測試。', 'ys-cart-smilepay-einvoice' );
			} else {
				try {
					// 測試連線一律強制 sandbox 模式，避免產生真實發票
					$client  = new YSSmilePayApiClient( $grvc, $verify_key, true );
					$payload = self::build_test_payload( $grvc, $verify_key, (string) ( $settings['pos_system_id'] ?? 'YS-CART' ) );

					$response = $client->issue( $payload );

					$status = isset( $response['Status'] ) ? (string) $response['Status'] : '';
					$desc   = isset( $response['Desc'] ) ? (string) $response['Desc'] : '';

					if ( '1' === $status || '0' === $status ) {
						// SmilePay 慣例：1 = 開立成功（B2C/B2B），0 = 成功（部分版本回傳）
						$result_code    = 'success';
						$result_message = sprintf(
							/* translators: %s: SmilePay 回應描述 */
							__( '測試連線成功。SmilePay 回應：%s', 'ys-cart-smilepay-einvoice' ),
							'' !== $desc ? $desc : __( '開立成功', 'ys-cart-smilepay-einvoice' )
						);
					} else {
						$result_code    = 'fail';
						$result_message = sprintf(
							/* translators: 1: 錯誤代碼 2: 錯誤描述 */
							__( '測試連線失敗（代碼 %1$s）：%2$s', 'ys-cart-smilepay-einvoice' ),
							'' !== $status ? $status : '?',
							'' !== $desc ? $desc : __( '未知錯誤', 'ys-cart-smilepay-einvoice' )
						);
					}
				} catch ( \Throwable $e ) {
					$result_code    = 'fail';
					$result_message = sprintf(
						/* translators: %s: 例外訊息 */
						__( '測試連線發生例外：%s', 'ys-cart-smilepay-einvoice' ),
						$e->getMessage()
					);
				}
			}
		}

		$redirect = add_query_arg(
			[
				'page'                          => self::MENU_SLUG,
				'ys_ec_smilepay_test_result'    => $result_code,
				'ys_ec_smilepay_test_message'   => rawurlencode( $result_message ),
			],
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	// ────────────────────────────────────────────────────────────────────
	// Internal helpers
	// ────────────────────────────────────────────────────────────────────

	/**
	 * 取得 Provider 實例。
	 *
	 * 若 Provider class 尚未由 backend agent 提供，回傳 null；
	 * 上游 resolve_settings_fields / resolve_settings 會 fallback 至內建定義。
	 *
	 * @return YSSmilePayInvoiceProvider|null
	 */
	private static function resolve_provider() {
		if ( ! class_exists( YSSmilePayInvoiceProvider::class ) ) {
			return null;
		}
		return new YSSmilePayInvoiceProvider();
	}

	private static function is_provider_enabled(): bool {
		if ( class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
			return \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::is_provider_enabled( 'ys_smilepay', YSSmilePayPlugin::manifest() );
		}

		return '1' === (string) get_option( self::OPTION_ENABLED, '0' );
	}

	private static function sync_provider_lifecycle( bool $enabled ): void {
		if ( ! class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
			return;
		}

		\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::set_provider_enabled( 'ys_smilepay', $enabled, YSSmilePayPlugin::manifest() );
	}

	/**
	 * 取得設定欄位定義。
	 *
	 * 優先呼叫 Provider 的 get_settings_fields()，若 Provider 不可用則 fallback
	 * 至本檔內建的 hard-coded 定義（與設計文件 §4.3.4 完全一致），讓 admin 頁
	 * 在 backend 還沒 ship 前即可獨立 render，避免 Frontend / Backend 解耦失敗。
	 *
	 * @param YSSmilePayInvoiceProvider|null $provider
	 * @return array<int, array<string, mixed>>
	 */
	private static function resolve_settings_fields( $provider ): array {
		if ( null !== $provider && method_exists( $provider, 'get_settings_fields' ) ) {
			$fields = $provider->get_settings_fields();
			if ( is_array( $fields ) && ! empty( $fields ) ) {
				return $fields;
			}
		}
		return self::fallback_settings_fields();
	}

	/**
	 * 取得目前儲存的設定（含預設值合併）。
	 *
	 * @param YSSmilePayInvoiceProvider|null $provider
	 * @return array<string, mixed>
	 */
	private static function resolve_settings( $provider ): array {
		if ( null !== $provider && method_exists( $provider, 'get_settings' ) ) {
			$settings = $provider->get_settings();
			if ( is_array( $settings ) ) {
				return $settings;
			}
		}

		// Fallback：直接讀 option，合併欄位預設值
		$stored = get_option( self::OPTION_SETTINGS, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		$defaults = [];
		foreach ( self::fallback_settings_fields() as $field ) {
			$defaults[ $field['key'] ] = $field['default'] ?? '';
		}

		return array_merge( $defaults, $stored );
	}

	/**
	 * Fallback 欄位定義（與設計文件 §4.3.4 對齊）。
	 *
	 * 此 fallback 僅在 Provider class 尚未載入時使用；正式啟用後應走 Provider。
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function fallback_settings_fields(): array {
		return [
			[
				'key'         => 'grvc',
				'label'       => __( '電子發票帳號 (Grvc)', 'ys-cart-smilepay-einvoice' ),
				'type'        => 'text',
				'required'    => true,
				'placeholder' => 'SEI1000034',
				'description' => __( '由速買配提供。試用帳號：SEI1000034', 'ys-cart-smilepay-einvoice' ),
				'default'     => '',
			],
			[
				'key'         => 'verify_key',
				'label'       => __( '驗證碼 (Verify_key)', 'ys-cart-smilepay-einvoice' ),
				'type'        => 'password',
				'required'    => true,
				'placeholder' => '',
				'description' => __( '由速買配提供，請於速買配後台「API 設定」取得，並請參閱外掛 README 取得試用憑證資訊。', 'ys-cart-smilepay-einvoice' ),
				'default'     => '',
			],
			[
				'key'         => 'sandbox',
				'label'       => __( '使用測試環境', 'ys-cart-smilepay-einvoice' ),
				'type'        => 'toggle',
				'description' => __( '勾選後 API 走 api_test/* 端點，不會產生真實發票。', 'ys-cart-smilepay-einvoice' ),
				'default'     => '1',
			],
			[
				'key'         => 'auto_issue',
				'label'       => __( '自動開立時機', 'ys-cart-smilepay-einvoice' ),
				'type'        => 'select',
				'options'     => [
					'off'             => __( '關閉（僅手動開立）', 'ys-cart-smilepay-einvoice' ),
					'auto_processing' => __( '訂單轉為「處理中」時自動開立', 'ys-cart-smilepay-einvoice' ),
					'auto_completed'  => __( '訂單轉為「已完成」時自動開立', 'ys-cart-smilepay-einvoice' ),
				],
				'description' => __( '在指定狀態轉換時自動呼叫 SmilePay API 開立發票。', 'ys-cart-smilepay-einvoice' ),
				'default'     => 'auto_processing',
			],
			[
				'key'         => 'enable_member_carrier',
				'label'       => __( '啟用速買配會員載具 (EJ0113)', 'ys-cart-smilepay-einvoice' ),
				'type'        => 'toggle',
				'description' => __( '消費者結帳時可選擇速買配會員載具。啟用前必須先在速買配後台啟用對應載具功能。', 'ys-cart-smilepay-einvoice' ),
				'default'     => '1',
			],
			[
				'key'         => 'enable_phone_carrier',
				'label'       => __( '啟用手機條碼載具 (3J0002)', 'ys-cart-smilepay-einvoice' ),
				'type'        => 'toggle',
				'description' => __( '啟用前必須先在速買配後台啟用對應載具功能。', 'ys-cart-smilepay-einvoice' ),
				'default'     => '1',
			],
			[
				'key'         => 'enable_cdc_carrier',
				'label'       => __( '啟用自然人憑證載具 (CQ0001)', 'ys-cart-smilepay-einvoice' ),
				'type'        => 'toggle',
				'description' => __( '啟用前必須先在速買配後台啟用對應載具功能。', 'ys-cart-smilepay-einvoice' ),
				'default'     => '1',
			],
			[
				'key'         => 'enable_donate',
				'label'       => __( '啟用捐贈愛心碼', 'ys-cart-smilepay-einvoice' ),
				'type'        => 'toggle',
				'description' => __( '消費者結帳時可選擇捐贈，輸入愛心碼後直接捐出整筆發票。', 'ys-cart-smilepay-einvoice' ),
				'default'     => '1',
			],
			[
				'key'         => 'pos_system_id',
				'label'       => __( 'POS System ID', 'ys-cart-smilepay-einvoice' ),
				'type'        => 'text',
				'placeholder' => 'YS-CART',
				'description' => __( '營業人自定義系統代號，用於速買配後台識別來源。', 'ys-cart-smilepay-einvoice' ),
				'default'     => 'YS-CART',
			],
		];
	}

	/**
	 * Sanitize 設定 payload。
	 *
	 * 規則對齊 Amego 模式：
	 *   - toggle    → '1' / '0'
	 *   - select    → 必須在 options keys 中，否則 fallback default
	 *   - password  → 留空保留原值（不洩漏 / 不覆寫）
	 *   - textarea  → sanitize_textarea_field
	 *   - text      → sanitize_text_field
	 *
	 * @param array<int, array<string, mixed>> $fields
	 * @param array<string, mixed>             $raw       wp_unslash 後的 $_POST。
	 * @param array<string, mixed>             $existing  目前已儲存的設定。
	 * @return array<string, mixed>
	 */
	private static function sanitize_settings_payload( array $fields, array $raw, array $existing ): array {
		$sanitized = [];

		foreach ( $fields as $field ) {
			$key  = (string) ( $field['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			$type = (string) ( $field['type'] ?? 'text' );
			$val  = $raw[ $key ] ?? '';

			switch ( $type ) {
				case 'toggle':
					$sanitized[ $key ] = ! empty( $val ) ? '1' : '0';
					break;

				case 'select':
					$candidate    = is_scalar( $val ) ? sanitize_text_field( (string) $val ) : '';
					$allowed_keys = array_keys( (array) ( $field['options'] ?? [] ) );
					$default      = (string) ( $field['default'] ?? '' );
					$sanitized[ $key ] = ( ! empty( $allowed_keys ) && in_array( $candidate, $allowed_keys, true ) )
						? $candidate
						: $default;
					break;

				case 'password':
					$candidate = is_scalar( $val ) ? sanitize_text_field( (string) $val ) : '';
					if ( '' === $candidate ) {
						$sanitized[ $key ] = (string) ( $existing[ $key ] ?? '' );
					} else {
						$sanitized[ $key ] = $candidate;
					}
					break;

				case 'textarea':
					$sanitized[ $key ] = is_scalar( $val )
						? sanitize_textarea_field( (string) $val )
						: '';
					break;

				case 'text':
				default:
					$sanitized[ $key ] = is_scalar( $val )
						? sanitize_text_field( (string) $val )
						: '';
					break;
			}
		}

		return $sanitized;
	}

	/**
	 * 解析 PRG redirect 帶回的 notice（顯示「已儲存」/「測試成功」/「測試失敗」）。
	 *
	 * @return array{type: string, message: string}|null
	 */
	private static function resolve_notice(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只讀 query string 做 UI notice，無副作用
		if ( ! empty( $_GET['ys_ec_smilepay_saved'] ) ) {
			return [
				'type'    => 'success',
				'message' => __( '設定已儲存。', 'ys-cart-smilepay-einvoice' ),
			];
		}

		if ( ! empty( $_GET['ys_ec_smilepay_test_result'] ) ) {
			$result = sanitize_key( wp_unslash( (string) $_GET['ys_ec_smilepay_test_result'] ) );
			$msg    = isset( $_GET['ys_ec_smilepay_test_message'] )
				? sanitize_text_field( rawurldecode( wp_unslash( (string) $_GET['ys_ec_smilepay_test_message'] ) ) )
				: '';

			if ( 'success' === $result ) {
				return [
					'type'    => 'success',
					'message' => '' !== $msg ? $msg : __( '測試連線成功。', 'ys-cart-smilepay-einvoice' ),
				];
			}
			return [
				'type'    => 'error',
				'message' => '' !== $msg ? $msg : __( '測試連線失敗。', 'ys-cart-smilepay-einvoice' ),
			];
		}
		// phpcs:enable

		return null;
	}

	/**
	 * 建立「測試連線」用的 minimal payload（NT$1 假發票）。
	 *
	 * 注意：本檔不直接打 SmilePay API（render() 階段絕對不發 HTTP 請求）；
	 * 此 payload 僅在 handle_test_connection() 觸發 admin-post 時建構，
	 * 由 YSSmilePayApiClient::issue() 負責實際 HTTP 通訊與簽章。
	 *
	 * @param string $grvc          速買配電子發票帳號
	 * @param string $verify_key    驗證碼
	 * @param string $pos_system_id POS System ID
	 * @return array<string, mixed>
	 */
	private static function build_test_payload( string $grvc, string $verify_key, string $pos_system_id ): array {
		$now = current_time( 'mysql' );
		// 解析日期/時間（current_time 回傳 'YYYY-MM-DD HH:MM:SS'）
		$dt          = is_string( $now ) ? strtotime( $now ) : time();
		$invoice_dt  = false !== $dt ? $dt : time();

		$order_number = 'YSCART-TEST-' . wp_generate_password( 8, false, false );

		return [
			'Grvc'         => $grvc,
			'Verify_key'   => $verify_key,
			'InvoiceDate'  => gmdate( 'Y/m/d', $invoice_dt + ( (int) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) ),
			'InvoiceTime'  => gmdate( 'H:i:s', $invoice_dt + ( (int) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) ),
			'Intype'       => '07',
			'TaxType'      => '1',
			'DonateMark'   => '0',
			'Description'  => __( 'YS CART 測試連線', 'ys-cart-smilepay-einvoice' ),
			'Quantity'     => '1',
			'UnitPrice'    => '1',
			'Amount'       => '1',
			'AllAmount'    => '1',
			'Email'        => (string) get_option( 'admin_email', '' ),
			'orderid'      => $order_number,
			'PosBarCode'   => '' !== $pos_system_id ? $pos_system_id : 'YS-CART',
			// Flag 給 API client：這是測試連線，可選擇 dry-run / 真打皆可（由 backend agent 決定）
			'_test_only'   => true,
		];
	}
}
