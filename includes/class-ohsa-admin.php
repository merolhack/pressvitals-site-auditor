<?php
/**
 * Admin UI — Tools → OmniHealth: Deep Site Auditor.
 *
 * Renders the last stored report grouped by category, exposes a Settings-API
 * form for thresholds + alert email, and "Run now" / "Rotate token" actions.
 * Every action is nonce-protected and capability-gated; all output is escaped.
 *
 * @package OmniHealthSiteAuditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OHSA_Admin {

	const PAGE_SLUG      = 'omnihealth-site-auditor';
	const SETTINGS_GROUP = 'ohsa_settings_group';

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
	 * Hook admin menu, settings and form handlers.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_ohsa_run_now', array( $this, 'handle_run_now' ) );
		add_action( 'admin_post_ohsa_rotate_token', array( $this, 'handle_rotate_token' ) );
	}

	/**
	 * Add the Tools submenu page.
	 */
	public function register_menu() {
		add_management_page(
			__( 'OmniHealth: Deep Site Auditor', 'omnihealth-site-auditor' ),
			__( 'OmniHealth: Deep Site Auditor', 'omnihealth-site-auditor' ),
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
			OHSA_OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => OHSA_Engine::default_settings(),
			)
		);

		add_settings_section(
			'ohsa_thresholds',
			__( 'Thresholds & alerts', 'omnihealth-site-auditor' ),
			'__return_false',
			self::PAGE_SLUG
		);

		$fields = array(
			'error_log_warn_mb' => __( 'Error log warn (MB)', 'omnihealth-site-auditor' ),
			'error_log_fail_mb' => __( 'Error log fail (MB)', 'omnihealth-site-auditor' ),
			'autoload_warn_mb'  => __( 'Autoload warn (MB)', 'omnihealth-site-auditor' ),
			'autoload_fail_mb'  => __( 'Autoload fail (MB)', 'omnihealth-site-auditor' ),
		);
		foreach ( $fields as $key => $label ) {
			add_settings_field(
				$key,
				$label,
				array( $this, 'render_number_field' ),
				self::PAGE_SLUG,
				'ohsa_thresholds',
				array( 'key' => $key )
			);
		}

		add_settings_field(
			'alert_email',
			__( 'Alert email', 'omnihealth-site-auditor' ),
			array( $this, 'render_email_field' ),
			self::PAGE_SLUG,
			'ohsa_thresholds'
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
		$defaults = OHSA_Engine::default_settings();
		$out      = array();

		foreach ( array( 'error_log_warn_mb', 'error_log_fail_mb', 'autoload_warn_mb', 'autoload_fail_mb' ) as $key ) {
			$value       = isset( $input[ $key ] ) ? (float) $input[ $key ] : (float) $defaults[ $key ];
			$out[ $key ] = max( 0.0, min( 100000.0, $value ) );
		}

		$email               = isset( $input['alert_email'] ) ? sanitize_email( $input['alert_email'] ) : '';
		$out['alert_email']  = is_email( $email ) ? $email : (string) get_option( 'admin_email' );

		return $out;
	}

	/**
	 * Render a numeric threshold field.
	 *
	 * @param array $args Field args (expects 'key').
	 */
	public function render_number_field( $args ) {
		$key   = isset( $args['key'] ) ? sanitize_key( $args['key'] ) : '';
		$value = OHSA_Engine::get_setting( $key, '' );
		printf(
			'<input type="number" step="0.1" min="0" name="%1$s[%2$s]" value="%3$s" class="small-text" />',
			esc_attr( OHSA_OPTION_SETTINGS ),
			esc_attr( $key ),
			esc_attr( (string) $value )
		);
	}

	/**
	 * Render the alert-email field.
	 */
	public function render_email_field() {
		$value = OHSA_Engine::get_setting( 'alert_email', get_option( 'admin_email' ) );
		printf(
			'<input type="email" name="%1$s[alert_email]" value="%2$s" class="regular-text" />',
			esc_attr( OHSA_OPTION_SETTINGS ),
			esc_attr( (string) $value )
		);
	}

	/**
	 * "Run now" handler — runs the engine and stores the report.
	 */
	public function handle_run_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'omnihealth-site-auditor' ) );
		}
		check_admin_referer( 'ohsa_run_now' );

		$report = $this->engine->run();
		update_option( OHSA_OPTION_REPORT, $report, false );

		wp_safe_redirect( add_query_arg( 'ohsa_msg', 'ran', admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	/**
	 * "Rotate token" handler — regenerates the probe token.
	 */
	public function handle_rotate_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'omnihealth-site-auditor' ) );
		}
		check_admin_referer( 'ohsa_rotate_token' );

		update_option( OHSA_OPTION_TOKEN, bin2hex( random_bytes( 16 ) ) );

		wp_safe_redirect( add_query_arg( 'ohsa_msg', 'rotated', admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'omnihealth-site-auditor' ) );
		}

		$report = get_option( OHSA_OPTION_REPORT );
		$token  = (string) get_option( OHSA_OPTION_TOKEN, '' );
		$msg    = isset( $_GET['ohsa_msg'] ) ? sanitize_key( wp_unslash( $_GET['ohsa_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OmniHealth: Deep Site Auditor', 'omnihealth-site-auditor' ); ?></h1>
			<p class="description" style="max-width:780px;">
				<?php esc_html_e( 'Headless-first, scheduled monitoring: severity-tiered probes, email alerts, and a token-gated REST report. It complements WordPress core’s built-in Site Health by adding automation, alerting, and security/ops audit probes core does not run.', 'omnihealth-site-auditor' ); ?>
				<a href="<?php echo esc_url( admin_url( 'site-health.php' ) ); ?>"><?php esc_html_e( 'Open the built-in Site Health screen', 'omnihealth-site-auditor' ); ?></a>
			</p>

			<?php if ( 'ran' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Health check executed.', 'omnihealth-site-auditor' ); ?></p></div>
			<?php elseif ( 'rotated' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Token rotated. Update any external probe that uses it.', 'omnihealth-site-auditor' ); ?></p></div>
			<?php endif; ?>

			<p>
				<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" style="display:inline;">
					<input type="hidden" name="action" value="ohsa_run_now" />
					<?php wp_nonce_field( 'ohsa_run_now' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Run now', 'omnihealth-site-auditor' ); ?></button>
				</form>
			</p>

			<?php $this->render_report( is_array( $report ) ? $report : array() ); ?>

			<hr />
			<h2><?php esc_html_e( 'Settings', 'omnihealth-site-auditor' ); ?></h2>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'External probe token', 'omnihealth-site-auditor' ); ?></h2>
			<p><?php esc_html_e( 'Used by external/CI monitors to call the report endpoint:', 'omnihealth-site-auditor' ); ?></p>
			<p><code><?php echo esc_html( rest_url( OHSA_REST::NAMESPACE . '/report' ) ); ?>?token=<?php echo esc_html( $token ); ?></code></p>
			<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>">
				<input type="hidden" name="action" value="ohsa_rotate_token" />
				<?php wp_nonce_field( 'ohsa_rotate_token' ); ?>
				<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Rotate the token? External probes using the old one will stop working.', 'omnihealth-site-auditor' ) ); ?>');">
					<?php esc_html_e( 'Rotate token', 'omnihealth-site-auditor' ); ?>
				</button>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the report grouped by functional category.
	 *
	 * @param array $report Report from OHSA_Engine::run().
	 */
	private function render_report( array $report ) {
		if ( empty( $report['checks'] ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No report yet. Click "Run now".', 'omnihealth-site-auditor' ) . '</p></div>';
			return;
		}

		$verdict = isset( $report['verdict'] ) ? $report['verdict'] : 'pass';
		echo '<p style="font-size:18px;font-weight:600;">'
			. esc_html( strtoupper( $verdict ) ) . ' — '
			. esc_html(
				sprintf(
					/* translators: 1: pass, 2: warn, 3: fail */
					__( '%1$d pass, %2$d warn, %3$d fail', 'omnihealth-site-auditor' ),
					(int) $report['pass'],
					(int) $report['warn'],
					(int) $report['fail']
				)
			)
			. '</p>';

		// Bucket by group.
		$buckets = array();
		foreach ( $report['checks'] as $id => $check ) {
			$group = isset( $check['group'] ) ? (string) $check['group'] : __( 'Other', 'omnihealth-site-auditor' );
			$buckets[ $group ][ $id ] = $check;
		}

		// Order: known groups first, then extras alphabetically.
		$order   = OHSA_Engine::group_order();
		$extras  = array_diff( array_keys( $buckets ), $order );
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
			foreach ( $rows as $row ) {
				if ( 'pass' === $row['status'] ) {
					++$pass;
				}
			}
			$color = ( $pass === $total ) ? '#16a34a' : '#d97706';
			printf(
				'<span style="padding:6px 10px;border:1px solid #dcdcde;border-left:4px solid %1$s;border-radius:4px;font-size:13px;">%2$s <strong>%3$d/%4$d</strong></span>',
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

			echo '<h3>' . esc_html( $group ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr>'
				. '<th>' . esc_html__( 'Status', 'omnihealth-site-auditor' ) . '</th>'
				. '<th>' . esc_html__( 'Check', 'omnihealth-site-auditor' ) . '</th>'
				. '<th>' . esc_html__( 'Detail', 'omnihealth-site-auditor' ) . '</th>'
				. '</tr></thead><tbody>';
			foreach ( $rows as $check ) {
				echo '<tr><td><strong>' . esc_html( strtoupper( $check['status'] ) ) . '</strong></td>'
					. '<td>' . esc_html( $check['label'] ) . '</td>'
					. '<td>' . esc_html( $check['detail'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}
}
