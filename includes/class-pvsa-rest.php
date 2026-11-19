<?php
/**
 * REST endpoints.
 *
 *   GET /wp-json/pressvitals/v1/ping
 *     - No authentication. Tiny always-fresh liveness JSON. Always HTTP 200.
 *
 *   GET /wp-json/pressvitals/v1/health?token=<TOKEN>[&fail_on=fail|warn]
 *     - Token-gated. Purpose-built for uptime monitors (UptimeRobot, BetterStack,
 *       load balancers). Compact JSON; HTTP 200 when healthy, HTTP 503 when the
 *       verdict reaches the `fail_on` threshold (default: "fail").
 *
 *   GET /wp-json/pressvitals/v1/report?token=<TOKEN>
 *     - Token-gated (constant-time hash_equals) OR a logged-in admin.
 *     - Token may instead be sent in the `X-PVSA-Token` header.
 *     - Runs the engine and returns the full report. For backward compatibility
 *       it still answers HTTP 503 (with the full JSON body) on a "fail" verdict.
 *       DEPRECATED behaviour: use /health for uptime monitoring; CI/CD should add
 *       `always_200=1` and read `verdict` from the JSON. A future major version
 *       will make /report answer HTTP 200 by default.
 *     - Status can be overridden via the `pvsa_report_http_status` filter.
 *
 *   /health and /report always send X-PVSA-Verdict, X-PVSA-Fail-Count and
 *   X-PVSA-Warn-Count headers, plus `Cache-Control: no-store`.
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
	 * Register the /ping, /health and /report routes.
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
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_health' ),
				'permission_callback' => array( $this, 'authorize_report' ),
				'args'                => array(
					'token'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'fail_on' => array(
						'type'     => 'string',
						'required' => false,
						'default'  => 'fail',
						'enum'     => array( 'fail', 'warn' ),
					),
				),
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
	 * Authorize the report/health endpoints: a manage_options user, or a valid token.
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
	 * Uptime-monitor endpoint: compact payload, 503 when the verdict reaches `fail_on`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_health( WP_REST_Request $request ) {
		$report  = $this->engine->run();
		$verdict = isset( $report['verdict'] ) ? (string) $report['verdict'] : 'pass';
		$counts  = $this->count_statuses( $report );

		$fail_on   = ( 'warn' === $request->get_param( 'fail_on' ) ) ? 'warn' : 'fail';
		$unhealthy = ( 'fail' === $verdict ) || ( 'warn' === $fail_on && 'warn' === $verdict );
		$status    = $unhealthy ? 503 : 200;

		/** This filter is documented in handle_report(). */
		$status = (int) apply_filters( 'pvsa_report_http_status', $status, $report, $request, 'health' );

		$failing = array();
		if ( isset( $report['checks'] ) && is_array( $report['checks'] ) ) {
			foreach ( $report['checks'] as $key => $check ) {
				$check_status = isset( $check['status'] ) ? $check['status'] : '';
				if ( 'fail' === $check_status || ( 'warn' === $fail_on && 'warn' === $check_status ) ) {
					$failing[] = array(
						'check'  => (string) $key,
						'status' => $check_status,
						'label'  => isset( $check['label'] ) ? (string) $check['label'] : (string) $key,
					);
				}
			}
		}

		$response = new WP_REST_Response(
			array(
				'ok'         => ! $unhealthy,
				'verdict'    => $verdict,
				'fail_on'    => $fail_on,
				'fail_count' => $counts['fail'],
				'warn_count' => $counts['warn'],
				'failing'    => $failing,
				'time'       => gmdate( 'c' ),
			),
			$status
		);
		$this->add_diagnostic_headers( $response, $verdict, $counts );

		return $response;
	}

	/**
	 * Run the checks and return the full report.
	 *
	 * Backward compatible: HTTP 503 (with the full JSON body) on a failing verdict.
	 * That behaviour is deprecated for monitoring — use /health instead. CI/CD
	 * clients may pass `always_200=1` to always receive HTTP 200 and evaluate
	 * `verdict` from the JSON.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_report( WP_REST_Request $request ) {
		$report  = $this->engine->run();
		$verdict = isset( $report['verdict'] ) ? (string) $report['verdict'] : 'pass';
		$counts  = $this->count_statuses( $report );

		$always_200 = rest_sanitize_boolean( $request->get_param( 'always_200' ) );
		$status     = ( 'fail' === $verdict && ! $always_200 ) ? 503 : 200;

		/**
		 * Filter the HTTP status returned by /report and /health.
		 *
		 * @param int             $status   Proposed HTTP status (200 or 503).
		 * @param array           $report   Full engine report.
		 * @param WP_REST_Request $request  Current request.
		 * @param string          $endpoint Either 'report' or 'health'.
		 */
		$status = (int) apply_filters( 'pvsa_report_http_status', $status, $report, $request, 'report' );

		$response = new WP_REST_Response( $report, $status );
		$this->add_diagnostic_headers( $response, $verdict, $counts );

		if ( 503 === $status ) {
			$response->header( 'X-PVSA-Deprecation', 'report-503-on-fail; use /health for uptime monitoring or add always_200=1' );
		}

		return $response;
	}

	/**
	 * Count fail/warn checks in a report.
	 *
	 * @param array $report Engine report.
	 * @return array{fail:int,warn:int}
	 */
	private function count_statuses( array $report ) {
		$counts = array(
			'fail' => 0,
			'warn' => 0,
		);
		if ( isset( $report['checks'] ) && is_array( $report['checks'] ) ) {
			foreach ( $report['checks'] as $check ) {
				$check_status = isset( $check['status'] ) ? $check['status'] : '';
				if ( isset( $counts[ $check_status ] ) ) {
					++$counts[ $check_status ];
				}
			}
		}
		return $counts;
	}

	/**
	 * Attach the X-PVSA-* diagnostic headers and disable caching.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param string           $verdict  pass|warn|fail.
	 * @param array            $counts   Output of count_statuses().
	 */
	private function add_diagnostic_headers( WP_REST_Response $response, $verdict, array $counts ) {
		$response->header( 'X-PVSA-Verdict', $verdict );
		$response->header( 'X-PVSA-Fail-Count', (string) $counts['fail'] );
		$response->header( 'X-PVSA-Warn-Count', (string) $counts['warn'] );
		$response->header( 'Cache-Control', 'no-store' );
	}
}
