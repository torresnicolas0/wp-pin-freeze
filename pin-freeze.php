<?php
/**
 * Plugin Name:       Pin & Freeze
 * Plugin URI:        https://github.com/torresnicolas0/wp-pin-freeze
 * Description:       Pin and freeze rendered HTML for individual blocks or entire posts/pages.
 * Version:           1.0.2
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Nicolás Torres
 * Author URI:        https://linkedin.com/in/torresnicolas/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pin-freeze
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPPF_VERSION', '1.0.2' );
define( 'WPPF_PLUGIN_FILE', __FILE__ );
define( 'WPPF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPPF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPPF_MIN_WP_VERSION', '5.2' );
define( 'WPPF_MIN_PHP_VERSION', '7.2' );

/**
 * Compat helper for REST boolean sanitization.
 *
 * @param mixed $value Raw value.
 * @return bool
 */
function wppf_rest_sanitize_boolean( $value ) {
	if ( function_exists( 'rest_sanitize_boolean' ) ) {
		return (bool) rest_sanitize_boolean( $value );
	}

	$filtered = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
	return null === $filtered ? false : (bool) $filtered;
}

/**
 * Compat helper for AJAX context detection.
 *
 * @return bool
 */
function wppf_wp_doing_ajax() {
	if ( function_exists( 'wp_doing_ajax' ) ) {
		return wp_doing_ajax();
	}

	return defined( 'DOING_AJAX' ) && DOING_AJAX;
}

/**
 * Compat helper for wp_date (introduced in WP 5.3).
 *
 * @param string            $format Format string.
 * @param int|null          $timestamp Unix timestamp.
 * @param DateTimeZone|null $timezone Optional timezone.
 * @return string
 */
function wppf_wp_date( $format, $timestamp = null, $timezone = null ) {
	$timestamp = null === $timestamp ? time() : (int) $timestamp;

	if ( function_exists( 'wp_date' ) ) {
		return wp_date( $format, $timestamp, $timezone );
	}

	if ( function_exists( 'date_i18n' ) ) {
		return date_i18n( $format, $timestamp, false );
	}

	return gmdate( $format, $timestamp );
}

/**
 * Check plugin environment requirements.
 *
 * @return bool
 */
function wppf_requirements_met() {
	global $wp_version;
	$wp_version = isset( $wp_version ) ? (string) $wp_version : '0';

	return version_compare( PHP_VERSION, WPPF_MIN_PHP_VERSION, '>=' ) &&
		version_compare( $wp_version, WPPF_MIN_WP_VERSION, '>=' );
}

/**
 * Deactivate plugin if requirements are not met.
 *
 * @return void
 */
function wppf_maybe_deactivate_for_requirements() {
	if ( wppf_requirements_met() || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	if ( function_exists( 'deactivate_plugins' ) ) {
		deactivate_plugins( plugin_basename( WPPF_PLUGIN_FILE ) );
	}
}

/**
 * Show requirements notice.
 *
 * @return void
 */
function wppf_requirements_notice() {
	if ( wppf_requirements_met() || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	global $wp_version;
	$wp_version = isset( $wp_version ) ? (string) $wp_version : 'unknown';

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: required WordPress version, 2: required PHP version, 3: current WordPress version, 4: current PHP version */
				__( 'Pin & Freeze requiere WordPress %1$s o superior y PHP %2$s o superior. Versiones actuales: WordPress %3$s, PHP %4$s.', 'pin-freeze' ),
				WPPF_MIN_WP_VERSION,
				WPPF_MIN_PHP_VERSION,
				$wp_version,
				PHP_VERSION
			)
		)
	);
}

if ( ! wppf_requirements_met() ) {
	add_action( 'admin_init', 'wppf_maybe_deactivate_for_requirements' );
	add_action( 'admin_notices', 'wppf_requirements_notice' );
	return;
}

require_once WPPF_PLUGIN_DIR . 'includes/settings-page.php';
require_once WPPF_PLUGIN_DIR . 'includes/history-manager.php';
require_once WPPF_PLUGIN_DIR . 'includes/ajax-fetcher.php';

/**
 * Main plugin class.
 */
class WPPF_Plugin {
	const META_POST_PINNED = '_wppf_is_post_pinned';
	const META_POST_HTML   = '_wppf_post_html';
	const NOTICE_TRANSIENT = 'wppf_notice_user_';

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_meta' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'admin_head', array( __CLASS__, 'admin_head_styles' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_admin_actions' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );

		add_filter( 'render_block', array( __CLASS__, 'render_pinned_block' ), 10, 2 );
		add_filter( 'the_content', array( __CLASS__, 'render_pinned_post_content' ), 9999 );
		add_filter( 'content_save_pre', array( __CLASS__, 'sanitize_pinned_block_content' ) );

		add_filter( 'display_post_states', array( __CLASS__, 'display_post_states' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'page_row_actions' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'page_row_actions' ), 10, 2 );

		if ( class_exists( 'WPPF_Settings_Page' ) ) {
			WPPF_Settings_Page::init();
		}

		if ( class_exists( 'WPPF_History_Manager' ) ) {
			WPPF_History_Manager::init();
		}

		if ( class_exists( 'WPPF_Ajax_Fetcher' ) ) {
			WPPF_Ajax_Fetcher::init();
		}
	}

	/**
	 * Register REST-exposed post meta for pinning full post HTML.
	 *
	 * @return void
	 */
	public static function register_post_meta() {
		$post_types = get_post_types(
			array(
				'public'       => true,
				'show_in_rest' => true,
			),
			'names'
		);

		foreach ( $post_types as $post_type ) {
			self::register_post_meta_compat(
				$post_type,
				self::META_POST_PINNED,
				array(
					'single'            => true,
					'type'              => 'boolean',
					'default'           => false,
					'show_in_rest'      => array(
						'schema' => array(
							'type' => 'boolean',
						),
					),
					'auth_callback'     => array( __CLASS__, 'auth_post_meta' ),
					'sanitize_callback' => array( __CLASS__, 'sanitize_meta_bool' ),
				)
			);

			self::register_post_meta_compat(
				$post_type,
				self::META_POST_HTML,
				array(
					'single'            => true,
					'type'              => 'string',
					'default'           => '',
					'show_in_rest'      => array(
						'schema' => array(
							'type' => 'string',
						),
					),
					'auth_callback'     => array( __CLASS__, 'auth_post_meta' ),
					'sanitize_callback' => array( __CLASS__, 'sanitize_meta_html' ),
				)
			);
		}
	}

	/**
	 * Register post meta with backward compatibility.
	 *
	 * @param string $post_type Post type name.
	 * @param string $meta_key Meta key.
	 * @param array  $args Meta registration args.
	 * @return void
	 */
	private static function register_post_meta_compat( $post_type, $meta_key, array $args ) {
		if ( function_exists( 'register_post_meta' ) ) {
			register_post_meta( $post_type, $meta_key, $args );
			return;
		}

		$meta_args                   = $args;
		$meta_args['object_subtype'] = $post_type;

		register_meta( 'post', $meta_key, $meta_args );
	}

	/**
	 * Capability guard for post meta.
	 *
	 * @param bool   $allowed Whether user has access.
	 * @param string $meta_key Meta key.
	 * @param int    $post_id Post ID.
	 * @param int    $user_id User ID.
	 * @return bool
	 */
	public static function auth_post_meta( $allowed, $meta_key, $post_id, $user_id ) {
		unset( $allowed, $meta_key );

		return user_can( $user_id, 'edit_post', $post_id );
	}

	/**
	 * Sanitize boolean meta.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function sanitize_meta_bool( $value ) {
		return wppf_rest_sanitize_boolean( $value );
	}

	/**
	 * Sanitize HTML meta depending on user capability.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function sanitize_meta_html( $value ) {
		$value = (string) $value;

		if ( current_user_can( 'unfiltered_html' ) ) {
			return $value;
		}

		return wp_kses_post( $value );
	}

	/**
	 * Enqueue editor script/style.
	 *
	 * @return void
	 */
	public static function enqueue_editor_assets() {
		$asset_path = WPPF_PLUGIN_DIR . 'build/index.asset.php';
		$asset      = array(
			'dependencies' => array(
				'wp-block-editor',
				'wp-blocks',
				'wp-components',
				'wp-compose',
				'wp-data',
				'wp-edit-post',
				'wp-element',
				'wp-hooks',
				'wp-i18n',
				'wp-plugins',
			),
			'version'      => WPPF_VERSION,
		);

		if ( file_exists( $asset_path ) ) {
			$asset = include $asset_path;
		}

		wp_enqueue_script(
			'wppf-editor-script',
			WPPF_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$style_file = WPPF_PLUGIN_DIR . 'build/index.css';
		if ( file_exists( $style_file ) ) {
			wp_enqueue_style(
				'wppf-editor-style',
				WPPF_PLUGIN_URL . 'build/index.css',
				array( 'wp-edit-blocks' ),
				filemtime( $style_file )
			);
		}

		wp_localize_script(
			'wppf-editor-script',
			'wppfEditorSettings',
			array(
				'canUnfilteredHtml' => current_user_can( 'unfiltered_html' ),
				'blockUnpinConfirm' => __( 'Este bloque está pineado. ¿Deseas despinearlo para editar?', 'pin-freeze' ),
				'postUnpinConfirm'  => __( 'Esta entrada está pineada. ¿Deseas despinearla para editar?', 'pin-freeze' ),
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'nonce'             => wp_create_nonce( 'wppf_editor_nonce' ),
				'captureSelector'   => class_exists( 'WPPF_Settings_Page' ) ? WPPF_Settings_Page::get_capture_selector() : '#content',
				'snapshotPostType'  => class_exists( 'WPPF_History_Manager' ) ? WPPF_History_Manager::POST_TYPE : 'wppf_snapshot',
			)
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'wppf-editor-script', 'pin-freeze', WPPF_PLUGIN_DIR . 'languages' );
		}
	}

	/**
	 * Enqueue admin styles on post list screens.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public static function enqueue_admin_assets( $hook ) {
		if ( ! in_array( $hook, array( 'edit.php', 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$style_file = WPPF_PLUGIN_DIR . 'build/index.css';
		if ( ! file_exists( $style_file ) ) {
			return;
		}

		wp_enqueue_style(
			'wppf-admin-style',
			WPPF_PLUGIN_URL . 'build/index.css',
			array( 'dashicons' ),
			filemtime( $style_file )
		);
	}

	/**
	 * Replace block output with pinned HTML.
	 *
	 * @param string $block_content Rendered block content.
	 * @param array  $block Full block data.
	 * @return string
	 */
	public static function render_pinned_block( $block_content, $block ) {
		if ( empty( $block['attrs'] ) || empty( $block['attrs']['wppf_is_pinned'] ) ) {
			return $block_content;
		}

		$is_pinned = wppf_rest_sanitize_boolean( $block['attrs']['wppf_is_pinned'] );
		if ( ! $is_pinned ) {
			return $block_content;
		}

		$static_html = isset( $block['attrs']['wppf_html'] ) ? (string) $block['attrs']['wppf_html'] : '';
		if ( '' === trim( $static_html ) ) {
			return $block_content;
		}

		return self::maybe_allow_raw_html( $static_html );
	}

	/**
	 * Replace full post content when post is pinned.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function render_pinned_post_content( $content ) {
		if ( is_admin() && ! wppf_wp_doing_ajax() && ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id || ! self::is_post_pinned( $post_id ) ) {
			return $content;
		}

		$static_html = (string) get_post_meta( $post_id, self::META_POST_HTML, true );
		if ( '' === trim( $static_html ) ) {
			return $content;
		}

		return self::maybe_allow_raw_html( $static_html );
	}

	/**
	 * Ensure pinned block HTML is sanitized on save for users without unfiltered_html.
	 *
	 * @param string $content Post content being saved.
	 * @return string
	 */
	public static function sanitize_pinned_block_content( $content ) {
		if ( current_user_can( 'unfiltered_html' ) ) {
			return $content;
		}

		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return wp_kses_post( $content );
		}

		$blocks = parse_blocks( $content );
		if ( empty( $blocks ) ) {
			return wp_kses_post( $content );
		}

		$blocks = self::sanitize_blocks_recursive( $blocks );
		return serialize_blocks( $blocks );
	}

	/**
	 * Add post list state label for pinned posts.
	 *
	 * @param string[] $states Existing states.
	 * @param WP_Post  $post Post object.
	 * @return string[]
	 */
	public static function display_post_states( $states, $post ) {
		if ( ! self::is_post_pinned( $post->ID ) ) {
			return $states;
		}

		$states['wppf_pinned'] = sprintf(
			'<span class="wppf-post-state"><span class="dashicons dashicons-pin" aria-hidden="true"></span>%s</span>',
			esc_html__( 'Pineado', 'pin-freeze' )
		);

		return $states;
	}

	/**
	 * Add list row actions for pinned posts.
	 *
	 * @param array   $actions Existing row actions.
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	public static function page_row_actions( $actions, $post ) {
		if ( ! self::is_post_pinned( $post->ID ) ) {
			return $actions;
		}

		$actions['wppf_pinned'] = sprintf(
			'<span class="wppf-pinned-row-action"><span class="dashicons dashicons-pin" aria-hidden="true"></span>%s</span>',
			esc_html__( 'Pineado', 'pin-freeze' )
		);

		$actions['wppf_unpin'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( self::get_unpin_url( $post ) ),
			esc_html__( 'Despinear', 'pin-freeze' )
		);

		return $actions;
	}

	/**
	 * Print inline admin CSS for pinned titles.
	 *
	 * @return void
	 */
	public static function admin_head_styles() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base ) {
			return;
		}

		global $wp_query;
		if ( empty( $wp_query->posts ) || ! is_array( $wp_query->posts ) ) {
			return;
		}

		$pinned_ids = array();
		foreach ( $wp_query->posts as $post ) {
			if ( $post instanceof WP_Post && self::is_post_pinned( $post->ID ) ) {
				$pinned_ids[] = (int) $post->ID;
			}
		}

		if ( empty( $pinned_ids ) ) {
			return;
		}

		$css_rules = array();
		foreach ( $pinned_ids as $post_id ) {
			$post_id = absint( $post_id );
			if ( ! $post_id ) {
				continue;
			}

			$css_rules[] = sprintf(
				'#post-%1$d .row-title{color:#7b3ff2 !important;}#post-%1$d .row-title:before{content:"\\f537";font-family:dashicons;display:inline-block;margin-right:4px;color:#7b3ff2;vertical-align:text-bottom;}',
				$post_id
			);
		}

		if ( empty( $css_rules ) ) {
			return;
		}

		printf(
			"<style id='wppf-admin-inline-css'>\n%s\n</style>\n",
			esc_html( implode( "\n", $css_rules ) )
		);
	}

	/**
	 * Handle nonced unpin actions from post list.
	 *
	 * @return void
	 */
	public static function handle_admin_actions() {
		if ( ! isset( $_GET['wppf_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['wppf_action'] ) );
		if ( 'unpin_post' !== $action ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		if ( ! $post_id ) {
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wppf_unpin_post_' . $post_id ) ) {
			wp_die( esc_html__( 'Nonce inválido.', 'pin-freeze' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'No tienes permisos para despinear este contenido.', 'pin-freeze' ) );
		}

		update_post_meta( $post_id, self::META_POST_PINNED, false );
		delete_post_meta( $post_id, self::META_POST_HTML );

		$referer = wp_get_referer();
		if ( ! $referer ) {
			$post_type = get_post_type( $post_id ) ?: 'post';
			$referer   = add_query_arg( 'post_type', $post_type, admin_url( 'edit.php' ) );
		}

		set_transient( self::NOTICE_TRANSIENT . get_current_user_id(), 'post_unpinned', MINUTE_IN_SECONDS );

		$redirect = remove_query_arg( array( 'wppf_action', 'post', '_wpnonce' ), $referer );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Admin notice messages.
	 *
	 * @return void
	 */
	public static function admin_notices() {
		$transient_key = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice        = get_transient( $transient_key );

		if ( ! is_string( $notice ) || '' === $notice ) {
			return;
		}

		delete_transient( $transient_key );
		$notice = sanitize_key( $notice );
		if ( 'post_unpinned' !== $notice ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Contenido despineado correctamente.', 'pin-freeze' ) . '</p></div>';
	}

	/**
	 * Recursive sanitizer for pinned block attributes.
	 *
	 * @param array $blocks Blocks list.
	 * @return array
	 */
	private static function sanitize_blocks_recursive( array $blocks ) {
		foreach ( $blocks as &$block ) {
			if (
				isset( $block['attrs'] ) &&
				isset( $block['attrs']['wppf_is_pinned'] ) &&
				wppf_rest_sanitize_boolean( $block['attrs']['wppf_is_pinned'] ) &&
				isset( $block['attrs']['wppf_html'] )
			) {
				$block['attrs']['wppf_html'] = wp_kses_post( (string) $block['attrs']['wppf_html'] );
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::sanitize_blocks_recursive( $block['innerBlocks'] );
			}
		}
		unset( $block );

		return $blocks;
	}

	/**
	 * Check if a post is pinned.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_post_pinned( $post_id ) {
		return wppf_rest_sanitize_boolean( get_post_meta( $post_id, self::META_POST_PINNED, true ) );
	}

	/**
	 * Return possibly unsanitized html based on capability.
	 *
	 * @param string $html HTML string.
	 * @return string
	 */
	private static function maybe_allow_raw_html( $html ) {
		$html = (string) $html;

		if ( current_user_can( 'unfiltered_html' ) ) {
			return $html;
		}

		return wp_kses_post( $html );
	}

	/**
	 * Build unpin URL.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private static function get_unpin_url( $post ) {
		$url = add_query_arg(
			array(
				'post_type'   => $post->post_type,
				'wppf_action' => 'unpin_post',
				'post'        => (int) $post->ID,
			),
			admin_url( 'edit.php' )
		);

		return wp_nonce_url( $url, 'wppf_unpin_post_' . (int) $post->ID );
	}
}

WPPF_Plugin::init();
