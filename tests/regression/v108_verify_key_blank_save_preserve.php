<?php
/**
 * Regression: 空白儲存不得清掉「解不開」的 verify_key、引導提示須持續（v1.0.6）。
 *
 * CODEX P2：v1.0.5 fail-closed 後，使用者進設定頁但未重填即按儲存時，
 * password 欄位 retain-on-empty 會用「解密後的空字串」覆寫 raw 加密 blob →
 * verify_key_needs_reentry() 不再成立 → 引導提示靜默消失，使用者陷入
 * 「provider 不可用但無提示」。
 *
 * v1.0.6：
 *   - preserve_unusable_verify_key()：留空 + 既有為加密信封 → 保留 raw（不被空值覆寫）。
 *   - encrypt_verify_key()：已是信封（含解不開者）不重複加密。
 *   - admin decrypt_verify_key()：與 provider 一致 fail-closed。
 *   - 後台提示改以「解密後 verify_key 是否為空」為總判準（涵蓋解不開 + 缺失），
 *     即使 raw 被清也持續顯示。
 */

declare( strict_types=1 );

$root   = dirname( __DIR__, 2 );
$admin  = (string) file_get_contents( $root . '/src/Admin/YSSmilePayAdmin.php' );
$plugin = (string) file_get_contents( $root . '/src/YSSmilePayPlugin.php' );

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
	'handle_save preserves an unusable verify_key before storage',
	str_contains( $admin, 'preserve_unusable_verify_key( $sanitized, $raw )' )
		&& str_contains( $admin, 'function preserve_unusable_verify_key' )
);

$check(
	'preserve restores the raw encrypted envelope only when the field was left blank',
	str_contains( $admin, "'' !== \$posted" )
		&& str_contains( $admin, 'self::looks_encrypted( $raw_vkey )' )
		&& str_contains( $admin, "\$sanitized['verify_key'] = \$raw_vkey" )
);

$check(
	'encrypt_verify_key never double-encrypts an existing envelope',
	str_contains( $admin, 'function encrypt_verify_key' )
		&& str_contains( $admin, 'if ( self::looks_encrypted( $verify_key ) ) {' )
);

$check(
	'admin decrypt_verify_key is fail-closed (mirrors provider; no passthrough of an undecryptable blob)',
	str_contains( $admin, 'function decrypt_verify_key' )
		&& str_contains( $admin, '! self::looks_encrypted( $verify_key )' )
		&& ! str_contains( $admin, "'' !== \$decrypted ? \$decrypted : \$verify_key" )
);

$check(
	'admin has looks_encrypted envelope detector',
	str_contains( $admin, 'function looks_encrypted' )
		&& str_contains( $admin, 'base64_decode( $value, true )' )
		&& str_contains( $admin, '>= 28' )
);

$check(
	'notice fires whenever the effective verify_key is empty (covers both undecryptable and missing)',
	str_contains( $plugin, '$effective_vkey' )
		&& str_contains( $plugin, "if ( '' !== \$effective_vkey ) {" )
		&& str_contains( $plugin, '尚未設定驗證碼' )
		&& str_contains( $plugin, '驗證碼無法解密' )
);

echo "REGRESSION v108_verify_key_blank_save_preserve PASS={$pass} FAIL={$fail}\n";
exit( $fail > 0 ? 1 : 0 );
