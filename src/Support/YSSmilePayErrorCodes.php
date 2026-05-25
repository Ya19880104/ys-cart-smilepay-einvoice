<?php
/**
 * SmilePay 錯誤碼 → 繁體中文人類可讀訊息對應表
 *
 * 來源：plugins-reference/smilepay-einvoice/API_DOCS_RAW.json 整理
 *   - Lab_1：開立發票（70+ 個錯誤碼）
 *   - Lab_2：折讓單（v1.1 預留）
 *   - Lab_3：作廢/註銷（11 個錯誤碼）
 *   - 框架自訂：-99999 網路 / -99998 解析 / -70xxx 範例 plugin 原生
 *
 * 設計原則：
 *   - 訊息以「商家可理解」為主，避免英文 / SmilePay 內部術語
 *   - 訊息**不洩漏** 任何 Verify_key 或客戶機敏資料
 *   - 找不到對應 code 時 fallback「SmilePay 錯誤（代碼 X）：原始 desc」
 *
 * @package YangSheep\SmilePayEInvoice\Support
 * @since   1.0.0
 */

namespace YangSheep\SmilePayEInvoice\Support;

defined( 'ABSPATH' ) || exit;

final class YSSmilePayErrorCodes {

	/**
	 * 把 SmilePay 回應的 Status code 翻譯成繁中錯誤訊息
	 *
	 * @param string $code SmilePay Status，e.g. '0' / '-10021' / '-99999'
	 * @param string $desc SmilePay 原始 Desc（fallback 用 / 網路錯誤時的細節）
	 * @return string
	 */
	public static function translate( string $code, string $desc = '' ): string {
		$map = self::get_map();

		if ( isset( $map[ $code ] ) ) {
			// 部分 code 需要把 desc 串進去（如 -99999 網路錯誤）
			return self::interpolate( $map[ $code ], $desc );
		}

		// 找不到 → 顯示原 code + 原 desc（buyer-facing safe — 不洩漏 secret）
		if ( '' !== $desc ) {
			return sprintf( 'SmilePay 錯誤（代碼 %s）：%s', $code, $desc );
		}
		return sprintf( 'SmilePay 錯誤（代碼 %s）', $code );
	}

	/**
	 * 取完整對應表（也供 unit test 直接驗證）
	 *
	 * @return array<string, string>
	 */
	public static function get_map(): array {
		return [
			// ────────────────────────────────
			// 成功
			// ────────────────────────────────
			'0'        => '操作成功',

			// ────────────────────────────────
			// Lab_1：開立發票
			// ────────────────────────────────
			'-1001'    => '商家帳號缺少參數，請確認設定頁的電子發票帳號（Grvc）與驗證碼（Verify_key）',
			'-10011'   => '查無商家帳號，請確認 SmilePay 後台是否已啟用 API 與本帳號設定',
			'-10012'   => '尚未開放 B2B 功能，請聯絡 SmilePay 客服啟用',
			'-10013'   => '尚未開放 B2C 功能，請聯絡 SmilePay 客服啟用',

			'-10021'   => '統一編號格式錯誤（必須為 8 碼數字）',
			'-10022'   => '統一編號發票不可設定為捐贈（DonateMark 必須為 0）',
			'-10023'   => '統一編號內容錯誤（請檢查 checksum 是否合法）',
			'-10024'   => '統一編號發票不可使用載具（請改開立 B2C 載具發票）',
			'-10025'   => '缺少公司名稱（CompanyName）',

			'-10031'   => '缺少開立日期或時間',
			'-10032'   => '開立日期 / 時間格式錯誤（應為 YYYY/MM/DD HH:MM:SS）',
			'-10033'   => 'B2C 發票必須在訂單成立後 48 小時內開立',
			'-10034'   => 'B2B 發票必須在訂單成立後 168 小時內開立',

			'-10041'   => '發票稅率類型（Intype）錯誤，僅允許 07 / 08',
			'-10042'   => '買受人註記欄（BuyerRemark）格式錯誤',
			'-10043'   => '通關方式註記（CustomsClearanceMark）錯誤',
			'-10044'   => '捐贈註記（DonateMark）錯誤，僅允許 0 / 1',
			'-10045'   => '愛心碼（LoveKey）不可空白',
			'-10046'   => '愛心碼伺服器異常，請稍後重試',
			'-10047'   => '查無此愛心碼，請確認後重試',
			'-10048'   => '課稅別（TaxType）錯誤',
			'-10049'   => '買受人簽署適用零稅率註記（BondedAreaConfirm）錯誤',
			'-100410'  => '總備註（MainRemark）格式錯誤或字數超過',
			'-100411'  => '相關號碼（RelateNumber）格式錯誤或字數超過',
			'-100412'  => '零稅率原因（ZeroTaxRateReason）格式錯誤',

			'-10051'   => '手機號碼（Phone）格式錯誤（請填入純數字）',
			'-10052'   => '載具號碼（CarrierID）格式錯誤',
			'-10053'   => '查無此載具號碼',
			'-10054'   => '建立載具失敗：缺少 Email / Phone',
			'-10055'   => '建立載具失敗，請稍後重試',
			'-10056'   => '查無此手機條碼',
			'-10057'   => '自然人憑證載具格式錯誤（必須為 2 英文 + 14 數字）',
			'-10058'   => '載具類型（CarrierType）非允許使用的代碼',

			'-10061'   => '商品各項目數量不符（Description / Quantity / UnitPrice / Amount 行數需一致）',
			'-10062'   => '商品內容長度不正確：品名最多 256 字、單位最多 6 字、備註最多 40 字',
			'-10063'   => '商品數量（Quantity）內容錯誤',
			'-10064'   => '商品金額（UnitPrice 或 Amount）內容錯誤',
			'-10065'   => '商品小計驗算錯誤（單價 × 數量 ≠ 明細總額）',
			'-10066'   => '商品總金額驗算錯誤（明細總額合計 ≠ AllAmount）',
			'-10067'   => '商品與總金額不符規定，請檢查折扣 / 運費的計算',
			'-10068'   => '混合稅率銷售額明細錯誤（SalesAmount / FreeTaxSalesAmount）',
			'-10069'   => '稅金與未稅銷售額驗算錯誤',
			'-100610'  => '稅率（TaxRate）內容錯誤',
			'-100611'  => '產品稅率（ProductTaxType）內容錯誤',

			'-10071'   => '無可用字軌，請於 SmilePay 後台分配發票字軌',
			'-10072'   => '自訂發票編號（data_id）重複，請使用不同的編號',
			'-10073'   => '營業人自定義系統代號（PosSystemID）格式錯誤',

			'-10081'   => '信用卡末四碼（Visa_Last4）格式錯誤',
			'-10082'   => '發票證明聯備註（Certificate_Remark）格式錯誤',
			'-10083'   => '自訂發票編號（data_id）格式錯誤',
			'-10084'   => '自訂號碼（orderid）格式錯誤',

			'-2001'    => '發票號碼（InvoiceNumber）格式錯誤',
			'-2002'    => '隨機碼（RandomNumber）格式錯誤',
			'-2003'    => '發票號碼不可重複',

			// ────────────────────────────────
			// Lab_3：作廢 / 註銷
			// ────────────────────────────────
			'-1000'    => '商家帳號缺少參數',
			// -1001 與 Lab_1 重複（同義「查無商家帳號」/「商家帳號缺少參數」），保留 Lab_1 訊息
			'-1002'    => '服務類型（types）錯誤，作廢必須為 Cancel / Void / CancelAllowance / StopProcessing',

			// 作廢 / 註銷專屬（-2001~-2010 在 modify 端點 vs issue 端點意義不同）
			// 由於本 v1.0 只用 issue 與 void 端點，這邊覆蓋兩端的常見組合：
			// issue: -2001=InvoiceNumber format / -2002=RandomNumber format / -2003=duplicate
			// void:  -2001=missing InvoiceNumber/CancelReason / -2002=CancelReason too long
			//        -2003=ReturnTaxDocumentNumber too long / -2004=Remark too long
			//        -2005=missing InvoiceNumber/VoidReason / -2006=VoidReason too long
			//        -2007=missing AllowanceNumber/CancelReason / -2008=不允許執行
			//        -2009=有折讓紀錄 / -2010=查無發票/折讓單
			//
			// 由於 -2001~-2003 衝突，我們在 translate() 用 desc 補充歧義；不衝突的單獨列：
			'-2004'    => '備註（Remark）超過字數限制（200 字）',
			'-2005'    => '缺少發票號碼或註銷原因',
			'-2006'    => '註銷原因（VoidReason）超過字數限制（20 字）',
			'-2007'    => '缺少折讓單號碼或作廢原因',
			'-2008'    => '發票目前狀態不允許執行該動作（可能已被作廢 / 已折讓 / 已上傳大平台）',
			'-2009'    => '發票有折讓紀錄不允許執行該動作',
			'-2010'    => '查無此發票或折讓單',

			// ────────────────────────────────
			// 範例 plugin 原生：-70xxx
			// （本框架的 wp_remote_post 不會產出這些，但若上游 lib 傳入此 code，需翻譯）
			// ────────────────────────────────
			'-70001'   => 'POST 參數錯誤',
			'-70002'   => '必填欄位缺失：%s',
			'-70003'   => '商品項目數量不一致',
			'-70004'   => '商品總額不一致（sum(Amount) ≠ AllAmount）',
			'-70010'   => '連線錯誤：%s',
			'-70011'   => '不明錯誤',
			'-70030'   => '查詢不明錯誤',
			'-70031'   => '載具查詢必填欄位缺失：%s',
			'-70033'   => '載具查詢連線錯誤：%s',

			// ────────────────────────────────
			// 框架自訂
			// ────────────────────────────────
			'-99998'   => 'SmilePay 回應解析失敗，可能是 SmilePay 伺服器返回非 XML 內容',
			'-99999'   => '網路連線失敗：%s',
		];
	}

	/**
	 * 把 message 中的 %s 替換成 desc
	 *
	 * 若 message 不含 %s 但有 desc，自動 append 「：%s」確保 desc 不會被吞掉
	 *
	 * @param string $message
	 * @param string $desc
	 * @return string
	 */
	private static function interpolate( string $message, string $desc ): string {
		if ( false !== strpos( $message, '%s' ) ) {
			return sprintf( $message, $desc );
		}
		// 不含 %s 且有 desc → 不 append（避免重複，多數 message 已自帶完整描述）
		return $message;
	}
}
