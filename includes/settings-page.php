<?php
/**
 * Settings page for WP Pin & Freeze.
 *
 * @package WP_Pin_Freeze
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WPPF_Settings_Page' ) ) {
	return;
}

/**
 * Settings management class.
 */
class WPPF_Settings_Page {
	const OPTION_CAPTURE_SELECTOR = 'wppf_capture_selector';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Register options page under Settings.
	 *
	 * @return void
	 */
	public static function register_settings_page() {
		add_options_page(
			__( 'WP Pin & Freeze', 'wp-pin-freeze' ),
			__( 'WP Pin & Freeze', 'wp-pin-freeze' ),
			'manage_options',
			'wp-pin-freeze',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register plugin settings and fields.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			'wppf_settings',
			self::OPTION_CAPTURE_SELECTOR,
			array(
				'type'              => 'string',
				'default'           => '#content',
				'sanitize_callback' => array( __CLASS__, 'sanitize_capture_selector' ),
			)
		);

		add_settings_section(
			'wppf_capture_section',
			__( 'Frontend Capture', 'wp-pin-freeze' ),
			array( __CLASS__, 'render_section_description' ),
			'wp-pin-freeze'
		);

		add_settings_field(
			self::OPTION_CAPTURE_SELECTOR,
			__( 'Capture Selector', 'wp-pin-freeze' ),
			array( __CLASS__, 'render_capture_selector_field' ),
			'wp-pin-freeze',
			'wppf_capture_section'
		);
	}

	/**
	 * Sanitize selector value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_capture_selector( $value ) {
		$selector = trim( (string) $value );
		if ( '' === $selector ) {
			return '#content';
		}

		$valid = preg_match( '/^[#.][A-Za-z0-9_-]+$/', $selector ) || preg_match( '/^[A-Za-z][A-Za-z0-9:-]*$/', $selector );
		if ( $valid ) {
			return $selector;
		}

		add_settings_error(
			self::OPTION_CAPTURE_SELECTOR,
			'invalid_selector',
			__( 'Selector inválido. Usa un selector simple: #id, .class o nombre de etiqueta.', 'wp-pin-freeze' )
		);

		return '#content';
	}

	/**
	 * Render section description.
	 *
	 * @return void
	 */
	public static function render_section_description() {
		echo '<p>' . esc_html__( 'Define el selector CSS del frontend que contiene el contenido real de la página para capturar HTML sin header/footer.', 'wp-pin-freeze' ) . '</p>';
	}

	/**
	 * Render selector field.
	 *
	 * @return void
	 */
	public static function render_capture_selector_field() {
		$value = self::get_capture_selector();
		?>
		<input
			type="text"
			name="<?php echo esc_attr( self::OPTION_CAPTURE_SELECTOR ); ?>"
			id="<?php echo esc_attr( self::OPTION_CAPTURE_SELECTOR ); ?>"
			class="regular-text"
			placeholder="#content"
			value="<?php echo esc_attr( $value ); ?>"
		/>
		<p class="description">
			<?php esc_html_e( 'Ejemplos: #content, .site-main, main', 'wp-pin-freeze' ); ?>
		</p>
		<?php
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP Pin & Freeze', 'wp-pin-freeze' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'wppf_settings' );
				do_settings_sections( 'wp-pin-freeze' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Get capture selector value.
	 *
	 * @return string
	 */
	public static function get_capture_selector() {
		$value = get_option( self::OPTION_CAPTURE_SELECTOR, '#content' );
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '#content';
		}

		return trim( $value );
	}
}
