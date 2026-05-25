# YS CART 速買配電子發票

> 速買配（SmilePay / 訊航科技）電子發票整合，作為 YS CART 主外掛的 invoice provider。

## 簡介

本外掛把速買配（SmilePay）的電子發票系統接到 YS CART，讓商家可以：

- 在 ys-cart 既有的「電子發票」管理介面下選用 SmilePay 作為發票供應商
- 與另一家 provider（Amego）並存，依商家偏好自由切換
- 自動 / 手動開立 B2C（個人）與 B2B（公司統編）發票
- 支援手機條碼、自然人憑證、速買配會員載具、捐贈愛心碼四種載具類型
- 後台一鍵作廢已開立發票
- 買家於「我的訂單」直接點開列印發票（A4 / A5 / 證明聯版型）

## 需求

- WordPress 6.0+
- PHP 8.1+
- **YS CART 主外掛 2.50.0+**（包含 invoice provider 框架與 ADR-053 多型化列印 URL 介面）

## 安裝

### 方式 1：後台直接上傳 zip

1. 從 [Releases 頁面](https://github.com/Ya19880104/ys-cart-smilepay-einvoice/releases) 下載最新 zip
2. 後台「外掛 → 安裝外掛 → 上傳外掛」
3. 啟用即可

### 方式 2：FTP / SSH 上傳

1. 把 zip 解壓到 `wp-content/plugins/ys-cart-smilepay-einvoice/`
2. 後台「外掛」啟用

## 設定

啟用後到「**電子發票** → **速買配 SmilePay**」設定頁，填入：

| 欄位 | 說明 |
|------|------|
| 電子發票帳號（Grvc）| 由速買配後台「Email/API 設定」取得，e.g. `SEI1000034`（試用值） |
| 驗證碼（Verify_key）| 由速買配後台取得，**請妥善保管，視為密碼** |
| 測試模式 | 預設啟用，會走 `api_test/*` 端點不產生真實發票 |
| 自動開立時機 | `關閉` / `訂單轉處理中` / `訂單轉已完成` |
| 載具開關 | 可逐項啟用 / 關閉手機條碼、自然人憑證、速買配會員載具、捐贈 |
| POS System ID | 用來在速買配後台辨識不同站台的來源，預設 `YS-CART` |

### 試用憑證（公開資訊，可直接用於測試）

速買配官方提供的開發用憑證：

```
Grvc       : SEI1000034
Verify_key : 9D73935693EE0237FABA6AB744E48661
商家代號    : 107
試用統編    : 80129529
```

速買配試用後台：<https://ssl.smse.com.tw/pay_gr/INDEX_LOGIN.ASP>

## 結帳體驗

啟用後消費者結帳時可選：

1. **個人發票**（不指定載具 → 一般雲端發票）
2. **個人發票 + 手機條碼**（格式 `/ABCD123`）
3. **個人發票 + 自然人憑證**（格式 `AB12345678901234`）
4. **個人發票 + 速買配會員載具**（自動以 Email 註冊）
5. **個人發票 + 捐贈**（輸入愛心碼，e.g. `25885`、`8585`）
6. **公司戶發票**（B2B，輸入 8 碼統編 + 公司抬頭）

## 列印發票

買家於「我的訂單 → 訂單詳情」會看到列印按鈕，點擊後會：

1. ys-cart REST endpoint 做權限驗證
2. server-side 302 redirect 到速買配的列印頁
3. 速買配開啟瀏覽器列印對話框（支援 A4 / A5 / 證明聯三種版型）

> **隱私說明**：列印 URL 含驗證碼，但走 server-side proxy 不會 echo 到 page source；
> 仍會出現在買家瀏覽器歷史紀錄與速買配 access log，這是 SmilePay v1.0 規格限制。

## 常見問題

### Q: 為何按「測試連線」收到 -10011 查無商家帳號？

A: 確認 Grvc 大小寫正確（SmilePay 帳號嚴格區分），並確認該帳號在速買配後台已啟用「API 設定」。

### Q: 開立發票時收到 -10067 商品與總金額不符規定？

A: 通常是商品明細的 sum(Amount) ≠ AllAmount 造成。本外掛會自動補一行「手續費」或「折扣」平衡；
若仍發生，請檢查 ys-cart 主框架的訂單運費 / 折扣是否有極端 rounding。

### Q: 為何 B2B 發票開不出來？

A: B2B 發票必須：
- 統編為合法 8 碼數字（含 checksum）
- 公司名稱不可空白
- 必須在訂單成立 168 小時內開立
- SmilePay 帳號已開放 B2B 功能（聯絡客服）

### Q: 可以開立折讓單嗎？

A: v1.0 暫不支援，預計 v1.1 加入。

## 版本歷史

詳見 [CHANGELOG.md](CHANGELOG.md)。

## 授權

GPL v2 或更新版本。詳見 [LICENSE](LICENSE)。

## 開發者

[YANGSHEEP DESIGN](https://yangsheep.com.tw) — 台灣 WooCommerce / WordPress 顧問與外掛開發
