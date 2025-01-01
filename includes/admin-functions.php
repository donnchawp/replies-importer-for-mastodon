<?php
/**
 * Admin functions for Replies Importer for Mastodon
 *
 * @package RepliesImporterForMastodon
 */

class Replies_Importer_For_Mastodon_Admin {
	use Replies_Importer_For_Mastodon_Logger;

	private $api;
	private $config;

	public function __construct() {
		$this->api    = new Replies_Importer_For_Mastodon_API();
		$this->config = Replies_Importer_For_Mastodon_Config::get_instance();
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'settings_init' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		$this->api->init();
	}

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu() {
		add_options_page(
			__( 'Replies Importer for Mastodon Settings', 'replies_importer_for_mastodon' ),
			__( 'Replies Importer for Mastodon', 'replies_importer_for_mastodon' ),
			'manage_options',
			'replies_importer_for_mastodon',
			array( $this, 'options_page' )
		);
	}

	public function options_page() {
		$this->debug_log( 'options_page: ' . print_r( $this->config->get( 'mastodon_instance_url' ), true ) );

		// Display admin notices
		if ( isset( $_GET['message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$message = '';
			$type    = 'updated';
			switch ( $_GET['message'] ) { // phpcs:ignore WordPress.Security.NonceVerification
				case 'instance_url_saved':
					$message = __( 'Instance URL saved successfully.', 'replies_importer_for_mastodon' );
					break;
				case 'schedule_updated':
					$message = __( 'Import schedule updated successfully.', 'replies_importer_for_mastodon' );
					break;
				case 'schedule_removed':
					$message = __( 'Scheduled import removed.', 'replies_importer_for_mastodon' );
					break;
				case 'import_initiated':
					$message = __( 'Mastodon replies import initiated.', 'replies_importer_for_mastodon' );
					break;
				case 'auth_success':
					$message = __( 'Successfully authenticated with Mastodon.', 'replies_importer_for_mastodon' );
					break;
			}
			if ( ! empty( $message ) ) {
				echo "<div class='" . esc_attr( $type ) . "'><p>" . esc_html( $message ) . '</p></div>';
			}
		}
		settings_errors( 'replies_importer_for_mastodon_messages' );
		?>
		<div class="wrap">
			<h2><?php esc_html_e( 'Replies Importer for Mastodon Settings', 'replies_importer_for_mastodon' ); ?></h2>
			<form action='options.php' method='post'>
				<?php
				settings_fields( 'replies_importer_for_mastodon_plugin' );
				do_settings_sections( 'replies_importer_for_mastodon_plugin' );
				submit_button( __( 'Save Settings', 'replies_importer_for_mastodon' ) );
				?>
			</form>
			<?php
			if ( ! empty( $this->config->get( 'mastodon_instance_url' ) ) && empty( $this->config->get_connection_option( 'access_token' ) ) ) {
				$auth_url = $this->api->get_authorization_url( $this->config->get( 'mastodon_instance_url' ) );
				$this->debug_log( 'auth_url: ' . $auth_url );
				if ( $auth_url ) {
					echo "<a href='" . esc_url( $auth_url ) . "' class='button button-primary'>" . esc_html__( 'Authorize with Mastodon', 'replies_importer_for_mastodon' ) . '</a>';
				}
			} elseif ( ! empty( $this->config->get_connection_option( 'access_token' ) ) ) {
				esc_html_e( 'Successfully authenticated with Mastodon.', 'replies_importer_for_mastodon' );
				?>
				<form action='' method='post'>
					<?php wp_nonce_field( 'replies_importer_for_mastodon_check_now', 'replies_importer_for_mastodon_nonce' ); ?>
					<h3><?php esc_html_e( 'Manual Import', 'replies_importer_for_mastodon' ); ?></h3>
					<?php submit_button( __( 'Check Now', 'replies_importer_for_mastodon' ), 'secondary', 'check_now' ); ?>
				</form>
				<form action='' method='post'>
					<?php wp_nonce_field( 'replies_importer_for_mastodon_disconnect', 'replies_importer_for_mastodon_nonce' ); ?>
					<?php submit_button( __( 'Disconnect', 'replies_importer_for_mastodon' ), 'secondary', 'disconnect' ); ?>
				</form>
				<?php
				$next_scheduled = wp_next_scheduled( 'replies_importer_for_mastodon_event' );
				if ( $next_scheduled ) {
					// translators: %s is the next scheduled import date and time.
					echo '<p>' . esc_html( sprintf( __( 'Next import scheduled for: %s', 'replies_importer_for_mastodon' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_scheduled ) ) ) . '</p>';
				}
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the debug mode setting field.
	 */
	public function debug_mode_render() {
		?>
		<input type='checkbox' name='replies_importer_for_mastodon_settings[debug_mode]' <?php checked( $this->config->get( 'debug_mode' ), true ); ?>>
		<label for="replies_importer_for_mastodon_settings[debug_mode]"><?php esc_html_e( 'Enable debug logging', 'replies_importer_for_mastodon' ); ?></label>
		<?php
	}

	/**
	 * Render the schedule period setting field.
	 */
	public function schedule_period_render() {
		?>
		<select name='replies_importer_for_mastodon_settings[schedule_period]'>
			<option value='hourly' <?php selected( $this->config->get( 'schedule_period' ), 'hourly' ); ?>><?php esc_html_e( 'Once an Hour', 'replies_importer_for_mastodon' ); ?></option>
			<option value='daily' <?php selected( $this->config->get( 'schedule_period' ), 'daily' ); ?>><?php esc_html_e( 'Once a Day', 'replies_importer_for_mastodon' ); ?></option>
			<option value='disabled' <?php selected( $this->config->get( 'schedule_period' ), 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'replies_importer_for_mastodon' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Render the instance URL setting field.
	 */
	public function instance_url_render() {
		$this->debug_log( 'instance_url_render: ' . print_r( $this->config->get( 'mastodon_instance_url' ), true ) );
		?>
		<input type='hidden' name='save_instance_url' value='1'>
		<input type='text' name='replies_importer_for_mastodon_settings[mastodon_instance_url]' value='<?php echo esc_attr( $this->config->get( 'mastodon_instance_url' ) ); ?>' style="width: 300px;">
		<?php
	}

	/**
	 * Render the settings section callback.
	 */
	public function settings_section_callback() {
		esc_html_e( 'Enter your Mastodon instance URL below:', 'replies_importer_for_mastodon' );
	}

	/**
	 * Initialize settings.
	 */
	public function settings_init() {
		register_setting(
			'replies_importer_for_mastodon_plugin',
			'replies_importer_for_mastodon_settings',
			array( $this, 'sanitize_settings' )
		);

		add_settings_section(
			'replies_importer_for_mastodon_plugin_section',
			__( 'Mastodon Account Settings', 'replies_importer_for_mastodon' ),
			array( $this, 'settings_section_callback' ),
			'replies_importer_for_mastodon_plugin'
		);

		add_settings_field(
			'mastodon_instance_url',
			__( 'Mastodon Instance URL', 'replies_importer_for_mastodon' ),
			array( $this, 'instance_url_render' ),
			'replies_importer_for_mastodon_plugin',
			'replies_importer_for_mastodon_plugin_section'
		);

		add_settings_field(
			'debug_mode',
			__( 'Debug Mode', 'replies_importer_for_mastodon' ),
			array( $this, 'debug_mode_render' ),
			'replies_importer_for_mastodon_plugin',
			'replies_importer_for_mastodon_plugin_section'
		);

		add_settings_field(
			'schedule_period',
			__( 'Import Schedule', 'replies_importer_for_mastodon' ),
			array( $this, 'schedule_period_render' ),
			'replies_importer_for_mastodon_plugin',
			'replies_importer_for_mastodon_plugin_section'
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input The input data to sanitize.
	 * @return array The sanitized input data.
	 */
	public function sanitize_settings( $input ) {
		$sanitized_input = array();
		$this->debug_log( 'sanitize_settings: ' . print_r( $input, true ) );
		if ( isset( $input['mastodon_instance_url'] ) ) {
			$sanitized_input['mastodon_instance_url'] = esc_url_raw( $input['mastodon_instance_url'] );
		}

		$sanitized_input['debug_mode'] = isset( $input['debug_mode'] ) ? (bool) $input['debug_mode'] : false;

		$sanitized_input['schedule_period'] = isset( $input['schedule_period'] ) ? sanitize_text_field( $input['schedule_period'] ) : 'hourly';
		$sanitized_input['client_id']       = isset( $input['client_id'] ) ? sanitize_text_field( $input['client_id'] ) : '';
		$sanitized_input['client_secret']   = isset( $input['client_secret'] ) ? sanitize_text_field( $input['client_secret'] ) : '';
		$sanitized_input['access_token']    = isset( $input['access_token'] ) ? sanitize_text_field( $input['access_token'] ) : '';

		// Schedule or remove the import event based on the selected option
		if ( 'disabled' === $sanitized_input['schedule_period'] ) {
			wp_clear_scheduled_hook( 'replies_importer_for_mastodon_event' );
		} else {
			$this->schedule_import();
		}

		return $sanitized_input;
	}

	/**
	 * Handle actions on the admin page.
	 */
	public function handle_actions() {
		$this->debug_log( '1 handle_actions' . print_r( $_GET, true ) . print_r( $_POST, true ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $_GET['page'] ) || 'replies_importer_for_mastodon' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$this->debug_log( '2 handle_actions' );

		if (
			isset( $_POST['check_now'] ) &&
			isset( $_POST['replies_importer_for_mastodon_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['replies_importer_for_mastodon_nonce'] ) ), 'replies_importer_for_mastodon_check_now' )
		) {
			$this->api->fetch_and_import_mastodon_comments();
			wp_safe_redirect( add_query_arg( 'message', 'import_initiated', remove_query_arg( 'code' ) ) );
			exit;
		}

		if (
			isset( $_POST['disconnect'] ) &&
			isset( $_POST['replies_importer_for_mastodon_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['replies_importer_for_mastodon_nonce'] ) ), 'replies_importer_for_mastodon_disconnect' )
		) {
			$this->api->disconnect();
			wp_safe_redirect( add_query_arg( 'message', 'disconnect', remove_query_arg( 'code' ) ) );
			exit;
		}

		$this->debug_log( '3 handle_actions' );
		// Handle Mastodon authorization
		if ( isset( $_GET['code'] ) && ! empty( $this->config->get( 'mastodon_instance_url' ) ) ) {
			$this->debug_log( '4 handle_actions' );
			$token = $this->api->get_access_token( $this->config->get( 'mastodon_instance_url' ), sanitize_text_field( wp_unslash( $_GET['code'] ) ) );
			if ( $token ) {
				$this->debug_log( '5 handle_actions ' . $token );
				$this->config->set_connection_option( 'access_token', $token );
				wp_safe_redirect( add_query_arg( 'message', 'auth_success', remove_query_arg( 'code' ) ) );
				exit;
			}
		}
	}

	public function schedule_import() {
		wp_clear_scheduled_hook( 'replies_importer_for_mastodon_event' );
		if ( 'hourly' === $this->config->get( 'schedule_period' ) ) {
			wp_schedule_event( time() + 10, 'hourly', 'replies_importer_for_mastodon_event' );
		} else {
			wp_schedule_event( time() + 10, 'daily', 'replies_importer_for_mastodon_event' );
		}
	}
}
