<?php
/**
 * REST endpoints.
 *
 *   GET /wp-json/pressvitals/v1/ping
 *     - No authentication. Tiny always-fresh liveness JSON.
 *
 *   GET /wp-json/pressvitals/v1/report?token=<TOKEN>
 *     - Token-gated (constant-time hash_equals) OR a logged-in admin.
 *     - Token may instead be sent in the `X-OHSA-Token` header.
 *     - Runs the engine and returns the full report. HTTP 503 when the
 *       verdict is "fail" so external monitors can alert on it (the JSON body
 *       is always included). Add `always_200=1` to always get HTTP 200 (CI/CD).
 *     - Always sends X-PVSA-Verdict, X-PVSA-Fail-Count, X-PVSA-Warn-Count headers.
 *
 * @package PressVitalsSiteAuditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PVSA_REST {

	const NAMESPACE = 'pressvitals/v1';

	/**
	 * @var PVSA_Engine
	 */
	private $engine;

	/**
	 * @param PVSA_Engine $engine Health-check engine.
	 */
	public function __construct( PVSA_Engine $engine ) {
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
					'token'      => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'always_200' => array(
						'type'     => 'boolean',
						'required' => false,
						'default'  => false,
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
				'plugin' => 'pressvitals-site-auditor',
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
			$provided = (string) $request->get_header( 'x_pvsa_token' );
		}
		$stored = (string) get_option( PVSA_OPTION_TOKEN, '' );

		if ( '' !== $stored && '' !== $provided && hash_equals( $stored, $provided ) ) {
			return true;
		}

		return new WP_Error(
			'pvsa_forbidden',
			__( 'A valid token is required.', 'pressvitals-site-auditor' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Run the checks and return the report.
	 *
	 * Returns HTTP 503 (with the full JSON body) on a failing verdict so uptime
	 * monitors can alert. CI/CD clients may pass `always_200=1` to always receive
	 * HTTP 200 and evaluate `verdict` from the JSON instead. Diagnostic headers
	 * `X-PVSA-Verdict`, `X-PVSA-Fail-Count` and `X-PVSA-Warn-Count` are always sent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_report( WP_REST_Request $request ) {
		$report  = $this->engine->run();
		$verdict = isset( $report['verdict'] ) ? (string) $report['verdict'] : 'pass';
		$is_fail = ( 'fail' === $verdict );

		$always_200 = rest_sanitize_boolean( $request->get_param( 'always_200' ) );
		$status     = ( $is_fail && ! $always_200 ) ? 503 : 200;

		$fail_count = 0;
		$warn_count = 0;
		if ( isset( $report['checks'] ) && is_array( $report['checks'] ) ) {
			foreach ( $report['checks'] as $check ) {
				$check_status = isset( $check['status'] ) ? $check['status'] : '';
				if ( 'fail' === $check_status ) {
					++$fail_count;
				} elseif ( 'warn' === $check_status ) {
					++$warn_count;
				}
			}
		}

		$response = new WP_REST_Response( $report, $status );
		$response->header( 'X-PVSA-Verdict', $verdict );
		$response->header( 'X-PVSA-Fail-Count', (string) $fail_count );
		$response->header( 'X-PVSA-Warn-Count', (string) $warn_count );

		return $response;
	}
}
