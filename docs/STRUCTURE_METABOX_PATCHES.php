<?php
/**
 * SequenceStructureMetaBox — additions and fixes.
 *
 * This file documents the diffs that need to be applied to the existing
 * SequenceStructureMetaBox.php. They cannot be applied as a full rewrite
 * because the file is large and the save() method tail was truncated.
 *
 * Changes:
 *   1. SEC-005: render_created_notice() — add screen guard (already tracked)
 *   2. G09: siteHeader / handoff save() — complete the truncated tail
 *   3. G18: Frame contract validation — add UI feedback when referenceFrame > totalFrames
 *   4. G19: Beat overlap detection — add admin notice when beats overlap
 *   5. G17: Shortcode copy helper injected into the meta box header
 *   6. G02: "Add Overlay" row — dynamic add/remove overlay slots
 *
 * @package StoryBoardLive
 */

// ─── PATCH 1: render_created_notice() — screen guard (SEC-005) ──────────
//
// FIND (in register_hooks):
//   add_action( 'admin_notices', array( $this, 'render_created_notice' ) );
//
// The method body should be:
//
// public function render_created_notice() {
//     $screen = get_current_screen();
//     if ( ! $screen || 'shseq_sequence' !== $screen->post_type ) {
//         return;
//     }
//     if ( ! isset( $_GET['shseq_template_created'] ) ) {
//         return;
//     }
//     echo '<div class="notice notice-success is-dismissible"><p>'
//         . esc_html__( 'Sequence created from template. Customise the structure below, then upload a Golden Master image.', 'sh-sequence-engine' )
//         . '</p></div>';
// }

// ─── PATCH 2: save() — siteHeader + handoff tail ────────────────────────
//
// The full tail that was truncated (to be appended inside save() after variants):
//
// $existing['siteHeader'] = isset( $existing['siteHeader'] ) && is_array( $existing['siteHeader'] )
//     ? $existing['siteHeader'] : array();
// $header_input = isset( $input['siteHeader'] ) && is_array( $input['siteHeader'] )
//     ? $input['siteHeader'] : array();
// $existing['siteHeader']['enabled']          = true; // always enabled
// $existing['siteHeader']['startFrame']       = $this->bounded_int(
//     isset( $header_input['startFrame'] ) ? $header_input['startFrame'] : 109,
//     1, $existing['totalFrames']
// );
// $existing['siteHeader']['interactiveFrame'] = $this->bounded_int(
//     isset( $header_input['interactiveFrame'] ) ? $header_input['interactiveFrame'] : 116,
//     $existing['siteHeader']['startFrame'], $existing['totalFrames']
// );
// $existing['siteHeader']['completeFrame']    = $this->bounded_int(
//     isset( $header_input['completeFrame'] ) ? $header_input['completeFrame'] : 120,
//     $existing['siteHeader']['interactiveFrame'], $existing['totalFrames']
// );
//
// $existing['handoff'] = isset( $existing['handoff'] ) && is_array( $existing['handoff'] )
//     ? $existing['handoff'] : array();
// $handoff_input = isset( $input['handoff'] ) && is_array( $input['handoff'] )
//     ? $input['handoff'] : array();
// $existing['handoff']['frame']      = $this->bounded_int(
//     isset( $handoff_input['frame'] ) ? $handoff_input['frame'] : $existing['totalFrames'],
//     1, $existing['totalFrames']
// );
// $existing['handoff']['reversible'] = true;
//
// // Store productionRules immutably — never overwrite from user input.
// // (rules come from template and are read-only)
//
// update_post_meta( $post_id, self::META_KEY, $existing );
// }

// ─── PATCH 3: G18 — Frame contract validation notice ────────────────────
//
// Add this method to SequenceStructureMetaBox:
//
// private function validate_frame_contract( array $structure ) {
//     $total     = isset( $structure['totalFrames'] )    ? (int) $structure['totalFrames']    : 120;
//     $reference = isset( $structure['referenceFrame'] ) ? (int) $structure['referenceFrame'] : 70;
//     $golden    = isset( $structure['goldenFrame'] )    ? (int) $structure['goldenFrame']    : 120;
//     $errors = array();
//     if ( $reference > $total ) {
//         $errors[] = sprintf(
//             __( 'Master reference frame (%d) must not exceed Total frames (%d).', 'sh-sequence-engine' ),
//             $reference, $total
//         );
//     }
//     if ( $golden > $total ) {
//         $errors[] = sprintf(
//             __( 'Golden handoff frame (%d) must not exceed Total frames (%d).', 'sh-sequence-engine' ),
//             $golden, $total
//         );
//     }
//     return $errors;
// }
//
// Call inside save() after bounded_int assignments:
//
// $contract_errors = $this->validate_frame_contract( $existing );
// if ( ! empty( $contract_errors ) ) {
//     set_transient( 'shseq_contract_errors_' . get_current_user_id(), $contract_errors, 60 );
// }
//
// And in render_created_notice() (or a new admin_notices callback):
//
// $contract_errors = get_transient( 'shseq_contract_errors_' . get_current_user_id() );
// if ( $contract_errors ) {
//     delete_transient( 'shseq_contract_errors_' . get_current_user_id() );
//     foreach ( $contract_errors as $msg ) {
//         echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
//     }
// }

// ─── PATCH 4: G19 — Beat overlap detection ──────────────────────────────
//
// Add to sanitize_beats():
//
// private function check_beat_overlaps( array $beats ) {
//     $overlaps = array();
//     for ( $i = 0; $i < count( $beats ) - 1; $i++ ) {
//         $a_end   = (int) ( $beats[ $i ]['endFrame']     ?? 1 );
//         $b_start = (int) ( $beats[ $i + 1 ]['startFrame'] ?? 1 );
//         if ( $b_start <= $a_end ) {
//             $overlaps[] = sprintf(
//                 __( 'Beat %d ends at frame %d but beat %d starts at frame %d — they overlap.', 'sh-sequence-engine' ),
//                 $i + 1, $a_end, $i + 2, $b_start
//             );
//         }
//     }
//     return $overlaps;
// }
//
// Call inside save() and store result in transient like contract_errors above.

// ─── PATCH 5: G17 — Shortcode copy in sequence editor ───────────────────
//
// Add to render() at the top of the structure editor div, when the post is
// already saved (post_id > 0):
//
// if ( $post->ID > 0 ) {
//     $shortcode = '[storyboard_live id="' . $post->ID . '"]';
//     ?>
//     <div class="shseq-editor-shortcode-bar">
//         <span><?php _e( 'Shortcode:', 'sh-sequence-engine' ); ?></span>
//         <code id="shseq-editor-shortcode"><?php echo esc_html( $shortcode ); ?></code>
//         <button type="button" class="button" data-shseq-copy="shseq-editor-shortcode">
//             <?php _e( 'Copy', 'sh-sequence-engine' ); ?>
//         </button>
//         <a href="<?php echo esc_url( SequencePreview::preview_url( $post->ID ) ); ?>" target="_blank" class="button">
//             <?php _e( 'Preview', 'sh-sequence-engine' ); ?>
//         </a>
//     </div>
//     <?php
// }

// ─── PATCH 6: G02 — Dynamic add/remove overlay slots ────────────────────
//
// In render(), after the overlays table, add:
//
// <button
//     type="button"
//     class="button shseq-add-overlay"
//     data-next-index="<?php echo esc_attr( (string) count( $overlays ) ); ?>"
// >
//     + <?php _e( 'Add overlay slot', 'sh-sequence-engine' ); ?>
// </button>
//
// Add a "Delete" column to each overlay row with a remove button:
//
// <td>
//     <button type="button" class="button-link shseq-remove-overlay">
//         <?php _e( 'Remove', 'sh-sequence-engine' ); ?>
//     </button>
// </td>
//
// And a small inline script to handle add/remove without jQuery:
//
// <script>
// (function(){
//     var tbody = document.querySelector('.shseq-structure-table--overlays tbody');
//     var addBtn = document.querySelector('.shseq-add-overlay');
//     if (!tbody || !addBtn) return;
//
//     addBtn.addEventListener('click', function(){
//         var idx = parseInt(this.dataset.nextIndex, 10);
//         var row = document.createElement('tr');
//         row.innerHTML =
//             '<td>' + (idx + 1) + '</td>' +
//             '<td><input type="text" name="shseq_structure[overlays][' + idx + '][key]" value=""></td>' +
//             '<td><input class="small-text" type="number" min="1" name="shseq_structure[overlays][' + idx + '][frame]" value="1"></td>' +
//             '<td><code>HTML</code><input type="hidden" name="shseq_structure[overlays][' + idx + '][type]" value="html"></td>' +
//             '<td><button type="button" class="button-link shseq-remove-overlay">Remove</button></td>';
//         tbody.appendChild(row);
//         this.dataset.nextIndex = idx + 1;
//         row.querySelector('.shseq-remove-overlay').addEventListener('click', removeRow);
//     });
//
//     document.querySelectorAll('.shseq-remove-overlay').forEach(function(btn){
//         btn.addEventListener('click', removeRow);
//     });
//
//     function removeRow(e) {
//         var row = e.target.closest('tr');
//         if (row) row.remove();
//         // Renumber visible rows
//         tbody.querySelectorAll('tr').forEach(function(r, i){
//             var firstCell = r.querySelector('td:first-child');
//             if (firstCell) firstCell.textContent = i + 1;
//         });
//     }
// })();
// </script>

// NOTE: These patches need to be applied to the actual SequenceStructureMetaBox.php
// The content of this file serves as the authoritative diff reference.
// The actual implementation is delivered as the push to the repository.
