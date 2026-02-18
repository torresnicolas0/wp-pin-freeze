<?php
/**
 * AJAX frontend capture handler.
 *
 * @package WP_Pin_Freeze
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WPPF_Ajax_Fetcher' ) ) {
	return;
}

/**
 * Frontend capture handler class.
 */
class WPPF_Ajax_Fetcher {
	/**
	 * Default request timeout for frontend capture.
	 */
	const REQUEST_TIMEOUT = 15;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_wppf_fetch_frontend', array( __CLASS__, 'ajax_fetch_frontend' ) );
	}

	/**
	 * Fetch and extract frontend HTML from the current post URL.
	 *
	 * @return void
	 */
	public static function ajax_fetch_frontend() {
		check_ajax_referer( 'wppf_editor_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
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
					'message' => __( 'No tienes permisos para capturar este contenido.', 'pin-freeze' ),
				),
				403
			);
		}

		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			wp_send_json_error(
				array(
					'message' => __( 'No se pudo generar el permalink del contenido.', 'pin-freeze' ),
				),
				500
			);
		}

		$request_result = self::request_frontend_document( $permalink );
		if ( is_wp_error( $request_result ) ) {
			wp_send_json_error(
				array(
					'message' => $request_result->get_error_message(),
				),
				500
			);
		}

		$raw_html = (string) $request_result['body'];
		if ( '' === trim( $raw_html ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'La respuesta del frontend llegó vacía.', 'pin-freeze' ),
				),
				500
			);
		}

		$selector = class_exists( 'WPPF_Settings_Page' ) ? WPPF_Settings_Page::get_capture_selector() : '#content';
		$html     = self::extract_selector_html( $raw_html, $selector );
		if ( is_wp_error( $html ) ) {
			wp_send_json_error(
				array(
					'message' => $html->get_error_message(),
				),
				422
			);
		}

		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$html = wp_kses_post( $html );
		}

		wp_send_json_success(
			array(
				'post_id'   => $post_id,
				'permalink' => esc_url_raw( $permalink ),
				'request'   => $request_result['request_type'],
				'selector'  => $selector,
				'html'      => (string) $html,
			)
		);
	}

	/**
	 * Fetch frontend document with local fallback for containerized/local envs.
	 *
	 * @param string $permalink Public URL.
	 * @return array|WP_Error
	 */
	private static function request_frontend_document( $permalink ) {
		$args     = self::get_request_args();
		$response = wp_remote_get( $permalink, $args );

		if ( ! is_wp_error( $response ) ) {
			$status_code = (int) wp_remote_retrieve_response_code( $response );
			if ( $status_code >= 200 && $status_code < 300 ) {
				return array(
					'body'         => (string) wp_remote_retrieve_body( $response ),
					'request_type' => 'remote',
				);
			}
		}

		$fallback_url  = self::build_local_fallback_url( $permalink );
		$fallback_args = self::get_request_args();
		if ( $fallback_url ) {
			$fallback_parts = wp_parse_url( $permalink );
			if ( ! empty( $fallback_parts['host'] ) ) {
				$fallback_args['headers']['Host']              = $fallback_parts['host'];
				$fallback_args['headers']['X-Forwarded-Host']  = $fallback_parts['host'];
				$fallback_args['headers']['X-Forwarded-Proto'] = ! empty( $fallback_parts['scheme'] ) ? $fallback_parts['scheme'] : 'https';
			}

			$fallback_response = wp_remote_get( $fallback_url, $fallback_args );
			if ( ! is_wp_error( $fallback_response ) ) {
				$fallback_status = (int) wp_remote_retrieve_response_code( $fallback_response );
				if ( $fallback_status >= 200 && $fallback_status < 300 ) {
					return array(
						'body'         => (string) wp_remote_retrieve_body( $fallback_response ),
						'request_type' => 'local_fallback',
					);
				}
			}

			$fallback_error = is_wp_error( $fallback_response )
				? $fallback_response->get_error_message()
				: sprintf(
					/* translators: %d: HTTP status code. */
					__( 'HTTP %d', 'pin-freeze' ),
					(int) wp_remote_retrieve_response_code( $fallback_response )
				);
		} else {
			$fallback_error = __( 'No se pudo construir URL de fallback local.', 'pin-freeze' );
		}

		$primary_error = is_wp_error( $response )
			? $response->get_error_message()
			: sprintf(
				/* translators: %d: HTTP status code. */
				__( 'HTTP %d', 'pin-freeze' ),
				(int) wp_remote_retrieve_response_code( $response )
			);

		return new WP_Error(
			'fetch_failed',
			sprintf(
				/* translators: 1: primary error, 2: fallback error. */
				__( 'No se pudo capturar el frontend. Remoto: %1$s. Fallback local: %2$s.', 'pin-freeze' ),
				$primary_error,
				$fallback_error
			)
		);
	}

	/**
	 * Build request args for capture.
	 *
	 * @return array
	 */
	private static function get_request_args() {
		return array(
			'timeout'     => self::REQUEST_TIMEOUT,
			'redirection' => 3,
			'headers'     => array(
				'Accept'     => 'text/html,application/xhtml+xml',
				'User-Agent' => 'Pin & Freeze/' . WPPF_VERSION,
			),
		);
	}

	/**
	 * Build local fallback URL that bypasses host DNS resolution.
	 *
	 * @param string $permalink Public URL.
	 * @return string
	 */
	private static function build_local_fallback_url( $permalink ) {
		$parts = wp_parse_url( $permalink );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$path .= '?' . $parts['query'];
		}

		return 'http://127.0.0.1' . $path;
	}

	/**
	 * Extract HTML by selector.
	 *
	 * @param string $document_html Full HTML document.
	 * @param string $selector CSS selector.
	 * @return string|WP_Error
	 */
	private static function extract_selector_html( $document_html, $selector ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error( 'dom_missing', __( 'DOMDocument no está disponible en este servidor.', 'pin-freeze' ) );
		}

		$selector = trim( (string) $selector );
		if ( '' === $selector ) {
			$selector = '#content';
		}

		$xpath_query = self::css_selector_to_xpath( $selector );
		if ( '' === $xpath_query ) {
			return new WP_Error(
				'invalid_selector',
				__( 'El selector configurado es inválido. Usa #id, .class o nombre de etiqueta.', 'pin-freeze' )
			);
		}

		$previous_errors = libxml_use_internal_errors( true );
		$dom             = new DOMDocument();
		$loaded          = $dom->loadHTML( '<?xml encoding="UTF-8">' . $document_html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );

		if ( ! $loaded ) {
			return new WP_Error( 'parse_failed', __( 'No se pudo parsear el HTML del frontend.', 'pin-freeze' ) );
		}

		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( $xpath_query );
		if ( ! $nodes || 0 === $nodes->length ) {
			return new WP_Error(
				'selector_not_found',
				sprintf(
					/* translators: %s: selector */
					__( 'No se encontró contenido para el selector "%s".', 'pin-freeze' ),
					esc_html( $selector )
				)
			);
		}

		$chunks = array();
		foreach ( $nodes as $node ) {
			$chunks[] = self::get_inner_html( $dom, $node );
		}

		$html = trim( implode( "\n", $chunks ) );
		if ( '' === $html ) {
			return new WP_Error( 'selector_empty', __( 'El selector existe, pero su contenido está vacío.', 'pin-freeze' ) );
		}

		return $html;
	}

	/**
	 * Convert basic CSS selector to XPath query.
	 *
	 * @param string $selector CSS selector.
	 * @return string
	 */
	private static function css_selector_to_xpath( $selector ) {
		$selector = trim( (string) $selector );
		if ( '' === $selector ) {
			return '';
		}

		if ( 0 === strpos( $selector, '#' ) ) {
			$id = substr( $selector, 1 );
			if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $id ) ) {
				return '';
			}

			return '//*[@id="' . $id . '"]';
		}

		if ( 0 === strpos( $selector, '.' ) ) {
			$class_name = substr( $selector, 1 );
			if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $class_name ) ) {
				return '';
			}

			return '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class_name . ' ")]';
		}

		if ( preg_match( '/^[A-Za-z][A-Za-z0-9:-]*$/', $selector ) ) {
			return '//' . strtolower( $selector );
		}

		return '';
	}

	/**
	 * Get node inner HTML.
	 *
	 * @param DOMDocument $dom DOM document.
	 * @param DOMNode     $node Node.
	 * @return string
	 */
	private static function get_inner_html( DOMDocument $dom, DOMNode $node ) {
		$output = '';
		foreach ( $node->childNodes as $child ) {
			$output .= $dom->saveHTML( $child );
		}

		return $output;
	}
}
