# Changelog

本外掛遵循 [Keep a Changelog](https://keepachangelog.com/zh-TW/1.1.0/) 規範，並使用
[Semantic Versioning](https://semver.org/lang/zh-TW/)。

## [Unreleased]

### Planned

- v1.1：折讓單支援（Allowance API）
- v1.1：消費者「我的常用載具」儲存（需 ys-cart 框架擴 `wp_ys_ec_user_carriers` 表）
- v1.1：訂單通知信嵌入發票號 / 列印連結
- v1.1：EPSON IP 列印（特殊版型 + 印表機 IP）
- v1.1：註銷發票（types=Void）

## [1.0.0] - 2026-05-26

### Added

- 完整實作 `YSInvoiceProviderInterface`（含 ADR-053 v2.50.0 新增的 `get_customer_invoice_url`）
- 支援 B2C 個人發票開立（一般雲端發票 / 手機條碼 / 自然人憑證 / 速買配會員載具 / 捐贈愛心碼）
- 支援 B2B 公司戶統編發票（自動 `Einvoice_Type=B2B` + `UnitTAX=Y`）
- 支援後台手動 + 自動開立（依 ys-cart 主框架設定 `auto_processing` / `auto_completed`）
- 支援後台一鍵作廢（types=Cancel）
- 支援買家「我的訂單」列印發票（A4 / A5 / 證明聯）
- 正式 / 測試環境 toggle（`sandbox`）
- 70+ SmilePay 錯誤碼翻譯為繁體中文人類可讀訊息（`YSSmilePayErrorCodes`）
- HPOS 相容（不直接寫 order meta，發票資料由 ys-cart 主框架存到 `ys_ec_invoices` 表）
- REST-first（零 `wp_ajax_*` / `wp_ajax_nopriv_*`）

### Security

- 使用 `wp_remote_post()` 並維持 `sslverify=true`（不關閉 SSL 驗證）
- `Verify_key` 於 log 中遮蔽（保留前 4 + 後 4，中間 `***`）
- 列印 URL 含 `Verify_key`，但透過 ys-cart server-side proxy + `Referrer-Policy: no-referrer`
  header（由 ADR-053 in ys-cart v2.50.0 內建）保護，不會 echo 到 buyer-facing HTML

### Dependencies

- 需要 YS CART **v2.50.0+**（含 ADR-053 invoice provider 多型化列印 URL 介面）
