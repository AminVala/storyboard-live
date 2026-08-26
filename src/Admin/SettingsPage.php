<?php
/**
 * Plugin settings page.
 *
 * Covers every option that was registered but had no UI:
 *   - shseq_delete_data_on_uninstall
 *   - Editor-role access to sequences
 *   - Default scroll length per variant
 *   - CDN/API base URL for frame assets
 *   - Version & license info panel
 *
 * Permissions: manage_shseq_settings  (granted to administrator on activation)
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

/**
 * Renders and saves the plugin Settings page.
 */
final class SettingsPage {

	const PAGE_SLUG   = 'shseq-settings';
	const OPTION_PAGE = 'shseq_settings';

	/** Registered settings fields. */
	const OPTION_DELETE_ON_UNINSTALL  = 'shseq_delete_data_on_uninstall';
	const OPTION_EDITOR_ACCESS        = 'shseq_editor_role_access';
	const OPTION_SCROLL_DESKTOP       = 'shseq_scroll_vh_desktop';
	const OPTION_SCROLL_TABLET        = 'shseq_scroll_vh_tablet';
	const OPTION_SCROLL_MOBILE        = 'shseq_scroll_vh_mobile';
	const OPTION_FRAMES_CDN           = 'shseq_frames_cdn_url';

	/** Register hooks. */
	public function register_hooks() {
		add_action( 'admin_menu',       array( $this, 'register_submenu' ) );
		add_action( 'admin_init',       array( $this, 'register_settings' ) );
		add_action( 'admin_post_shseq_save_settings', array( $this, 'save_settings' ) );
	}

	/** Register the Settings submenu under StoryBoard Live. */
	public function register_submenu() {
		add_submenu_page(
			AdminMenu::MENU_SLUG,
			__( 'StoryBoard Live Settings', 'sh-sequence-engine' ),
			__( 'Settings', 'sh-sequence-engine' ),
			'manage_shseq_settings',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/** Register options with WordPress. */
	public function register_settings() {
		register_setting( self::OPTION_PAGE, self::OPTION_DELETE_ON_UNINSTALL,
			array( 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' )
		);
		register_setting( self::OPTION_PAGE, self::OPTION_EDITOR_ACCESS,
			array( 'type' => 'boolean', 'default' => false, 'sanitize_callback' => 'rest_sanitize_boolean' )
		);
		register_setting( self::OPTION_PAGE, self::OPTION_SCROLL_DESKTOP,
			array( 'type' => 'integer', 'default' => 420, 'sanitize_callback' => array( $this, 'sanitize_scroll_vh' ) )
		);
		register_setting( self::OPTION_PAGE, self::OPTION_SCROLL_TABLET,
			array( 'type' => 'integer', 'default' => 360, 'sanitize_callback' => array( $this, 'sanitize_scroll_vh' ) )
		);
		register_setting( self::OPTION_PAGE, self::OPTION_SCROLL_MOBILE,
			array( 'type' => 'integer', 'default' => 320, 'sanitize_callback' => array( $this, 'sanitize_scroll_vh' ) )
		);
		register_setting( self::OPTION_PAGE, self::OPTION_FRAMES_CDN,
			array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'esc_url_raw' )
		);
	}

	/**
	 * Clamp scroll VH to a sane range (100–1000).
	 *
	 * @param mixed $val Raw value.
	 * @return int
	 */
	public function sanitize_scroll_vh( $val ) {
		$int = (int) $val;
		return max( 100, min( 1000, $int ) );
	}

	/** Render the settings page. */
	public function render() {
		if ( ! current_user_can( 'manage_shseq_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'sh-sequence-engine' ) );
		}

		$delete_on_uninstall = (bool) get_option( self::OPTION_DELETE_ON_UNINSTALL, false );
		$editor_access       = (bool) get_option( self::OPTION_EDITOR_ACCESS, false );
		$scroll_desktop      = (int)  get_option( self::OPTION_SCROLL_DESKTOP, 420 );
		$scroll_tablet       = (int)  get_option( self::OPTION_SCROLL_TABLET, 360 );
		$scroll_mobile       = (int)  get_option( self::OPTION_SCROLL_MOBILE, 320 );
		$frames_cdn          = (string) get_option( self::OPTION_FRAMES_CDN, '' );

		$saved = isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'];
		?>
		<div class="wrap shseq-admin">
			<header class="shseq-page-header">
				<div>
					<h1 class="shseq-page-header__title">
						<?php echo esc_html__( 'Settings', 'sh-sequence-engine' ); ?>
					</h1>
					<p class="shseq-page-header__subtitle">
						<?php echo esc_html__( 'Global configuration for StoryBoard Live.', 'sh-sequence-engine' ); ?>
					</p>
				</div>
				<span class="shseq-version-chip">v<?php echo esc_html( SHSEQ_VERSION ); ?></span>
			</header>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Settings saved.', 'sh-sequence-engine' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="shseq_save_settings">
				<?php wp_nonce_field( 'shseq_save_settings', '_shseq_settings_nonce' ); ?>

				<?php /* ── Access ──────────────────────────────────── */ ?>
				<div class="shseq-settings-section">
					<h2 class="shseq-settings-section__title">
						<?php echo esc_html__( 'Access', 'sh-sequence-engine' ); ?>
					</h2>

					<div class="shseq-settings-row">
						<label class="shseq-settings-row__toggle">
							<input
								type="checkbox"
								name="<?php echo esc_attr( self::OPTION_EDITOR_ACCESS ); ?>"
								value="1"
								<?php checked( $editor_access ); ?>
							>
							<span class="shseq-settings-row__toggle-track" aria-hidden="true"></span>
							<span class="shseq-settings-row__label">
								<?php echo esc_html__( 'Grant Editor role access to Sequences', 'sh-sequence-engine' ); ?>
							</span>
						</label>
						<p class="shseq-settings-row__desc">
							<?php echo esc_html__( 'By default only Administrators can create and edit Sequences. Enable this to let the Editor role manage Sequences as well. Changes take effect immediately after saving.', 'sh-sequence-engine' ); ?>
						</p>
					</div>
				</div>

				<?php /* ── Scroll defaults ──────────────────────────── */ ?>
				<div class="shseq-settings-section">
					<h2 class="shseq-settings-section__title">
						<?php echo esc_html__( 'Default scroll length (vh)', 'sh-sequence-engine' ); ?>
					</h2>
					<p class="shseq-settings-section__desc">
						<?php echo esc_html__( 'These values set the scroll-space height for new Sequences. Individual sequences can override per-variant values in their Story Structure.', 'sh-sequence-engine' ); ?>
					</p>

					<div class="shseq-settings-trio">
						<?php $this->scroll_field( self::OPTION_SCROLL_DESKTOP, __( 'Desktop', 'sh-sequence-engine' ), $scroll_desktop, '(≥ 1180px)' ); ?>
						<?php $this->scroll_field( self::OPTION_SCROLL_TABLET, __( 'Tablet', 'sh-sequence-engine' ), $scroll_tablet, '(768–1179px)' ); ?>
						<?php $this->scroll_field( self::OPTION_SCROLL_MOBILE, __( 'Mobile', 'sh-sequence-engine' ), $scroll_mobile, '(< 768px)' ); ?>
					</div>
				</div>

				<?php /* ── Frame assets CDN ─────────────────────────── */ ?>
				<div class="shseq-settings-section">
					<h2 class="shseq-settings-section__title">
						<?php echo esc_html__( 'Frame assets base URL', 'sh-sequence-engine' ); ?>
					</h2>
					<p class="shseq-settings-section__desc">
						<?php echo esc_html__( 'Leave empty to use the plugin\'s built-in asset path. Set a CDN URL (e.g. https://cdn.example.com/shseq) to serve demo frames from a fast external origin. Must not end with a slash.', 'sh-sequence-engine' ); ?>
					</p>

					<div class="shseq-settings-row">
						<input
							type="url"
							id="shseq-frames-cdn"
							name="<?php echo esc_attr( self::OPTION_FRAMES_CDN ); ?>"
							class="regular-text"
							value="<?php echo esc_attr( $frames_cdn ); ?>"
							placeholder="https://cdn.example.com/shseq"
						>
						<p class="description">
							<?php echo esc_html__( 'Only affects the demo shortcode frame paths. Golden Master images are always served from the WordPress media library.', 'sh-sequence-engine' ); ?>
						</p>
					</div>
				</div>

				<?php /* ── Data / uninstall ─────────────────────────── */ ?>
				<div class="shseq-settings-section shseq-settings-section--danger">
					<h2 class="shseq-settings-section__title">
						<?php echo esc_html__( 'Data & uninstall', 'sh-sequence-engine' ); ?>
					</h2>

					<div class="shseq-settings-row">
						<label class="shseq-settings-row__toggle">
							<input
								type="checkbox"
								name="<?php echo esc_attr( self::OPTION_DELETE_ON_UNINSTALL ); ?>"
								value="1"
								<?php checked( $delete_on_uninstall ); ?>
							>
							<span class="shseq-settings-row__toggle-track" aria-hidden="true"></span>
							<span class="shseq-settings-row__label">
								<?php echo esc_html__( 'Delete all Sequence data when the plugin is uninstalled', 'sh-sequence-engine' ); ?>
							</span>
						</label>
						<p class="shseq-settings-row__desc shseq-settings-row__desc--warning">
							<?php echo esc_html__( 'When enabled, uninstalling the plugin permanently removes all Sequences, their metadata, and plugin options. This cannot be undone. Leave disabled if you plan to reinstall later.', 'sh-sequence-engine' ); ?>
						</p>
					</div>
				</div>

				<div class="shseq-settings-actions">
					<?php submit_button( __( 'Save settings', 'sh-sequence-engine' ), 'primary', 'submit', false ); ?>
				</div>
			</form>

			<?php /* ── Version / license info ────────────────────── */ ?>
			<div class="shseq-settings-section shseq-settings-section--info">
				<h2 class="shseq-settings-section__title">
					<?php echo esc_html__( 'Version information', 'sh-sequence-engine' ); ?>
				</h2>
				<table class="shseq-info-table">
					<tr>
						<th><?php echo esc_html__( 'Plugin version', 'sh-sequence-engine' ); ?></th>
						<td><code><?php echo esc_html( SHSEQ_VERSION ); ?></code></td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Schema version', 'sh-sequence-engine' ); ?></th>
						<td><code><?php echo esc_html( (string) SHSEQ_SCHEMA_VERSION ); ?></code></td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'PHP version', 'sh-sequence-engine' ); ?></th>
						<td><code><?php echo esc_html( PHP_VERSION ); ?></code></td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'WordPress version', 'sh-sequence-engine' ); ?></th>
						<td><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'License', 'sh-sequence-engine' ); ?></th>
						<td>GPL-2.0-or-later</td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle the settings form save.
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_shseq_settings' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sh-sequence-engine' ) );
		}

		$nonce = isset( $_POST['_shseq_settings_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_shseq_settings_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'shseq_save_settings' ) ) {
			wp_die( esc_html__( 'Settings could not be verified.', 'sh-sequence-engine' ) );
		}

		/* Boolean options — absence of checkbox means false */
		update_option( self::OPTION_DELETE_ON_UNINSTALL, ! empty( $_POST[ self::OPTION_DELETE_ON_UNINSTALL ] ), false );

		$prev_editor_access = (bool) get_option( self::OPTION_EDITOR_ACCESS, false );
		$new_editor_access  = ! empty( $_POST[ self::OPTION_EDITOR_ACCESS ] );
		update_option( self::OPTION_EDITOR_ACCESS, $new_editor_access, false );

		/* Apply/revoke editor access */
		if ( $prev_editor_access !== $new_editor_access ) {
			$this->sync_editor_capabilities( $new_editor_access );
		}

		/* Numeric options */
		update_option( self::OPTION_SCROLL_DESKTOP, $this->sanitize_scroll_vh( isset( $_POST[ self::OPTION_SCROLL_DESKTOP ] ) ? $_POST[ self::OPTION_SCROLL_DESKTOP ] : 420 ) );
		update_option( self::OPTION_SCROLL_TABLET,  $this->sanitize_scroll_vh( isset( $_POST[ self::OPTION_SCROLL_TABLET ] ) ? $_POST[ self::OPTION_SCROLL_TABLET ] : 360 ) );
		update_option( self::OPTION_SCROLL_MOBILE,  $this->sanitize_scroll_vh( isset( $_POST[ self::OPTION_SCROLL_MOBILE ] ) ? $_POST[ self::OPTION_SCROLL_MOBILE ] : 320 ) );

		/* URL option */
		$cdn = isset( $_POST[ self::OPTION_FRAMES_CDN ] ) ? esc_url_raw( wp_unslash( $_POST[ self::OPTION_FRAMES_CDN ] ) ) : '';
		// Remove trailing slash for consistency.
		update_option( self::OPTION_FRAMES_CDN, rtrim( $cdn, '/' ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::PAGE_SLUG,
					'settings-updated' => 'true',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Add or remove Sequence capabilities from the Editor role.
	 *
	 * @param bool $grant True to grant, false to revoke.
	 */
	private function sync_editor_capabilities( $grant ) {
		$role = get_role( 'editor' );
		if ( ! $role ) {
			return;
		}

		$caps = array(
			'edit_shseq_sequences',
			'edit_others_shseq_sequences',
			'publish_shseq_sequences',
			'read_private_shseq_sequences',
			'delete_shseq_sequences',
			'delete_published_shseq_sequences',
			'edit_published_shseq_sequences',
			'create_shseq_sequences',
		);

		foreach ( $caps as $cap ) {
			if ( $grant ) {
				$role->add_cap( $cap );
			} else {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Render a scroll-VH number field.
	 *
	 * @param string $name    Option name.
	 * @param string $label   Human label.
	 * @param int    $value   Current value.
	 * @param string $hint    Small hint text.
	 */
	private function scroll_field( $name, $label, $value, $hint ) {
		?>
		<label class="shseq-settings-trio__field">
			<span class="shseq-settings-trio__label"><?php echo esc_html( $label ); ?></span>
			<small class="shseq-settings-trio__hint"><?php echo esc_html( $hint ); ?></small>
			<div class="shseq-settings-trio__input-wrap">
				<input
					type="number"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( (string) $value ); ?>"
					min="100"
					max="1000"
					step="10"
					class="small-text"
				>
				<span class="shseq-settings-trio__unit">vh</span>
			</div>
		</label>
		<?php
	}
}
