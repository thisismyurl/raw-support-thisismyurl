<?php
/**
 * Author:                Christopher Ross
 * Author URI:            https://thisismyurl.com/?source=raw-support-thisismyurl
 * Plugin Name:           RAW Support by thisismyurl
 * Plugin URI:            https://thisismyurl.com/raw-support-thisismyurl/?source=raw-support-thisismyurl
 * Donate link:           https://thisismyurl.com/raw-support-thisismyurl/#register?source=raw-support-thisismyurl
 * Description:           Safely enable RAW uploads and convert existing images to AVIF format.
 * Tags:                  raw, uploads, media library, optimization
 * Version:               1.2601.04
 * Requires at least:     5.3
 * Requires PHP:          7.4
 * Update URI:            https://github.com/thisismyurl/raw-support-thisismyurl
 * GitHub Plugin URI:     https://github.com/thisismyurl/raw-support-thisismyurl
 * Primary Branch:        main
 * Text Domain:           raw-support-thisismyurl
 * License:               GPL2
 * License URI:           https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

namespace TIMU\Plugins\RAW;

use TIMU\Core\TIMU_Core_v1;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suite Bootloader
 */
function timu_raw_support_register_and_boot(): void {
	global $timu_suite_registry;
	if ( ! isset( $timu_suite_registry ) ) $timu_suite_registry = [ 'core_versions' => [], 'plugins' => [] ];

	$bundled_core_path = plugin_dir_path( __FILE__ ) . 'core/class-timu-core.php';
	$bundled_version   = '1.26010313';

	$timu_suite_registry['core_versions'][$bundled_version] = $bundled_core_path;
	$timu_suite_registry['plugins']['raw-support'] = [ 'class' => __NAMESPACE__ . '\TIMU_RAW_Support' ];

	if ( ! has_action( 'plugins_loaded', 'timu_suite_master_bootloader' ) ) {
		add_action( 'plugins_loaded', 'timu_suite_master_bootloader', -100 );
	}
}
timu_raw_support_register_and_boot();

class TIMU_RAW_Support extends TIMU_Core_v1 {

	public function __construct() {
		parent::__construct( 'raw-support-thisismyurl', plugin_dir_url( __FILE__ ), 'timu_raw_settings_group', '', 'tools.php' );
		add_action( 'init', [ $this, 'setup_plugin' ] );
		add_filter( 'upload_mimes', [ $this, 'add_raw_mime_types' ] );
		add_filter( 'wp_handle_upload', [ $this, 'process_raw_upload' ] );
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		register_activation_hook( __FILE__, [ $this, 'activate_plugin_defaults' ] );
	}

	public function setup_plugin(): void {
		$this->is_licensed();
		$blueprint = [
			'config' => [
				'title' => __( 'RAW Configuration', 'raw-support-thisismyurl' ),
				'fields' => [
					'enabled' => [ 'type' => 'toggle', 'label' => __( 'Enable Support', 'raw-support-thisismyurl' ), 'default' => 1 ],
					'target_format' => [ 'type' => 'radio', 'label' => __( 'Target Format', 'raw-support-thisismyurl' ), 'options' => [ 'webp' => 'WebP' ], 'default' => 'webp' ],
				],
			],
		];
		$this->init_settings_generator( $blueprint );
	}

	public function activate_plugin_defaults(): void {
		$option_name = "{$this->plugin_slug}_options";
		if ( false === get_option( $option_name ) ) update_option( $option_name, [ 'enabled' => 1, 'target_format' => 'webp' ] );
	}

	public function add_admin_menu(): void {
		add_management_page( __( 'RAW Settings', 'raw-support-thisismyurl' ), __( 'RAW Support', 'raw-support-thisismyurl' ), 'manage_options', $this->plugin_slug, [ $this, 'render_settings_page' ] );
	}

	public function add_raw_mime_types( array $mimes ): array {
		if ( (int) $this->get_plugin_option( 'enabled', 1 ) ) {
			$mimes['cr2'] = 'image/x-canon-cr2';
			$mimes['nef'] = 'image/x-nikon-nef';
			$mimes['arw'] = 'image/x-sony-arw';
			$mimes['dng'] = 'image/x-adobe-dng';
		}
		return $mimes;
	}

	public function process_raw_upload( array $upload ): array {
		if ( ! (int) $this->get_plugin_option( 'enabled', 1 ) ) return $upload;
		return $this->processor->process_image_conversion( $upload, 'webp', 80 );
	}
}
