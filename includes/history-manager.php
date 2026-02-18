<?php
/**
 * Snapshot history manager.
 *
 * @package WP_Pin_Freeze
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WPPF_History_Manager' ) ) {
	return;
}

/**
 * Snapshot history helper class.
 */
class WPPF_History_Manager {
	const POST_TYPE         = 'wppf_snapshot';
	const DEFAULT_MAX_ITEMS = 10;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_snapshot_post_type' ) );
		add_action( 'wp_ajax_wppf_save_post_pin', array( __CLASS__, 'ajax_save_post_pin' ) );
	}

	/**
	 * Register hidden snapshot CPT.
	 *
	 * @return void
	 */
	public static function register_snapshot_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels' => array(
					'name'          => __( 'Pin Snapshots', 'pin-freeze' ),
					'singular_name' => __( 'Pin Snapshot', 'pin-freeze' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'exclude_from_search'=> true,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => false,
				'show_in_admin_bar'  => false,
				'menu_position'      => null,
				'rewrite'            => false,
				'query_var'          => false,
				'has_archive'        => false,
				'hierarchical'       => true,
				'supports'           => array( 'title', 'editor', 'author' ),
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
				'show_in_rest'       => true,
				'rest_base'          => self::POST_TYPE,
			)
		);
	}

	/**
	 * Save pinned post HTML via AJAX and create snapshot.
	 *
	 * @return void
	 */
	public static function ajax_save_post_pin() {
		check_ajax_referer( 'wppf_editor_nonce', 'nonce' );

		$post_id = (int) filter_input( INPUT_POST, 'post_id', FILTER_VALIDATE_INT );
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			wp_send_json_error(
				array(
					'message' => __( 'ID de contenido inválido.', 'pin-freeze' ),
				),
				400
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No tienes permisos para pinear este contenido.', 'pin-freeze' ),
				),
				403
			);
		}

		$is_pinned_value = filter_input( INPUT_POST, 'is_pinned', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$is_pinned       = null === $is_pinned_value ? true : wppf_rest_sanitize_boolean( $is_pinned_value );

		$skip_snapshot_value = filter_input( INPUT_POST, 'skip_snapshot', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$skip_snapshot       = null === $skip_snapshot_value ? false : wppf_rest_sanitize_boolean( $skip_snapshot_value );

		$html_value = filter_input( INPUT_POST, 'html', FILTER_UNSAFE_RAW );
		$html       = self::sanitize_html_for_user( is_string( $html_value ) ? $html_value : '' );

		update_post_meta( $post_id, '_wppf_is_post_pinned', $is_pinned );
		update_post_meta( $post_id, '_wppf_post_html', $html );

		$snapshot_id = 0;
		if ( ! $skip_snapshot ) {
			$snapshot_id = self::create_snapshot( $post_id, $html, get_current_user_id() );
			if ( is_wp_error( $snapshot_id ) ) {
				wp_send_json_error(
					array(
						'message' => $snapshot_id->get_error_message(),
					),
					500
				);
			}
		}

		$author = get_userdata( get_current_user_id() );

		wp_send_json_success(
			array(
				'message'     => $skip_snapshot ? __( 'Pin actualizado sin crear snapshot.', 'pin-freeze' ) : __( 'Pin guardado y snapshot creado.', 'pin-freeze' ),
				'post_id'     => $post_id,
				'is_pinned'   => $is_pinned,
				'html'        => $html,
				'snapshot_id' => (int) $snapshot_id,
				'snapshot'    => array(
					'id'     => (int) $snapshot_id,
					'date'   => get_post_field( 'post_date', $snapshot_id ),
					'author' => $author ? $author->display_name : '',
				),
			)
		);
	}

	/**
	 * Create a snapshot entry.
	 *
	 * @param int    $post_id Source post ID.
	 * @param string $html Snapshot HTML.
	 * @param int    $author_id Author user ID.
	 * @return int|WP_Error
	 */
	public static function create_snapshot( $post_id, $html, $author_id ) {
		$post_id   = absint( $post_id );
		$author_id = absint( $author_id );
		$html      = (string) $html;

		if ( ! $post_id ) {
			return new WP_Error( 'invalid_post', __( 'No se pudo crear el snapshot: contenido inválido.', 'pin-freeze' ) );
		}

		$title = sprintf(
			/* translators: 1: post title, 2: date */
			__( 'Snapshot de %1$s - %2$s', 'pin-freeze' ),
			get_the_title( $post_id ),
			wppf_wp_date( 'Y-m-d H:i:s' )
		);

		$snapshot_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_parent'  => $post_id,
				'post_title'   => $title,
				'post_content' => $html,
				'post_author'  => $author_id > 0 ? $author_id : get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $snapshot_id ) ) {
			return $snapshot_id;
		}

		self::rotate_snapshots( $post_id, self::get_snapshot_limit() );

		return (int) $snapshot_id;
	}

	/**
	 * Keep only latest N snapshots for a post.
	 *
	 * @param int $post_id Parent post ID.
	 * @param int $limit Maximum items.
	 * @return void
	 */
	public static function rotate_snapshots( $post_id, $limit ) {
		$post_id = absint( $post_id );
		$limit   = absint( $limit );

		if ( ! $post_id || $limit < 1 ) {
			return;
		}

		$snapshot_ids = get_posts(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'publish',
				'post_parent'            => $post_id,
				'posts_per_page'         => -1,
				'orderby'                => 'date',
					'order'                  => 'DESC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_term_cache' => false,
					'update_post_meta_cache' => false,
				)
			);

		if ( count( $snapshot_ids ) <= $limit ) {
			return;
		}

		$ids_to_delete = array_slice( $snapshot_ids, $limit );
		foreach ( $ids_to_delete as $snapshot_id ) {
			wp_delete_post( (int) $snapshot_id, true );
		}
	}

	/**
	 * Max snapshots per post.
	 *
	 * @return int
	 */
	public static function get_snapshot_limit() {
		$limit = (int) apply_filters( 'wppf_snapshot_limit', self::DEFAULT_MAX_ITEMS );
		if ( $limit < 1 ) {
			$limit = self::DEFAULT_MAX_ITEMS;
		}

		return $limit;
	}

	/**
	 * Sanitize HTML depending on user capability.
	 *
	 * @param string $html Raw html.
	 * @return string
	 */
	private static function sanitize_html_for_user( $html ) {
		$html = (string) $html;

		if ( current_user_can( 'unfiltered_html' ) ) {
			return $html;
		}

		return wp_kses_post( $html );
	}
}
