/**
 * StoryBoard Live — Gutenberg Block Editor Script
 *
 * Registers the shseq/frame-sequence block in the block editor.
 * The block renders server-side (render_callback), so the editor
 * just shows a placeholder with a sequence-ID number input.
 */
(function (blocks, element, blockEditor, components, i18n) {
	var el          = element.createElement;
	var __          = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody   = components.PanelBody;
	var TextControl = components.TextControl;

	blocks.registerBlockType('shseq/frame-sequence', {
		edit: function (props) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return [
				el(InspectorControls, { key: 'inspector' },
					el(PanelBody, { title: __('Sequence Settings', 'sh-sequence-engine'), initialOpen: true },
						el(TextControl, {
							label: __('Sequence ID', 'sh-sequence-engine'),
							help: __('Enter the ID of the Sequence post to display.', 'sh-sequence-engine'),
							value: String(attributes.sequenceId || ''),
							type: 'number',
							min: 0,
							onChange: function (val) {
								setAttributes({ sequenceId: parseInt(val, 10) || 0 });
							},
						}),
						el(TextControl, {
							label: __('Height', 'sh-sequence-engine'),
							help: __('CSS height of the sequence wrapper (e.g. 100vh).', 'sh-sequence-engine'),
							value: attributes.height || '100vh',
							onChange: function (val) {
								setAttributes({ height: val });
							},
						})
					)
				),
				el('div', {
					key: 'preview',
					className: 'shseq-block-placeholder',
					style: {
						background: 'linear-gradient(135deg,#1d2327 0%,#2c3338 100%)',
						color: '#fff',
						padding: '40px 20px',
						textAlign: 'center',
						borderRadius: '4px',
					}
				},
					el('p', { style: { margin: '0 0 8px', fontSize: '12px', opacity: '.7', textTransform: 'uppercase', letterSpacing: '.06em' } },
						__('StoryBoard Live', 'sh-sequence-engine')
					),
					el('p', { style: { margin: 0, fontSize: '18px', fontWeight: '700' } },
						attributes.sequenceId
							? __('Sequence #', 'sh-sequence-engine') + attributes.sequenceId
							: __('No sequence selected — enter a Sequence ID in the sidebar.', 'sh-sequence-engine')
					)
				)
			];
		},

		save: function () {
			// Server-side render — no client-side save markup needed.
			return null;
		},
	});

}(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n
));
