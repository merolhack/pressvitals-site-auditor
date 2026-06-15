<?php
/**
 * REST endpoints.
 *
 *   GET /wp-json/omnihealth/v1/ping
 *     - No authentication. Tiny always-fresh liveness JSON.
 *
 *   GET /wp-json/omnihealth/v1/report?token=<TOKEN>
 *     - Token-gated (constant-time hash_equals) OR a logged-in admin.
 *     - Token may instead be sent in the `X-OHSA-Token` header.
 *     - Runs the engine and returns the full report. HTTP 503 when the
 *       verdict is "fail" so external/CI monitors can alert on it.
 *
 * @package OmniHealthSiteAuditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OHSA_REST {

	const NAMESPACE = 'omnihealth/v1';

	/**
	 * @var OHSA_Engine
	 */
	private $engine;

	/**
	 * @param OHSA_Engine $engine Health-check engine.
	 */
	public function __construct( OHSA_Engine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * Hook route registration.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the /ping and /report routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/ping',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_ping' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/report',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_report' ),
				'permission_callback' => array( $this, 'authorize_report' ),
				'args'                => array(
					'token' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Liveness probe — always 200, no auth, no secrets.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_ping() {
		return new WP_REST_Response(
			array(
				'ok'     => true,
				'plugin' => 'omnihealth-site-auditor',
				'time'   => gmdate( 'c' ),
			),
			200
		);
	}

	/**
	 * Authorize the report endpoint: a manage_options user, or a valid token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function authorize_report( WP_REST_Request $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$provided = (string) $request->get_param( 'token' );
		if ( '' === $provided ) {
			$provided = (string) $request->get_header( 'x_ohsa_token' );
		}
		$stored = (string) get_option( OHSA_OPTION_TOKEN, '' );

		if ( '' !== $stored && '' !== $provided && hash_equals( $stored, $provided ) ) {
			return true;
		}

		return new WP_Error(
			'ohsa_forbidden',
			__( 'A valid token is required.', 'omnihealth-site-auditor' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Run the checks and return the report (503 on a failing verdict).
	 *
	 * @return WP_REST_Response
	 */
	public function handle_report() {
		$report = $this->engine->run();
		$status = ( isset( $report['verdict'] ) && 'fail' === $report['verdict'] ) ? 503 : 200;

		return new WP_REST_Response( $report, $status );
	}
}
