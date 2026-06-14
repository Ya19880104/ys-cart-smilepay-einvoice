<?php
/**
 * SmilePay（速買配）Invoice Provider 實作
 *
 * 實作 ys-cart 的 YSInvoiceProviderInterface 介面（含 ADR-053 v2.50.0 新增的
 * get_customer_invoice_url），讓商家可以在多 provider 並存環境下用 SmilePay 開立 /
 * 作廢 / 查詢 / 列印發票。
 *
 * 設定 keys（option：ys_ec_smilepay_settings）：
 *   - grvc                  (string)   電子發票帳號（試用：SEI1000034）
 *   - verify_key            (string)   驗證碼（SECRET；測試憑證請參閱 README.md）
 *   - sandbox               ('1'/'0')  是否使用測試端點（api_test/*）
 *   - auto_issue            (string)   off / auto_processing / auto_completed
 *   - enable_member_carrier ('1'/'0')  顯示速買配會員載具選項（EJ0113）
 *   - enable_phone_carrier  ('1'/'0')  顯示手機條碼（3J0002）
 *   - enable_cdc_carrier    ('1'/'0')  顯示自然人憑證（CQ0001）
 *   - enable_donate         ('1'/'0')  顯示捐贈選項
 *   - pos_system_id         (string)   SmilePay 後台識別來源（預設 'YSCART'）
 *
 * 啟用 toggle：option `ys_ec_smilepay_enabled` ('1'/'0')
 *
 * 載具類型 mapping（ys-cart 主框架 ←→ SmilePay）：
 *   - mobile → CarrierType=3J0002 + CarrierID/CarrierID2 同值
 *   - cdc    → CarrierType=CQ0001 + CarrierID/CarrierID2 同值
 *   - member → CarrierType=EJ0113（CarrierID 可空，速買配用 Email/Phone 自動建立）
 *   - donate → 不傳 CarrierType，傳 DonateMark=1 + LoveKey
 *   - 其他   → DonateMark=0
 *
 * B2B / B2C：
 *   - buyer_type='company' + buyer_tax_id 非空 → 加 Buyer_id + CompanyName +
 *     Einvoice_Type=B2B + UnitTAX=Y，且不傳 CarrierType / DonateMark / LoveKey
 *   - 其餘 → B2C，傳 Name=$buyer_name + DonateMark=0|1
 *
 * @package YangSheep\SmilePayEInvoice\Providers
 * @since   1.0.0
 */

namespace YangSheep\SmilePayEInvoice\Providers;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Invoice\YSInvoiceProviderInterface;
use YangSheep\Ecommerce\Models\YSInvoice;
use YangSheep\Ecommerce\Utils\YSCrypto;
use YangSheep\SmilePayEInvoice\Api\YSSmilePayApiClient;
use YangSheep\SmilePayEInvoice\Api\YSSmilePayApiResponse;
use YangSheep\SmilePayEInvoice\Support\YSSmilePayErrorCodes;

class YSSmilePayInvoiceProvider implements YSInvoiceProviderInterface {

	/** Provider ID */
	public const ID = 'smilepay';

	/** Settings option key */
	public const OPTION_SETTINGS = 'ys_ec_smilepay_settings';

	/** Enabled toggle option key */
	public const OPTION_ENABLED = 'ys_ec_smilepay_enabled';

	/**
	 * 取得 provider 唯一識別碼
	 */
	public function get_id(): string {
		return self::ID;
	}

	/**
	 * 取得 provider 顯示名稱
	 */
	public function get_title(): string {
		return '速買配 SmilePay 電子發票';
	}

	/**
	 * 是否已啟用
	 *
	 * 除了 toggle option 外，也檢查必要設定（Grvc + Verify_key）是否齊全。
	 * 沒填的 provider 不能被 YSInvoiceRegistry::get_active() 誤選。
	 */
	public function is_enabled(): bool {
		if ( '1' !== (string) get_option( self::OPTION_ENABLED, '0' ) ) {
			return false;
		}

		$settings   = $this->get_settings();
		$grvc       = trim( (string) ( $settings['grvc'] ?? '' ) );
		$verify_key = trim( (string) ( $settings['verify_key'] ?? '' ) );

		return '' !== $grvc && '' !== $verify_key;
	}

	/**
	 * 後台設定欄位定義（給 Admin form render 用）
	 *
	 * 結構與 YSAmegoInvoiceProvider::get_settings_fields() 一致，讓 admin
	 * 的通用 form render helper 可重用。
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings_fields(): array {
		return [
			[
				'key'         => 'grvc',
				'label'       => 'SmilePay 電子發票帳號（Grvc）',
				'type'        => 'text',
				'required'    => true,
				'placeholder' => 'SEI1000034',
				'description' => '由速買配提供。試用帳號：SEI1000034',
				'default'     => '',
			],
			[
				'key'         => 'verify_key',
				'label'       => '驗證碼（Verify_key）',
				'type'        => 'password',
				'required'    => true,
				'placeholder' => '請輸入 SmilePay 驗證碼',
				'description' => '由速買配後台「電子發票」設定取得；測試憑證請參閱外掛 README.md。',
				'default'     => '',
			],
			[
				'key'         => 'sandbox',
				'label'       => '測試模式',
				'type'        => 'toggle',
				'required'    => false,
				'description' => '啟用後使用 SmilePay 測試端點（api_test/*），不會產生真實發票。',
				'default'     => '1',
			],
			[
				'key'         => 'auto_issue',
				'label'       => '自動開立時機',
				'type'        => 'select',
				'required'    => false,
				'options'     => [
					'off'             => '關閉（僅後台手動開立）',
					'auto_processing' => '訂單轉為「處理中」時自動開立',
					'auto_completed'  => '訂單轉為「已完成」時自動開立',
				],
				'description' => '若 ys-cart 主框架已有發票自動開立設定，將以主框架為準。',
				'default'     => 'auto_processing',
			],
			[
				'key'         => 'enable_member_carrier',
				'label'       => '啟用速買配會員載具（EJ0113）',
				'type'        => 'toggle',
				'required'    => false,
				'description' => '結帳時消費者可選擇此載具；SmilePay 會用 Email / Phone 自動建立載具。',
				'default'     => '1',
			],
			[
				'key'         => 'enable_phone_carrier',
				'label'       => '啟用手機條碼（3J0002）',
				'type'        => 'toggle',
				'required'    => false,
				'description' => '結帳時消費者可填入手機條碼（/ABCD123 格式）。',
				'default'     => '1',
			],
			[
				'key'         => 'enable_cdc_carrier',
				'label'       => '啟用自然人憑證（CQ0001）',
				'type'        => 'toggle',
				'required'    => false,
				'description' => '結帳時消費者可填入自然人憑證（2 英文 + 14 數字）。',
				'default'     => '1',
			],
			[
				'key'         => 'enable_donate',
				'label'       => '啟用捐贈愛心碼',
				'type'        => 'toggle',
				'required'    => false,
				'description' => '結帳時消費者可選擇將發票捐贈給社福團體。',
				'default'     => '1',
			],
			[
				'key'         => 'pos_system_id',
				'label'       => 'POS System ID',
				'type'        => 'text',
				'required'    => false,
				'placeholder' => 'YSCART',
				'description' => '營業人自定義系統代號，用於 SmilePay 後台識別來源（20 字元內，英數字）。',
				'default'     => 'YSCART',
			],
		];
	}

	/**
	 * 取得載具類型清單
	 *
	 * 鍵值必須對齊 ys-cart 主框架的 carrier_type 名稱（mobile / cdc / member / donate），
	 * 不可自創。標籤顯示給結帳頁 / 後台下拉用。
	 *
	 * 動態依設定篩選：若 enable_xxx_carrier='0' 則該載具不出現在清單中。
	 *
	 * @return array<string, string>
	 */
	public function get_carrier_types(): array {
		$settings = $this->get_settings();
		$out      = [];

		if ( '1' === (string) ( $settings['enable_phone_carrier'] ?? '1' ) ) {
			$out['mobile'] = '手機條碼';
		}
		if ( '1' === (string) ( $settings['enable_cdc_carrier'] ?? '1' ) ) {
			$out['cdc'] = '自然人憑證';
		}
		if ( '1' === (string) ( $settings['enable_member_carrier'] ?? '1' ) ) {
			$out['member'] = '速買配會員載具';
		}
		if ( '1' === (string) ( $settings['enable_donate'] ?? '1' ) ) {
			$out['donate'] = '捐贈';
		}

		return $out;
	}

	/**
	 * 開立發票
	 *
	 * 完整流程：
	 *   1. 建立 API client（檢查必要設定齊全）
	 *   2. 把 invoice_data 轉成 SmilePay POST 欄位（build_payload）
	 *   3. 呼叫 API → 取 XML response
	 *   4. 正規化回傳結構
	 *
	 * @param array<string, mixed> $invoice_data 由 YSInvoiceManager 提供（介面 docblock 詳列 keys）
	 * @return array{success:bool, invoice_number:string, random_code:string, raw:array, error:?string}
	 */
	public function issue_invoice( array $invoice_data ): array {
		$client = $this->build_client();
		if ( null === $client ) {
			return [
				'success'        => false,
				'invoice_number' => '',
				'random_code'    => '',
				'raw'            => [],
				'error'          => 'SmilePay 未設定 Grvc 或 Verify_key，請先至後台填寫。',
			];
		}

		try {
			$payload = $this->build_payload( $invoice_data );
		} catch ( \Throwable $e ) {
			return [
				'success'        => false,
				'invoice_number' => '',
				'random_code'    => '',
				'raw'            => [],
				'error'          => 'SmilePay payload 組裝失敗：' . $e->getMessage(),
			];
		}

		$response = $client->issue( $payload );
		return $this->normalize_issue_response( $response );
	}

	/**
	 * 作廢發票
	 *
	 * SmilePay 作廢需要 InvoiceDate（YYYY/MM/DD）。從 ys_ec_invoices 表的 issued_at
	 * 撈取；若 caller 沒帶（極少數情境），取當下時間台北時區作為 best-effort。
	 *
	 * @param string $invoice_number 發票號碼（10 碼）
	 * @param string $reason         作廢原因
	 * @return array{success:bool, raw:array, error:?string}
	 */
	public function void_invoice( string $invoice_number, string $reason = '' ): array {
		$client = $this->build_client();
		if ( null === $client ) {
			return [
				'success' => false,
				'raw'     => [],
				'error'   => 'SmilePay 未設定 Grvc 或 Verify_key，請先至後台填寫。',
			];
		}

		$invoice_number = trim( $invoice_number );
		if ( '' === $invoice_number ) {
			return [
				'success' => false,
				'raw'     => [],
				'error'   => '發票號碼為空，無法作廢。',
			];
		}

		// 從 ys_ec_invoices 撈 issued_at（HPOS 相容：不用 post_meta）
		$invoice_date = $this->fetch_invoice_date_from_table( $invoice_number );

		$cancel_reason = '' !== trim( $reason ) ? $reason : '商家後台操作作廢';

		$response = $client->void( $invoice_number, $invoice_date, $cancel_reason );

		if ( $response->success() ) {
			return [
				'success' => true,
				'raw'     => $response->to_array(),
				'error'   => null,
			];
		}

		return [
			'success' => false,
			'raw'     => $response->to_array(),
			'error'   => YSSmilePayErrorCodes::translate( $response->status(), $response->desc() ),
		];
	}

	/**
	 * 查詢發票狀態
	 *
	 * SmilePay 沒有獨立「查發票狀態」API，直接從 ys-cart 主表 ys_ec_invoices 讀。
	 * （HPOS 相容：不用 get_post_meta；用 $wpdb）
	 *
	 * @param string $invoice_number
	 * @return array{success:bool, status:string, raw:array, error:?string}
	 */
	public function query_invoice( string $invoice_number ): array {
		global $wpdb;

		$invoice_number = trim( $invoice_number );
		if ( '' === $invoice_number ) {
			return [
				'success' => false,
				'status'  => 'unknown',
				'raw'     => [],
				'error'   => '發票號碼為空。',
			];
		}

		if ( ! defined( 'YS_ECOMMERCE_TABLE_PREFIX' ) ) {
			return [
				'success' => false,
				'status'  => 'unknown',
				'raw'     => [],
				'error'   => 'ys-cart 表前綴未定義，無法查詢。',
			];
		}

		$table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'invoices';

		// SECURITY: $invoice_number 已 trim，且 $wpdb->prepare 會 escape
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT invoice_number, status, issued_at, voided_at, random_code, provider FROM {$table} WHERE invoice_number = %s AND provider = %s LIMIT 1",
				$invoice_number,
				self::ID
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return [
				'success' => false,
				'status'  => 'unknown',
				'raw'     => [],
				'error'   => '查無此發票（' . $invoice_number . '）。',
			];
		}

		// status 正規化（ys_ec_invoices 的 status 可能是 'issued'/'voided'/'pending'/'failed'）
		$status = (string) ( $row['status'] ?? 'unknown' );
		if ( ! in_array( $status, [ 'issued', 'voided', 'pending', 'failed', 'unknown' ], true ) ) {
			$status = 'unknown';
		}

		return [
			'success' => true,
			'status'  => $status,
			'raw'     => $row,
			'error'   => null,
		];
	}

	/**
	 * 取得可供買家檢視 / 列印的發票 URL（ADR-053 v2.50.0 新增）
	 *
	 * SmilePay 的「列印 URL」不是 API call、不會即時產生短期 token，而是靜態組成的
	 * InvoiceDetails.php?Grvc=...&Verify_key=...&InNumber=...&InvoiceDate=...&RaNumber=...
	 *
	 * 必要 context keys（缺一不可）：
	 *   - invoice_date：YYYY-MM-DD 或 YYYY/MM/DD（會 normalize 為 YYYY/MM/DD）
	 *   - random_code ：B2C 隨機碼或 B2B 統編
	 *
	 * 若 context 缺欄位 → fail-soft 回 success=false（不丟例外）。
	 *
	 * @param string               $invoice_number 發票號碼（10 碼）
	 * @param array<string, mixed> $context        補充欄位
	 * @return array{success:bool, file_url:string, raw:?array, error:?string}
	 */
	public function get_customer_invoice_url( string $invoice_number, array $context = [] ): array {
		$invoice_number = trim( $invoice_number );
		if ( '' === $invoice_number ) {
			return [
				'success'  => false,
				'file_url' => '',
				'raw'      => null,
				'error'    => 'SmilePay print URL requires invoice_number, invoice_date, random_code.',
			];
		}

		$invoice_date = trim( (string) ( $context['invoice_date'] ?? '' ) );
		$random_code  = trim( (string) ( $context['random_code'] ?? '' ) );

		// 日期 fallback：若 caller 沒提供，從 ys_ec_invoices 撈
		if ( '' === $invoice_date ) {
			$invoice_date = $this->fetch_invoice_date_from_table( $invoice_number );
		}

		// random_code fallback：從 ys_ec_invoices 撈
		if ( '' === $random_code ) {
			$random_code = $this->fetch_random_code_from_table( $invoice_number );
		}

		if ( '' === $invoice_date || '' === $random_code ) {
			return [
				'success'  => false,
				'file_url' => '',
				'raw'      => null,
				'error'    => 'SmilePay print URL requires invoice_number, invoice_date, random_code.',
			];
		}

		$client = $this->build_client();
		if ( null === $client ) {
			return [
				'success'  => false,
				'file_url' => '',
				'raw'      => null,
				'error'    => 'SmilePay Grvc / Verify_key is not configured.',
			];
		}

		// normalize 日期格式：YYYY-MM-DD → YYYY/MM/DD（取前 10 字元，去 timezone）
		$normalized_date = str_replace( '-', '/', substr( $invoice_date, 0, 10 ) );

		$url = '';

		return [
			'success'  => false,
			'file_url' => $url,
			'raw'      => null, // 非 API call，無 raw payload
			'error'    => 'SmilePay invoice files are served through the secure server-side proxy.',
		];
	}

	/**
	 * 取得設定（含預設值）
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		$stored = get_option( self::OPTION_SETTINGS, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		$defaults = [];
		foreach ( $this->get_settings_fields() as $field ) {
			$defaults[ $field['key'] ] = $field['default'] ?? '';
		}

		$settings = array_merge( $defaults, $stored );
		if ( isset( $settings['verify_key'] ) ) {
			$settings['verify_key'] = $this->decrypt_verify_key( (string) $settings['verify_key'] );
		}

		return $settings;
	}

	/**
	 * Fetch the customer invoice print/PDF file server-side.
	 *
	 * @param string               $invoice_number Invoice number.
	 * @param array<string, mixed> $context        Invoice context.
	 * @return array{success:bool, body:string, content_type:string, filename:string, raw:array<string,mixed>, error:?string}
	 */
	public function stream_customer_invoice_file( string $invoice_number, array $context = [] ): array {
		$resolved = $this->resolve_print_context( $invoice_number, $context );
		if ( empty( $resolved['success'] ) ) {
			return [
				'success'      => false,
				'body'         => '',
				'content_type' => '',
				'filename'     => '',
				'raw'          => [],
				'error'        => (string) ( $resolved['error'] ?? 'SmilePay print context is incomplete.' ),
			];
		}

		$client = $this->build_client();
		if ( null === $client ) {
			return [
				'success'      => false,
				'body'         => '',
				'content_type' => '',
				'filename'     => '',
				'raw'          => [],
				'error'        => 'SmilePay Grvc / Verify_key is not configured.',
			];
		}

		return $client->fetch_print_file(
			(string) $resolved['invoice_number'],
			(string) $resolved['invoice_date'],
			(string) $resolved['random_code']
		);
	}

	/**
	 * Resolve invoice print context from REST context or the invoice table.
	 *
	 * @param string               $invoice_number Invoice number.
	 * @param array<string, mixed> $context        Invoice context.
	 * @return array{success:bool, invoice_number:string, invoice_date:string, random_code:string, error:?string}
	 */
	private function resolve_print_context( string $invoice_number, array $context = [] ): array {
		$invoice_number = trim( $invoice_number );
		if ( '' === $invoice_number ) {
			return [
				'success'        => false,
				'invoice_number' => '',
				'invoice_date'   => '',
				'random_code'    => '',
				'error'          => 'SmilePay print requires invoice_number.',
			];
		}

		$invoice_date = trim( (string) ( $context['invoice_date'] ?? '' ) );
		$random_code  = trim( (string) ( $context['random_code'] ?? '' ) );

		if ( '' === $invoice_date ) {
			$invoice_date = $this->fetch_invoice_date_from_table( $invoice_number );
		}

		if ( '' === $random_code ) {
			$random_code = $this->fetch_random_code_from_table( $invoice_number );
		}

		if ( '' === $invoice_date || '' === $random_code ) {
			return [
				'success'        => false,
				'invoice_number' => $invoice_number,
				'invoice_date'   => '',
				'random_code'    => '',
				'error'          => 'SmilePay print requires invoice_date and random_code.',
			];
		}

		return [
			'success'        => true,
			'invoice_number' => $invoice_number,
			'invoice_date'   => str_replace( '-', '/', substr( $invoice_date, 0, 10 ) ),
			'random_code'    => $random_code,
			'error'          => null,
		];
	}

	// ──────────────────────────────────────────────────────────────────────
	// internals
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * Decrypt Verify_key from storage while keeping plaintext fallback for old installs.
	 */
	private function decrypt_verify_key( string $verify_key ): string {
		$verify_key = trim( $verify_key );
		if ( '' === $verify_key || ! class_exists( YSCrypto::class ) ) {
			return $verify_key;
		}

		try {
			$decrypted = YSCrypto::decrypt_from_storage( $verify_key );
			return '' !== $decrypted ? $decrypted : $verify_key;
		} catch ( \Throwable $e ) {
			return $verify_key;
		}
	}

	/**
	 * 建立 API client；設定不齊全則回 null
	 */
	private function build_client(): ?YSSmilePayApiClient {
		$settings   = $this->get_settings();
		$grvc       = trim( (string) ( $settings['grvc'] ?? '' ) );
		$verify_key = trim( (string) ( $settings['verify_key'] ?? '' ) );

		if ( '' === $grvc || '' === $verify_key ) {
			return null;
		}

		$sandbox = '1' === (string) ( $settings['sandbox'] ?? '1' );

		return new YSSmilePayApiClient( $grvc, $verify_key, $sandbox );
	}

	/**
	 * 把 generic invoice_data 轉成 SmilePay POST 欄位
	 *
	 * @param array<string, mixed> $invoice_data
	 * @return array<string, string|int>
	 */
	private function build_payload( array $invoice_data ): array {
		$settings = $this->get_settings();

		$is_company   = 'company' === ( $invoice_data['buyer_type'] ?? 'personal' );
		$buyer_tax_id = trim( (string) ( $invoice_data['buyer_tax_id'] ?? '' ) );

		// B2B 必須統編非空，否則退回 B2C（防呆：caller 可能 buyer_type=company 但統編空）
		$is_b2b = $is_company && '' !== $buyer_tax_id;

		$carrier     = (string) ( $invoice_data['carrier_type'] ?? '' );
		$carrier_id  = trim( (string) ( $invoice_data['carrier_id'] ?? '' ) );
		$donate_code = trim( (string) ( $invoice_data['donate_code'] ?? '' ) );
		$buyer_name  = trim( (string) ( $invoice_data['buyer_name'] ?? '' ) );
		$buyer_email = trim( (string) ( $invoice_data['buyer_email'] ?? '' ) );

		// 商品明細：用 `|` join，每項 name 必須移除 `|` 防破壞分隔符
		$items_meta = $this->build_items_payload( $invoice_data );

		// 時間：台北時區（SmilePay 規範）
		$tz_taipei  = new \DateTimeZone( 'Asia/Taipei' );
		$now_taipei = new \DateTimeImmutable( 'now', $tz_taipei );

		$total_amount = (int) round( (float) ( $invoice_data['total_amount'] ?? 0 ) );
		$tax_amount   = (int) round( (float) ( $invoice_data['tax_amount'] ?? 0 ) );

		// 基礎 payload（B2C / B2B 共用）
		$payload = [
			// ── 時間 ──
			'InvoiceDate'   => $now_taipei->format( 'Y/m/d' ),
			'InvoiceTime'   => $now_taipei->format( 'H:i:s' ),
			// ── 發票稅率（v1.0 僅支援應稅電子發票） ──
			'Intype'        => '07',
			'TaxType'       => '1',
			// ── 商品明細 ──
			'Description'   => $items_meta['Description'],
			'Quantity'      => $items_meta['Quantity'],
			'UnitPrice'     => $items_meta['UnitPrice'],
			'Amount'        => $items_meta['Amount'],
			'AllAmount'     => (string) $total_amount,
			// ── 識別 / 追溯 ──
			'orderid'       => $this->truncate( (string) ( $invoice_data['order_number'] ?? $invoice_data['order_id'] ?? '' ), 30 ),
			'data_id'       => $this->build_data_id( $invoice_data ),
			// SmilePay PosSystemID 規範：20 字元（英文/數字）。任何非英數字字元（如 - _ . 中文）會觸發 -10073。
			'PosSystemID'   => $this->truncate( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $settings['pos_system_id'] ?? 'YSCART' ) ), 20 ),
			// ── 買受人聯絡（共用） ──
			'Email'         => $this->truncate( $buyer_email, 80 ),
		];

		// 電話（共用，去除非數字字元）
		$phone = preg_replace( '/\D+/', '', (string) ( $invoice_data['buyer_phone'] ?? '' ) );
		if ( '' !== (string) $phone ) {
			$payload['Phone'] = $phone;
		}

		if ( $is_b2b ) {
			// ── B2B：統編發票 ──
			$payload['Buyer_id']      = $buyer_tax_id;
			$payload['CompanyName']   = $this->truncate( '' !== $buyer_name ? $buyer_name : '公司', 30 );
			$payload['Einvoice_Type'] = 'B2B';
			$payload['UnitTAX']       = 'Y'; // 商品金額含稅
			$payload['DonateMark']    = '0';

			// B2B 不可使用載具 / 捐贈
			if ( $tax_amount > 0 ) {
				$payload['TaxAmount'] = (string) $tax_amount;
			}
		} else {
			// ── B2C：個人發票 ──
			$payload['Name'] = $this->truncate( '' !== $buyer_name ? $buyer_name : '個人', 30 );

			if ( 'donate' === $carrier ) {
				// 捐贈發票：DonateMark=1 + LoveKey
				$payload['DonateMark'] = '1';
				if ( '' !== $donate_code ) {
					$payload['LoveKey'] = $donate_code;
				}
				// 捐贈時不傳 CarrierType
			} else {
				$payload['DonateMark'] = '0';

				$carrier_code = $this->map_carrier_type( $carrier );
				if ( '' !== $carrier_code ) {
					$payload['CarrierType'] = $carrier_code;

					// 速買配會員載具（EJ0113）允許 CarrierID 為空（會用 Email 自動建立）
					// 其他載具（mobile / cdc）CarrierID 必填且 CarrierID2 同值
					if ( 'EJ0113' === $carrier_code ) {
						// 若有 carrier_id 就帶，沒有就讓速買配用 Email 建立
						if ( '' !== $carrier_id ) {
							$payload['CarrierID']  = $carrier_id;
							$payload['CarrierID2'] = $carrier_id;
						}
					} else {
						// 其他載具：CarrierID 必須有值
						if ( '' !== $carrier_id ) {
							$payload['CarrierID']  = $carrier_id;
							$payload['CarrierID2'] = $carrier_id;
						}
					}
				}
			}
		}

		// 信用卡末四碼（若 ys-cart 主框架的 invoice_data 有帶）
		$last4 = trim( (string) ( $invoice_data['card_last4'] ?? '' ) );
		if ( '' !== $last4 && preg_match( '/^\d{4}$/', $last4 ) ) {
			$payload['Visa_Last4'] = $last4;
		}

		return $payload;
	}

	/**
	 * Build a SmilePay data_id that remains unique when an order is reissued.
	 *
	 * SmilePay keeps data_id uniqueness even after an invoice is voided. The
	 * core manager inserts the pending invoice row before calling the provider,
	 * so the latest pending invoice ID is a stable per-attempt suffix.
	 *
	 * @param array<string, mixed> $invoice_data
	 */
	private function build_data_id( array $invoice_data ): string {
		$order_id = (int) ( $invoice_data['order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			return $this->truncate( 'YSCART-' . wp_generate_uuid4(), 50 );
		}

		$invoice_id = $this->find_current_pending_invoice_id( $order_id );
		if ( $invoice_id > 0 ) {
			return $this->truncate( sprintf( 'YSCART-%d-%d', $order_id, $invoice_id ), 50 );
		}

		return $this->truncate( sprintf( 'YSCART-%d', $order_id ), 50 );
	}

	private function find_current_pending_invoice_id( int $order_id ): int {
		if ( $order_id <= 0 || ! class_exists( YSInvoice::class ) ) {
			return 0;
		}

		global $wpdb;
		$table = YSInvoice::table();
		$id    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE order_id = %d AND status = %s ORDER BY id DESC LIMIT 1",
				$order_id,
				'pending'
			)
		);

		return $id ? (int) $id : 0;
	}

	/**
	 * 商品明細組裝（含金額平衡邏輯）
	 *
	 * 規範：
	 *   1. 每項 name 必須 str_replace('|', '', ...) 防破壞 `|` 分隔
	 *   2. 數量 / 單價 / 金額均轉整數（SmilePay 規範）
	 *   3. 若 sum(Amount) ≠ AllAmount，補一行「手續費」（差正）或「折扣」（差負）平衡
	 *      避免 SmilePay 拒收 -10066 / -10067
	 *
	 * @param array<string, mixed> $invoice_data
	 * @return array{Description:string, Quantity:string, UnitPrice:string, Amount:string}
	 */
	private function build_items_payload( array $invoice_data ): array {
		$items_raw = (array) ( $invoice_data['items'] ?? [] );

		$descriptions = [];
		$quantities   = [];
		$unit_prices  = [];
		$amounts      = [];
		$amount_sum   = 0;

		foreach ( $items_raw as $item ) {
			$name      = (string) ( $item['name'] ?? '商品' );
			$name      = str_replace( '|', '', $name ); // 防破壞分隔符
			$name      = '' !== trim( $name ) ? $name : '商品';
			$qty       = max( 1, (int) ( $item['qty'] ?? 1 ) );
			$line_amt  = (int) round( (float) ( $item['amount'] ?? $item['subtotal'] ?? $item['line_total'] ?? 0 ) );
			$unit_amt  = (int) round( $line_amt / $qty );

			$descriptions[] = $this->truncate( $name, 256 );
			$quantities[]   = (string) $qty;
			$unit_prices[]  = (string) $unit_amt;
			$amounts[]      = (string) $line_amt;
			$amount_sum    += $line_amt;
		}

		// 若無任何 item，補一行讓 SmilePay 不拒收（極少數情境）
		if ( empty( $descriptions ) ) {
			$total          = (int) round( (float) ( $invoice_data['total_amount'] ?? 0 ) );
			$descriptions[] = '商品';
			$quantities[]   = '1';
			$unit_prices[]  = (string) $total;
			$amounts[]      = (string) $total;
			$amount_sum     = $total;
		}

		// 平衡：sum(Amount) 必須等於 AllAmount
		$total = (int) round( (float) ( $invoice_data['total_amount'] ?? 0 ) );
		$delta = $total - $amount_sum;
		if ( 0 !== $delta ) {
			if ( $delta > 0 ) {
				// 差正 → 補「手續費」
				$descriptions[] = '手續費';
				$quantities[]   = '1';
				$unit_prices[]  = (string) $delta;
				$amounts[]      = (string) $delta;
			} else {
				// 差負 → 補「折扣」（金額為負）
				$descriptions[] = '折扣';
				$quantities[]   = '1';
				$unit_prices[]  = (string) $delta;
				$amounts[]      = (string) $delta;
			}
		}

		return [
			'Description' => implode( '|', $descriptions ),
			'Quantity'    => implode( '|', $quantities ),
			'UnitPrice'   => implode( '|', $unit_prices ),
			'Amount'      => implode( '|', $amounts ),
		];
	}

	/**
	 * 本地 carrier 類型 → SmilePay CarrierType 代碼
	 *
	 * @param string $local_carrier 'mobile'|'cdc'|'member'|'donate'|''
	 * @return string SmilePay 代碼（'3J0002'/'CQ0001'/'EJ0113'/''）
	 */
	private function map_carrier_type( string $local_carrier ): string {
		$map = [
			'mobile' => '3J0002', // 手機條碼
			'cdc'    => 'CQ0001', // 自然人憑證
			'member' => 'EJ0113', // 速買配會員載具
			// 'donate' / '' / 'none' → '' （由 build_payload 額外處理 DonateMark）
		];
		return $map[ $local_carrier ] ?? '';
	}

	/**
	 * 從 ys_ec_invoices 撈某張發票的 issued_at，並 normalize 成 YYYY/MM/DD
	 *
	 * 找不到 / 表不存在 → fallback 當下台北時區的日期（best-effort，避免 caller 完全卡死）
	 *
	 * @param string $invoice_number
	 * @return string YYYY/MM/DD
	 */
	private function fetch_invoice_date_from_table( string $invoice_number ): string {
		global $wpdb;

		if ( ! defined( 'YS_ECOMMERCE_TABLE_PREFIX' ) || ! isset( $wpdb ) ) {
			return $this->today_in_taipei();
		}

		$table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'invoices';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$issued_at = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT issued_at FROM {$table} WHERE invoice_number = %s AND provider = %s LIMIT 1",
				$invoice_number,
				self::ID
			)
		);

		$issued_at = is_string( $issued_at ) ? trim( $issued_at ) : '';
		if ( '' === $issued_at ) {
			return $this->today_in_taipei();
		}

		// issued_at 可能是 YYYY-MM-DD HH:MM:SS 或 YYYY-MM-DD（依 ys-cart schema）
		// 取前 10 字元 + 轉斜線
		return str_replace( '-', '/', substr( $issued_at, 0, 10 ) );
	}

	/**
	 * 從 ys_ec_invoices 撈某張發票的 random_code
	 *
	 * @param string $invoice_number
	 * @return string 4 碼隨機碼 / 統編 / ''（找不到）
	 */
	private function fetch_random_code_from_table( string $invoice_number ): string {
		global $wpdb;

		if ( ! defined( 'YS_ECOMMERCE_TABLE_PREFIX' ) || ! isset( $wpdb ) ) {
			return '';
		}

		$table = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'invoices';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$random_code = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT random_code FROM {$table} WHERE invoice_number = %s AND provider = %s LIMIT 1",
				$invoice_number,
				self::ID
			)
		);

		return is_string( $random_code ) ? trim( $random_code ) : '';
	}

	/**
	 * 當下日期，台北時區
	 */
	private function today_in_taipei(): string {
		$tz = new \DateTimeZone( 'Asia/Taipei' );
		return ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y/m/d' );
	}

	/**
	 * 把 issue API 的 YSSmilePayApiResponse 正規化成 interface 規定的 array shape
	 *
	 * @param YSSmilePayApiResponse $response
	 * @return array{success:bool, invoice_number:string, random_code:string, raw:array, error:?string}
	 */
	private function normalize_issue_response( YSSmilePayApiResponse $response ): array {
		if ( $response->success() ) {
			return [
				'success'        => true,
				'invoice_number' => $response->get( 'InvoiceNumber', '' ),
				'random_code'    => $response->get( 'RandomNumber', '' ),
				'raw'            => $response->to_array(),
				'error'          => null,
			];
		}

		return [
			'success'        => false,
			'invoice_number' => '',
			'random_code'    => '',
			'raw'            => $response->to_array(),
			'error'          => YSSmilePayErrorCodes::translate( $response->status(), $response->desc() ),
		];
	}

	/**
	 * UTF-8 安全的字串截斷
	 */
	private function truncate( string $str, int $max ): string {
		if ( mb_strlen( $str, 'UTF-8' ) <= $max ) {
			return $str;
		}
		return mb_substr( $str, 0, $max, 'UTF-8' );
	}
}
