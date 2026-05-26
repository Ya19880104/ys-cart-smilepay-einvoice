<?php
/**
 * SmilePay HTTP 客戶端
 *
 * 把 SmilePay 的 4 個 ASP 端點與 1 個列印 URL 抽象成 issue() / void() /
 * query_carrier() / build_print_url() 方法。
 *
 * 設計原則：
 *   1. 使用 wp_remote_post()（WordPress HTTP API），不用 curl —— 可被 hook、
 *      proxy 設定與 pre_http_request filter 影響，符合 WordPress 規範。
 *   2. timeout 30 秒（SmilePay 偶爾慢回，30 秒平衡 UX 與穩定性）
 *   3. 絕對禁止關閉 SSL 驗證 — 速買配官方範例 CURLOPT_SSL_VERIFYPEER => FALSE
 *      是錯誤示範，wp_remote_post 預設 sslverify=true，我們維持。
 *   4. 所有外部 call 包 try/catch，失敗一律回 STATUS_NETWORK_ERROR，不丟例外
 *      （fail-soft，由 caller / YSInvoiceManager 處理）
 *   5. log 時用 redact_verify_key() 遮蔽 Verify_key（永遠不可記錄全文）
 *
 * @package YangSheep\SmilePayEInvoice\Api
 * @since   1.0.0
 */

namespace YangSheep\SmilePayEInvoice\Api;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Utils\YSLogger;

final class YSSmilePayApiClient {

	// ── 開立發票端點 ──
	public const API_ISSUE_PROD     = 'https://ssl.smse.com.tw/api/SPEinvoice_Storage.asp';
	public const API_ISSUE_TEST     = 'https://ssl.smse.com.tw/api_test/SPEinvoice_Storage.asp';

	// ── 作廢/註銷端點（共用 modify 端點，types 參數區分） ──
	public const API_MODIFY_PROD    = 'https://ssl.smse.com.tw/api/SPEinvoice_Storage_Modify.asp';
	public const API_MODIFY_TEST    = 'https://ssl.smse.com.tw/api_test/SPEinvoice_Storage_Modify.asp';

	// ── 載具查詢端點 ──
	public const API_CARRIER_PROD   = 'https://ssl.smse.com.tw/api/SPeinvoice_Carrier.asp';
	public const API_CARRIER_TEST   = 'https://ssl.smse.com.tw/api_test/SPeinvoice_Carrier.asp';

	// ── 列印 URL（GET，非 API，買家瀏覽器直接訪問） ──
	public const PRINT_URL_PROD     = 'https://einvoice.smilepay.net/einvoice/SmilePayCarrier/InvoiceDetails.php';
	public const PRINT_URL_TEST     = 'https://einvoice.smilepay.net/einvoice_test/SmilePayCarrier/InvoiceDetails.php';

	/**
	 * HTTP 請求 timeout（秒）
	 */
	private const HTTP_TIMEOUT = 30;

	/**
	 * SmilePay 電子發票帳號（Grvc）
	 */
	private string $grvc;

	/**
	 * SmilePay 驗證碼（Verify_key） — SECRET，永不可寫進 log
	 */
	private string $verify_key;

	/**
	 * 是否使用沙箱端點
	 */
	private bool $sandbox;

	/**
	 * @param string $grvc       電子發票帳號
	 * @param string $verify_key 驗證碼（SECRET）
	 * @param bool   $sandbox    true=測試環境
	 */
	public function __construct( string $grvc, string $verify_key, bool $sandbox = false ) {
		$this->grvc       = trim( $grvc );
		$this->verify_key = trim( $verify_key );
		$this->sandbox    = $sandbox;
	}

	/**
	 * 開立發票
	 *
	 * payload 應已組好 SmilePay 大寫駝峰欄位，由 provider::build_payload() 產出。
	 * 本方法只負責加掛 grvc / Verify_key（避免 provider 重複組）+ HTTP 傳送 + 解析。
	 *
	 * @param array<string, string|int> $payload SmilePay POST 欄位
	 * @return YSSmilePayApiResponse
	 */
	public function issue( array $payload ): YSSmilePayApiResponse {
		$payload['Grvc']       = $this->grvc;
		$payload['Verify_key'] = $this->verify_key;

		$url = $this->sandbox ? self::API_ISSUE_TEST : self::API_ISSUE_PROD;
		return $this->http_post( $url, $payload, 'issue' );
	}

	/**
	 * 作廢發票（types=Cancel）
	 *
	 * @param string $invoice_number 發票號碼（10 碼）
	 * @param string $invoice_date   發票日期（YYYY/MM/DD）
	 * @param string $cancel_reason  作廢原因（最多 20 字）
	 * @param string $remark         額外備註（最多 200 字，可空）
	 * @return YSSmilePayApiResponse
	 */
	public function void(
		string $invoice_number,
		string $invoice_date,
		string $cancel_reason,
		string $remark = ''
	): YSSmilePayApiResponse {
		$payload = [
			'Grvc'           => $this->grvc,
			'Verify_key'     => $this->verify_key,
			'types'          => 'Cancel',
			'InvoiceNumber'  => $invoice_number,
			'InvoiceDate'    => $invoice_date,
			'CancelReason'   => $this->truncate( $cancel_reason, 20 ),
			'Remark'         => $this->truncate( $remark, 200 ),
		];

		$url = $this->sandbox ? self::API_MODIFY_TEST : self::API_MODIFY_PROD;
		return $this->http_post( $url, $payload, 'void' );
	}

	/**
	 * 查詢載具（驗證 carrier_id 是否有效）
	 *
	 * @param string $carrier_id_type CHK_DATA / CHK_CARD / NEW
	 * @param string $email
	 * @param string $carrier_id
	 * @return YSSmilePayApiResponse
	 */
	public function query_carrier(
		string $carrier_id_type,
		string $email = '',
		string $carrier_id = ''
	): YSSmilePayApiResponse {
		$payload = [
			'Grvc'      => $this->grvc,
			// 載具查詢端點不收 Verify_key（依官方範例觀察）；如未來規格更新可在此補
			'types'     => $carrier_id_type,
			'Email'     => $email,
			'CarrierID' => $carrier_id,
		];

		$url = $this->sandbox ? self::API_CARRIER_TEST : self::API_CARRIER_PROD;
		return $this->http_post( $url, $payload, 'query_carrier' );
	}

	/**
	 * 組列印 URL（GET，非 API call）
	 *
	 * 給 ADR-053 get_customer_invoice_url() 使用；URL 含 Verify_key query string，
	 * 透過 server-side proxy 302 redirect 給買家瀏覽器（不會 echo 到 page source）。
	 *
	 * @param string $invoice_number 發票號碼（10 碼）
	 * @param string $invoice_date   發票日期（YYYY/MM/DD）
	 * @param string $random_code    B2C 隨機碼 / B2B 統編
	 * @return string 完整 print URL
	 */
	public function build_print_url(
		string $invoice_number,
		string $invoice_date,
		string $random_code
	): string {
		$base = $this->sandbox ? self::PRINT_URL_TEST : self::PRINT_URL_PROD;
		return add_query_arg(
			[
				'Grvc'        => $this->grvc,
				'Verify_key'  => $this->verify_key,
				'InNumber'    => $invoice_number,
				'InvoiceDate' => $invoice_date,
				'RaNumber'    => $random_code,
			],
			$base
		);
	}

	/**
	 * 取得當前環境是 sandbox 還是 production（給 logging）
	 */
	public function is_sandbox(): bool {
		return $this->sandbox;
	}

	/**
	 * 取得 Grvc（給 logging / 公開資料）
	 */
	public function get_grvc(): string {
		return $this->grvc;
	}

	// ──────────────────────────────────────────────────────────────────────
	// internals
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * 統一 POST 處理
	 *
	 * 步驟：
	 *   1. log request（遮蔽 Verify_key）
	 *   2. wp_remote_post()
	 *   3. 錯誤處理（WP_Error / non-200 / 空 body）→ STATUS_NETWORK_ERROR
	 *   4. simplexml_load_string() 解析 → 成功就回 YSSmilePayApiResponse::from_xml
	 *   5. log response（非 Status=0 寫 warning）
	 *
	 * @param string                    $url
	 * @param array<string, string|int> $payload
	 * @param string                    $op_label 用於 log channel suffix
	 * @return YSSmilePayApiResponse
	 */
	private function http_post( string $url, array $payload, string $op_label ): YSSmilePayApiResponse {
		// log request（必經遮蔽）
		$this->log_request( $op_label, $url, $payload );

		$response = wp_remote_post(
			$url,
			[
				'timeout'   => self::HTTP_TIMEOUT,
				'sslverify' => true, // 永不關閉 SSL 驗證
				'body'      => $payload,
				'headers'   => [
					// SmilePay API 規定使用 application/x-www-form-urlencoded（預設）
					// 不需特別 set Content-Type，wp_remote_post 會自動加
					'Accept' => 'application/xml, text/xml',
				],
				'user-agent' => 'YS CART SmilePay Provider/' . ( defined( 'YS_SMILEPAY_VERSION' ) ? YS_SMILEPAY_VERSION : '1.0.1' ),
			]
		);

		// 1) WP HTTP 層錯誤（DNS / SSL / timeout）
		if ( is_wp_error( $response ) ) {
			$err_msg = $response->get_error_message();
			$this->log_error( $op_label, 'WP_Error: ' . $err_msg );
			return YSSmilePayApiResponse::from_network_error( 'network: ' . $err_msg );
		}

		// 2) HTTP status code 非 200
		$http_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $http_code ) {
			$this->log_error( $op_label, sprintf( 'HTTP %d non-200 from SmilePay', $http_code ) );
			return YSSmilePayApiResponse::from_network_error(
				sprintf( 'http_status: %d', $http_code )
			);
		}

		// 3) body 為空
		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === trim( $body ) ) {
			$this->log_error( $op_label, 'empty response body' );
			return YSSmilePayApiResponse::from_network_error( 'empty body' );
		}

		// 4) XML 解析
		//    use_internal_errors / libxml_clear_errors：避免 libxml 把 warning 噴到輸出
		$prev = libxml_use_internal_errors( true );
		try {
			$xml = simplexml_load_string( $body );
		} catch ( \Throwable $e ) {
			$xml = null;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );
		}

		if ( false === $xml || null === $xml ) {
			$this->log_error( $op_label, 'XML parse failed; body snippet: ' . substr( $body, 0, 200 ) );
			return YSSmilePayApiResponse::from_network_error( 'xml_parse_failed' );
		}

		$result = YSSmilePayApiResponse::from_xml( $xml );

		// log response 結果（不含 Verify_key）
		$this->log_response( $op_label, $result );

		return $result;
	}

	/**
	 * 截斷字串到指定長度（SmilePay 多數欄位都有字數上限）
	 */
	private function truncate( string $str, int $max ): string {
		if ( mb_strlen( $str, 'UTF-8' ) <= $max ) {
			return $str;
		}
		return mb_substr( $str, 0, $max, 'UTF-8' );
	}

	/**
	 * 遮蔽 Verify_key（保留前 4 + 後 4，中間 ***）
	 */
	private function redact_verify_key(): string {
		$len = strlen( $this->verify_key );
		if ( 0 === $len ) {
			return '(empty)';
		}
		if ( $len <= 8 ) {
			return str_repeat( '*', $len );
		}
		return substr( $this->verify_key, 0, 4 ) . '***' . substr( $this->verify_key, -4 );
	}

	/**
	 * Log API request（遮蔽敏感資訊）
	 *
	 * @param string                    $op_label
	 * @param string                    $url
	 * @param array<string, string|int> $payload
	 */
	private function log_request( string $op_label, string $url, array $payload ): void {
		if ( ! class_exists( YSLogger::class ) ) {
			return;
		}

		$safe_payload = $payload;
		if ( isset( $safe_payload['Verify_key'] ) ) {
			$safe_payload['Verify_key'] = $this->redact_verify_key();
		}

		YSLogger::info(
			'smilepay-api',
			sprintf( '[%s] request to %s', $op_label, $url ),
			[
				'op'      => $op_label,
				'sandbox' => $this->sandbox,
				'grvc'    => $this->grvc,
				'payload' => $safe_payload,
			]
		);
	}

	/**
	 * Log API response（成功 info、失敗 warning）
	 */
	private function log_response( string $op_label, YSSmilePayApiResponse $resp ): void {
		if ( ! class_exists( YSLogger::class ) ) {
			return;
		}

		// Defensive：即使 SmilePay 目前 spec 不在 response echo Verify_key，
		// 未來若 API 升級加入 debug 欄位，這層 sanitize 防止 secret 進 log（v1.0 ADR-053 §3.6 規範）。
		$raw_safe = $resp->to_array();
		foreach ( [ 'Verify_key', 'verify_key', 'VerifyKey', 'verifykey' ] as $secret_key ) {
			if ( isset( $raw_safe[ $secret_key ] ) ) {
				unset( $raw_safe[ $secret_key ] );
			}
		}

		$ctx = [
			'op'     => $op_label,
			'status' => $resp->status(),
			'desc'   => $resp->desc(),
			'raw'    => $raw_safe,
		];

		if ( $resp->success() ) {
			YSLogger::info(
				'smilepay-api',
				sprintf( '[%s] success', $op_label ),
				$ctx
			);
		} else {
			YSLogger::warning(
				'smilepay-api',
				sprintf( '[%s] failed: status=%s desc=%s', $op_label, $resp->status(), $resp->desc() ),
				$ctx
			);
		}
	}

	/**
	 * Log 內部錯誤（網路 / 解析）
	 */
	private function log_error( string $op_label, string $message ): void {
		if ( ! class_exists( YSLogger::class ) ) {
			return;
		}

		YSLogger::error(
			'smilepay-api',
			sprintf( '[%s] %s', $op_label, $message ),
			[
				'op'      => $op_label,
				'sandbox' => $this->sandbox,
				'grvc'    => $this->grvc,
			]
		);
	}
}
