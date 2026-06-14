<?php
/**
 * Regression: SmilePay invoice print/PDF files are fetched server-side.
 *
 * SmilePay print URLs contain Verify_key. They must never be returned to the
 * browser as customer-visible redirect URLs.
 */

declare( strict_types=1 );

$root     = dirname( __DIR__, 2 );
$provider = $root . '/src/Providers/YSSmilePayInvoiceProvider.php';
$client   = $root . '/src/Api/YSSmilePayApiClient.php';
$provider_src = is_readable( $provider ) ? (string) file_get_contents( $provider ) : '';
$client_src   = is_readable( $client ) ? (string) file_get_contents( $client ) : '';
$pass = 0;
$fail = 0;

function v105_check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) {
		echo "[PASS] {$label}\n";
		$pass++;
		return;
	}
	echo "[FAIL] {$label}\n";
	$fail++;
}

function v105_method_body( string $source, string $method ): string {
	$needle = 'function ' . $method;
	$start  = strpos( $source, $needle );
	if ( false === $start ) {
		return '';
	}
	$brace = strpos( $source, '{', $start );
	if ( false === $brace ) {
		return '';
	}
	$depth = 0;
	$len   = strlen( $source );
	for ( $i = $brace; $i < $len; $i++ ) {
		$char = $source[ $i ];
		if ( '{' === $char ) {
			$depth++;
		} elseif ( '}' === $char ) {
			$depth--;
			if ( 0 === $depth ) {
				return substr( $source, $start, $i - $start + 1 );
			}
		}
	}
	return '';
}

$customer_url_body = v105_method_body( $provider_src, 'get_customer_invoice_url' );

v105_check( 'provider implements stream_customer_invoice_file', false !== strpos( $provider_src, 'function stream_customer_invoice_file' ) );
v105_check( 'customer URL method no longer builds SmilePay secret URL', '' !== $customer_url_body && false === strpos( $customer_url_body, 'build_print_url' ) );
v105_check( 'customer URL method fails closed instead of returning file_url', false !== strpos( $customer_url_body, 'server-side proxy' ) && false !== strpos( $customer_url_body, "'file_url' => ''" ) );
v105_check( 'client implements fetch_print_file', false !== strpos( $client_src, 'function fetch_print_file' ) );
v105_check( 'server-side fetch uses WordPress HTTP GET', false !== strpos( $client_src, 'wp_remote_get(' ) );
v105_check( 'server-side fetch blocks redirects', false !== strpos( $client_src, "'redirection'         => 0" ) );
v105_check( 'server-side fetch caps response size', false !== strpos( $client_src, "'limit_response_size' => self::PRINT_FILE_LIMIT_BYTES" ) );
v105_check( 'server-side fetch validates SmilePay host', false !== strpos( $client_src, "'einvoice.smilepay.net' !== \$host" ) );
v105_check( 'server-side fetch does not log raw print URL', false === strpos( v105_method_body( $client_src, 'fetch_print_file' ), 'log_request' ) );

echo "PASS={$pass} FAIL={$fail}\n";
exit( $fail > 0 ? 1 : 0 );

