<?php
/**
 * Runtime frontend assets.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Frontend;

/**
 * Registers and conditionally loads the runtime foundation.
 *
 * M6.5 keeps the heavy Canvas runtime out of the initial navigation. A tiny
 * inline bootstrap requests it only when the visitor shows intent to interact
 * with the story. The entry poster and semantic HTML remain available before
 * that enhancement happens.
 */
final class RuntimeAssets {

	/** Runtime core script/style handle. */
	const HANDLE = 'shseq-runtime-core';

	/** CSS body class applied only to shortcode-driven runtime pages. */
	const BODY_CLASS = 'shseq-has-runtime';

	/** @var RuntimeManifest */
	private $manifest;

	/** @var bool */
	private $inline_style_added = false;

	/** @var bool */
	private $runtime_enqueued = false;

	/** @var bool */
	private $preflight_printed = false;

	/**
	 * Request-local result cache for page detection.
	 *
	 * @var bool|null
	 */
	private $runtime_presence = null;

	/**
	 * @param RuntimeManifest $manifest Runtime manifest service.
	 */
	public function __construct( RuntimeManifest $manifest ) {
		$this->manifest = $manifest;
	}

	/** Register hooks. */
	public function register_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_for_current_post' ), 20 );
		add_action( 'wp_head', array( $this, 'print_preflight_script' ), 0 );
		add_action( 'wp_head', array( $this, 'print_preload_hints' ), 1 );
		add_action( 'wp_footer', array( $this, 'print_bootstrap_script' ), 1 );
		add_filter( 'body_class', array( $this, 'filter_body_classes' ) );
	}

	/**
	 * Register assets without loading the heavy runtime globally.
	 */
	public function register_assets() {
		wp_register_style(
			self::HANDLE,
			false,
			array(),
			SHSEQ_VERSION
		);

		wp_register_script(
			self::HANDLE,
			SHSEQ_URL . 'assets/frontend/runtime-core.min.js',
			array(),
			SHSEQ_VERSION,
			true
		);
	}

	/** Load lightweight assets only on StoryBoard pages. */
	public function maybe_enqueue_for_current_post() {
		if ( ! $this->current_post_has_runtime_shortcode() ) {
			return;
		}

		$this->enqueue();
	}

	/**
	 * Print the tiny JS capability/header preflight before normal styles.
	 *
	 * This replaces the broken external preflight path from M6.4. WordPress is
	 * already inside the wp_head action when wp_enqueue_scripts fires, so a
	 * did_action( 'wp_head' ) guard could never guarantee an early head script.
	 */
	public function print_preflight_script() {
		if ( $this->preflight_printed || ! $this->current_post_has_runtime_shortcode() ) {
			return;
		}

		$file = SHSEQ_DIR . 'assets/frontend/theme-header-preflight.min.js';
		if ( ! is_readable( $file ) ) {
			$file = SHSEQ_DIR . 'assets/frontend/theme-header-preflight.js';
		}
		if ( ! is_readable( $file ) ) {
			return;
		}

		$script = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $script || '' === trim( $script ) ) {
			return;
		}

		if ( function_exists( 'wp_print_inline_script_tag' ) ) {
			wp_print_inline_script_tag( $script, array( 'id' => 'storyboard-live-preflight' ) );
		} else {
			echo '<script id="storyboard-live-preflight">' . $script . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static plugin-owned JavaScript.
		}

		$this->preflight_printed = true;
	}

	/**
	 * Print media-aware poster preloads before ordinary stylesheet/script work.
	 */
	public function print_preload_hints() {
		if ( ! $this->current_post_has_runtime_shortcode() ) {
			return;
		}

		foreach ( $this->manifest->get_demo_preload_candidates() as $candidate ) {
			printf(
				'<link rel="preload" as="image" href="%1$s" type="image/webp" fetchpriority="high" media="%2$s">' . "\n",
				esc_url( $candidate['url'] ),
				esc_attr( $candidate['media'] )
			);
		}
	}

	/**
	 * Add a page-scoped class used by runtime CSS and header preflight.
	 *
	 * @param string[] $classes Existing body classes.
	 * @return string[]
	 */
	public function filter_body_classes( $classes ) {
		if ( $this->current_post_has_runtime_shortcode() ) {
			$classes[] = self::BODY_CLASS;
		}

		return array_values( array_unique( $classes ) );
	}

	/**
	 * Enqueue the lightweight foundation. The heavy core is requested by the
	 * inline bootstrap after wheel/touch/keyboard/focus intent.
	 */
	public function enqueue() {
		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			$this->register_assets();
		}

		wp_enqueue_style( self::HANDLE );
		$this->add_inline_runtime_style();
		$this->runtime_enqueued = true;
	}

	/**
	 * Return the versioned core URL used by the intent bootstrap.
	 *
	 * @return string
	 */
	public function get_runtime_core_url() {
		return add_query_arg(
			'ver',
			rawurlencode( SHSEQ_VERSION ),
			SHSEQ_URL . 'assets/frontend/runtime-core.min.js'
		);
	}

	/** Attach the runtime stylesheet inline once. */
	private function add_inline_runtime_style() {
		if ( $this->inline_style_added ) {
			return;
		}

		$css_file = SHSEQ_DIR . 'assets/frontend/runtime-core.min.css';
		if ( ! is_readable( $css_file ) ) {
			$css_file = SHSEQ_DIR . 'assets/frontend/runtime-core.css';
		}
		if ( ! is_readable( $css_file ) ) {
			return;
		}

		$css = file_get_contents( $css_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $css || '' === trim( $css ) ) {
			return;
		}

		wp_add_inline_style( self::HANDLE, $css );
		$this->inline_style_added = true;
	}

	/**
	 * Print the tiny user-intent bootstrap in the footer.
	 *
	 * @return void
	 */
	public function print_bootstrap_script() {
		if ( ! $this->runtime_enqueued ) {
			return;
		}

		$file = SHSEQ_DIR . 'assets/frontend/runtime-bootstrap.min.js';
		if ( ! is_readable( $file ) ) {
			$file = SHSEQ_DIR . 'assets/frontend/runtime-bootstrap.js';
		}
		if ( ! is_readable( $file ) ) {
			return;
		}

		$script = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $script || '' === trim( $script ) ) {
			return;
		}

		if ( function_exists( 'wp_print_inline_script_tag' ) ) {
			wp_print_inline_script_tag( $script, array( 'id' => 'storyboard-live-bootstrap' ) );
		} else {
			echo '<script id="storyboard-live-bootstrap">' . $script . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static plugin-owned JavaScript.
		}
	}

	/**
	 * Check the current singular post for a supported runtime shortcode.
	 *
	 * The result is memoized because this check is used by wp_head,
	 * wp_enqueue_scripts and body_class during the same request.
	 *
	 * @return bool
	 */
	private function current_post_has_runtime_shortcode() {
		if ( null !== $this->runtime_presence ) {
			return $this->runtime_presence;
		}

		$this->runtime_presence = false;

		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();
		if ( ! $post ) {
			return false;
		}

		if ( $this->text_has_supported_shortcode( (string) $post->post_content ) ) {
			$this->runtime_presence = true;
			return true;
		}

		// Only touch Elementor metadata when the ordinary post content did not
		// contain a supported shortcode. A direct marker scan is enough for this
		// page-presence check and avoids running the shortcode-regex parser several
		// times during the critical HTML response.
		$elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
		if ( is_string( $elementor_data ) && $this->text_has_supported_shortcode( $elementor_data ) ) {
			$this->runtime_presence = true;
			return true;
		}

		return false;
	}

	/**
	 * Fast request-time marker scan used only to decide whether runtime assets
	 * belong on the current page. The shortcode renderer remains WordPress'
	 * normal shortcode parser.
	 *
	 * @param string $text Page or Elementor source text.
	 * @return bool
	 */
	private function text_has_supported_shortcode( $text ) {
		if ( '' === $text ) {
			return false;
		}

		foreach ( $this->supported_shortcodes() as $shortcode ) {
			if ( false !== strpos( $text, '[' . $shortcode ) ) {
				return true;
			}
		}

		return false;
	}

	/** @return string[] */
	private function supported_shortcodes() {
		return array(
			RuntimeShortcode::SHORTCODE,
			RuntimeShortcode::M6_SHORTCODE,
			RuntimeShortcode::M5_SHORTCODE,
			RuntimeShortcode::M4_SHORTCODE,
			RuntimeShortcode::M2_SHORTCODE,
			RuntimeShortcode::M1_SHORTCODE,
		);
	}
}
