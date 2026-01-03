<?php
/**
 * Author:              Christopher Ross
 * Author URI:          https://thisismyurl.com/?source=raw-support-thisismyurl
 * Plugin Name:         RAW Support by thisismyurl
 * Plugin URI:          https://thisismyurl.com/raw-support-thisismyurl/?source=raw-support-thisismyurl
 * Donate link:         https://thisismyurl.com/raw-support-thisismyurl/#register?source=raw-support-thisismyurl
 * 
 * Description:         Safely enable RAW uploads and convert existing images to AVIF format.
 * Tags:                raw, uploads, media library, optimization
 * 
 * Version: 1.26010222
 * Requires at least:   5.3
 * Requires PHP:        7.4
 * 
 * Update URI:          https://github.com/thisismyurl/raw-support-thisismyurl
 * GitHub Plugin URI:   https://github.com/thisismyurl/raw-support-thisismyurl
 * Primary Branch:      main
 * Text Domain:         raw-support-thisismyurl
 * 
 * License:             GPL2
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 * 
 * @package TIMU_AVIF_Support
 * 
 * 
 */
/**
 * Security: Prevent direct file access to prevent path traversal or unauthorized execution.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Version-aware Core Loader
 *
 * Checks for the existence of the base TIMU_Core_v1 class to ensure the shared 
 * library is loaded exactly once, preventing class redeclaration errors in a 
 * multi-plugin environment.
 */
function timu_raw_support_load_core() {
	$core_path = plugin_dir_path( __FILE__ ) . 'core/class-timu-core.php';
	if ( ! class_exists( 'TIMU_Core_v1' ) ) {
		require_once $core_path;
	}
}
timu_raw_support_load_core();

/**
 * Class TIMU_RAW_Support
 *
 * Extends TIMU_Core_v1 to leverage shared settings generation and image conversion 
 * utilities. Implements specific logic for RAW sanitization and Media Library display.
 */
class TIMU_RAW_Support extends TIMU_Core_v1 {

	/**
	 * Constructor: Orchestrates the plugin lifecycle.
	 *
	 * Registers hooks for settings initialization, MIME type filtering, 
	 * and pre-upload processing.
	 */
	public function __construct() {
		parent::__construct(
			'raw-support-thisismyurl',      // Unique plugin slug.
			plugin_dir_url( __FILE__ ),       // Base URL for enqueuing assets.
			'timu_raw_settings_group',        // Settings API group name.
			'',                               // Custom icon URL (null for default).
			'tools.php'                       // Admin menu parent location.
		);

		/**
		 * Hook: Initialize settings blueprint after standard core initialization.
		 */
		add_action( 'init', array( $this, 'setup_plugin' ) );

		/**
		 * Filters: Lifecycle hooks for expanding and sanitizing uploads.
		 */
		add_filter( 'upload_mimes', array( $this, 'add_raw_mime_types' ) );
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'process_raw_upload' ) );

		/**
		 * Actions: UI enhancements for the WordPress Admin dashboard.
		 */
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

		/**
		 * Activation: Register defaults only once upon plugin activation.
		 */
		register_activation_hook( __FILE__, array( $this, 'activate_plugin_defaults' ) );

		add_action( 'timu_sidebar_under_banner', array( $this, 'render_default_sidebar_actions' ) );
	}

	/**
	 * Configuration Blueprint
	 *
	 * Defines the settings schema for the Core's automated UI engine. 
	 * Utilizes cascading visibility via parent/child keys for a streamlined UX.
	 */
	public function setup_plugin() {
		/** @var bool $webp_active Dependency check for sibling WebP plugin. */
		$webp_active = class_exists( 'TIMU_WebP_Support' );
		/** @var bool $avif_active Dependency check for sibling AVIF plugin. */
		$avif_active = class_exists( 'TIMU_AVIF_Support' );

		$this->is_licensed();

		/**
		 * Dynamically build the radio options based on the presence of siblings.
		 */
		$format_options = array(
			'raw'     => __( 'Upload as RAW file format(s).', 'raw-support-thisismyurl' )
		);

		if ( $webp_active ) {
			$format_options['webp'] = __( 'Convert to .webp file format.', 'raw-support-thisismyurl' );
		}

		if ( $avif_active ) {
			$format_options['avif'] = __( 'Convert to .avif file format.', 'raw-support-thisismyurl' );
		}

		$blueprint = array(
			'config' => array(
				'title'  => __( 'RAW Configuration', 'raw-support-thisismyurl' ),
				'fields' => array(
					'enabled'       => array(
						'type'      => 'switch',
						'label'     => __( 'Enable RAW Support', 'raw-support-thisismyurl' ),
						'desc'      => __( 'Allows .raw files to be uploaded and processed by this plugin.', 'raw-support-thisismyurl' ),
						'is_parent' => true,
						'default'   => 1,
					),
					'target_format' => array(
						'type'      => 'radio',
						'label'     => __( 'RAW Handling Mode', 'raw-support-thisismyurl' ),
						'parent'    => 'enabled',
						'is_parent' => true,
						'options'   => $format_options,
						'default'   => 'raw',
						'desc'      => ( ! $webp_active || ! $avif_active )
									? __( 'Install <a href="https://thisismyurl.com/thisismyurl-webp-support/">WebP</a> or <a href="https://thisismyurl.com/thisismyurl-avif-support/">AVIF</a> plugins for more options.', 'raw-support-thisismyurl' )
									: __( 'Choose how to process .raw files upon upload.', 'raw-support-thisismyurl' ),
					),
					'webp_quality'  => array(
						'type'    => 'range', // Now a slider!
						'default' => 80,
						'min'     => 10,
						'max'     => 100,
						'label'        => __( 'WebP Quality', 'raw-support-thisismyurl' ),
						'default'      => 80,
						'show_if' => array(
							'field' => 'target_format', // Must match the ID of your radio buttons
							'value' => 'webp'           // Must match the value 'webp' in the radio option
						)
					),
					'avif_quality'  => array(
						'type'    => 'range', // Now a slider!
						'default' => 80,
						'min'     => 10,
						'max'     => 100,
						'label'        => __( 'AVIF Quality', 'raw-support-thisismyurl' ),
						'show_if' => array(
							'field' => 'target_format', // Must match the ID of your radio buttons
							'value' => 'avif'           // Must match the value 'webp' in the radio option
						)
					),
					'hr'  => array(
						'type'    	=> 'hr'
					),
					'license_key'  => array(
						'type'    => 'license',
						'default' => '',
						'label'   => __( 'License Key', 'webp-support-thisismyurl' ),
						'desc'      => ( $this->license_message )
					),
				),
			),
		);

		$this->init_settings_generator( $blueprint );
	}
	

	/**
	 * Default Option Initialization
	 *
	 * Adheres to standard update_option logic to avoid overwriting existing user data.
	 */
	public function activate_plugin_defaults() {
		$option_name = "{$this->plugin_slug}_options";
		if ( false === get_option( $option_name ) ) {
			update_option( $option_name, array(
				'enabled'       => 1,
				'target_format' => 'raw',
			) );
		}
	}

	/**
	 * Admin Menu Entry
	 *
	 * Hooks into the WordPress Tools menu.
	 */
	public function add_admin_menu() {
		add_management_page(
			__( 'RAW Support Settings', 'raw-support-thisismyurl' ),
			__( 'RAW Support', 'raw-support-thisismyurl' ),
			'manage_options',
			$this->plugin_slug,
			array( $this, 'render_settings_page' )
		);
	}
	/**
	 * Injects WebP-specific buttons into the Core sidebar.
	 */
	public function add_bulk_action_buttons( $current_slug ) {
		// Only show these buttons on the WebP settings page.
		if ( $current_slug !== $this->plugin_slug ) {
			return;
		}

	}
	/**
	 * Expand MIME Support
	 *
	 * Modifies the allowed MIME types to permit RAW and compressed RAW uploads.
	 *
	 * @param array $mimes Existing allowed MIME types.
	 * @return array Filtered MIME types.
	 */
	public function add_raw_mime_types( $mimes ) {
		if ( 1 === (int) $this->get_plugin_option( 'enabled', 1 ) ) {
			// Canon
			$mimes['cr2']  = 'image/x-canon-cr2';
			$mimes['cr3']  = 'image/x-canon-cr3';
			$mimes['crw']  = 'image/x-canon-crw';

			// Nikon
			$mimes['nef']  = 'image/x-nikon-nef';
			$mimes['nrw']  = 'image/x-nikon-nrw';

			// Sony
			$mimes['arw']  = 'image/x-sony-arw';
			$mimes['sr2']  = 'image/x-sony-sr2';

			// Adobe / Generic
			$mimes['dng']  = 'image/x-adobe-dng';
			
			// Fujifilm
			$mimes['raf']  = 'image/x-fuji-raf';

			// Olympus
			$mimes['orf']  = 'image/x-olympus-orf';

			// Panasonic
			$mimes['rw2']  = 'image/x-panasonic-rw2';

			// Pentax
			$mimes['pef']  = 'image/x-pentax-pef';
		}
		return $mimes;
	}

}

/**
 * Initialize the RAW support plugin.
 */
new TIMU_RAW_Support();
