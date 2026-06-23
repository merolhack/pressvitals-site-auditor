<?php
/**
 * Admin UI — Tools → PressVitals Site Auditor.
 *
 * Renders the last stored report grouped by category, exposes a Settings-API
 * form for thresholds + alert email, and "Run now" / "Rotate token" actions.
 * Every action is nonce-protected and capability-gated; all output is escaped.
 *
 * @package PressVitalsSiteAuditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PVSA_Admin {

	const PAGE_SLUG      = 'pressvitals-site-auditor';
	const SETTINGS_GROUP = 'pvsa_settings_group';

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
	 * Hook admin menu, settings and form handlers.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_post_pvsa_run_now', array( $this, 'handle_run_now' ) );
		add_action( 'admin_post_pvsa_rotate_token', array( $this, 'handle_rotate_token' ) );
		add_action( 'admin_post_pvsa_export_json', array( $this, 'handle_export_json' ) );
		add_action( 'admin_post_pvsa_export_csv', array( $this, 'handle_export_csv' ) );
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook The current admin page.
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'pvsa-admin',
			plugins_url( 'assets/js/pvsa-admin.js', PVSA_PLUGIN_FILE ),
			array(),
			PVSA_VERSION,
			true
		);
	}

	/**
	 * Add the Tools submenu page.
	 */
	public function register_menu() {
		add_management_page(
			__( 'PressVitals Site Auditor', 'pressvitals-site-auditor' ),
			__( 'PressVitals Site Auditor', 'pressvitals-site-auditor' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the settings, section and fields (Settings API).
	 */
	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			PVSA_OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => PVSA_Engine::default_settings(),
			)
		);

		add_settings_section(
			'pvsa_thresholds',
			__( 'Thresholds & alerts', 'pressvitals-site-auditor' ),
			'__return_false',
			self::PAGE_SLUG
		);

		$fields = array(
			'error_log_warn_mb' => __( 'Error log warn (MB)', 'pressvitals-site-auditor' ),
			'error_log_fail_mb' => __( 'Error log fail (MB)', 'pressvitals-site-auditor' ),
			'autoload_warn_mb'  => __( 'Autoload warn (MB)', 'pressvitals-site-auditor' ),
			'autoload_fail_mb'  => __( 'Autoload fail (MB)', 'pressvitals-site-auditor' ),
		);
		foreach ( $fields as $key => $label ) {
			add_settings_field(
				$key,
				$label,
				array( $this, 'render_number_field' ),
				self::PAGE_SLUG,
				'pvsa_thresholds',
				array( 'key' => $key )
			);
		}

		add_settings_field(
			'alert_email',
			__( 'Alert email', 'pressvitals-site-auditor' ),
			array( $this, 'render_email_field' ),
			self::PAGE_SLUG,
			'pvsa_thresholds'
		);
	}

	/**
	 * Sanitize the settings array before save.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = PVSA_Engine::default_settings();
		$out      = array();

		foreach ( array( 'error_log_warn_mb', 'error_log_fail_mb', 'autoload_warn_mb', 'autoload_fail_mb' ) as $key ) {
			$value       = isset( $input[ $key ] ) ? (float) $input[ $key ] : (float) $defaults[ $key ];
			$out[ $key ] = max( 0.0, min( 100000.0, $value ) );
		}

		$email              = isset( $input['alert_email'] ) ? sanitize_email( $input['alert_email'] ) : '';
		$out['alert_email'] = is_email( $email ) ? $email : (string) get_option( 'admin_email' );

		return $out;
	}

	/**
	 * Render a numeric threshold field.
	 *
	 * @param array $args Field args (expects 'key').
	 */
	public function render_number_field( $args ) {
		$key   = isset( $args['key'] ) ? sanitize_key( $args['key'] ) : '';
		$value = PVSA_Engine::get_setting( $key, '' );
		printf(
			'<input type="number" step="0.1" min="0" name="%1$s[%2$s]" value="%3$s" class="small-text" />',
			esc_attr( PVSA_OPTION_SETTINGS ),
			esc_attr( $key ),
			esc_attr( (string) $value )
		);
	}

	/**
	 * Render the alert-email field.
	 */
	public function render_email_field() {
		$value = PVSA_Engine::get_setting( 'alert_email', get_option( 'admin_email' ) );
		printf(
			'<input type="email" name="%1$s[alert_email]" value="%2$s" class="regular-text" />',
			esc_attr( PVSA_OPTION_SETTINGS ),
			esc_attr( (string) $value )
		);
	}

	/**
	 * "Run now" handler — runs the engine and stores the report.
	 */
	public function handle_run_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'pressvitals-site-auditor' ) );
		}
		check_admin_referer( 'pvsa_run_now' );

		$report = $this->engine->run();
		update_option( PVSA_OPTION_REPORT, $report, false );

		wp_safe_redirect( add_query_arg( 'pvsa_msg', 'ran', admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	/**
	 * "Rotate token" handler — regenerates the probe token.
	 */
	public function handle_rotate_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'pressvitals-site-auditor' ) );
		}
		check_admin_referer( 'pvsa_rotate_token' );

		update_option( PVSA_OPTION_TOKEN, bin2hex( random_bytes( 16 ) ) );

		wp_safe_redirect( add_query_arg( 'pvsa_msg', 'rotated', admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	/**
	 * Export the current report as JSON.
	 */
	public function handle_export_json() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'pressvitals-site-auditor' ) );
		}
		check_admin_referer( 'pvsa_export_json' );

		$report = get_option( PVSA_OPTION_REPORT );
		if ( ! is_array( $report ) ) {
			$report = array();
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="pressvitals-report-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo wp_json_encode( $report, JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Export the current report as CSV.
	 */
	public function handle_export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'pressvitals-site-auditor' ) );
		}
		check_admin_referer( 'pvsa_export_csv' );

		$report = get_option( PVSA_OPTION_REPORT );
		if ( ! is_array( $report ) || empty( $report['checks'] ) ) {
			wp_die( esc_html__( 'No report available to export.', 'pressvitals-site-auditor' ) );
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="pressvitals-report-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = '"Group","Label","ID","Tier","Status","Duration (ms)","Detail"' . "\r\n";

		foreach ( $report['checks'] as $id => $check ) {
			$row = array(
				isset( $check['group'] ) ? $check['group'] : '',
				isset( $check['label'] ) ? $check['label'] : '',
				$id,
				isset( $check['tier'] ) ? $check['tier'] : '',
				isset( $check['status'] ) ? $check['status'] : '',
				isset( $check['duration_ms'] ) ? $check['duration_ms'] : '',
				isset( $check['detail'] ) ? wp_strip_all_tags( $check['detail'] ) : '',
			);

			// Basic CSV escaping: quote fields that contain comma, quote, or newline.
			$escaped_row = array_map(
				static function ( $field ) {
					$field = (string) $field;
					if ( strpos( $field, '"' ) !== false || strpos( $field, ',' ) !== false || strpos( $field, "\n" ) !== false || strpos( $field, "\r" ) !== false ) {
						$field = '"' . str_replace( '"', '""', $field ) . '"';
					}
					return $field;
				},
				$row
			);

			$out .= implode( ',', $escaped_row ) . "\r\n";
		}
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV output is safely generated above.
		exit;
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'pressvitals-site-auditor' ) );
		}

		$report = get_option( PVSA_OPTION_REPORT );
		$token  = (string) get_option( PVSA_OPTION_TOKEN, '' );
		$msg    = isset( $_GET['pvsa_msg'] ) ? sanitize_key( wp_unslash( $_GET['pvsa_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PressVitals Site Auditor', 'pressvitals-site-auditor' ); ?></h1>
			<p class="description" style="max-width:780px;">
				<?php esc_html_e( 'Headless-first, scheduled monitoring: severity-tiered probes, email alerts, and a token-gated REST report. It complements WordPress core’s built-in Site Health by adding automation, alerting, and security/ops audit probes core does not run.', 'pressvitals-site-auditor' ); ?>
				<a href="<?php echo esc_url( admin_url( 'site-health.php' ) ); ?>"><?php esc_html_e( 'Open the built-in Site Health screen', 'pressvitals-site-auditor' ); ?></a>
			</p>

			<?php if ( 'ran' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Health check executed.', 'pressvitals-site-auditor' ); ?></p></div>
			<?php elseif ( 'rotated' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Token rotated. Update any external probe that uses it.', 'pressvitals-site-auditor' ); ?></p></div>
			<?php endif; ?>

			<p>
				<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" style="display:inline;">
					<input type="hidden" name="action" value="pvsa_run_now" />
					<?php wp_nonce_field( 'pvsa_run_now' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Run now', 'pressvitals-site-auditor' ); ?></button>
				</form>
				<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" style="display:inline; margin-left:10px;">
					<input type="hidden" name="action" value="pvsa_export_json" />
					<?php wp_nonce_field( 'pvsa_export_json' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Export JSON', 'pressvitals-site-auditor' ); ?></button>
				</form>
				<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" style="display:inline; margin-left:10px;">
					<input type="hidden" name="action" value="pvsa_export_csv" />
					<?php wp_nonce_field( 'pvsa_export_csv' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Export CSV', 'pressvitals-site-auditor' ); ?></button>
				</form>
			</p>

			<?php $this->render_report( is_array( $report ) ? $report : array() ); ?>

			<hr />
			<h2><?php esc_html_e( 'Settings', 'pressvitals-site-auditor' ); ?></h2>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'External probe token', 'pressvitals-site-auditor' ); ?></h2>
			<p><?php esc_html_e( 'Used by external/CI monitors to call the report endpoint:', 'pressvitals-site-auditor' ); ?></p>
			<p><code><?php echo esc_html( rest_url( PVSA_REST::NAMESPACE . '/report' ) ); ?>?token=<?php echo esc_html( $token ); ?></code></p>
			<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>">
				<input type="hidden" name="action" value="pvsa_rotate_token" />
				<?php wp_nonce_field( 'pvsa_rotate_token' ); ?>
				<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Rotate the token? External probes using the old one will stop working.', 'pressvitals-site-auditor' ) ); ?>');">
					<?php esc_html_e( 'Rotate token', 'pressvitals-site-auditor' ); ?>
				</button>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the report grouped by functional category.
	 *
	 * @param array $report Report from PVSA_Engine::run().
	 */
	private function render_report( array $report ) {
		if ( empty( $report['checks'] ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No report yet. Click "Run now".', 'pressvitals-site-auditor' ) . '</p></div>';
			return;
		}

		$verdict = isset( $report['verdict'] ) ? $report['verdict'] : 'pass';
		echo '<p style="font-size:18px;font-weight:600;">'
			. esc_html( strtoupper( $verdict ) ) . ' — '
			. esc_html(
				sprintf(
					/* translators: 1: Number of passing checks, 2: Number of warning checks, 3: Number of failing checks */
					__( '%1$d pass, %2$d warn, %3$d fail', 'pressvitals-site-auditor' ),
					(int) $report['pass'],
					(int) $report['warn'],
					(int) $report['fail']
				)
			)
			. '</p>';

		// Bucket by group.
		$buckets = array();
		foreach ( $report['checks'] as $id => $check ) {
			$group                    = isset( $check['group'] ) ? (string) $check['group'] : __( 'Other', 'pressvitals-site-auditor' );
			$buckets[ $group ][ $id ] = $check;
		}

		// Order: known groups first, then extras alphabetically.
		$order  = PVSA_Engine::group_order();
		$extras = array_diff( array_keys( $buckets ), $order );
		sort( $extras );
		$ordered = array_merge( array_intersect( $order, array_keys( $buckets ) ), $extras );

		$rank = array(
			'fail' => 0,
			'warn' => 1,
			'pass' => 2,
		);

		// Summary box: one pill per group (passed/total).
		echo '<div style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0;">';
		foreach ( $ordered as $group ) {
			$rows  = $buckets[ $group ];
			$total = count( $rows );
			$pass  = 0;
			$fail  = 0;
			$warn  = 0;
			foreach ( $rows as $row ) {
				if ( 'pass' === $row['status'] ) {
					++$pass;
				} elseif ( 'fail' === $row['status'] ) {
					++$fail;
				} else {
					++$warn;
				}
			}
			if ( $fail > 0 ) {
				$color = '#d63638';
			} elseif ( $warn > 0 ) {
				$color = '#d97706';
			} else {
				$color = '#16a34a';
			}
			printf(
				'<a href="#ohsa-group-%1$s" style="padding:6px 10px;border:1px solid #dcdcde;border-left:4px solid %2$s;border-radius:4px;font-size:13px;text-decoration:none;color:inherit;box-shadow:none;">%3$s <strong>%4$d/%5$d</strong></a>',
				esc_attr( sanitize_title( $group ) ),
				esc_attr( $color ),
				esc_html( $group ),
				(int) $pass,
				(int) $total
			);
		}
		echo '</div>';

		// Per-group tables.
		foreach ( $ordered as $group ) {
			$rows = $buckets[ $group ];
			uasort(
				$rows,
				static function ( $a, $b ) use ( $rank ) {
					$ra = isset( $rank[ $a['status'] ] ) ? $rank[ $a['status'] ] : 9;
					$rb = isset( $rank[ $b['status'] ] ) ? $rank[ $b['status'] ] : 9;
					return $ra <=> $rb;
				}
			);

			$group_id = 'ohsa-group-' . sanitize_title( $group );
			echo '<h3 id="' . esc_attr( $group_id ) . '" style="scroll-margin-top:40px; cursor:pointer;">' . esc_html( $group ) . ' <span class="dashicons dashicons-arrow-down-alt2" style="font-size:16px;line-height:1.5;"></span></h3>';
			echo '<table id="' . esc_attr( $group_id ) . '-table" class="widefat striped" style="display:table;"><thead><tr>'
				. '<th>' . esc_html__( 'Status', 'pressvitals-site-auditor' ) . '</th>'
				. '<th>' . esc_html__( 'Check', 'pressvitals-site-auditor' ) . '</th>'
				. '<th>' . esc_html__( 'Tier', 'pressvitals-site-auditor' ) . '</th>'
				. '<th>' . esc_html__( 'Time', 'pressvitals-site-auditor' ) . '</th>'
				. '<th>' . esc_html__( 'Detail', 'pressvitals-site-auditor' ) . '</th>'
				. '</tr></thead><tbody>';
			foreach ( $rows as $check ) {
				$tier = isset( $check['tier'] ) ? $check['tier'] : '-';
				$time = isset( $check['duration_ms'] ) ? $check['duration_ms'] . 'ms' : '-';

				$row_bg_color = '';
				$status_color = '';
				if ( 'fail' === $check['status'] ) {
					$row_bg_color = '#fcf0f1';
					$status_color = '#d63638';
				} elseif ( 'warn' === $check['status'] ) {
					$row_bg_color = '#fdf6e6';
					$status_color = '#d97706';
				} else {
					$status_color = '#16a34a';
				}

				echo '<tr' . ( $row_bg_color ? ' style="background-color: ' . esc_attr( $row_bg_color ) . ';"' : '' ) . '><td style="color: ' . esc_attr( $status_color ) . ';"><strong>' . esc_html( strtoupper( $check['status'] ) ) . '</strong></td>'
					. '<td>' . esc_html( $check['label'] ) . '</td>'
					. '<td>' . esc_html( $tier ) . '</td>'
					. '<td>' . esc_html( $time ) . '</td>'
					. '<td>' . esc_html( $check['detail'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}
}
