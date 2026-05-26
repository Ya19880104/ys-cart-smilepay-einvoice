<?php
/**
 * SmilePay invoice provider manifest for YS CART.
 *
 * @package YangSheep\SmilePayEInvoice
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

return [
	'id'                 => 'ys_smilepay',
	'name'               => '速買配 SmilePay 電子發票',
	'description'        => '速買配電子發票 provider，支援 B2C/B2B 開立、作廢、查詢、載具、捐贈與發票列印。',
	'version'            => YS_SMILEPAY_VERSION,
	'contract_version'   => 1,
	'plugin_file'        => YS_SMILEPAY_BASENAME,
	'icon'               => 'dashicons-media-spreadsheet',
	'documentation_url'  => 'https://www.smilepay.net/',
	'legacy_setting_key' => 'ys_ec_smilepay_enabled',
	'domains'            => [ 'invoice' ],
	'capabilities'       => [
		'invoice' => [
			'providers' => [
				[
					'id'          => \YangSheep\SmilePayEInvoice\Providers\YSSmilePayInvoiceProvider::ID,
					'label'       => '速買配 SmilePay 電子發票',
					'class'       => \YangSheep\SmilePayEInvoice\Providers\YSSmilePayInvoiceProvider::class,
					'description' => 'SmilePay 電子發票開立、作廢、查詢與列印。',
				],
			],
		],
	],
	'admin_page'         => [
		'slug'                => 'ys-provider-smilepay',
		'title'               => '速買配 SmilePay 電子發票',
		'render_callback'     => [ \YangSheep\SmilePayEInvoice\Admin\YSSmilePayAdmin::class, 'render' ],
		'capability_required' => 'manage_options',
		'icon'                => 'dashicons-media-spreadsheet',
	],
	'callback_routes'    => [],
	'allowed_hosts'      => [
		'einvoice.smilepay.net',
	],
	'health_check'       => [
		'callback'  => null,
		'cache_ttl' => 3600,
	],
];
