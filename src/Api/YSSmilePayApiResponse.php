<?php
/**
 * SmilePay API 回應 DTO
 *
 * 封裝 SmilePay 4 個 .asp 端點共用的 XML 回應結構，把 simplexml 物件轉成
 * 平鋪的 associative array，並提供 success() / 取欄位 helper。
 *
 * SmilePay 共用回應形狀（XML root = <SmilePayEinvoice>）：
 *   - <Status>0</Status>            ← 必有；'0' 為成功
 *   - <Desc></Desc>                 ← 失敗原因或空字串
 *   - <Grvc>SEI1000034</Grvc>
 *   - 視端點額外的 <InvoiceNumber>/<RandomNumber>/<InvoiceDate>/<CancelDate>/...
 *
 * 框架契約：
 *   - 任何欄位都可能是空字串 '' 而非 null（XML 解析特性）
 *   - 數值欄位（Status）以字串型別回傳，比較時 strict 比對字串 '0'
 *
 * @package YangSheep\SmilePayEInvoice\Api
 * @since   1.0.0
 */

namespace YangSheep\SmilePayEInvoice\Api;

defined( 'ABSPATH' ) || exit;

final class YSSmilePayApiResponse {

	/**
	 * SmilePay 統一「成功」狀態碼
	 */
	public const STATUS_SUCCESS = '0';

	/**
	 * 框架內部「未知 / 解析失敗」狀態碼
	 */
	public const STATUS_PARSE_ERROR = '-99998';

	/**
	 * 框架內部「網路 / 連線失敗」狀態碼
	 */
	public const STATUS_NETWORK_ERROR = '-99999';

	/**
	 * 原始解析後 array（XML → assoc array）
	 *
	 * @var array<string, mixed>
	 */
	private array $raw;

	/**
	 * @param array<string, mixed> $raw 從 simplexml 轉成 (array) 後的結果
	 */
	public function __construct( array $raw ) {
		$this->raw = $raw;
	}

	/**
	 * 從 SimpleXMLElement 物件建構（呼叫 ::http_post() 後內部使用）
	 *
	 * 若 $xml 為 null（解析失敗）回傳一個帶 STATUS_PARSE_ERROR 的 fallback response。
	 *
	 * @param \SimpleXMLElement|null $xml
	 * @return self
	 */
	public static function from_xml( ?\SimpleXMLElement $xml ): self {
		if ( null === $xml ) {
			return new self( [
				'Status' => self::STATUS_PARSE_ERROR,
				'Desc'   => 'XML parse failed',
			] );
		}

		// SimpleXMLElement → array：每個欄位變成 string（空欄位變空 SimpleXMLElement）
		$normalized = [];
		foreach ( (array) $xml as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$normalized[ $k ] = (string) $v;
			} elseif ( is_object( $v ) || is_array( $v ) ) {
				// SimpleXMLElement 空 tag 會被解析成空物件，正規化為 ''
				$normalized[ $k ] = '';
			} else {
				$normalized[ $k ] = '';
			}
		}

		return new self( $normalized );
	}

	/**
	 * 從框架內部錯誤（網路 / 連線）建構
	 *
	 * @param string $desc 錯誤原因（人類可讀）
	 * @return self
	 */
	public static function from_network_error( string $desc ): self {
		return new self( [
			'Status' => self::STATUS_NETWORK_ERROR,
			'Desc'   => $desc,
		] );
	}

	/**
	 * 是否為「成功」回應（Status === '0'）
	 */
	public function success(): bool {
		return self::STATUS_SUCCESS === $this->get( 'Status' );
	}

	/**
	 * 取得 Status code（字串型別）
	 */
	public function status(): string {
		return $this->get( 'Status', '' );
	}

	/**
	 * 取得錯誤描述（SmilePay 原文，未經翻譯）
	 */
	public function desc(): string {
		return $this->get( 'Desc', '' );
	}

	/**
	 * 取得某個欄位（找不到回 default）
	 *
	 * @param string $key
	 * @param string $default
	 * @return string
	 */
	public function get( string $key, string $default = '' ): string {
		if ( ! isset( $this->raw[ $key ] ) ) {
			return $default;
		}
		$v = $this->raw[ $key ];
		return is_scalar( $v ) ? (string) $v : $default;
	}

	/**
	 * 取得原始解析後 array（給 logging / audit 用）
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->raw;
	}
}
