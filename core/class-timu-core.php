<?php
/**
 * TIMU Shared Core Library - Controller
 *
 * This class serves as the central hub for the TIMU plugin suite.
 * It coordinates sub-modules and handles global licensing/filtering.
 *
 * @package     TIMU_Core
 * @version     1.2601.031250
 * @since       1.0.0
 */

declare(strict_types=1);

namespace TIMU\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract Core Class
 * * The central neurological system for the plugin suite.
 */
abstract class TIMU_Core_v1 {

	/** @var string Plugin slug */
	public string $plugin_slug;

	/** @var string Plugin URL */
	public string $plugin_url;

	/** @var string Options group name */
	public string $options_group;

	/** @var string Plugin icon path */
	public string $plugin_icon;

	/** @var string Parent menu slug */
	public string $menu_parent;

	/** @var string Licensing message */
	public string $license_message = '';

	/** @var array Blueprint for settings generation */
	public array $settings_blueprint = [];

	/** @var \WP_Filesystem_Base|null WP_Filesystem instance */
	public $fs = null;

	/** @var \TIMU\Core\TIMU_Admin_v1|null Admin component */
	public $admin;

	/** @var \TIMU\Core\TIMU_Ajax_v1|null AJAX component */
	public $ajax;

	/** @var \TIMU\Core\TIMU_Processor_v1|null Processor component */
	public $processor;

	/** @var \TIMU\Core\TIMU_Vault_v1|null Vault component */
	public $vault;

	/**
	 * Constructor
	 */
	public function __construct( 
		string $slug, 
		string $url, 
		string $group, 
		string $icon = '', 
		string $parent = 'options-general.php' 
	) {
		$this->plugin_slug   = $slug;
		$this->plugin_url    = $url;
		$this->options_group = $group;
		$this->plugin_icon   = $icon;
		$this->menu_parent   = $parent;

		$this->load_components();

		// Dashboard Hooks.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_core_assets' ] );
		add_filter( "plugin_action_links_{$this->plugin_slug}/{$this->plugin_slug}.php", [ $this, 'add_plugin_action_links' ] );
		add_action( 'admin_init', [ $this, 'handle_core_activation_redirect' ] );

		// Filter the content for image replacements.
		add_filter( 'the_content', [ $this, 'filter_content_images' ], 99 );

		// Sidebar Integration.
		if ( $this->admin ) {
			add_action( 'timu_sidebar_under_banner', [ $this->admin, 'render_default_sidebar_actions' ] );
		}

		// Media Library integration.
		add_filter( 'attachment_fields_to_edit', [ $this, 'add_media_sidebar_actions' ], 10, 2 );
	}

	/**
	 * Loads internal components.
	 */
	private function load_components(): void {
		require_once 'class-timu-vault.php';
		$this->vault = new TIMU_Vault_v1( $this );

		if ( is_admin() || wp_doing_ajax() ) {
			require_once 'class-timu-admin.php';
			require_once 'class-timu-ajax.php';
			require_once 'class-timu-processor.php';

			$this->processor = new TIMU_Processor_v1( $this );
			$this->ajax      = new TIMU_Ajax_v1( $this );
			$this->admin     = new TIMU_Admin_v1( $this );
		}
	}

	/**
	 * UI Bridge: Settings API registration.
	 */
	public function init_settings_generator( array $blueprint ): void {
		$this->settings_blueprint = $blueprint;
		$this->admin?->register_settings_api();
	}

	/**
	 * UI Bridge: Renders settings page.
	 */
	public function render_settings_page(): void {
		$this->admin?->render_settings_page();
	}

	/**
	 * Core Image Conversion Logic.
	 *
	 * @param int $id Attachment ID.
	 * @return array{success: bool, message: string}
	 */
	public function run_conversion_logic( int $id ): array {
		$prefix      = $this->get_data_prefix();
		$savings_key = "_{$prefix}_savings";
		$file_path   = get_attached_file( $id );

		if ( ! $file_path || ! file_exists( (string) $file_path ) ) {
			return [
				'success' => false,
				'message' => __( 'File not found on disk.', 'timu' ),
			];
		}

		$old_size   = filesize( $file_path );
		$vault_path = $this->vault->get_vault_path( $file_path );

		if ( ! $this->vault->move_to_vault( $file_path, $vault_path ) ) {
			return [
				'success' => false,
				'message' => __( 'Vaulting failed. Check permissions.', 'timu' ),
			];
		}

		$quality = (int) $this->get_plugin_option( 'quality', 80 );
		$target  = ( str_contains( $this->plugin_slug, 'avif' ) ) ? 'avif' : 'webp';

		$result = $this->processor->process_image_conversion(
			[
				'file' => $vault_path,
				'url'  => wp_get_attachment_url( $id ),
			],
			$target,
			$quality
		);

		if ( isset( $result['file'] ) && file_exists( $result['file'] ) ) {
			$this->processor->update_attachment_references( $id, $result['file'], $target );
			update_post_meta( $id, "_{$prefix}_original_path", $vault_path );
			update_post_meta( $id, $savings_key, ( $old_size - filesize( $result['file'] ) ) );
			
			// Invalidate all caches: savings, lookups, and bulk stats.
			$this->invalidate_savings_cache( $savings_key );
			$this->invalidate_bulk_stats();

			return [
				'success' => true,
				'message' => __( 'Optimized and shiny!', 'timu' ),
			];
		}

		// Revert if failed.
		$this->vault->recover_from_vault( $vault_path, $file_path );
		return [
			'success' => false,
			'message' => __( 'Optimization failed.', 'timu' ),
		];
	}

	/**
	 * Retrieves and caches the bulk processing statistics.
	 *
	 * @return array{unprocessed: int, processed: int}
	 */
	public function get_bulk_stats(): array {
		$cache_key = "{$this->plugin_slug}_bulk_stats";
		$cached    = wp_cache_get( $cache_key, 'timu_core' );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		$prefix = $this->get_data_prefix();

		// Count Unprocessed.
		$unprocessed = new \WP_Query( [
			'post_type'      => 'attachment',
			'post_mime_type' => [ 'image/jpeg', 'image/png' ],
			'posts_per_page' => -1,
			'meta_query'     => [ [ 'key' => "_{$prefix}_savings", 'compare' => 'NOT EXISTS' ] ],
			'fields'         => 'ids',
			'no_found_rows'  => false,
		] );

		// Count Processed.
		$processed = new \WP_Query( [
			'post_type'      => 'attachment',
			'meta_query'     => [ [ 'key' => "_{$prefix}_savings", 'compare' => 'EXISTS' ] ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		] );

		$stats = [
			'unprocessed' => (int) $unprocessed->found_posts,
			'processed'   => (int) $processed->found_posts,
		];

		// Cache for 1 hour.
		wp_cache_set( $cache_key, $stats, 'timu_core', HOUR_IN_SECONDS );

		return $stats;
	}

	/**
	 * Invalidates bulk stats cache.
	 */
	public function invalidate_bulk_stats(): void {
		wp_cache_delete( "{$this->plugin_slug}_bulk_stats", 'timu_core' );
	}

	/**
	 * Determines the data prefix based on plugin slug.
	 */
	public function get_data_prefix(): string {
		return match ( true ) {
			str_contains( $this->plugin_slug, 'webp' ) => 'webp',
			str_contains( $this->plugin_slug, 'heic' ) => 'heic',
			str_contains( $this->plugin_slug, 'avif' ) => 'avif',
			default => 'timu',
		};
	}

	/**
	 * Helper to get plugin options.
	 */
	public function get_plugin_option( string $key = '', mixed $default = '' ): mixed {
		$options = get_option( $this->plugin_slug . '_options', [] );
		if ( empty( $key ) ) {
			return $options;
		}
		return $options[ $key ] ?? $default;
	}

	/**
	 * Initializes WP Filesystem.
	 */
	public function init_fs(): \WP_Filesystem_Base {
		if ( null === $this->fs ) {
			global $wp_filesystem;
			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}
			$this->fs = $wp_filesystem;
		}
		return $this->fs;
	}

	/**
	 * Formats bytes into human-readable units.
	 */
	public function format_bytes( int $bytes, int $precision = 2 ): string {
		$units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		$bytes = max( $bytes, 0 );
		$pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow   = (int) min( $pow, count( $units ) - 1 );
		$bytes /= ( 1024 ** $pow );
		return round( $bytes, $precision ) . ' ' . $units[ $pow ];
	}

	/**
	 * Calculates total savings with Object Cache.
	 */
	public function calculate_total_savings( string $savings_key ): int {
		$cache_key = "{$this->plugin_slug}_total_savings_{$savings_key}";
		$cached    = wp_cache_get( $cache_key, 'timu_core' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$total = (int) $wpdb->get_var( 
			$wpdb->prepare( "SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} WHERE meta_key = %s", $savings_key ) 
		);

		wp_cache_set( $cache_key, $total, 'timu_core', 12 * HOUR_IN_SECONDS );
		return $total;
	}

	/**
	 * Invalidates the savings cache.
	 */
	public function invalidate_savings_cache( string $savings_key ): void {
		wp_cache_delete( "{$this->plugin_slug}_total_savings_{$savings_key}", 'timu_core' );
	}

	/**
	 * Handles redirect after plugin activation.
	 */
	public function handle_core_activation_redirect(): void {
		if ( get_transient( "{$this->plugin_slug}_activation_redirect" ) ) {
			delete_transient( "{$this->plugin_slug}_activation_redirect" );
			if ( ! is_network_admin() && ! isset( $_GET['activate-multi'] ) ) {
				wp_safe_redirect( admin_url( $this->menu_parent . '?page=' . $this->plugin_slug ) );
				exit;
			}
		}
	}

	/**
	 * Enqueues admin assets.
	 */
	public function enqueue_core_assets( string $hook ): void {
		wp_enqueue_style( 'timu-core-css', $this->plugin_url . 'core/assets/shared-admin.css', [], '1.26' );
		wp_enqueue_script( 'timu-core-ui', $this->plugin_url . 'core/assets/shared-admin.js', [ 'jquery' ], '1.26', true );

		if ( isset( $_GET['page'] ) && $_GET['page'] === $this->plugin_slug ) {
			wp_enqueue_script( 'timu-core-bulk', $this->plugin_url . 'core/assets/shared-bulk.js', [ 'jquery', 'timu-core-ui' ], '1.26', true );
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'wp-color-picker' );
			wp_enqueue_media();
		}

		wp_localize_script(
			'timu-core-ui',
			'timu_core_vars',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'timu_install_nonce' ),
				'slug'     => $this->plugin_slug,
			]
		);
	}

	/**
	 * Adds action links to the plugin list.
	 */
	public function add_plugin_action_links( array $links ): array {
		$settings_url = admin_url( $this->menu_parent . '?page=' . $this->plugin_slug );
		$links[]      = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'timu' ) . '</a>';

		$label  = $this->is_licensed() ? __( 'Support', 'timu' ) : __( 'Register', 'timu' );
		$links[] = sprintf(
			'<a href="https://thisismyurl.com/%s/" target="_blank" rel="noopener">%s</a>',
			esc_attr( $this->plugin_slug ),
			esc_html( $label )
		);

		return $links;
	}

	/**
	 * Replaces image URLs in content with Object Cache mapping.
	 */
	public function filter_content_images( string $content ): string {
		if ( is_admin() || empty( $content ) ) {
			return $content;
		}
		
		$prefix     = $this->get_data_prefix();
		$target_ext = ( 'avif' === $prefix ) ? 'avif' : 'webp';
		$pattern    = '/(href|src|srcset)=["\']([^"\']+\.(jpe?g|png))["\']/i';
		
		return (string) preg_replace_callback(
			$pattern,
			function( array $m ) use ( $prefix, $target_ext ) {
				$url       = $m[2];
				$cache_key = 'url_to_id_' . md5( $url );
				$id        = wp_cache_get( $cache_key, 'timu_url_lookups' );

				if ( false === $id ) {
					$id = (int) attachment_url_to_postid( $url );
					wp_cache_set( $cache_key, $id, 'timu_url_lookups', DAY_IN_SECONDS );
				}

				if ( $id > 0 && get_post_meta( $id, "_{$prefix}_savings", true ) ) {
					return $m[1] . '="' . preg_replace( '/\.(jpe?g|png)$/i', '.' . $target_ext, $url ) . '"';
				}
				
				return $m[0];
			},
			$content
		);
	}

	/**
	 * Validates license status with Persistent Transients.
	 */
	public function is_licensed(): bool {
		$key = (string) $this->get_plugin_option( 'license_key', '' );

		if ( empty( $key ) ) {
			$this->license_message = __( 'Please enter your License Key to register.', 'timu' );
			return false;
		}

		$transient_key = "{$this->plugin_slug}_license_check";
		$cached_status = get_transient( $transient_key );

		if ( false !== $cached_status ) {
			$this->license_message = (string) ( $cached_status['message'] ?? '' );
			return 'active' === $cached_status['status'];
		}

		$api_url = add_query_arg(
			[
				'url'  => get_site_url(),
				'item' => $this->plugin_slug,
				'key'  => $key,
			],
			'https://thisismyurl.com/wp-json/license-manager/v1/check/'
		);

		$response = wp_remote_get( $api_url, [ 'timeout' => 15 ] );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$status  = $body['status'] ?? 'inactive';
		$message = $body['message'] ?? '';

		set_transient( $transient_key, [ 'status' => $status, 'message' => $message ], 12 * HOUR_IN_SECONDS );

		$this->license_message = (string) $message;
		return 'active' === $status;
	}

	/**
	 * Sanitizes plugin options and clears relevant transients.
	 */
	public function sanitize_core_options( array $input ): array {
		delete_transient( "{$this->plugin_slug}_license_check" );
		$this->invalidate_bulk_stats();
		
		$input['enabled'] = isset( $input['enabled'] ) ? 1 : 0;
		if ( isset( $input['license_key'] ) ) {
			$input['license_key'] = sanitize_text_field( $input['license_key'] );
		}

		return $input;
	}

	/**
	 * Bridge for media sidebar integration.
	 */
	public function add_media_sidebar_actions( array $form_fields, \WP_Post $post ): array {
		return $this->admin?->add_media_sidebar_actions( $form_fields, $post ) ?? $form_fields;
	}
}