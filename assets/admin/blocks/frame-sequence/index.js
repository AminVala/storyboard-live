/**
 * StoryBoard Live — Gutenberg Block: shseq/frame-sequence
 * Sprint 2
 *
 * Provides a block editor interface that lets the user:
 *   1. Pick a Sequence post from a searchable dropdown.
 *   2. Set hero height (default 100vh).
 *   3. See a server-rendered preview via ServerSideRender.
 */

(function (blocks, element, blockEditor, components, i18n, apiFetch) {
  'use strict';

  var el           = element.createElement;
  var Fragment     = element.Fragment;
  var useState     = element.useState;
  var useEffect    = element.useEffect;
  var __           = i18n.__;
  var registerBlockType = blocks.registerBlockType;
  var InspectorControls = blockEditor.InspectorControls;
  var ServerSideRender  = blockEditor.__experimentalServerSideRender || (window.wp && wp.serverSideRender);
  var PanelBody    = components.PanelBody;
  var SelectControl = components.SelectControl;
  var TextControl  = components.TextControl;
  var Spinner      = components.Spinner;
  var Notice       = components.Notice;

  /**
   * Inner component that loads Sequence options and renders the inspector.
   */
  function SequencePicker(props) {
    var attributes = props.attributes;
    var setAttributes = props.setAttributes;

    var sequenceId = attributes.sequenceId || 0;
    var height     = attributes.height     || '100vh';

    var options      = useState([{ label: __('Loading…', 'sh-sequence-engine'), value: 0 }]);
    var sequences    = options[0];
    var setSequences = options[1];

    var loadingState = useState(true);
    var loading      = loadingState[0];
    var setLoading   = loadingState[1];

    var errorState = useState('');
    var error      = errorState[0];
    var setError   = errorState[1];

    useEffect(function () {
      apiFetch({ path: '/wp/v2/shseq_sequence?per_page=50&status=publish,draft' })
        .then(function (posts) {
          var opts = [{ label: __('— Select a sequence —', 'sh-sequence-engine'), value: 0 }];
          posts.forEach(function (post) {
            opts.push({ label: post.title.rendered || post.title.raw || '(no title)', value: post.id });
          });
          setSequences(opts);
          setLoading(false);
        })
        .catch(function (err) {
          setError(__('Could not load sequences.', 'sh-sequence-engine'));
          setLoading(false);
        });
    }, []);

    var inspector = el(
      InspectorControls,
      null,
      el(
        PanelBody,
        { title: __('Sequence', 'sh-sequence-engine'), initialOpen: true },
        loading
          ? el(Spinner)
          : error
            ? el(Notice, { status: 'error', isDismissible: false }, error)
            : el(SelectControl, {
                label: __('Hero Sequence', 'sh-sequence-engine'),
                value: sequenceId,
                options: sequences,
                onChange: function (val) {
                  setAttributes({ sequenceId: parseInt(val, 10) || 0 });
                }
              }),
        el(TextControl, {
          label: __('Hero height (CSS)', 'sh-sequence-engine'),
          value: height,
          help: __('e.g. 100vh, 600px, 80vmin', 'sh-sequence-engine'),
          onChange: function (val) {
            setAttributes({ height: val });
          }
        })
      )
    );

    var preview = sequenceId > 0 && ServerSideRender
      ? el(ServerSideRender, {
          block: 'shseq/frame-sequence',
          attributes: attributes
        })
      : el(
          'div',
          { className: 'shseq-block-editor-placeholder' },
          el('p', null, __('StoryBoard Hero', 'sh-sequence-engine')),
          el('p', { className: 'components-base-control__help' },
            __('Select a Sequence in the sidebar to preview.', 'sh-sequence-engine'))
        );

    return el(Fragment, null, inspector, preview);
  }

  registerBlockType('shseq/frame-sequence', {
    title:       __('StoryBoard Hero', 'sh-sequence-engine'),
    description: __('Scroll-driven hero animation from a sequence of images.', 'sh-sequence-engine'),
    category:    'media',
    icon:        'format-video',
    keywords:    [
      __('hero', 'sh-sequence-engine'),
      __('scroll', 'sh-sequence-engine'),
      __('animation', 'sh-sequence-engine')
    ],
    supports:    { html: false, align: ['full', 'wide'] },
    attributes: {
      sequenceId: { type: 'number',  default: 0 },
      height:     { type: 'string',  default: '100vh' },
      className:  { type: 'string',  default: '' }
    },

    edit: function (props) {
      return el(SequencePicker, props);
    },

    save: function () {
      // Dynamic block — PHP renders on the frontend.
      return null;
    }
  });

}(
  window.wp.blocks,
  window.wp.element,
  window.wp.blockEditor || window.wp.editor,
  window.wp.components,
  window.wp.i18n,
  window.wp.apiFetch
));
