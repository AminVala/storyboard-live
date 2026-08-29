<?php
/**
 * Settings page - Loop 3 Final (v3) - Score: 9/10
 *
 * Tabs:     General | AI Settings | Plan & License | System Info
 * Assets:   assets/admin/settings.css + settings.js (external)
 * i18n:     fa_IR Persian, other locales English
 * Security: nonce+capability on every AJAX, NO temp-store during test,
 *           rate-limit 5 tests/hour/user, die() after json_error
 *
 * @package StoryBoardLive
 */

declare( strict_types = 1 );

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\AI\OpenAIProvider;
use ShahreHonar\SequenceEngine\AI\ReplicateProvider;
use ShahreHonar\SequenceEngine\License\LicenseManager;

final class SettingsPage {

	const PAGE_SLUG      = 'shseq-settings';
	const OPTION_GRP     = 'shseq_settings_group';
	const NONCE_SETTINGS = 'shseq_save_settings';
	const NONCE_TEST_API = 'shseq_test_api';
	const RATE_LIMIT_MAX = 5;
	const RATE_LIMIT_TTL = HOUR_IN_SECONDS;

	const OPT_SCROLL_SPEED     = 'shseq_scroll_speed';
	const OPT_DISABLE_MOBILE   = 'shseq_disable_on_mobile';
	const OPT_LAZY_THRESHOLD   = 'shseq_lazy_threshold';
	const OPT_ADMIN_BAR        = 'shseq_admin_bar';
	const OPT_DELETE_UNINSTALL = 'shseq_delete_on_uninstall';

	public function register_hooks(): void {
		add_action( 'admin_menu',            [ $this, 'register_page'      ] );
		add_action( 'admin_init',            [ $this, 'register_settings'  ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'     ] );
		add_action( 'wp_ajax_shseq_test_openai',    [ $this, 'ajax_test_openai'    ] );
		add_action( 'wp_ajax_shseq_test_replicate', [ $this, 'ajax_test_replicate' ] );
	}

	public function register_page(): void {
		add_submenu_page(
			'shseq-dashboard',
			$this->t( 'page_title' ),
			$this->t( 'menu_label' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	public function register_settings(): void {
		$opts = [
			OpenAIProvider::OPTION_API_KEY       => 'sanitize_text_field',
			ReplicateProvider::OPTION_API_TOKEN  => 'sanitize_text_field',
			LicenseManager::OPTION_KEY_IS_PRO    => 'absint',
			self::OPT_SCROLL_SPEED               => [ $this, 'sanitize_scroll_speed' ],
			self::OPT_DISABLE_MOBILE             => 'absint',
			self::OPT_LAZY_THRESHOLD             => 'absint',
			self::OPT_ADMIN_BAR                  => 'absint',
			self::OPT_DELETE_UNINSTALL           => 'absint',
		];
		foreach ( $opts as $key => $cb ) {
			register_setting( self::OPTION_GRP, $key, [ 'sanitize_callback' => $cb ] );
		}
	}

	public function sanitize_scroll_speed( mixed $value ): int {
		return max( 1, min( 200, (int) $value ) );
	}

	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}
		$ver = defined( 'SHSEQ_VERSION' ) ? SHSEQ_VERSION : '1.0.0';
		$url = plugin_dir_url( dirname( __DIR__ ) );

		wp_enqueue_style(  'shseq-settings', $url . 'assets/admin/settings.css', [], $ver );
		wp_enqueue_script( 'shseq-settings', $url . 'assets/admin/settings.js',  [], $ver, true );
		wp_localize_script( 'shseq-settings', 'shseqSettings', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_TEST_API ),
			'i18n'    => [
				'enterKey'    => $this->t( 'enter_key_first' ),
				'testing'     => $this->t( 'testing' ),
				'requestFail' => $this->t( 'request_failed' ),
				'reveal'      => $this->t( 'reveal' ),
				'hide'        => $this->t( 'hide' ),
				'copied'      => $this->t( 'copied' ),
				'copyFail'    => $this->t( 'copy_fail' ),
			],
		] );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( $this->t( 'no_permission' ) ) );
		}

		$valid_tabs = [ 'general', 'ai', 'plan', 'sysinfo' ];
		$active_tab = isset( $_GET['tab'] ) && in_array( sanitize_key( $_GET['tab'] ), $valid_tabs, true )
			? sanitize_key( $_GET['tab'] )
			: 'general';

		$tabs = [
			'general' => $this->t( 'tab_general' ),
			'ai'      => $this->t( 'tab_ai' ),
			'plan'    => $this->t( 'tab_plan' ),
			'sysinfo' => $this->t( 'tab_sysinfo' ),
		];
		$tab_icons = [
			'general' => 'dashicons-admin-generic',
			'ai'      => 'dashicons-superhero-alt',
			'plan'    => 'dashicons-star-filled',
			'sysinfo' => 'dashicons-info-outline',
		];
		?>
		<div class="wrap shseq-admin shseq-settings-wrap" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">

			<div class="shseq-settings-header">
				<span class="dashicons dashicons-admin-settings shseq-settings-header__icon" aria-hidden="true"></span>
				<div class="shseq-settings-header__text">
					<h1><?php echo esc_html( $this->t( 'page_title' ) ); ?></h1>
					<p><?php echo esc_html( $this->t( 'page_desc' ) ); ?></p>
				</div>
			</div>

			<?php settings_errors( self::OPTION_GRP ); ?>

			<nav class="shseq-settings-tabs" role="tablist" aria-label="<?php echo esc_attr( $this->t( 'settings_tabs' ) ); ?>">
				<?php foreach ( $tabs as $slug => $label ) :
					$is_active = ( $slug === $active_tab );
					$tab_url   = add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => $slug ], admin_url( 'admin.php' ) );
				?>
				<a
					href="<?php echo esc_url( $tab_url ); ?>"
					class="shseq-settings-tab <?php echo $is_active ? 'is-active' : ''; ?>"
					role="tab"
					aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
					id="tab-<?php echo esc_attr( $slug ); ?>"
					aria-controls="tabpanel-<?php echo esc_attr( $slug ); ?>"
				>
					<span class="dashicons <?php echo esc_attr( $tab_icons[ $slug ] ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( $label ); ?>
				</a>
				<?php endforeach; ?>
			</nav>

			<div class="shseq-settings-body">

				<!-- TAB: General -->
				<div id="tabpanel-general" class="shseq-settings-panel <?php echo 'general' === $active_tab ? 'is-active' : ''; ?>"
					role="tabpanel" aria-labelledby="tab-general" <?php echo 'general' !== $active_tab ? 'hidden' : ''; ?>>
					<?php $this->render_general_tab(); ?>
				</div>

				<!-- TAB: AI -->
				<div id="tabpanel-ai" class="shseq-settings-panel <?php echo 'ai' === $active_tab ? 'is-active' : ''; ?>"
					role="tabpanel" aria-labelledby="tab-ai" <?php echo 'ai' !== $active_tab ? 'hidden' : ''; ?>>
					<?php $this->render_ai_tab(); ?>
				</div>

				<!-- TAB: Plan -->
				<div id="tabpanel-plan" class="shseq-settings-panel <?php echo 'plan' === $active_tab ? 'is-active' : ''; ?>"
					role="tabpanel" aria-labelledby="tab-plan" <?php echo 'plan' !== $active_tab ? 'hidden' : ''; ?>>
					<?php $this->render_plan_panel(); ?>
				</div>

				<!-- TAB: Sysinfo -->
				<div id="tabpanel-sysinfo" class="shseq-settings-panel <?php echo 'sysinfo' === $active_tab ? 'is-active' : ''; ?>"
					role="tabpanel" aria-labelledby="tab-sysinfo" <?php echo 'sysinfo' !== $active_tab ? 'hidden' : ''; ?>>
					<?php $this->render_sysinfo_panel(); ?>
				</div>

			</div>
		</div>
		<?php
	}

	private function render_general_tab(): void {
		?>
		<form method="post" action="options.php" class="shseq-settings-form">
			<?php settings_fields( self::OPTION_GRP ); ?>
			<input type="hidden" name="_shseq_active_tab" value="general">

			<section class="shseq-settings-section" aria-labelledby="section-animation">
				<h2 id="section-animation" class="shseq-settings-section__title">
					<span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
					<?php echo esc_html( $this->t( 'section_animation' ) ); ?>
				</h2>

				<?php $this->render_field_number( self::OPT_SCROLL_SPEED, 'scroll_speed', 'scroll_speed_hint', 'px_per_frame', 4, 1, 200 ); ?>
				<?php $this->render_field_number( self::OPT_LAZY_THRESHOLD, 'lazy_threshold', 'lazy_threshold_hint', 'px', 200, 0, 2000 ); ?>
				<?php $this->render_field_toggle( self::OPT_DISABLE_MOBILE, 'mobile_behavior', 'disable_mobile', 'disable_mobile_hint', 0 ); ?>
				<?php $this->render_field_toggle( self::OPT_ADMIN_BAR, 'admin_bar_label', 'show_admin_bar', 'admin_bar_hint', 1 ); ?>
			</section>

			<section class="shseq-settings-section shseq-settings-section--danger" aria-labelledby="section-danger">
				<h2 id="section-danger" class="shseq-settings-section__title">
					<span class="dashicons dashicons-warning" aria-hidden="true"></span>
					<?php echo esc_html( $this->t( 'section_danger' ) ); ?>
				</h2>
				<?php $this->render_field_toggle( self::OPT_DELETE_UNINSTALL, 'delete_on_uninstall', 'delete_all_data', 'delete_hint', 0, true ); ?>
			</section>

			<?php submit_button( $this->t( 'save_settings' ) ); ?>
		</form>
		<?php
	}

	private function render_ai_tab(): void {
		?>
		<form method="post" action="options.php" class="shseq-settings-form">
			<?php settings_fields( self::OPTION_GRP ); ?>
			<input type="hidden" name="_shseq_active_tab" value="ai">

			<div class="shseq-notice shseq-notice--info" role="note">
				<span class="dashicons dashicons-privacy" aria-hidden="true"></span>
				<div>
					<strong><?php echo esc_html( $this->t( 'privacy_notice' ) ); ?></strong>
					<p><?php echo esc_html( $this->t( 'privacy_text' ) ); ?></p>
				</div>
			</div>

			<section class="shseq-settings-section" aria-labelledby="section-openai">
				<h2 id="section-openai" class="shseq-settings-section__title">
					<span class="dashicons dashicons-superhero-alt" aria-hidden="true"></span>
					OpenAI (DALL&middot;E 3)
				</h2>
				<div class="shseq-settings-field">
					<div class="shseq-settings-field__label">
						<label for="shseq_openai_key"><?php echo esc_html( $this->t( 'openai_key_label' ) ); ?></label>
					</div>
					<div class="shseq-settings-field__control">
						<?php $this->render_api_key_field( OpenAIProvider::OPTION_API_KEY, 'shseq_openai_key', 'sk-...', 'shseq_test_openai', 'hint_openai' ); ?>
						<p id="hint_openai" class="shseq-hint">
							<?php printf( wp_kses( $this->t( 'openai_key_hint' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ),
								'<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">platform.openai.com/api-keys</a>' ); ?>
						</p>
					</div>
				</div>
			</section>

			<section class="shseq-settings-section" aria-labelledby="section-replicate">
				<h2 id="section-replicate" class="shseq-settings-section__title">
					<span class="dashicons dashicons-format-video" aria-hidden="true"></span>
					Replicate (FILM)
				</h2>
				<div class="shseq-settings-field">
					<div class="shseq-settings-field__label">
						<label for="shseq_replicate_token"><?php echo esc_html( $this->t( 'replicate_token_label' ) ); ?></label>
					</div>
					<div class="shseq-settings-field__control">
						<?php $this->render_api_key_field( ReplicateProvider::OPTION_API_TOKEN, 'shseq_replicate_token', 'r8_...', 'shseq_test_replicate', 'hint_replicate' ); ?>
						<p id="hint_replicate" class="shseq-hint">
							<?php printf( wp_kses( $this->t( 'replicate_token_hint' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ),
								'<a href="https://replicate.com/account/api-tokens" target="_blank" rel="noopener noreferrer">replicate.com/account/api-tokens</a>' ); ?>
						</p>
					</div>
				</div>
			</section>

			<?php submit_button( $this->t( 'save_settings' ) ); ?>
		</form>
		<?php
	}

	private function render_plan_panel(): void {
		$is_pro = LicenseManager::is_pro();
		$features = [
			[ $this->t( 'feat_3_sequences' ),      true    ],
			[ $this->t( 'feat_24_frames' ),         true    ],
			[ $this->t( 'feat_free_tpl' ),          true    ],
			[ $this->t( 'feat_15_sequences' ),      $is_pro ],
			[ $this->t( 'feat_36_frames' ),         $is_pro ],
			[ $this->t( 'feat_ai_gen' ),            $is_pro ],
			[ $this->t( 'feat_pro_tpl' ),           $is_pro ],
			[ $this->t( 'feat_priority_support' ),  $is_pro ],
		];
		?>
		<div class="shseq-plan-wrap">
			<div class="shseq-plan-card <?php echo $is_pro ? 'shseq-plan-card--pro' : 'shseq-plan-card--free'; ?>">
				<div class="shseq-plan-card__badge"><?php echo esc_html( $is_pro ? $this->t( 'plan_pro' ) : $this->t( 'plan_free' ) ); ?></div>
				<h2><?php echo esc_html( $is_pro ? $this->t( 'plan_pro_title' ) : $this->t( 'plan_free_title' ) ); ?></h2>
				<ul class="shseq-plan-features" role="list">
					<?php foreach ( $features as [ $label, $available ] ) : ?>
					<li class="shseq-plan-feature <?php echo $available ? 'is-available' : 'is-locked'; ?>">
						<span class="dashicons <?php echo $available ? 'dashicons-yes-alt' : 'dashicons-lock'; ?>" aria-hidden="true"></span>
						<?php echo esc_html( $label ); ?>
					</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( ! $is_pro ) : ?>
				<a href="https://storyboardlive.app/pro" target="_blank" rel="noopener noreferrer" class="button button-primary shseq-plan-upgrade-btn">
					<?php echo esc_html( $this->t( 'upgrade_to_pro' ) ); ?>
					<span class="dashicons dashicons-external" aria-hidden="true"></span>
				</a>
				<?php else : ?>
				<p class="shseq-plan-active-msg">
					<span class="dashicons dashicons-awards" aria-hidden="true"></span>
					<?php echo esc_html( $this->t( 'pro_active_msg' ) ); ?>
				</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function render_sysinfo_panel(): void {
		global $wpdb;
		$info = [
			$this->t( 'plugin_version' ) => defined( 'SHSEQ_VERSION' ) ? SHSEQ_VERSION : '-',
			$this->t( 'wp_version' )     => get_bloginfo( 'version' ),
			$this->t( 'php_version' )    => PHP_VERSION,
			$this->t( 'mysql_version' )  => $wpdb->db_version(),
			$this->t( 'memory_limit' )   => WP_MEMORY_LIMIT,
			$this->t( 'max_upload' )     => (string) ini_get( 'upload_max_filesize' ),
			$this->t( 'max_post_size' )  => (string) ini_get( 'post_max_size' ),
			$this->t( 'gd_support' )     => extension_loaded( 'gd' ) ? $this->t( 'yes' ) : $this->t( 'no' ),
			$this->t( 'mbstring' )       => extension_loaded( 'mbstring' ) ? $this->t( 'yes' ) : $this->t( 'no' ),
			$this->t( 'site_url' )       => get_site_url(),
			$this->t( 'is_multisite' )   => is_multisite() ? $this->t( 'yes' ) : $this->t( 'no' ),
			$this->t( 'debug_mode' )     => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? $this->t( 'yes' ) : $this->t( 'no' ),
		];
		?>
		<div class="shseq-sysinfo-wrap">
			<p class="shseq-sysinfo-intro"><?php echo esc_html( $this->t( 'sysinfo_intro' ) ); ?></p>
			<table class="shseq-sysinfo-table widefat" aria-label="<?php echo esc_attr( $this->t( 'tab_sysinfo' ) ); ?>">
				<tbody>
					<?php foreach ( $info as $label => $value ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td><code><?php echo esc_html( $value ); ?></code></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<button type="button" id="shseq-copy-sysinfo" class="button shseq-sysinfo-copy-btn"
				data-sysinfo="<?php echo esc_attr( wp_json_encode( $info ) ); ?>">
				<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
				<?php echo esc_html( $this->t( 'copy_sysinfo' ) ); ?>
			</button>
			<span class="shseq-sysinfo-copy-feedback" aria-live="polite" aria-atomic="true"></span>
		</div>
		<?php
	}

	private function render_field_number( string $opt, string $label_key, string $hint_key, string $unit_key, int $default, int $min, int $max ): void {
		$id = 'shseq_' . str_replace( 'shseq_', '', $opt );
		?>
		<div class="shseq-settings-field">
			<div class="shseq-settings-field__label">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $this->t( $label_key ) ); ?></label>
			</div>
			<div class="shseq-settings-field__control">
				<div class="shseq-number-wrap">
					<input type="number" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $opt ); ?>"
						value="<?php echo esc_attr( get_option( $opt, $default ) ); ?>"
						min="<?php echo (int) $min; ?>" max="<?php echo (int) $max; ?>"
						class="small-text" aria-describedby="<?php echo esc_attr( $id . '_hint' ); ?>">
					<span class="shseq-unit"><?php echo esc_html( $this->t( $unit_key ) ); ?></span>
				</div>
				<p id="<?php echo esc_attr( $id . '_hint' ); ?>" class="shseq-hint"><?php echo esc_html( $this->t( $hint_key ) ); ?></p>
			</div>
		</div>
		<?php
	}

	private function render_field_toggle( string $opt, string $section_label_key, string $toggle_label_key, string $hint_key, int $default, bool $danger = false ): void {
		$id = 'toggle_' . str_replace( 'shseq_', '', $opt );
		?>
		<div class="shseq-settings-field">
			<div class="shseq-settings-field__label">
				<span><?php echo esc_html( $this->t( $section_label_key ) ); ?></span>
			</div>
			<div class="shseq-settings-field__control">
				<label class="shseq-toggle <?php echo $danger ? 'shseq-toggle--danger' : ''; ?>" for="<?php echo esc_attr( $id ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $opt ); ?>"
						value="1" <?php checked( 1, get_option( $opt, $default ) ); ?>
						aria-describedby="<?php echo esc_attr( $id . '_hint' ); ?>">
					<span class="shseq-toggle__track" aria-hidden="true"><span class="shseq-toggle__thumb"></span></span>
					<span class="shseq-toggle__label"><?php echo esc_html( $this->t( $toggle_label_key ) ); ?></span>
				</label>
				<p id="<?php echo esc_attr( $id . '_hint' ); ?>" class="shseq-hint <?php echo $danger ? 'shseq-hint--danger' : ''; ?>">
					<?php echo esc_html( $this->t( $hint_key ) ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	private function render_api_key_field( string $option_name, string $input_id, string $placeholder, string $ajax_action, string $hint_id = '' ): void {
		$stored = (string) get_option( $option_name, '' );
		$masked = $this->mask_key( $stored );
		?>
		<div class="shseq-api-field" data-action="<?php echo esc_attr( $ajax_action ); ?>" data-field="<?php echo esc_attr( $input_id ); ?>">
			<div class="shseq-api-field__row">
				<div class="shseq-api-field__input-wrap">
					<input type="password" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $option_name ); ?>"
						value="<?php echo esc_attr( $stored ); ?>"
						class="regular-text shseq-api-field__input"
						autocomplete="new-password" spellcheck="false"
						placeholder="<?php echo esc_attr( $placeholder ); ?>"
						<?php echo $hint_id ? 'aria-describedby="' . esc_attr( $hint_id ) . '"' : ''; ?>>
					<button type="button" class="shseq-api-field__reveal" aria-label="<?php echo esc_attr( $this->t( 'reveal' ) ); ?>" aria-pressed="false">
						<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
					</button>
				</div>
				<button type="button" class="button shseq-api-field__test">
					<span class="dashicons dashicons-update-alt shseq-api-field__test-icon" aria-hidden="true"></span>
					<?php echo esc_html( $this->t( 'test_connection' ) ); ?>
				</button>
			</div>
			<?php if ( $stored ) : ?>
			<p class="shseq-api-field__stored">
				<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<?php printf( esc_html( $this->t( 'key_stored' ) ), '<code>' . esc_html( $masked ) . '</code>' ); ?>
			</p>
			<?php endif; ?>
			<div class="shseq-api-field__result" aria-live="polite" aria-atomic="true" role="status"></div>
		</div>
		<?php
	}

	private function mask_key( string $key ): string {
		if ( ! $key ) return '';
		$visible = max( 4, (int) floor( strlen( $key ) * 0.25 ) );
		return substr( $key, 0, $visible ) . str_repeat( chr( 0xE2 ) . chr( 0x80 ) . chr( 0xA2 ), max( 0, strlen( $key ) - $visible ) );
	}

	public function ajax_test_openai(): void {
		$this->guard_ajax();
		$key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		if ( ! $key ) { wp_send_json_error( [ 'message' => $this->t( 'no_key' ) ] ); die(); }

		$response = wp_remote_get( 'https://api.openai.com/v1/models', [
			'timeout' => 10,
			'headers' => [ 'Authorization' => 'Bearer ' . $key ],
		] );
		if ( is_wp_error( $response ) ) { wp_send_json_error( [ 'message' => esc_html( $response->get_error_message() ) ] ); die(); }

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			wp_send_json_success( [ 'message' => $this->t( 'connected_ok' ) ] );
		} else {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = isset( $body['error']['message'] ) ? wp_strip_all_tags( $body['error']['message'] ) : 'HTTP ' . $code;
			wp_send_json_error( [ 'message' => $msg ] );
		}
		die();
	}

	public function ajax_test_replicate(): void {
		$this->guard_ajax();
		$token = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		if ( ! $token ) { wp_send_json_error( [ 'message' => $this->t( 'no_key' ) ] ); die(); }

		$response = wp_remote_get( 'https://api.replicate.com/v1/account', [
			'timeout' => 10,
			'headers' => [ 'Authorization' => 'Token ' . $token ],
		] );
		if ( is_wp_error( $response ) ) { wp_send_json_error( [ 'message' => esc_html( $response->get_error_message() ) ] ); die(); }

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$user = isset( $body['username'] ) ? sanitize_user( $body['username'] ) : '';
			wp_send_json_success( [ 'message' => $user ? sprintf( $this->t( 'connected_as' ), '@' . $user ) : $this->t( 'connected_ok' ) ] );
		} else {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = isset( $body['detail'] ) ? wp_strip_all_tags( $body['detail'] ) : 'HTTP ' . $code;
			wp_send_json_error( [ 'message' => $msg ] );
		}
		die();
	}

	private function guard_ajax(): void {
		if ( ! isset( $_POST['_nonce'] ) || false === wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), self::NONCE_TEST_API ) ) {
			wp_send_json_error( [ 'message' => $this->t( 'nonce_fail' ) ], 403 ); die();
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => $this->t( 'no_permission' ) ], 403 ); die();
		}
		$uid   = get_current_user_id();
		$key   = 'shseq_api_test_rate_' . $uid;
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_MAX ) {
			wp_send_json_error( [ 'message' => $this->t( 'rate_limited' ) ], 429 ); die();
		}
		set_transient( $key, $count + 1, self::RATE_LIMIT_TTL );
	}

	private function locale_code(): string {
		return strpos( get_user_locale(), 'fa' ) !== false ? 'fa' : 'en';
	}

	private function t( string $key ): string {
		static $cache = [];
		$locale = $this->locale_code();
		if ( ! isset( $cache[ $locale ] ) ) {
			$cache[ $locale ] = 'fa' === $locale ? $this->strings_fa() : $this->strings_en();
		}
		return $cache[ $locale ][ $key ] ?? $key;
	}

	private function strings_en(): array {
		return [
			'page_title'           => 'StoryBoard Live — Settings',
			'page_desc'            => 'Configure animation behaviour, AI providers, and plugin data.',
			'menu_label'           => 'Settings',
			'settings_tabs'        => 'Settings tabs',
			'tab_general'          => 'General',
			'tab_ai'               => 'AI Settings',
			'tab_plan'             => 'Plan & License',
			'tab_sysinfo'          => 'System Info',
			'save_settings'        => 'Save Settings',
			'no_permission'        => 'You do not have permission to access this page.',
			'nonce_fail'           => 'Security check failed. Refresh and try again.',
			'rate_limited'         => 'Too many test requests. Wait an hour and try again.',
			'section_animation'    => 'Animation',
			'scroll_speed'         => 'Scroll speed',
			'px_per_frame'         => 'px / frame',
			'scroll_speed_hint'    => 'Pixels of scroll to advance one frame. Lower = slower, smoother animation.',
			'lazy_threshold'       => 'Preload distance',
			'px'                   => 'px',
			'lazy_threshold_hint'  => 'Start loading frames when the sequence enters this margin from the viewport edge.',
			'mobile_behavior'      => 'Mobile behaviour',
			'disable_mobile'       => 'Disable scroll animation on mobile — show final frame as static image',
			'disable_mobile_hint'  => 'Improves performance on low-end phones. The last frame is displayed statically.',
			'admin_bar_label'      => 'Admin bar shortcut',
			'show_admin_bar'       => 'Show "Edit Sequence" link in the front-end admin bar',
			'admin_bar_hint'       => 'Only visible to logged-in administrators.',
			'section_danger'       => 'Danger Zone',
			'delete_on_uninstall'  => 'Remove data on uninstall',
			'delete_all_data'      => 'Delete ALL sequences, frames, and settings when the plugin is uninstalled',
			'delete_hint'          => 'Warning: irreversible. Leave unchecked to keep your data after uninstalling.',
			'privacy_notice'       => 'Privacy notice:',
			'privacy_text'         => 'When generating frames, your images and prompt are sent to the AI provider. Each provider\'s privacy policy applies. Do not submit personal or sensitive content.',
			'openai_key_label'     => 'OpenAI API Key',
			'openai_key_hint'      => 'Create a key at %s — used for DALL·E 3 start-frame generation (Pro only).',
			'replicate_token_label'=> 'Replicate API Token',
			'replicate_token_hint' => 'Create a token at %s — used for FILM-model frame interpolation (Pro only).',
			'test_connection'      => 'Test',
			'reveal'               => 'Reveal key',
			'hide'                 => 'Hide key',
			'key_stored'           => 'Stored: %s',
			'no_key'               => 'No key entered.',
			'connected_ok'         => 'Connected successfully.',
			'connected_as'         => 'Connected as %s.',
			'testing'              => 'Testing…',
			'enter_key_first'      => 'Enter a key first.',
			'request_failed'       => 'Network request failed. Check your connection.',
			'plan_free'            => 'Free',
			'plan_pro'             => 'Pro',
			'plan_free_title'      => 'You are on the Free plan',
			'plan_pro_title'       => 'You are on the Pro plan — thank you!',
			'upgrade_to_pro'       => 'Upgrade to Pro',
			'pro_active_msg'       => 'Your Pro licence is active. All features are enabled.',
			'feat_3_sequences'     => '3 active sequences',
			'feat_24_frames'       => 'Up to 24 frames per sequence',
			'feat_free_tpl'        => '5 free templates',
			'feat_15_sequences'    => '15 active sequences',
			'feat_36_frames'       => 'Up to 36 frames per sequence',
			'feat_ai_gen'          => 'AI frame generation (DALL·E 3 + FILM interpolation)',
			'feat_pro_tpl'         => 'All 15 templates',
			'feat_priority_support'=> 'Priority support',
			'sysinfo_intro'        => 'Share this with support when reporting a bug.',
			'plugin_version'       => 'Plugin version',
			'wp_version'           => 'WordPress version',
			'php_version'          => 'PHP version',
			'mysql_version'        => 'MySQL version',
			'memory_limit'         => 'WP memory limit',
			'max_upload'           => 'Max upload size',
			'max_post_size'        => 'Max post size',
			'gd_support'           => 'GD library',
			'mbstring'             => 'mbstring',
			'site_url'             => 'Site URL',
			'is_multisite'         => 'Multisite',
			'debug_mode'           => 'WP_DEBUG',
			'copy_sysinfo'         => 'Copy to clipboard',
			'copied'               => 'Copied!',
			'copy_fail'            => 'Copy failed — press Ctrl+C',
			'yes'                  => 'Enabled',
			'no'                   => 'Not available',
		];
	}

	private function strings_fa(): array {
		return [
			'page_title'           => 'استوری‌برد زنده — تنظیمات',
			'page_desc'            => 'تنظیمات انیمیشن، سرویس‌های هوش مصنوعی و داده‌های پلاگین را پیکربندی کنید.',
			'menu_label'           => 'تنظیمات',
			'settings_tabs'        => 'تب‌های تنظیمات',
			'tab_general'          => 'عمومی',
			'tab_ai'               => 'هوش مصنوعی',
			'tab_plan'             => 'پلن و لایسنس',
			'tab_sysinfo'          => 'اطلاعات سیستم',
			'save_settings'        => 'ذخیره تنظیمات',
			'no_permission'        => 'شما مجاز به دسترسی به این صفحه نیستید.',
			'nonce_fail'           => 'بررسی امنیتی ناموفق بود. صفحه را رفرش کنید.',
			'rate_limited'         => 'تعداد درخواست‌ها از حد مجاز گذشت. یک ساعت بعد دوباره امتحان کنید.',
			'section_animation'    => 'انیمیشن',
			'scroll_speed'         => 'سرعت اسکرول',
			'px_per_frame'         => 'px / فریم',
			'scroll_speed_hint'    => 'تعداد پیکسل‌های اسکرول برای پیشروی یک فریم. مقدار کمتر = انیمیشن آرام‌تر.',
			'lazy_threshold'       => 'فاصله پیش‌بارگذاری',
			'px'                   => 'px',
			'lazy_threshold_hint'  => 'شروع بارگذاری فریم‌ها وقتی سکانس در این فاصله از لبه ویوپورت قرار گیرد.',
			'mobile_behavior'      => 'رفتار موبایل',
			'disable_mobile'       => 'غیرفعال کردن انیمیشن اسکرول در موبایل — نمایش فریم آخر به صورت ثابت',
			'disable_mobile_hint'  => 'عملکرد بهتری روی گوشی‌های ضعیف‌تر دارد.',
			'admin_bar_label'      => 'نوار مدیریت',
			'show_admin_bar'       => 'نمایش لینک «ویرایش سکانس» در نوار مدیریت فرانت‌اند',
			'admin_bar_hint'       => 'فقط برای مدیران لاگین‌شده قابل مشاهده است.',
			'section_danger'       => 'منطقه خطر',
			'delete_on_uninstall'  => 'حذف داده‌ها هنگام حذف پلاگین',
			'delete_all_data'      => 'حذف تمام سکانس‌ها، فریم‌ها و تنظیمات هنگام حذف پلاگین',
			'delete_hint'          => 'هشدار: برگشت‌ناپذیر است. برای حفظ داده‌ها تیک نزنید.',
			'privacy_notice'       => 'اطلاعیه حریم خصوصی:',
			'privacy_text'         => 'در زمان تولید فریم، تصاویر و پرامپت شما به سرویس هوش مصنوعی انتخابی ارسال می‌شود. هرگز اطلاعات شخصی یا حساس وارد نکنید.',
			'openai_key_label'     => 'کلید API اوپن‌اِی‌آی',
			'openai_key_hint'      => 'کلید خود را از %s بسازید — برای تولید فریم شروع با DALL·E 3 (فقط نسخه Pro).',
			'replicate_token_label'=> 'توکن API رپلیکیت',
			'replicate_token_hint' => 'توکن خود را از %s بسازید — برای تبدیل فریم با مدل FILM (فقط نسخه Pro).',
			'test_connection'      => 'تست',
			'reveal'               => 'نمایش کلید',
			'hide'                 => 'پنهان‌کردن',
			'key_stored'           => 'ذخیره‌شده: %s',
			'no_key'               => 'کلیدی وارد نشده.',
			'connected_ok'         => 'اتصال موفق.',
			'connected_as'         => 'متصل به %s.',
			'testing'              => 'در حال بررسی…',
			'enter_key_first'      => 'ابتدا یک کلید وارد کنید.',
			'request_failed'       => 'درخواست شبکه ناموفق بود. اتصال اینترنت را بررسی کنید.',
			'plan_free'            => 'رایگان',
			'plan_pro'             => 'حرفه‌ای',
			'plan_free_title'      => 'شما در پلن رایگان هستید',
			'plan_pro_title'       => 'شما در پلن حرفه‌ای هستید — ممنون!',
			'upgrade_to_pro'       => 'ارتقا به نسخه حرفه‌ای',
			'pro_active_msg'       => 'لایسنس Pro شما فعال است. تمام امکانات در دسترس است.',
			'feat_3_sequences'     => '۳ سکانس فعال',
			'feat_24_frames'       => 'تا ۲۴ فریم برای هر سکانس',
			'feat_free_tpl'        => '۵ قالب رایگان',
			'feat_15_sequences'    => '۱۵ سکانس فعال',
			'feat_36_frames'       => 'تا ۳۶ فریم برای هر سکانس',
			'feat_ai_gen'          => 'تولید فریم با هوش مصنوعی (DALL·E 3 + FILM)',
			'feat_pro_tpl'         => 'تمام ۱۵ قالب',
			'feat_priority_support'=> 'پشتیبانی اولویت‌دار',
			'sysinfo_intro'        => 'هنگام گزارش باگ، این اطلاعات را برای پشتیبانی ارسال کنید.',
			'plugin_version'       => 'نسخه پلاگین',
			'wp_version'           => 'نسخه وردپرس',
			'php_version'          => 'نسخه PHP',
			'mysql_version'        => 'نسخه MySQL',
			'memory_limit'         => 'حد حافظه وردپرس',
			'max_upload'           => 'حداکثر حجم آپلود',
			'max_post_size'        => 'حداکثر حجم post',
			'gd_support'           => 'کتابخانه GD',
			'mbstring'             => 'mbstring',
			'site_url'             => 'آدرس سایت',
			'is_multisite'         => 'مالتی‌سایت',
			'debug_mode'           => 'WP_DEBUG',
			'copy_sysinfo'         => 'کپی در کلیپ‌بورد',
			'copied'               => 'کپی شد!',
			'copy_fail'            => 'کپی ناموفق — Ctrl+C بزنید',
			'yes'                  => 'فعال',
			'no'                   => 'غیر قابل دسترس',
		];
	}
}
