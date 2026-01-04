<?php
/**
 * TIMU Secure Vault Component
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
 * Vault Class
 */
class TIMU_Vault_v1 {

	public function __construct( private readonly TIMU_Core_v1 $core ) {}

	public function get_vault_path( string $path ): string {
		$upload_dir = wp_upload_dir();
		$hash       = substr( wp_hash( AUTH_SALT ), 0, 8 );
		$base_vault = $upload_dir['basedir'] . '/timu-backups-' . $hash;

		if ( ! file_exists( $base_vault ) ) {
			wp_mkdir_p( $base_vault );
			file_put_contents( $base_vault . '/.htaccess', "Deny from all\nOptions -Indexes" );
			file_put_contents( $base_vault . '/index.php', '<?php // Silence' );
		}

		$relative = str_replace( $upload_dir['basedir'], '', $path );
		$target   = $base_vault . $relative;
		wp_mkdir_p( dirname( $target ) );
		return $target;
	}

	public function move_to_vault( string $src, string $dest ): bool {
		return $this->core->init_fs()->move( $src, $dest, true );
	}

	public function recover_from_vault( string $vault, string $live ): bool {
		return file_exists( $vault ) && $this->core->init_fs()->move( $vault, $live, true );
	}
}