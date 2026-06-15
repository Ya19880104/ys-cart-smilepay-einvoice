<?php
/**
 * Regression: verify_key 解不開時 fail-closed + 後台引導重新輸入（v1.0.5）。
 *
 * 背景：verify_key 以 YSCrypto 站台金鑰加密儲存。網站搬遷 / SECURE_AUTH_KEY 變動會讓
 * 既有加密值解不開。舊版 decrypt_verify_key 會 passthrough「加密 blob 當金鑰」送往 SmilePay，
 * 只回「查無商家帳號」難排查。v1.0.5 改 fail-closed（回 ''）+ 後台 admin_notices 明確引導。
 */

declare( strict_types=1 );

$root     = dirname( __DIR__, 2 );
$provider = (string) file_get_contents( $root . '/src/Providers/YSSmilePayInvoiceProvider.php' );
$plugin   = (string) file_get_contents( $root . '/src/YSSmilePayPlugin.php' );

$pass = 0;
$fail = 0;

$check = static function ( string $label, bool $ok ) use ( &$pass, &$fail ): void {
	if ( $ok ) {
		echo "PASS: {$label}\n";
		$pass++;
		return;
	}
	echo "FAIL: {$label}\n";
	$fail++;
};

$check(
	'provider has looks_encrypted envelope detector',
	str_contains( $provider, 'function looks_encrypted' )
		&& str_contains( $provider, 'base64_decode( $value, true )' )
		&& str_contains( $provider, '>= 28' )
);

$check(
	'decrypt_verify_key fails closed (no passthrough of an undecryptable encrypted blob)',
	str_contains( $provider, 'function decrypt_verify_key' )
		&& str_contains( $provider, '! $this->looks_encrypted( $verify_key )' )
		// 舊的 passthrough 模式必須消失（解不開回原值）。
		&& ! str_contains( $provider, "'' !== \$decrypted ? \$decrypted : \$verify_key" )
);

$check(
	'provider exposes verify_key_needs_reentry()',
	str_contains( $provider, 'public function verify_key_needs_reentry' )
);

$check(
	'plugin registers admin_notices guidance hook',
	str_contains( $plugin, "add_action( 'admin_notices', [ \$this, 'maybe_render_verify_key_notice' ] )" )
);

$check(
	'notice guides user to re-enter verify_key and links to the settings page',
	str_contains( $plugin, 'function maybe_render_verify_key_notice' )
		&& str_contains( $plugin, 'verify_key_needs_reentry' )
		&& str_contains( $plugin, 'YSSmilePayAdmin::MENU_SLUG' )
		&& str_contains( $plugin, 'current_user_can( \'manage_options\' )' )
);

echo "REGRESSION v107_verify_key_failclosed_contract PASS={$pass} FAIL={$fail}\n";
exit( $fail > 0 ? 1 : 0 );
