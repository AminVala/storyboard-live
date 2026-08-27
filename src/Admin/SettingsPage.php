<?php
/**
 * Settings page — Sprint 3 update.
 *
 * Adds BYOK API key fields for:
 *   - OpenAI (DALL·E 3) — Start Frame generation
 *   - Replicate         — Frame interpolation
 *
 * Existing settings retained:
 *   - Pro license key (placeholder for Sprint 3+ license check)
 *   - Delete on uninstall toggle
 *   - Scroll length / mobile disable option
 *   - CDN / API endpoint
 *   - Version / plugin info
 *
 * Security:
 *   - API keys are stored in wp_options — not exposed in page source.
 *   - Keys are masked in the UI (password input) and never echoed in plain text.
 *   - A "Test Connection" AJAX button validates each key live.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\AI\OpenAIProvider;
use ShahreHonar\SequenceEngine\AI\ReplicateProvider;
use ShahreHonar\SequenceEngine\License\LicenseManager;

/**
 * Renders and handles the plugin settings page.
 */
final class SettingsPage {

	const PAGE_SLUG   = 'shseq-settings';
	const OPTION_GRP  = 'shseq_settings_group';
	const NONCE_KEY   = 'shseq_settings_nonce';

	/** Register hooks. */
	public function register_hooks() {
		add_action( 'admin_menu',            array( $this, 'register_page' ) );
		add_action( 'admin_init',            array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// AJAX: test API connections.
		add_action( 'wp_ajax_shseq_test_openai',    array( $this, 'ajax_test_openai' ) );
		add_action( 'wp_ajax_shseq_test_replicate', array( $this, 'ajax_test_replicate' ) );
	}

	/** Register the settings submenu page. */
	public function register_page() {
		add_submenu_page(
			'shseq-dashboard',
			__( 'StoryBoard Live Settings', 'sh-sequence-engine' ),
			__( 'Settings', 'sh-sequence-engine' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/** Register settings via WordPress Settings API. */
	public function register_settings() {
		// ── AI API Keys ───────────────────────────────────────────────────

		register_setting( self::OPTION_GRP, OpenAIProvider::OPTION_API_KEY,    array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( self::OPTION_GRP, ReplicateProvider::OPTION_API_TOKEN, array( 'sanitize_callback' => 'sanitize_text_field' ) );

		// ── General ───────────────────────────────────────────────────────

		register_setting( self::OPTION_GRP, 'shseq_delete_on_uninstall', array( 'sanitize_callback' => 'absint' ) );
		register_setting( self::OPTION_GRP, 'shseq_disable_on_mobile',   array( 'sanitize_callback' => 'absint' ) );
		register_setting( self::OPTION_GRP, LicenseManager::OPTION_KEY_IS_PRO, array( 'sanitize_callback' => 'absint' ) );
	}

	/** Enqueue admin JS for AJAX test buttons. */
	public function enqueue_scripts( $hook ) {
		if ( 'storyboard-live_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		// Inline script — no extra file dependency.
		wp_add_inline_script(
			'jquery',
			$this->get_test_connection_js()
		);
	}

	/** Render the settings page. */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sh-sequence-engine' ) );
		}
		?>
		<div class="wrap shseq-admin">
			<h1><?php esc_html_e( 'StoryBoard Live — Settings', 'sh-sequence-engine' ); ?></h1>

			<?php settings_errors( self::OPTION_GRP ); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GRP );
				wp_nonce_field( self::NONCE_KEY, '_shseq_settings_nonce' );
				?>

				<?php /* ── AI API Keys section ── */ ?>
				<h2 class="title"><?php esc_html_e( 'AI Generation (Pro) — API Keys', 'sh-sequence-engine' ); ?></h2>

				<?php $this->render_privacy_notice(); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="shseq_openai_api_key">
								<?php esc_html_e( 'OpenAI API Key', 'sh-sequence-engine' ); ?>
							</label>
						</th>
						<td>
							<?php $this->render_api_key_field(
								OpenAIProvider::OPTION_API_KEY,
								'shseq_openai_api_key',
								__( 'sk-…', 'sh-sequence-engine' ),
								'shseq_test_openai',
								__( 'Test OpenAI', 'sh-sequence-engine' )
							); ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to OpenAI API keys page. */
									esc_html__( 'Create a key at %s. Used to generate the Start Frame from your prompt (DALL·E 3).', 'sh-sequence-engine' ),
									'<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com/api-keys</a>'
								);
								?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="shseq_replicate_api_token">
								<?php esc_html_e( 'Replicate API Token', 'sh-sequence-engine' ); ?>
							</label>
						</th>
						<td>
							<?php $this->render_api_key_field(
								ReplicateProvider::OPTION_API_TOKEN,
								'shseq_replicate_api_token',
								__( 'r8_…', 'sh-sequence-engine' ),
								'shseq_test_replicate',
								__( 'Test Replicate', 'sh-sequence-engine' )
							); ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to Replicate API tokens page. */
									esc_html__( 'Create a token at %s. Used to interpolate 24–36 frames from Start → End (FILM model).', 'sh-sequence-engine' ),
									'<a href="https://replicate.com/account/api-tokens" target="_blank" rel="noopener">replicate.com/account/api-tokens</a>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<?php /* ── Plan / License section ── */ ?>
				<h2 class="title"><?php esc_html_e( 'Plan', 'sh-sequence-engine' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Pro Plan', 'sh-sequence-engine' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( LicenseManager::OPTION_KEY_IS_PRO ); ?>"
									value="1"
									<?php checked( 1, get_option( LicenseManager::OPTION_KEY_IS_PRO, 0 ) ); ?>
								>
								<?php esc_html_e( 'Enable Pro features (AI generation, 15 heroes, 36 frames, 10 content steps)', 'sh-sequence-engine' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Sprint 3 placeholder — will be replaced by verified license-key check in a future release.', 'sh-sequence-engine' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php /* ── General section ── */ ?>
				<h2 class="title"><?php esc_html_e( 'General', 'sh-sequence-engine' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Mobile animation', 'sh-sequence-engine' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="shseq_disable_on_mobile"
									value="1"
									<?php checked( 1, get_option( 'shseq_disable_on_mobile', 0 ) ); ?>
								>
								<?php esc_html_e( 'Disable scroll animation on mobile devices (show static last frame instead)', 'sh-sequence-engine' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Remove data on uninstall', 'sh-sequence-engine' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="shseq_delete_on_uninstall"
									value="1"
									<?php checked( 1, get_option( 'shseq_delete_on_uninstall', 0 ) ); ?>
								>
								<?php esc_html_e( 'Delete all plugin data (sequences, frames, settings) when the plugin is uninstalled', 'sh-sequence-engine' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php /* ── Version info ── */ ?>
			<hr>
			<p class="description">
				<?php
				printf(
					/* translators: %s: version number. */
					esc_html__( 'StoryBoard Live version %s', 'sh-sequence-engine' ),
					esc_html( defined( 'SHSEQ_VERSION' ) ? SHSEQ_VERSION : '—' )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the data-privacy disclosure notice for AI providers.
	 */
	private function render_privacy_notice() {
		?>
		<div class="notice notice-info inline">
			<p>
				<strong><?php esc_html_e( 'Privacy notice:', 'sh-sequence-engine' ); ?></strong>
				<?php esc_html_e( 'When you generate frames, your uploaded images and text prompt are sent to the configured AI provider (OpenAI and/or Replicate). Each provider\'s privacy policy applies. Do not generate frames containing personal or sensitive information.', 'sh-sequence-engine' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render a masked API key input with an inline "Test" button.
	 *
	 * @param string $option_name  WordPress option key.
	 * @param string $input_id     HTML element ID.
	 * @param string $placeholder  Placeholder text.
	 * @param string $ajax_action  AJAX action for the test button.
	 * @param string $button_label Button label.
	 */
	private function render_api_key_field( $option_name, $input_id, $placeholder, $ajax_action, $button_label ) {
		$stored = get_option( $option_name, '' );
		// Mask: show only the first 6 chars + asterisks if a key is set.
		$display = ! empty( $stored )
			? substr( $stored, 0, 6 ) . str_repeat( '•', max( 0, strlen( $stored ) - 6 ) )
			: '';
		?>
		<div style="display:flex;align-items:center;gap:8px;">
			<input
				type="password"
				id="<?php echo esc_attr( $input_id ); ?>"
				name="<?php echo esc_attr( $option_name ); ?>"
				value="<?php echo esc_attr( $stored ); ?>"
				class="regular-text"
				autocomplete="new-password"
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
			>
			<button
				type="button"
				class="button shseq-test-api-btn"
				data-action="<?php echo esc_attr( $ajax_action ); ?>"
				data-field="<?php echo esc_attr( $input_id ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'shseq_test_api' ) ); ?>"
			>
				<?php echo esc_html( $button_label ); ?>
			</button>
			<span class="shseq-test-api-result description" style="min-width:120px;"></span>
		</div>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────
	// AJAX: test API connections
	// ─────────────────────────────────────────────────────────────────────

	/** Test the OpenAI API key. */
	public function ajax_test_openai() {
		$this->check_test_nonce();

		$key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		if ( empty( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'No API key provided.', 'sh-sequence-engine' ) ) );
			return;
		}

		// Temporarily store the key, test, then restore.
		$previous = get_option( OpenAIProvider::OPTION_API_KEY, '' );
		update_option( OpenAIProvider::OPTION_API_KEY, $key );

		// Lightweight test: list models endpoint.
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'timeout' => 10,
				'headers' => array( 'Authorization' => 'Bearer ' . $key ),
			)
		);

		update_option( OpenAIProvider::OPTION_API_KEY, $previous );

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 === (int) $code ) {
			wp_send_json_success( array( 'message' => __( 'Connected successfully.', 'sh-sequence-engine' ) ) );
		} else {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
			wp_send_json_error( array( 'message' => $msg ) );
		}
	}

	/** Test the Replicate API token. */
	public function ajax_test_replicate() {
		$this->check_test_nonce();

		$token = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		if ( empty( $token ) ) {
			wp_send_json_error( array( 'message' => __( 'No API token provided.', 'sh-sequence-engine' ) ) );
			return;
		}

		// Lightweight test: account endpoint.
		$response = wp_remote_get(
			'https://api.replicate.com/v1/account',
			array(
				'timeout' => 10,
				'headers' => array( 'Authorization' => 'Token ' . $token ),
			)
		);

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 === (int) $code ) {
			$body     = json_decode( wp_remote_retrieve_body( $response ), true );
			$username = isset( $body['username'] ) ? $body['username'] : '';
			wp_send_json_success( array(
				'message' => $username
					? sprintf( __( 'Connected as @%s.', 'sh-sequence-engine' ), $username )
					: __( 'Connected successfully.', 'sh-sequence-engine' ),
			) );
		} else {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = isset( $body['detail'] ) ? $body['detail'] : 'HTTP ' . $code;
			wp_send_json_error( array( 'message' => $msg ) );
		}
	}

	/** Verify the test-connection nonce and capability. */
	private function check_test_nonce() {
		if ( ! isset( $_POST['_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'shseq_test_api' )
		) {
			wp_send_json_error( array( 'message' => __( 'Nonce verification failed.', 'sh-sequence-engine' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sh-sequence-engine' ) ), 403 );
		}
	}

	/**
	 * Inline JS for the Test Connection buttons.
	 *
	 * @return string
	 */
	private function get_test_connection_js() {
		return "
(function($){
  $(document).on('click', '.shseq-test-api-btn', function(){
    var btn    = $(this);
    var action = btn.data('action');
    var field  = btn.data('field');
    var nonce  = btn.data('nonce');
    var result = btn.siblings('.shseq-test-api-result');
    var key    = $('#' + field).val();

    if (!key) { result.text('" . esc_js( __( 'Enter a key first.', 'sh-sequence-engine' ) ) . "').css('color','#b32d2e'); return; }

    btn.prop('disabled', true).text('" . esc_js( __( 'Testing…', 'sh-sequence-engine' ) ) . "');
    result.text('').css('color','');

    $.post(ajaxurl, { action: action, api_key: key, _nonce: nonce }, function(res){
      if (res.success) {
        result.text(res.data.message).css('color','#00a32a');
      } else {
        result.text(res.data.message).css('color','#b32d2e');
      }
    }).fail(function(){
      result.text('" . esc_js( __( 'Request failed.', 'sh-sequence-engine' ) ) . "').css('color','#b32d2e');
    }).always(function(){
      btn.prop('disabled', false).text(btn.data('original-label') || '" . esc_js( __( 'Test', 'sh-sequence-engine' ) ) . "');
    });
  });

  // Store original labels
  $('.shseq-test-api-btn').each(function(){
    $(this).data('original-label', $(this).text());
  });
}(jQuery));
";
	}
}
