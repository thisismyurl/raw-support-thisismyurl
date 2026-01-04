<?php
/**
 * TIMU Image Processor Component
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
 * Processor Class
 */
class TIMU_Processor_v1 {

	public function __construct( private readonly TIMU_Core_v1 $core ) {}

	public function process_image_conversion( array $upload, string $target_ext, int $quality = 80 ): array {
		if ( ! class_exists( '\Imagick' ) ) {
			return $upload;
		}

		$file_path = (string) $upload['file'];
		$info      = pathinfo( $file_path );
		$new_path  = trailingslashit( $info['dirname'] ) . $info['filename'] . '.' . $target_ext;

		try {
			$image = new \Imagick( $file_path );
			if ( $image->getImageAlphaChannel() ) {
				$image->setImageBackgroundColor( 'white' );
				$image = $image->mergeImageLayers( \Imagick::LAYERMETHOD_FLATTEN );
			}
			$image->setImageFormat( $target_ext );
			$image->setImageCompressionQuality( $quality );
			$image->writeImage( $new_path );
			$image->clear();
			$image->destroy();

			if ( file_exists( $new_path ) ) {
				$upload['file'] = $new_path;
				$upload['type'] = 'image/' . $target_ext;
			}
		} catch ( \Exception $e ) {
			error_log( 'TIMU Processor Error: ' . $e->getMessage() );
		}
		return $upload;
	}

	public function regenerate_attachment_thumbnails( int $attachment_id ): bool {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( (string) $file_path ) ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, (string) $file_path );
		return ! is_wp_error( $metadata ) && wp_update_attachment_metadata( $attachment_id, $metadata );
	}

	public function update_attachment_references( int $id, string $new_path, string $format ): void {
		wp_update_post( [ 'ID' => $id, 'post_mime_type' => 'image/' . $format ] );
		update_attached_file( $id, $new_path );
		$this->regenerate_attachment_thumbnails( $id );
	}
}