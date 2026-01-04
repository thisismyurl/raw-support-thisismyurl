<?php
/**
 * TIMU Admin UI Component
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
 * Admin UI Class
 */
class TIMU_Admin_v1 {

	/**
	 * Constructor using Promotion.
	 */
	public function __construct( private readonly TIMU_Core_v1 $core ) {
		add_action( 'admin_init', [ $this, 'register_settings_api' ] );
	}

	/**
	 * Registers Settings API components.
	 */
	public function register_settings_api(): void {
		if ( ! is_admin() || ! function_exists( 'add_settings_section' ) ) {
			return;
		}

		register_setting(
			$this->core->options_group,
			$this->core->plugin_slug . '_options',
			[ 'sanitize_callback' => [ $this->core, 'sanitize_core_options' ] ]
		);

		if ( empty( $this->core->settings_blueprint ) ) {
			return;
		}

		foreach ( $this->core->settings_blueprint as $section_id => $section ) {
			add_settings_section( $section_id, $section['title'] ?? '', null, $this->core->plugin_slug );

			foreach ( (array) ( $section['fields'] ?? [] ) as $field_id => $args ) {
				add_settings_field(
					$field_id,
					$args['label'] ?? '',
					[ $this, 'render_generated_field' ],
					$this->core->plugin_slug,
					$section_id,
					array_merge( $args, [ 'id' => $field_id ] )
				);
			}
		}
	}

	/**
	 * Renders dynamic setting fields.
	 */
	public function render_generated_field( array $args ): void {
		$options = (array) $this->core->get_plugin_option();
		$value   = isset( $options[ $args['id'] ] ) && '' !== $options[ $args['id'] ]
				? $options[ $args['id'] ]
				: ( $args['default'] ?? '' );
		
		$name = sprintf( '%s_options[%s]', $this->core->plugin_slug, $args['id'] );
		$is_master = ( 'enabled' === $args['id'] ) ? ' timu-master-toggle' : '';
		
		$conditional_attrs = '';
		if ( ! empty( $args['show_if'] ) ) {
			$conditional_attrs = sprintf( 
				' data-show-if-field="%s" data-show-if-value="%s"', 
				esc_attr( $args['show_if']['field'] ), 
				esc_attr( $args['show_if']['value'] ) 
			);
		}

		echo '<div class="timu-field-wrapper' . esc_attr( $is_master ) . '"' . $conditional_attrs . '>';

		switch ( $args['type'] ) {
			case 'toggle':
				printf( '<label class="timu-switch"><input type="checkbox" name="%s" value="1" %s /><span class="timu-slider"></span></label>', esc_attr( $name ), checked( 1, (int) $value, false ) );
				break;
			case 'range':
				echo '<div class="timu-range-container" style="display:flex; align-items:center; gap:12px;">';
				printf( '<input type="range" name="%s" value="%s" min="%d" max="%d" oninput="this.nextElementSibling.value = this.value" style="flex-grow:1;" />', esc_attr( $name ), esc_attr( (string) $value ), $args['min'] ?? 0, $args['max'] ?? 100 );
				printf( '<output style="font-weight:bold; min-width:30px;">%d</output>%%', (int) $value );
				echo '</div>';
				break;
			case 'color':
				printf( '<input type="text" name="%s" value="%s" class="timu-color-picker" />', esc_attr( $name ), esc_attr( (string) $value ) );
				break;
			default:
				printf( '<input type="text" name="%s" value="%s" class="regular-text" />', esc_attr( $name ), esc_attr( (string) $value ) );
				break;
		}

		if ( ! empty( $args['desc'] ) ) {
			echo '<p class="description">' . wp_kses_post( $args['desc'] ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Renders main settings page.
	 */
	public function render_settings_page(): void {
		?>
		<div class="wrap timu-admin-wrap">
			<div class="timu-header"><h1><?php echo esc_html( get_admin_page_title() ); ?></h1></div>
			<form method="post" action="options.php">
				<?php settings_fields( $this->core->options_group ); ?>
				<div id="poststuff">
					<div id="post-body" class="metabox-holder columns-2">
						<div id="post-body-content">
							<?php foreach ( (array) $this->core->settings_blueprint as $section_id => $section ) : ?>
								<div class="timu-card">
									<div class="timu-card-header"><?php echo esc_html( $section['title'] ); ?></div>
									<div class="timu-card-body"><table class="form-table"><?php do_settings_fields( $this->core->plugin_slug, $section_id ); ?></table></div>
								</div>
							<?php endforeach; ?>
							<?php submit_button(); ?>
							<?php $this->render_unprocessed_log(); ?>
							<?php $this->render_processing_log(); ?>
						</div>
						<?php $this->render_core_sidebar(); ?>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders sidebar container.
	 */
	public function render_core_sidebar(): void {
		?>
		<div id="postbox-container-1" class="postbox-container" style="width: 280px; float: right; margin-left: 20px;">
			<div class="postbox">
				<div class="inside">
					<?php do_action( 'timu_sidebar_under_banner', $this->core->plugin_slug ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders default bulk actions in sidebar.
	 */
	public function render_default_sidebar_actions( string $slug ): void {
		if ( $slug !== $this->core->plugin_slug ) {
			return;
		}
		$is_enabled = (int) $this->core->get_plugin_option( 'enabled', 1 );
		?>
		<button type="button" id="timu-run-bulk" class="button button-primary" style="width: 100%;" <?php disabled( $is_enabled, 0 ); ?>>
			<?php echo $is_enabled ? esc_html__( 'Bulk Process Library', 'timu' ) : esc_html__( 'Enable Plugin to Process', 'timu' ); ?>
		</button>
		<?php
	}

	/**
	 * Injects re-optimize button into media modal.
	 */
	public function add_media_sidebar_actions( array $form_fields, \WP_Post $post ): array {
		$form_fields['timu_optimization'] = [
			'label' => __( 'Optimization', 'timu' ),
			'input' => 'html',
			'html'  => sprintf( '<button type="button" class="button timu-process-single" data-id="%d">Re-optimize</button>', (int) $post->ID ),
		];
		return $form_fields;
	}

	/**
	 * Renders tables of images.
	 */
	public function render_unprocessed_log(): void {
		$stats = $this->core->get_bulk_stats();
		if ( $stats['unprocessed'] > 0 ) {
			echo '<div class="timu-card"><div class="timu-card-header">' . esc_html__( 'Unprocessed Images', 'timu' ) . ' (' . $stats['unprocessed'] . ')</div></div>';
		}
	}

	public function render_processing_log(): void {
		$stats = $this->core->get_bulk_stats();
		if ( $stats['processed'] > 0 ) {
			echo '<div class="timu-card"><div class="timu-card-header">' . esc_html__( 'Converted Images', 'timu' ) . ' (' . $stats['processed'] . ')</div></div>';
		}
	}
}