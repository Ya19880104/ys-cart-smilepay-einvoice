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

## [1.0.6] - 2026-06-15

### Fixed

- Saving the settings page without re-entering the Verify_key no longer wipes an
  undecryptable (migration / security-key change) Verify_key. Previously the blank
  password field retained the *decrypted* value — which was empty after the v1.0.5
  fail-closed change — and overwrote the stored ciphertext, so `verify_key_needs_reentry()`
  stopped firing and the guidance notice silently vanished, leaving the provider unusable
  with no hint. `preserve_unusable_verify_key()` now keeps the raw envelope on a blank save,
  and `encrypt_verify_key()` never double-encrypts an existing envelope.
- The admin's own `decrypt_verify_key()` (settings-page fallback path) now matches the
  provider's fail-closed behavior.

### Changed

- The admin guidance notice now fires whenever SmilePay is enabled but the effective
  Verify_key is empty — covering both the undecryptable case (migration) and a plainly
  missing key — instead of only the undecryptable case, with a message tailored to each.

### Added

- Regression v108 (blank-save preservation + broadened guidance contract).

## [1.0.5] - 2026-06-15

### Security

- Fail closed when the stored Verify_key cannot be decrypted. The Verify_key is stored encrypted
  with this site's keys; after a site migration or a change to WordPress security keys
  (SECURE_AUTH_KEY, etc.) the existing ciphertext can no longer be decrypted. The previous version
  passed the undecryptable ciphertext through as if it were the key, so SmilePay only returned
  "merchant account not found" with no clear cause. This version detects an
  envelope-shaped-but-undecryptable value and returns an empty string (the provider treats it as
  unconfigured) instead of sending invalid ciphertext. Plaintext keys from older installs still
  pass through unchanged.

### Added

- Admin guidance: when the Verify_key cannot be decrypted, an admin notice explains the likely
  cause (migration / security-key change) and links straight to the settings page to re-enter the
  Verify_key, restoring invoice issuance in one step.
- Regression v107 (fail-closed + guidance notice contract).

## [1.0.4] - 2026-06-15

### Security

- Customer invoice files are now served through the core shared server-side
  proxy (`stream_customer_invoice_file`) instead of redirecting the browser to
  the SmilePay print URL, which carries the secret `Verify_key`.
  `get_customer_invoice_url()` now fails closed (returns `success:false`) so the
  secret can no longer leak via query string or Referer. The proxy enforces a
  host allowlist (`einvoice.smilepay.net`), blocks redirects, caps the response
  at 5 MB, and allowlists PDF/HTML/PNG/JPEG content types.

### Added

- Register with the YS Plugin Hub Client (`YSPluginHubClient::register`) to join
  the shared YS CART plugin update lifecycle, consistent with the other providers.
- Headless SDK (`sdk/`), skill doc (`skills/`), release build script
  (`bin/build-release.php`), and package-contract regression (v104).
- Regression: v105 (invoice file proxy contract), v106 (Hub client registration contract).

## [1.0.3] - 2026-05-31

### Fixed

- Group the SmilePay provider before invoice registry initialization to fix
  provider load ordering.
- Add regression: v102 (admin group contract), v103 (bootstrap priority contract).

## [1.0.2] - 2026-05-30

### Changed

- Align SmilePay invoice provider settings UI with the YS CART admin visual contract.
- Harden invoice provider lifecycle behavior and provider-gated admin registration.
- Add review regression coverage for settings layout and provider registration.

## [1.0.1] - 2026-05-26

### Changed

- Require YS CART 2.51.0+ provider lifecycle gating.
- Register SmilePay invoice surfaces only through manifest lifecycle state.

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
