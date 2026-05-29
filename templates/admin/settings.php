<?php
/**
 * SmilePay e-invoice provider settings template.
 *
 * Rendered inside YSAdminApp::open()/close().
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

$field_map = [];
foreach ( $fields as $field ) {
	$key = (string) ( $field['key'] ?? '' );
	if ( '' !== $key ) {
		$field_map[ $key ] = $field;
	}
}

$save_url = admin_url( 'admin-post.php' );
$test_url = admin_url( 'admin-post.php' );

$field_groups = [
	'api'      => [
		'title'       => __( 'API 憑證', 'ys-cart-smilepay-einvoice' ),
		'description' => __( '填入速買配提供的電子發票帳號與驗證碼。正式環境上線前，請先以測試模式完成連線測試。', 'ys-cart-smilepay-einvoice' ),
		'fields'      => [ 'grvc', 'verify_key', 'sandbox', 'pos_system_id' ],
	],
	'issue'    => [
		'title'       => __( '開立規則', 'ys-cart-smilepay-einvoice' ),
		'description' => __( '控制訂單狀態變更後是否自動開立發票。若 YS CART 核心已有全站發票規則，會以核心規則為準。', 'ys-cart-smilepay-einvoice' ),
		'fields'      => [ 'auto_issue' ],
	],
	'carriers' => [
		'title'       => __( '載具與捐贈', 'ys-cart-smilepay-einvoice' ),
		'description' => __( '選擇結帳頁可提供的發票載具與捐贈方式。未啟用的項目不應出現在用戶端選項。', 'ys-cart-smilepay-einvoice' ),
		'fields'      => [ 'enable_member_carrier', 'enable_phone_carrier', 'enable_cdc_carrier', 'enable_donate' ],
	],
];

$render_field = static function ( array $field ) use ( $settings ): void {
	$key      = (string) ( $field['key'] ?? '' );
	if ( '' === $key ) {
		return;
	}

	$label    = (string) ( $field['label'] ?? $key );
	$type     = (string) ( $field['type'] ?? 'text' );
	$required = ! empty( $field['required'] );
	$desc     = (string) ( $field['description'] ?? '' );
	$ph       = (string) ( $field['placeholder'] ?? '' );
	$default  = (string) ( $field['default'] ?? '' );
	$value    = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : $default;
	$field_id = 'ys-ec-smilepay-' . sanitize_html_class( $key );
	?>
	<div class="ysca-field ys-ec-form-group">
		<label class="ysca-field__label<?php echo $required ? ' ysca-field__label--required' : ''; ?>" for="<?php echo esc_attr( $field_id ); ?>">
			<?php echo esc_html( $label ); ?>
		</label>

		<?php if ( 'toggle' === $type ) : ?>
			<label class="ysca-switch-label ysca-switch-label--trailing" for="<?php echo esc_attr( $field_id ); ?>">
				<span><?php esc_html_e( '啟用', 'ys-cart-smilepay-einvoice' ); ?></span>
				<span class="ysca-switch" aria-hidden="true">
					<input
						type="checkbox"
						name="<?php echo esc_attr( $key ); ?>"
						id="<?php echo esc_attr( $field_id ); ?>"
						value="1"
						<?php checked( '1' === $value ); ?>
					>
					<span class="ysca-switch-slider"></span>
				</span>
			</label>
		<?php elseif ( 'select' === $type ) : ?>
			<select
				name="<?php echo esc_attr( $key ); ?>"
				id="<?php echo esc_attr( $field_id ); ?>"
				class="ysca-select ysca-field--md"
			>
				<?php foreach ( (array) ( $field['options'] ?? [] ) as $option_value => $option_label ) : ?>
					<option value="<?php echo esc_attr( (string) $option_value ); ?>" <?php selected( $value, (string) $option_value ); ?>>
						<?php echo esc_html( (string) $option_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		<?php elseif ( 'password' === $type ) : ?>
			<input
				type="password"
				name="<?php echo esc_attr( $key ); ?>"
				id="<?php echo esc_attr( $field_id ); ?>"
				class="ysca-input ysca-field--md"
				value=""
				placeholder="<?php echo esc_attr( '' !== $value ? __( '已儲存；留空不變更', 'ys-cart-smilepay-einvoice' ) : $ph ); ?>"
				autocomplete="new-password"
				spellcheck="false"
			>
		<?php elseif ( 'textarea' === $type ) : ?>
			<textarea
				name="<?php echo esc_attr( $key ); ?>"
				id="<?php echo esc_attr( $field_id ); ?>"
				class="ysca-textarea"
				rows="4"
				placeholder="<?php echo esc_attr( $ph ); ?>"
			><?php echo esc_textarea( $value ); ?></textarea>
		<?php else : ?>
			<input
				type="text"
				name="<?php echo esc_attr( $key ); ?>"
				id="<?php echo esc_attr( $field_id ); ?>"
				class="ysca-input ysca-field--md"
				value="<?php echo esc_attr( $value ); ?>"
				placeholder="<?php echo esc_attr( $ph ); ?>"
			>
		<?php endif; ?>

		<?php if ( '' !== $desc ) : ?>
			<p class="description ysca-field__hint"><?php echo esc_html( $desc ); ?></p>
		<?php endif; ?>
	</div>
	<?php
};
?>

<?php if ( null !== $notice ) : ?>
	<?php $notice_class = 'success' === $notice['type'] ? 'notice-success' : 'notice-error'; ?>
	<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible ysca-notice-spaced" role="status">
		<p><?php echo esc_html( $notice['message'] ); ?></p>
	</div>
<?php endif; ?>

<nav class="ysca-tabs ysca-tabs--navigation ysca-tabs--scroll" role="navigation" aria-label="<?php esc_attr_e( '速買配設定分頁', 'ys-cart-smilepay-einvoice' ); ?>">
	<a class="ysca-tab ysca-tabs__item ysca-tabs__item--active is-active" href="#ys-ec-smilepay-api"><?php esc_html_e( 'API 設定', 'ys-cart-smilepay-einvoice' ); ?></a>
	<a class="ysca-tab ysca-tabs__item" href="#ys-ec-smilepay-issue"><?php esc_html_e( '開立規則', 'ys-cart-smilepay-einvoice' ); ?></a>
	<a class="ysca-tab ysca-tabs__item" href="#ys-ec-smilepay-carriers"><?php esc_html_e( '載具與捐贈', 'ys-cart-smilepay-einvoice' ); ?></a>
	<a class="ysca-tab ysca-tabs__item" href="#ys-ec-smilepay-test"><?php esc_html_e( '測試連線', 'ys-cart-smilepay-einvoice' ); ?></a>
</nav>

<form method="post" action="<?php echo esc_url( $save_url ); ?>" class="ysca-stack-md" aria-labelledby="ys-ec-smilepay-settings-heading">
	<input type="hidden" name="action" value="<?php echo esc_attr( $admin_class::ADMIN_POST_ACTION ); ?>">
	<?php wp_nonce_field( $admin_class::NONCE_ACTION ); ?>

	<section class="ysca-card ysca-card--soft ysca-section ysca-settings-panel--master" aria-labelledby="ys-ec-smilepay-settings-heading">
		<div class="ysca-inline-cluster">
			<div>
				<h2 id="ys-ec-smilepay-settings-heading" class="ysca-section__title">
					<?php esc_html_e( '速買配電子發票', 'ys-cart-smilepay-einvoice' ); ?>
				</h2>
				<p class="description">
					<?php esc_html_e( '啟用後，速買配會出現在 YS CART 電子發票供應商清單；未啟用或憑證不完整時，不會成為可用發票供應商。', 'ys-cart-smilepay-einvoice' ); ?>
				</p>
			</div>
			<div>
				<label class="ysca-switch-label ysca-switch-label--trailing" for="ys-ec-smilepay-enabled">
					<span><?php esc_html_e( '啟用供應商', 'ys-cart-smilepay-einvoice' ); ?></span>
					<span class="ysca-switch" aria-hidden="true">
						<input
							type="checkbox"
							name="<?php echo esc_attr( $admin_class::OPTION_ENABLED ); ?>"
							id="ys-ec-smilepay-enabled"
							value="1"
							<?php checked( $enabled ); ?>
						>
						<span class="ysca-switch-slider"></span>
					</span>
				</label>
			</div>
		</div>
	</section>

	<section class="ysca-card ysca-card--soft ysca-section" aria-labelledby="ys-ec-smilepay-overview-title">
		<div class="ysca-section-head">
			<div>
				<h2 id="ys-ec-smilepay-overview-title" class="ysca-section-head__title">
					<?php esc_html_e( '接入說明', 'ys-cart-smilepay-einvoice' ); ?>
				</h2>
				<p class="ysca-section-head__desc">
					<?php esc_html_e( '請向速買配申請電子發票服務後填入 API 憑證。測試帳號請參閱外掛 README；使用測試憑證時請保持測試模式啟用。', 'ys-cart-smilepay-einvoice' ); ?>
				</p>
			</div>
		</div>
		<p class="description">
			<?php esc_html_e( '官方網站：', 'ys-cart-smilepay-einvoice' ); ?>
			<a href="https://www.smilepay.net/" target="_blank" rel="noopener">https://www.smilepay.net/</a>
			<span aria-hidden="true"> · </span>
			<?php esc_html_e( 'API 文件：', 'ys-cart-smilepay-einvoice' ); ?>
			<a href="https://www.smilepay.net/api/" target="_blank" rel="noopener">https://www.smilepay.net/api/</a>
		</p>
	</section>

	<?php foreach ( $field_groups as $section_id => $group ) : ?>
		<section
			id="ys-ec-smilepay-<?php echo esc_attr( $section_id ); ?>"
			class="ysca-card ysca-card--soft ysca-section"
			aria-labelledby="ys-ec-smilepay-<?php echo esc_attr( $section_id ); ?>-title"
		>
			<div class="ysca-section-head">
				<div>
					<h2 id="ys-ec-smilepay-<?php echo esc_attr( $section_id ); ?>-title" class="ysca-section-head__title">
						<?php echo esc_html( (string) $group['title'] ); ?>
					</h2>
					<p class="ysca-section-head__desc">
						<?php echo esc_html( (string) $group['description'] ); ?>
					</p>
				</div>
			</div>
			<div class="ysca-form-grid ysca-form-grid--2">
				<?php
				foreach ( (array) $group['fields'] as $field_key ) {
					if ( isset( $field_map[ $field_key ] ) ) {
						$render_field( $field_map[ $field_key ] );
					}
				}
				?>
			</div>
		</section>
	<?php endforeach; ?>

	<div class="ysca-actions-bar ysca-actions-bar--wrap">
		<button type="submit" class="ysca-btn ysca-btn--primary">
			<?php esc_html_e( '儲存速買配設定', 'ys-cart-smilepay-einvoice' ); ?>
		</button>
		<span class="description">
			<?php esc_html_e( '儲存後請執行測試連線，確認 Grvc / Verify_key 可正常呼叫 SmilePay 測試端點。', 'ys-cart-smilepay-einvoice' ); ?>
		</span>
	</div>
</form>

<form
	id="ys-ec-smilepay-test"
	method="post"
	action="<?php echo esc_url( $test_url ); ?>"
	class="ysca-card ysca-card--soft ysca-section ysca-card-spaced"
	aria-labelledby="ys-ec-smilepay-test-heading"
>
	<input type="hidden" name="action" value="<?php echo esc_attr( $admin_class::TEST_CONNECTION_ACTION ); ?>">
	<?php wp_nonce_field( $admin_class::NONCE_ACTION ); ?>

	<div class="ysca-section-head">
		<div>
			<h2 id="ys-ec-smilepay-test-heading" class="ysca-section-head__title">
				<?php esc_html_e( '測試連線', 'ys-cart-smilepay-einvoice' ); ?>
			</h2>
			<p class="ysca-section-head__desc">
				<?php esc_html_e( '使用目前已儲存的憑證呼叫 SmilePay 測試端點並送出一筆 NT$1 測試發票資料。', 'ys-cart-smilepay-einvoice' ); ?>
			</p>
		</div>
		<button type="submit" class="ysca-btn ysca-btn--secondary">
			<?php esc_html_e( '執行測試連線', 'ys-cart-smilepay-einvoice' ); ?>
		</button>
	</div>
	<p class="description">
		<?php esc_html_e( '測試連線不使用 AJAX；送出後會回到本頁顯示結果。正式環境上線前，請先取消測試模式並重新測試真實憑證。', 'ys-cart-smilepay-einvoice' ); ?>
	</p>
</form>
