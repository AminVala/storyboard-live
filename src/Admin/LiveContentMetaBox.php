<?php
/**
 * Live HTML overlay content meta box.
 *
 * The Production Sheet requires every text/logo/CTA to be a live HTML overlay
 * (never baked into the image). This meta box lets the administrator enter that
 * overlay content. Each overlay's reveal frame comes from the Story Structure;
 * here we store only its safe HTML content, keyed by overlay key.
 *
 * @package StoryBoardLive
 */

namespace ShahreHonar\SequenceEngine\Admin;

use ShahreHonar\SequenceEngine\Content\SequencePostType;

/**
 * Stores brand-safe overlay content per overlay key.
 */
final class LiveContentMetaBox {

	const META_KEY = '_shseq_live_content';

	/** Register hooks. */
	public function register_hooks() {
		add_action( 'add_meta_boxes_' . SequencePostType::POST_TYPE, array( $this, 'register_meta_box' ) );
		add_action( 'save_post_' . SequencePostType::POST_TYPE, array( $this, 'save' ), 10, 2 );
	}

	/** Register meta box. */
	public function register_meta_box() {
		add_meta_box(
			'shseq-live-content',
			__( 'Live overlay content', 'sh-sequence-engine' ),
			array( $this, 'render' ),
			SequencePostType::POST_TYPE,
			'normal',
			'default'
		);
	}

	/**
	 * Read stored overlay content.
	 *
	 * @param int $post_id Sequence id.
	 * @return array<string,array<string,string>>
	 */
	public static function get_content( $post_id ) {
		$stored = get_post_meta( $post_id, self::META_KEY, true );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Overlay keys derived from the stored structure, in reveal order.
	 *
	 * @param int $post_id Sequence id.
	 * @return array<int,array<string,mixed>>
	 */
	private function overlay_slots( $post_id ) {
		$structure = get_post_meta( $post_id, SequenceStructureMetaBox::META_KEY, true );
		$overlays  = is_array( $structure ) && isset( $structure['overlays'] ) && is_array( $structure['overlays'] ) ? $structure['overlays'] : array();

		$slots = array();
		foreach ( $overlays as $overlay ) {
			if ( ! is_array( $overlay ) || empty( $overlay['key'] ) ) {
				continue;
			}
			$slots[] = array(
				'key'   => sanitize_key( $overlay['key'] ),
				'frame' => isset( $overlay['frame'] ) ? (int) $overlay['frame'] : 1,
			);
		}
		return $slots;
	}

	/** Render fields. */
	public function render( $post ) {
		$slots = $this->overlay_slots( $post->ID );
		if ( empty( $slots ) ) {
			echo '<p>' . esc_html__( 'This sequence has no overlay slots yet. Create one from Ready Templates or add overlays in Story Structure first.', 'sh-sequence-engine' ) . '</p>';
			return;
		}

		wp_nonce_field( 'shseq_save_live_content', '_shseq_live_content_nonce' );
		$content = self::get_content( $post->ID );
		?>
		<div class="shseq-live-content-editor">
			<p class="description"><?php echo esc_html__( 'Enter the live text, headings and call-to-action links. These appear as HTML overlays on top of the Golden Master at their reveal frame — never baked into the image.', 'sh-sequence-engine' ); ?></p>
			<?php foreach ( $slots as $slot ) :
				$key   = $slot['key'];
				$saved = isset( $content[ $key ] ) && is_array( $content[ $key ] ) ? $content[ $key ] : array();
				$tag   = isset( $saved['tag'] ) ? $saved['tag'] : $this->default_tag( $key );
				$text  = isset( $saved['text'] ) ? $saved['text'] : '';
				$href  = isset( $saved['href'] ) ? $saved['href'] : '';
				?>
				<fieldset class="shseq-live-content-slot">
					<legend>
						<code><?php echo esc_html( $key ); ?></code>
						<span class="shseq-live-content-frame"><?php echo esc_html( sprintf( /* translators: %d: frame number. */ __( 'reveal frame %d', 'sh-sequence-engine' ), $slot['frame'] ) ); ?></span>
					</legend>
					<label>
						<span><?php echo esc_html__( 'Element', 'sh-sequence-engine' ); ?></span>
						<select name="shseq_live_content[<?php echo esc_attr( $key ); ?>][tag]">
							<?php foreach ( $this->allowed_tags() as $option ) : ?>
								<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $tag, $option ); ?>><?php echo esc_html( $option ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<span><?php echo esc_html__( 'Text', 'sh-sequence-engine' ); ?></span>
						<input type="text" class="widefat" name="shseq_live_content[<?php echo esc_attr( $key ); ?>][text]" value="<?php echo esc_attr( $text ); ?>">
					</label>
					<label>
						<span><?php echo esc_html__( 'Link URL (optional)', 'sh-sequence-engine' ); ?></span>
						<input type="url" class="widefat" name="shseq_live_content[<?php echo esc_attr( $key ); ?>][href]" value="<?php echo esc_attr( $href ); ?>" placeholder="https://">
					</label>
				</fieldset>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Save overlay content safely.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['_shseq_live_content_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_shseq_live_content_nonce'] ) ), 'shseq_save_live_content' ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || SequencePostType::POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( ! isset( $_POST['shseq_live_content'] ) || ! is_array( $_POST['shseq_live_content'] ) ) {
			return;
		}

		$input   = wp_unslash( $_POST['shseq_live_content'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below.
		$allowed = $this->allowed_tags();
		$result  = array();

		foreach ( $input as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}
			$key = sanitize_key( $key );
			if ( '' === $key ) {
				continue;
			}
			$tag = isset( $value['tag'] ) ? sanitize_key( $value['tag'] ) : 'p';
			if ( ! in_array( $tag, $allowed, true ) ) {
				$tag = 'p';
			}
			$result[ $key ] = array(
				'tag'  => $tag,
				'text' => isset( $value['text'] ) ? sanitize_text_field( $value['text'] ) : '',
				'href' => isset( $value['href'] ) ? esc_url_raw( $value['href'] ) : '',
			);
		}

		update_post_meta( $post_id, self::META_KEY, $result );
	}

	/** @return string[] */
	private function allowed_tags() {
		return array( 'p', 'span', 'h1', 'h2', 'h3', 'a' );
	}

	/**
	 * Guess a sensible default tag from the overlay key.
	 *
	 * @param string $key Overlay key.
	 * @return string
	 */
	private function default_tag( $key ) {
		if ( false !== strpos( $key, 'title' ) ) {
			return 'h2';
		}
		if ( 'actions' === $key || false !== strpos( $key, 'cta' ) ) {
			return 'a';
		}
		return 'p';
	}
}
