<?php
/**
 * 速買配 SmilePay 電子發票 — 後台設定頁 template。
 *
 * 由 YSSmilePayAdmin::render() 在 YSAdminApp::open()/close() 區塊內 include。
 *
 * ADR-052 原則 2：本 template 不再 echo 任何 `<div class="wrap">`，整個頁面外殼
 * 由 YSAdminApp shell 負責。本 template 只 render 頁面內容區（page-root 之下）。
 *
 * ADR-052 原則 5：僅使用 ys-cart 既有 `.ysca-*` primitive class。
 *
 * 變數（由 render() 注入）：
 *   - $fields    array  欄位定義（由 Provider 或 fallback 提供）
 *   - $settings  array  目前儲存的設定（含預設值合併）
 *   - $enabled   bool   啟用 toggle 當前值
 *   - $notice    ?array { type: 'success'|'error', message: string }
 *
 * @package YangSheep\SmilePayEInvoice\Templates
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/** @var array<int, array<string, mixed>> $fields */
/** @var array<string, mixed>             $settings */
/** @var bool                             $enabled */
/** @var array{type:string,message:string}|null $notice */

$admin_class = \YangSheep\SmilePayEInvoice\Admin\YSSmilePayAdmin::class;

$save_url = admin_url( 'admin-post.php' );
$test_url = admin_url( 'admin-post.php' );
?>

<?php /* PRG redirect notice（儲存成功 / 測試連線結果）。 */ ?>
<?php if ( null !== $notice ) : ?>
	<?php $notice_class = 'success' === $notice['type'] ? 'notice-success' : 'notice-error'; ?>
	<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible ysca-notice-spaced" role="status">
		<p><?php echo esc_html( $notice['message'] ); ?></p>
	</div>
<?php endif; ?>

<?php /* 說明區（與 Amego 設定頁的 description card 同節奏）。 */ ?>
<div class="ysca-card ysca-card--soft ysca-card--dense">
	<p class="description">
		<strong><?php esc_html_e( '速買配（SmilePay / 訊航科技）電子發票 API', 'ys-cart-smilepay-einvoice' ); ?></strong><br>
		<?php
		printf(
			/* translators: 1: 申請連結 2: API 文件連結 */
			esc_html__( '申請帳號：%1$s／API 文件：%2$s', 'ys-cart-smilepay-einvoice' ),
			'<a href="https://www.smilepay.net/" target="_blank" rel="noopener">https://www.smilepay.net/</a>',
			'<a href="https://www.smilepay.net/api/" target="_blank" rel="noopener">https://www.smilepay.net/api/</a>'
		);
		?>
	</p>
	<p class="description">
		<?php esc_html_e( '試用憑證請參閱外掛 README（README.md / readme.txt）的「試用憑證」段落取得 Grvc 與 Verify_key。使用試用憑證時，請務必勾選下方「使用測試環境」，所有 API 將走 api_test/* 端點不會產生真實發票。', 'ys-cart-smilepay-einvoice' ); ?>
	</p>
</div>

<?php /* ─────────────── 主設定表單 ─────────────── */ ?>
<form
	method="post"
	action="<?php echo esc_url( $save_url ); ?>"
	class="ysca-card ysca-card--soft ysca-section"
	aria-labelledby="ys-ec-smilepay-settings-heading"
>
	<input type="hidden" name="action" value="<?php echo esc_attr( $admin_class::ADMIN_POST_ACTION ); ?>">
	<?php wp_nonce_field( $admin_class::NONCE_ACTION ); ?>

	<h2 id="ys-ec-smilepay-settings-heading" class="screen-reader-text">
		<?php esc_html_e( '速買配電子發票設定', 'ys-cart-smilepay-einvoice' ); ?>
	</h2>

	<table class="ysca-settings-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row">
					<?php esc_html_e( '啟用速買配發票', 'ys-cart-smilepay-einvoice' ); ?>
				</th>
				<td>
					<label class="ysca-switch-label">
						<input
							type="checkbox"
							name="<?php echo esc_attr( $admin_class::OPTION_ENABLED ); ?>"
							value="1"
							<?php checked( $enabled ); ?>
						>
						<span><?php esc_html_e( '啟用後，將出現在「電子發票設定 → 作用中供應商」清單中。', 'ys-cart-smilepay-einvoice' ); ?></span>
					</label>
					<p class="description">
						<?php esc_html_e( '啟用前請確認已填妥「電子發票帳號 (Grvc)」與「驗證碼 (Verify_key)」，否則 SmilePay API 將無法呼叫。', 'ys-cart-smilepay-einvoice' ); ?>
					</p>
				</td>
			</tr>

			<?php
			foreach ( $fields as $field ) :
				$key      = (string) ( $field['key'] ?? '' );
				if ( '' === $key ) {
					continue;
				}
				$label    = (string) ( $field['label'] ?? $key );
				$type     = (string) ( $field['type'] ?? 'text' );
				$required = ! empty( $field['required'] );
				$desc     = (string) ( $field['description'] ?? '' );
				$ph       = (string) ( $field['placeholder'] ?? '' );
				$default  = (string) ( $field['default'] ?? '' );
				$value    = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : $default;
				$field_id = 'ys-ec-smilepay-' . $key;
				?>
				<tr>
					<th scope="row">
						<label for="<?php echo esc_attr( $field_id ); ?>">
							<?php echo esc_html( $label ); ?>
							<?php if ( $required ) : ?>
								<span class="ysca-required-mark" aria-hidden="true"> *</span>
								<span class="screen-reader-text"><?php esc_html_e( '必填', 'ys-cart-smilepay-einvoice' ); ?></span>
							<?php endif; ?>
						</label>
					</th>
					<td>
						<?php switch ( $type ) :
							case 'toggle': ?>
								<label class="ysca-switch-label">
									<input
										type="checkbox"
										name="<?php echo esc_attr( $key ); ?>"
										id="<?php echo esc_attr( $field_id ); ?>"
										value="1"
										<?php checked( '1' === $value ); ?>
									>
									<span><?php esc_html_e( '啟用', 'ys-cart-smilepay-einvoice' ); ?></span>
								</label>
								<?php break;

							case 'select': ?>
								<select
									name="<?php echo esc_attr( $key ); ?>"
									id="<?php echo esc_attr( $field_id ); ?>"
									class="ysca-select"
								>
									<?php foreach ( (array) ( $field['options'] ?? [] ) as $ov => $ol ) : ?>
										<option value="<?php echo esc_attr( (string) $ov ); ?>" <?php selected( $value, (string) $ov ); ?>>
											<?php echo esc_html( (string) $ol ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php break;

							case 'password': ?>
								<?php
								// password 永遠不回顯任何 byte；只在 placeholder 暗示「已設定」狀態
								$pw_placeholder = '' !== $value
									? __( '（已設定，留空保持原值）', 'ys-cart-smilepay-einvoice' )
									: $ph;
								?>
								<input
									type="password"
									name="<?php echo esc_attr( $key ); ?>"
									id="<?php echo esc_attr( $field_id ); ?>"
									class="ysca-input"
									value=""
									placeholder="<?php echo esc_attr( $pw_placeholder ); ?>"
									autocomplete="new-password"
									spellcheck="false"
								>
								<?php break;

							case 'textarea': ?>
								<textarea
									name="<?php echo esc_attr( $key ); ?>"
									id="<?php echo esc_attr( $field_id ); ?>"
									class="ysca-textarea"
									rows="4"
									placeholder="<?php echo esc_attr( $ph ); ?>"
								><?php echo esc_textarea( $value ); ?></textarea>
								<?php break;

							case 'text':
							default: ?>
								<input
									type="text"
									name="<?php echo esc_attr( $key ); ?>"
									id="<?php echo esc_attr( $field_id ); ?>"
									class="ysca-input"
									value="<?php echo esc_attr( $value ); ?>"
									placeholder="<?php echo esc_attr( $ph ); ?>"
								>
								<?php break;
						endswitch; ?>

						<?php if ( '' !== $desc ) : ?>
							<p class="description"><?php echo esc_html( $desc ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<p class="ysca-inline-actions ysca-inline-actions--start">
		<button type="submit" class="ysca-btn ysca-btn--primary">
			<?php esc_html_e( '儲存設定', 'ys-cart-smilepay-einvoice' ); ?>
		</button>
	</p>

	<p class="description">
		<?php esc_html_e( '儲存後請使用下方「測試連線」按鈕，以當前憑證打 SmilePay 測試環境驗證 Grvc / Verify_key 是否正確。', 'ys-cart-smilepay-einvoice' ); ?>
	</p>
</form>

<?php /* ─────────────── 測試連線（獨立 form，admin-post，不是 AJAX） ─────────────── */ ?>
<form
	method="post"
	action="<?php echo esc_url( $test_url ); ?>"
	class="ysca-card ysca-card--soft ysca-section ysca-card-spaced"
	aria-labelledby="ys-ec-smilepay-test-heading"
>
	<input type="hidden" name="action" value="<?php echo esc_attr( $admin_class::TEST_CONNECTION_ACTION ); ?>">
	<?php wp_nonce_field( $admin_class::NONCE_ACTION ); ?>

	<h2 id="ys-ec-smilepay-test-heading" class="ysca-section__title">
		<?php esc_html_e( '測試連線', 'ys-cart-smilepay-einvoice' ); ?>
	</h2>

	<p class="description">
		<?php esc_html_e( '以目前已儲存的「電子發票帳號 (Grvc)」與「驗證碼 (Verify_key)」呼叫 SmilePay 測試環境，嘗試開立一張 NT$1 的假發票。本動作強制使用 api_test/* 端點，絕對不會產生真實發票。', 'ys-cart-smilepay-einvoice' ); ?>
	</p>

	<p class="ysca-inline-actions ysca-inline-actions--start">
		<button type="submit" class="ysca-btn ysca-btn--secondary">
			<?php esc_html_e( '測試連線', 'ys-cart-smilepay-einvoice' ); ?>
		</button>
	</p>

	<p class="description">
		<?php esc_html_e( '提示：請先儲存「電子發票帳號 (Grvc)」與「驗證碼 (Verify_key)」再執行測試，未儲存的輸入值不會帶入測試請求。', 'ys-cart-smilepay-einvoice' ); ?>
	</p>
</form>
