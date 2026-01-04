<?php
/**
 * TIMU AJAX Handlers
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
 * AJAX Component Class
 */
class TIMU_Ajax_v1 {

	public function __construct( private readonly TIMU_Core_v1 $core ) {
		add_action( 'wp_ajax_timu_process_single', [ $this, 'ajax_process_single_image' ] );
		add_action( 'wp_ajax_timu_run_bulk_process', [ $this, 'ajax_run_bulk_process' ] );
	}

	public function ajax_process_single_image(): void {
		check_ajax_referer( 'timu_install_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Permissions error.', 'timu' ) );
		}

		$id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid ID.', 'timu' ) );
		}

		$result = $this->core->run_conversion_logic( $id );
		$result['success'] ? wp_send_json_success( $result['message'] ) : wp_send_json_error( $result['message'] );
	}

	public function ajax_run_bulk_process(): void {
		check_ajax_referer( 'timu_install_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Permissions error.', 'timu' ) );
		}

		$prefix      = $this->core->get_data_prefix();
		$savings_key = "_{$prefix}_savings";

		$attachments = get_posts( [
			'post_type'      => 'attachment',
			'post_mime_type' => [ 'image/jpeg', 'image/png' ],
			'posts_per_page' => 5,
			'meta_query'     => [ [ 'key' => $savings_key, 'compare' => 'NOT EXISTS' ] ],
			'fields'         => 'ids',
		] );

		if ( empty( $attachments ) ) {
			$this->core->invalidate_bulk_stats();
			wp_send_json_success( [ 'done' => true ] );
		}

		foreach ( (array) $attachments as $id ) {
			$this->core->run_conversion_logic( (int) $id );
		}

		$stats = $this->core->get_bulk_stats();
		wp_send_json_success( [
			'done'      => false,
			'remaining' => $stats['unprocessed'],
			'completed' => $stats['processed'],
		] );
	}
}