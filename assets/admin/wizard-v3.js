/**
 * StoryBoard Live — Sequence Wizard v3 JS (Loop 3 Final)
 *
 * Covers:
 *  - Step navigation with ARIA panel switching
 *  - Template card keyboard+mouse selection
 *  - Name debounced uniqueness check (AJAX)
 *  - Frame upload (drag+drop, multi-file, progress, sort)
 *  - Final image upload + preview
 *  - AI generation kick-off + polling
 *  - Canvas Overlay Editor (drag, resize, inline-edit, undo/redo)
 *  - Step 4 frame generation polling
 *  - Step 5 review summary + preflight + publish
 *  - Toast notifications
 *  - Full i18n via shseqWizard.i18n
 *  - RTL-aware (reads dir attribute)
 */
(function () {
	'use strict';

	var cfg      = window.shseqWizard || {};
	var i18n     = cfg.i18n     || {};
	var nonces   = cfg.nonces   || {};
	var isPro    = !!cfg.isPro;
	var postId   = cfg.postId   || 0;
	var ajaxUrl  = cfg.ajaxUrl  || '';
	var locale   = cfg.locale   || 'en';
	var isRtl    = document.querySelector('[dir="rtl"]') !== null;

	// DOM refs
	var wrapEl        = document.querySelector('.shseq-wizard-wrap');
	var srEl          = document.getElementById('shseq-wiz-sr');
	var errorBanner   = document.getElementById('shseq-wiz-error');
	var toastEl       = document.getElementById('shseq-toast');
	var postIdInput   = document.getElementById('shseq-post-id');
	var selectedTplEl = document.getElementById('shseq-selected-template');

	var currentStep   = cfg.currentStep || 1;
	var toastTimer;
	var nameDebounce;
	var aiPollTimer;
	var genPollTimer;
	var undoStack     = [];
	var redoStack     = [];
	var MAX_UNDO      = 20;
	var selectedEl    = null;    // currently selected overlay element
	var dragEl        = null;
	var resizeEl      = null;
	var dragStartX, dragStartY, elStartX, elStartY;
	var resizeStartX, resizeStartY, elStartW, elStartH;
	var uploadedFrameIds = [];   // attachment IDs in order
	var finalImageId  = 0;
	var aiImageId     = 0;
	var genTotalFrames= cfg.frameCount || 24;
	var genMode       = '';      // 'upload' | 'frames' | 'ai'

	// ── Utilities ─────────────────────────────────────────────────────────

	function $(sel, ctx) { return (ctx || document).querySelector(sel); }
	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

	function announce(msg) {
		if (!srEl) return;
		srEl.textContent = '';
		setTimeout(function () { srEl.textContent = msg; }, 50);
	}

	function showToast(msg, type) {
		if (!toastEl) return;
		clearTimeout(toastTimer);
		toastEl.textContent = msg;
		toastEl.className = 'shseq-toast is-visible' + (type === 'error' ? ' is-error' : type === 'success' ? ' is-success' : '');
		toastEl.removeAttribute('hidden');
		toastTimer = setTimeout(function () {
			toastEl.classList.remove('is-visible');
			setTimeout(function () { toastEl.setAttribute('hidden', ''); }, 250);
		}, 3000);
	}

	function showError(msg) {
		if (!errorBanner) return;
		errorBanner.textContent = msg;
		errorBanner.removeAttribute('hidden');
		errorBanner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	}
	function clearError() {
		if (!errorBanner) return;
		errorBanner.setAttribute('hidden', '');
	}

	function ajax(action, data, onSuccess, onError) {
		var fd = new FormData();
		fd.append('action', action);
		Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
		fetch(ajaxUrl, { method: 'POST', body: fd })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res.success) { onSuccess(res.data); }
				else {
					var msg = (res.data && res.data.message) || i18n.error_generic || 'Error.';
					if (onError) onError(msg, res.data);
					else showError(msg);
				}
			})
			.catch(function (e) {
				var msg = e.message || (i18n.error_generic || 'Network error.');
				if (onError) onError(msg);
				else showError(msg);
			});
	}

	function setBtn(id, loading) {
		var btn = $(id);
		if (!btn) return;
		btn.disabled = loading;
		btn.dataset.origText = btn.dataset.origText || btn.textContent.trim();
		btn.textContent = loading ? (i18n.saving || 'Saving…') : btn.dataset.origText;
	}

	// ── Step navigation ───────────────────────────────────────────────────

	function goToStep(n) {
		$$('.shseq-wiz-panel').forEach(function (p) {
			var pStep = parseInt(p.dataset.step, 10);
			p.classList.toggle('is-active', pStep === n);
			p.setAttribute('aria-hidden', pStep === n ? 'false' : 'true');
		});
		$$('.shseq-wiz-step').forEach(function (s) {
			var sN = parseInt(s.dataset.step, 10);
			s.classList.toggle('is-done', sN < n);
			s.classList.toggle('is-active', sN === n);
		});
		// Update progressbar aria
		var prog = $('.shseq-wiz-progress');
		if (prog) prog.setAttribute('aria-valuenow', n);

		currentStep = n;

		// Step-specific entry actions
		if (n === 3) { initStep3(); }
		if (n === 4 && isPro) { initStep4(); }
		if (n === 5 || (!isPro && n === 4)) { initStep5(); }

		window.scrollTo({ top: 0, behavior: 'smooth' });
		announce(i18n['step' + n + '_short'] || ('Step ' + n));
	}

	// ── Back buttons ──────────────────────────────────────────────────────

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.shseq-btn-back');
		if (!btn) return;
		var target = parseInt(btn.dataset.target, 10) || (currentStep - 1);
		ajax('shseq_wiz_back', { nonce: nonces.back, post_id: postId, target_step: target },
			function () { goToStep(target); },
			function (msg) { showError(msg); }
		);
	});

	// ── Step 1: Name + Template ───────────────────────────────────────────

	var nameInput = $('#shseq-seq-name');
	var nameStatus = $('#shseq-name-status');

	if (nameInput) {
		nameInput.addEventListener('input', function () {
			clearTimeout(nameDebounce);
			var val = this.value.trim();
			if (!val) { setNameStatus('', ''); return; }
			setNameStatus('checking', i18n.name_checking || 'Checking…');
			nameDebounce = setTimeout(function () {
				ajax('shseq_wiz_check_name',
					{ nonce: nonces.name, name: val, post_id: postId },
					function (data) {
						if (data.available) {
							setNameStatus('available', i18n.name_available || '✓ Available');
						} else {
							setNameStatus('taken', i18n.name_taken || '✕ Already taken');
						}
					}
				);
			}, 400);
		});
	}

	function setNameStatus(cls, msg) {
		if (!nameStatus) return;
		nameStatus.className = 'shseq-name-status' + (cls ? ' ' + cls : '');
		nameStatus.textContent = msg;
	}

	// Template card selection
	document.addEventListener('click', function (e) {
		var card = e.target.closest('.shseq-tpl-card');
		if (!card || card.classList.contains('is-locked')) return;
		$$('.shseq-tpl-card').forEach(function (c) {
			c.classList.remove('is-selected');
			c.setAttribute('aria-checked', 'false');
		});
		card.classList.add('is-selected');
		card.setAttribute('aria-checked', 'true');
		if (selectedTplEl) selectedTplEl.value = card.dataset.templateId || 'blank';
	});

	// Keyboard for template cards
	document.addEventListener('keydown', function (e) {
		var card = e.target.closest('.shseq-tpl-card');
		if (!card) return;
		if ((e.key === 'Enter' || e.key === ' ') && !card.classList.contains('is-locked')) {
			e.preventDefault();
			card.click();
		}
	});

	// Step 1 save
	var s1Btn = $('#shseq-s1-save');
	if (s1Btn) {
		s1Btn.addEventListener('click', function () {
			clearError();
			var name = nameInput ? nameInput.value.trim() : '';
			if (!name) { showError(i18n.name_required || 'Name is required.'); nameInput && nameInput.focus(); return; }
			if (nameStatus && nameStatus.classList.contains('taken')) {
				showError(i18n.name_duplicate || 'Name is already taken.'); return;
			}
			var tplId = selectedTplEl ? selectedTplEl.value : 'blank';
			setBtn('#shseq-s1-save', true);
			ajax('shseq_wiz_step1',
				{ nonce: nonces.s1, name: name, template_id: tplId, post_id: postId },
				function (data) {
					postId = data.postId;
					if (postIdInput) postIdInput.value = postId;
					genTotalFrames = data.frameCount || 24;
					setBtn('#shseq-s1-save', false);
					goToStep(2);
				},
				function (msg, d) {
					setBtn('#shseq-s1-save', false);
					if (d && d.field === 'name') { showError(msg); nameInput && nameInput.focus(); }
					else showError(msg);
				}
			);
		});
	}

	// ── Step 2: Upload ────────────────────────────────────────────────────

	// ── FREE: multi-frame upload ──
	var framesDropzone = $('#shseq-frames-dropzone');
	var framesInput    = $('#shseq-frames-input');
	var framesGrid     = $('#shseq-frames-grid');
	var framesHint     = $('#shseq-frames-count-hint');
	var uploadProgress = $('#shseq-upload-progress');
	var uploadFill     = $('#shseq-upload-fill');
	var uploadLabel    = $('#shseq-upload-label');
	var s2SaveBtn      = $('#shseq-s2-save');

	function enableS2Save() {
		if (s2SaveBtn) s2SaveBtn.disabled = false;
	}

	function renderFrameThumb(attId, url, idx) {
		if (!framesGrid) return;
		var div = document.createElement('div');
		div.className = 'shseq-frame-thumb';
		div.dataset.id = attId;
		if (idx === uploadedFrameIds.length - 1) div.classList.add('is-final');
		div.innerHTML =
			'<img src="' + url + '" alt="Frame ' + (idx+1) + '">' +
			'<span class="shseq-frame-thumb__num">' + (idx+1) + '</span>' +
			'<button type="button" class="shseq-frame-thumb__remove" aria-label="Remove frame">✕</button>';
		div.querySelector('.shseq-frame-thumb__remove').addEventListener('click', function () {
			var id = parseInt(div.dataset.id, 10);
			uploadedFrameIds = uploadedFrameIds.filter(function (f) { return f !== id; });
			framesGrid.removeChild(div);
			reindexFrames();
			if (uploadedFrameIds.length === 0 && s2SaveBtn) s2SaveBtn.disabled = true;
		});
		framesGrid.appendChild(div);
	}

	function reindexFrames() {
		$$('.shseq-frame-thumb', framesGrid).forEach(function (t, i) {
			var num = t.querySelector('.shseq-frame-thumb__num');
			if (num) num.textContent = i + 1;
			t.classList.toggle('is-final', i === uploadedFrameIds.length - 1);
		});
		if (framesHint) framesHint.textContent = uploadedFrameIds.length + ' / ' + cfg.maxFrames + ' frames';
	}

	function uploadFrameFile(file, onDone) {
		if (!isPro) {
			// Free: WebP/JPEG/PNG
			var allowed = cfg.allowedMimes || ['image/webp', 'image/jpeg', 'image/png'];
			if (allowed.indexOf(file.type) === -1) {
				showToast(i18n.invalid_file_type || 'Invalid file type.', 'error'); return;
			}
		}
		var fd = new FormData();
		fd.append('action', 'shseq_wiz_step2_upload');
		fd.append('nonce', nonces.s2);
		fd.append('post_id', postId);
		fd.append('is_final_image', isPro ? '1' : '0');
		fd.append('file', file);
		fetch(ajaxUrl, { method: 'POST', body: fd })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res.success) {
					if (onDone) onDone(res.data);
				} else {
					showToast((res.data && res.data.message) || (i18n.error_generic || 'Upload error.'), 'error');
				}
			})
			.catch(function () { showToast(i18n.error_generic || 'Network error.', 'error'); });
	}

	if (framesInput) {
		framesInput.addEventListener('change', function () {
			var files = Array.prototype.slice.call(this.files);
			if (!files.length) return;
			if (uploadedFrameIds.length + files.length > cfg.maxFrames) {
				showToast('Max ' + cfg.maxFrames + ' frames.', 'error'); return;
			}
			var total = files.length, done = 0;
			if (uploadProgress) uploadProgress.removeAttribute('hidden');
			files.forEach(function (file) {
				uploadFrameFile(file, function (data) {
					uploadedFrameIds.push(data.attachmentId);
					renderFrameThumb(data.attachmentId, data.thumb, uploadedFrameIds.length - 1);
					done++;
					var pct = Math.round(done / total * 100);
					if (uploadFill) { uploadFill.style.width = pct + '%'; uploadFill.parentElement.setAttribute('aria-valuenow', pct); }
					if (uploadLabel) uploadLabel.textContent = done + ' / ' + total;
					if (done === total) {
						reindexFrames();
						enableS2Save();
						if (uploadProgress) uploadProgress.setAttribute('hidden', '');
					}
				});
			});
			this.value = '';
		});
	}

	if (framesDropzone) {
		framesDropzone.addEventListener('dragover', function (e) { e.preventDefault(); this.classList.add('is-dragging'); });
		framesDropzone.addEventListener('dragleave', function () { this.classList.remove('is-dragging'); });
		framesDropzone.addEventListener('drop', function (e) {
			e.preventDefault();
			this.classList.remove('is-dragging');
			var files = Array.prototype.slice.call(e.dataTransfer.files);
			if (framesInput) {
				// Synthetic change event not possible with FileList; handle directly
				if (uploadedFrameIds.length + files.length > cfg.maxFrames) {
					showToast('Max ' + cfg.maxFrames + ' frames.', 'error'); return;
				}
				var total = files.length, done = 0;
				if (uploadProgress) uploadProgress.removeAttribute('hidden');
				files.forEach(function (file) {
					uploadFrameFile(file, function (data) {
						uploadedFrameIds.push(data.attachmentId);
						renderFrameThumb(data.attachmentId, data.thumb, uploadedFrameIds.length - 1);
						done++;
						var pct = Math.round(done / total * 100);
						if (uploadFill) { uploadFill.style.width = pct + '%'; }
						if (done === total) { reindexFrames(); enableS2Save(); if (uploadProgress) uploadProgress.setAttribute('hidden', ''); }
					});
				});
			}
		});
	}

	// ── PRO: Single final image upload ──
	var finalDropzone  = $('#shseq-final-dropzone');
	var finalInput     = $('#shseq-final-input');
	var finalPreview   = $('#shseq-final-preview');
	var finalImg       = $('#shseq-final-img');
	var finalRemoveBtn = $('#shseq-final-remove');

	function handleFinalImageUpload(file) {
		uploadFrameFile(file, function (data) {
			finalImageId = data.attachmentId;
			genMode = 'upload';
			if (finalImg) finalImg.src = data.url;
			if (finalPreview) finalPreview.removeAttribute('hidden');
			if (finalDropzone) finalDropzone.setAttribute('hidden', '');
			enableS2Save();
		});
	}

	if (finalInput) {
		finalInput.addEventListener('change', function () {
			if (this.files[0]) handleFinalImageUpload(this.files[0]);
			this.value = '';
		});
	}
	if (finalDropzone) {
		finalDropzone.addEventListener('dragover', function (e) { e.preventDefault(); this.classList.add('is-dragging'); });
		finalDropzone.addEventListener('dragleave', function () { this.classList.remove('is-dragging'); });
		finalDropzone.addEventListener('drop', function (e) {
			e.preventDefault(); this.classList.remove('is-dragging');
			if (e.dataTransfer.files[0]) handleFinalImageUpload(e.dataTransfer.files[0]);
		});
	}
	if (finalRemoveBtn) {
		finalRemoveBtn.addEventListener('click', function () {
			finalImageId = 0; genMode = '';
			if (finalImg) finalImg.src = '';
			if (finalPreview) finalPreview.setAttribute('hidden', '');
			if (finalDropzone) finalDropzone.removeAttribute('hidden');
			if (s2SaveBtn) s2SaveBtn.disabled = true;
		});
	}

	// Mode tabs (Pro)
	$$('.shseq-mode-tab').forEach(function (tab) {
		tab.addEventListener('click', function () {
			$$('.shseq-mode-tab').forEach(function (t) { t.classList.remove('shseq-mode-tab--active'); t.setAttribute('aria-selected', 'false'); });
			$$('.shseq-mode-panel').forEach(function (p) { p.classList.remove('is-active'); p.setAttribute('aria-hidden', 'true'); });
			tab.classList.add('shseq-mode-tab--active');
			tab.setAttribute('aria-selected', 'true');
			var panel = $('#shseq-mode-' + tab.dataset.mode);
			if (panel) { panel.classList.add('is-active'); panel.setAttribute('aria-hidden', 'false'); }
		});
	});

	// AI generation
	var aiGenerateBtn  = $('#shseq-ai-generate');
	var aiProgressWrap = $('#shseq-ai-progress');
	var aiStatusLabel  = $('#shseq-ai-status-label');
	var aiResult       = $('#shseq-ai-result');
	var aiImg          = $('#shseq-ai-img');
	var aiAcceptBtn    = $('#shseq-ai-accept');
	var aiRegenBtn     = $('#shseq-ai-regenerate');

	function kickOffAI() {
		var prompt = ($('#shseq-ai-prompt') || {}).value || '';
		var style  = ($('input[name="shseq_ai_style"]:checked') || {}).value || 'photorealistic';
		if (!prompt.trim()) { showError(i18n.prompt_required || 'Prompt required.'); return; }
		clearError();
		if (aiProgressWrap) aiProgressWrap.removeAttribute('hidden');
		if (aiGenerateBtn) aiGenerateBtn.disabled = true;
		if (aiResult) aiResult.setAttribute('hidden', '');

		ajax('shseq_wiz_step2_ai',
			{ nonce: nonces.s2, post_id: postId, prompt: prompt, style: style },
			function () {
				genMode = 'ai';
				pollAIStatus();
			},
			function (msg) {
				if (aiProgressWrap) aiProgressWrap.setAttribute('hidden', '');
				if (aiGenerateBtn) aiGenerateBtn.disabled = false;
				showError(msg);
			}
		);
	}

	function pollAIStatus() {
		aiPollTimer = setInterval(function () {
			ajax('shseq_wiz_step2_ai_status', { nonce: nonces.s2, post_id: postId },
				function (data) {
					if (aiStatusLabel) aiStatusLabel.textContent = data.status === 'done' ? (i18n.gen_done || 'Done!') : (i18n.generating || 'Generating…');
					if (data.done) {
						clearInterval(aiPollTimer);
						if (aiProgressWrap) aiProgressWrap.setAttribute('hidden', '');
						if (aiImg) aiImg.src = data.url;
						if (aiResult) aiResult.removeAttribute('hidden');
						aiImageId = 0; // will be stored after accept
						if (aiGenerateBtn) aiGenerateBtn.disabled = false;
					}
					if (data.status === 'failed') {
						clearInterval(aiPollTimer);
						if (aiProgressWrap) aiProgressWrap.setAttribute('hidden', '');
						if (aiGenerateBtn) aiGenerateBtn.disabled = false;
						showError(data.error || (i18n.gen_failed || 'Generation failed.'));
					}
				}
			);
		}, 3000);
	}

	if (aiGenerateBtn) aiGenerateBtn.addEventListener('click', kickOffAI);
	if (aiAcceptBtn) {
		aiAcceptBtn.addEventListener('click', function () {
			// The final image ID was already stored server-side when generation completed
			finalImageId = -1; // sentinel: stored server-side
			genMode = 'ai';
			enableS2Save();
			showToast(i18n.saved || 'Image accepted.', 'success');
		});
	}
	if (aiRegenBtn) aiRegenBtn.addEventListener('click', kickOffAI);

	// Step 2 save
	if (s2SaveBtn) {
		s2SaveBtn.addEventListener('click', function () {
			clearError();
			if (!isPro) {
				// Free: confirm frames
				if (!uploadedFrameIds.length) { showError(i18n.no_frames || 'Upload at least one frame.'); return; }
				setBtn('#shseq-s2-save', true);
				ajax('shseq_wiz_step2_confirm_frames' || 'shseq_wiz_step2_upload', // handler resolves frames
					{ nonce: nonces.s2, post_id: postId, 'frame_ids[]': uploadedFrameIds, action: 'shseq_wiz_step2_confirm_frames' },
					function () { setBtn('#shseq-s2-save', false); goToStep(3); },
					function (msg) { setBtn('#shseq-s2-save', false); showError(msg); }
				);
			} else {
				// Pro: final image must be set
				if (!finalImageId && genMode !== 'ai') { showError(i18n.no_final_image || 'No final image.'); return; }
				goToStep(3);
			}
		});
	}

	// ── Step 3: Canvas Overlay Editor ─────────────────────────────────────

	var canvasWrap       = $('#shseq-canvas-wrap');
	var extractLoading   = $('#shseq-extract-loading');
	var canvasContainer  = $('#shseq-canvas-container');
	var canvasBg         = $('#shseq-canvas-bg');
	var canvasOverlays   = $('#shseq-canvas-overlays');
	var undoBtn          = $('#shseq-undo');
	var redoBtn          = $('#shseq-redo');
	var propPanel        = $('#shseq-element-props');
	var propText         = $('#shseq-prop-text');
	var propSize         = $('#shseq-prop-size');
	var propColor        = $('#shseq-prop-color');
	var propAlign        = $('#shseq-prop-align');
	var propDelete       = $('#shseq-prop-delete');

	var overlayItems = []; // {id, type, text, x, y, w, h, fontSize, color, align}
	var elCounter = 0;

	function initStep3() {
		if (!extractLoading || !canvasWrap) return;
		extractLoading.style.display = 'flex';
		if (canvasWrap) canvasWrap.setAttribute('hidden', '');

		ajax('shseq_wiz_step3_extract', { nonce: nonces.s3, post_id: postId },
			function (data) {
				extractLoading.style.display = 'none';
				if (canvasWrap) canvasWrap.removeAttribute('hidden');
				// Set background
				if (canvasBg && data.cleanBgUrl) {
					canvasBg.style.backgroundImage = 'url(' + data.cleanBgUrl + ')';
				}
				// Render extracted overlays
				overlayItems = data.overlays || [];
				renderAllOverlays();
				saveUndoSnapshot();
			},
			function () {
				extractLoading.style.display = 'none';
				if (canvasWrap) canvasWrap.removeAttribute('hidden');
				overlayItems = [];
				renderAllOverlays();
			}
		);
	}

	// Viewport switch
	$$('.shseq-vp-tab').forEach(function (tab) {
		tab.addEventListener('click', function () {
			$$('.shseq-vp-tab').forEach(function (t) {
				t.classList.remove('shseq-vp-tab--active');
				t.setAttribute('aria-selected', 'false');
			});
			tab.classList.add('shseq-vp-tab--active');
			tab.setAttribute('aria-selected', 'true');
			if (canvasContainer) canvasContainer.dataset.viewport = tab.dataset.viewport;
		});
	});

	// Add overlay buttons
	$$('.shseq-add-overlay').forEach(function (btn) {
		btn.addEventListener('click', function () {
			addOverlay(btn.dataset.type || 'heading');
		});
	});

	function addOverlay(type) {
		var defaults = { heading: { text: 'Heading', fontSize: 36, h: 12 }, paragraph: { text: 'Paragraph text', fontSize: 18, h: 10 }, button: { text: 'Click Here', fontSize: 16, h: 8 } };
		var d = defaults[type] || defaults.heading;
		var item = { id: 'el_' + (++elCounter), type: type, text: d.text, x: 10, y: 40, w: 40, h: d.h, fontSize: d.fontSize, color: '#ffffff', align: isRtl ? 'right' : 'left' };
		overlayItems.push(item);
		renderOverlayEl(item);
		saveUndoSnapshot();
	}

	function renderAllOverlays() {
		if (!canvasOverlays) return;
		canvasOverlays.innerHTML = '';
		overlayItems.forEach(renderOverlayEl);
	}

	function renderOverlayEl(item) {
		if (!canvasOverlays) return;
		var el = document.createElement('div');
		el.className = 'shseq-overlay-el';
		el.dataset.id = item.id;
		el.style.left   = item.x + '%';
		el.style.top    = item.y + '%';
		el.style.width  = item.w + '%';
		el.style.height = item.h + '%';

		var tag = item.type === 'heading' ? 'h2' : item.type === 'button' ? 'button' : 'p';
		var content = document.createElement(tag);
		content.className = 'shseq-overlay-el__content';
		content.contentEditable = 'true';
		content.textContent = item.text;
		content.style.fontSize   = item.fontSize + 'px';
		content.style.color      = item.color;
		content.style.textAlign  = item.align;
		content.style.margin     = '0';
		content.style.padding    = '4px 8px';
		content.style.width      = '100%';
		content.style.height     = '100%';
		content.style.outline    = 'none';
		content.setAttribute('aria-label', item.type + ': ' + item.text);

		var resize = document.createElement('div');
		resize.className = 'shseq-overlay-el__resize';
		resize.setAttribute('aria-hidden', 'true');

		el.appendChild(content);
		el.appendChild(resize);
		canvasOverlays.appendChild(el);

		// Drag
		el.addEventListener('mousedown', function (e) {
			if (e.target === resize) return; // let resize handle it
			selectOverlayEl(el);
			dragEl = el;
			dragStartX = e.clientX;
			dragStartY = e.clientY;
			var rect = canvasContainer.getBoundingClientRect();
			elStartX = item.x;
			elStartY = item.y;
			e.preventDefault();
		});

		// Resize
		resize.addEventListener('mousedown', function (e) {
			resizeEl = el;
			resizeStartX = e.clientX;
			resizeStartY = e.clientY;
			var rect = canvasContainer.getBoundingClientRect();
			elStartW = item.w;
			elStartH = item.h;
			e.preventDefault();
			e.stopPropagation();
		});

		// Inline edit
		content.addEventListener('input', function () {
			var it = overlayById(el.dataset.id);
			if (it) { it.text = content.textContent; }
			if (propText && selectedEl === el) propText.value = content.textContent;
		});
		content.addEventListener('blur', function () { saveUndoSnapshot(); });

		el.addEventListener('click', function (e) { selectOverlayEl(el); e.stopPropagation(); });
	}

	// Mouse move / up for drag & resize
	document.addEventListener('mousemove', function (e) {
		if (dragEl && canvasContainer) {
			var rect = canvasContainer.getBoundingClientRect();
			var dx = (e.clientX - dragStartX) / rect.width * 100;
			var dy = (e.clientY - dragStartY) / rect.height * 100;
			var item = overlayById(dragEl.dataset.id);
			if (!item) return;
			item.x = Math.max(0, Math.min(100 - item.w, elStartX + dx));
			item.y = Math.max(0, Math.min(100 - item.h, elStartY + dy));
			dragEl.style.left = item.x + '%';
			dragEl.style.top  = item.y + '%';
		}
		if (resizeEl && canvasContainer) {
			var rect = canvasContainer.getBoundingClientRect();
			var dx = (e.clientX - resizeStartX) / rect.width * 100;
			var dy = (e.clientY - resizeStartY) / rect.height * 100;
			var item = overlayById(resizeEl.dataset.id);
			if (!item) return;
			item.w = Math.max(5, Math.min(100, elStartW + dx));
			item.h = Math.max(3, Math.min(100, elStartH + dy));
			resizeEl.style.width  = item.w + '%';
			resizeEl.style.height = item.h + '%';
		}
	});
	document.addEventListener('mouseup', function () {
		if (dragEl || resizeEl) saveUndoSnapshot();
		dragEl = null; resizeEl = null;
	});

	// Deselect on canvas click
	if (canvasOverlays) {
		canvasOverlays.addEventListener('click', function (e) {
			if (e.target === canvasOverlays) deselectEl();
		});
	}

	function selectOverlayEl(el) {
		$$('.shseq-overlay-el').forEach(function (e) { e.classList.remove('is-selected'); });
		el.classList.add('is-selected');
		selectedEl = el;
		var item = overlayById(el.dataset.id);
		if (!item) return;
		if (propPanel) propPanel.removeAttribute('hidden');
		if (propText) propText.value = item.text;
		if (propSize) propSize.value = item.fontSize;
		if (propColor) propColor.value = item.color;
		if (propAlign) propAlign.value = item.align;
	}

	function deselectEl() {
		$$('.shseq-overlay-el').forEach(function (e) { e.classList.remove('is-selected'); });
		selectedEl = null;
		if (propPanel) propPanel.setAttribute('hidden', '');
	}

	// Property changes
	[propText, propSize, propColor, propAlign].forEach(function (inp) {
		if (!inp) return;
		inp.addEventListener('input', function () {
			if (!selectedEl) return;
			var item = overlayById(selectedEl.dataset.id);
			if (!item) return;
			var content = selectedEl.querySelector('.shseq-overlay-el__content');
			if (inp === propText)  { item.text = inp.value; if (content) content.textContent = inp.value; }
			if (inp === propSize)  { item.fontSize = parseInt(inp.value, 10); if (content) content.style.fontSize = item.fontSize + 'px'; }
			if (inp === propColor) { item.color = inp.value; if (content) content.style.color = inp.value; }
			if (inp === propAlign) { item.align = inp.value; if (content) content.style.textAlign = inp.value; }
		});
		inp.addEventListener('change', function () { saveUndoSnapshot(); });
	});

	if (propDelete) {
		propDelete.addEventListener('click', function () {
			if (!selectedEl) return;
			var id = selectedEl.dataset.id;
			overlayItems = overlayItems.filter(function (i) { return i.id !== id; });
			canvasOverlays && canvasOverlays.removeChild(selectedEl);
			deselectEl();
			saveUndoSnapshot();
		});
	}

	// Undo/Redo
	function saveUndoSnapshot() {
		undoStack.push(JSON.stringify(overlayItems));
		if (undoStack.length > MAX_UNDO) undoStack.shift();
		redoStack = [];
		updateUndoRedoBtns();
	}
	function updateUndoRedoBtns() {
		if (undoBtn) undoBtn.disabled = undoStack.length <= 1;
		if (redoBtn) redoBtn.disabled = redoStack.length === 0;
	}
	function applySnapshot(snap) {
		overlayItems = JSON.parse(snap);
		renderAllOverlays();
		deselectEl();
	}
	if (undoBtn) undoBtn.addEventListener('click', function () {
		if (undoStack.length <= 1) return;
		redoStack.push(undoStack.pop());
		applySnapshot(undoStack[undoStack.length - 1]);
		updateUndoRedoBtns();
	});
	if (redoBtn) redoBtn.addEventListener('click', function () {
		if (!redoStack.length) return;
		var snap = redoStack.pop();
		undoStack.push(snap);
		applySnapshot(snap);
		updateUndoRedoBtns();
	});

	// Keyboard undo/redo
	document.addEventListener('keydown', function (e) {
		if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) { e.preventDefault(); undoBtn && undoBtn.click(); }
		if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.key === 'z' && e.shiftKey))) { e.preventDefault(); redoBtn && redoBtn.click(); }
	});

	function overlayById(id) {
		return overlayItems.find(function (i) { return i.id === id; });
	}

	// Step 3 save
	var s3Btn = $('#shseq-s3-save');
	if (s3Btn) {
		s3Btn.addEventListener('click', function () {
			clearError();
			setBtn('#shseq-s3-save', true);
			ajax('shseq_wiz_step3_save',
				{ nonce: nonces.s3, post_id: postId, overlays: JSON.stringify(overlayItems) },
				function (data) {
					setBtn('#shseq-s3-save', false);
					goToStep(data.nextStep);
				},
				function (msg) { setBtn('#shseq-s3-save', false); showError(msg); }
			);
		});
	}

	// ── Step 4: Frame Generation (Pro) ────────────────────────────────────

	var genFrameCount = $('#shseq-gen-frame-count');
	var genRemaining  = $('#shseq-gen-remaining');
	var genFinalThumb = $('#shseq-gen-final-thumb');
	var genFinalImg   = $('#shseq-gen-final-img');
	var genProgressWrap = $('#shseq-gen-progress-wrap');
	var genFill       = $('#shseq-gen-fill');
	var genStatusLbl  = $('#shseq-gen-status-label');
	var genPctLbl     = $('#shseq-gen-pct');
	var genStrip      = $('#shseq-gen-strip');
	var genStartBtn   = $('#shseq-s4-start');
	var genNextBtn    = $('#shseq-s4-next');

	function initStep4() {
		if (genFrameCount) genFrameCount.textContent = genTotalFrames;
		if (genRemaining)  genRemaining.textContent  = genTotalFrames - 1;

		// Show final frame thumb
		var thumbUrl = ($('#shseq-final-img') || {}).src || '';
		if (thumbUrl && genFinalThumb && genFinalImg) {
			genFinalImg.src = thumbUrl;
			genFinalThumb.removeAttribute('hidden');
		}
	}

	if (genStartBtn) {
		genStartBtn.addEventListener('click', function () {
			genStartBtn.disabled = true;
			if (genProgressWrap) genProgressWrap.removeAttribute('hidden');
			ajax('shseq_wiz_step4_start', { nonce: nonces.s4, post_id: postId },
				function (data) {
					if (data.alreadyRunning) { pollGenStatus(); return; }
					pollGenStatus();
				},
				function (msg) { genStartBtn.disabled = false; showError(msg); }
			);
		});
	}

	function pollGenStatus() {
		genPollTimer = setInterval(function () {
			ajax('shseq_wiz_step4_status', { nonce: nonces.s4, post_id: postId },
				function (data) {
					var labels = data.labels || {};
					if (genStatusLbl) genStatusLbl.textContent = labels[data.status] || data.status;
					if (genPctLbl) genPctLbl.textContent = data.percent + '%';
					if (genFill) { genFill.style.width = data.percent + '%'; genFill.parentElement.setAttribute('aria-valuenow', data.percent); }

					if (data.done) {
						clearInterval(genPollTimer);
						if (genNextBtn) genNextBtn.removeAttribute('hidden');
						if (genStartBtn) genStartBtn.setAttribute('hidden', '');
						showToast(i18n.gen_done || 'Frames ready!', 'success');
					}
					if (data.status === 'failed') {
						clearInterval(genPollTimer);
						if (genStartBtn) genStartBtn.disabled = false;
						showError(data.error || (i18n.gen_failed || 'Generation failed.'));
					}
				}
			);
		}, 4000);
	}

	if (genNextBtn) {
		genNextBtn.addEventListener('click', function () {
			goToStep(5);
		});
	}

	// ── Step 5: Review & Publish ──────────────────────────────────────────

	function initStep5() {
		// Review list
		var reviewList = $('#shseq-review-list');
		if (reviewList) {
			var name  = (nameInput || {}).value || '—';
			var tplId = (selectedTplEl || {}).value || 'blank';
			reviewList.innerHTML =
				'<dt>' + (i18n.step1_short || 'Step 1') + '</dt><dd>' + esc(name) + ' / ' + esc(tplId) + '</dd>' +
				'<dt>' + (i18n.step2_short_pro || 'Step 2') + '</dt><dd>' + esc(genMode || '—') + '</dd>' +
				'<dt>' + (i18n.total_frames || 'Frames') + '</dt><dd>' + genTotalFrames + '</dd>';
		}

		// Preview
		var previewBtn = $('#shseq-preview-btn');
		if (previewBtn && postId) {
			var pUrl = location.origin + '/?shseq_preview=' + postId;
			previewBtn.href = pUrl;
		}

		// Preflight checks (server-side could add more)
		var preflightList = $('#shseq-preflight-list');
		if (preflightList) {
			preflightList.innerHTML = '';
			addPreflightItem(preflightList, !!postId, i18n.sequence_name || 'Sequence saved', 'ok');
			addPreflightItem(preflightList, genTotalFrames > 0, i18n.total_frames || 'Frames', isPro ? 'ok' : 'warn');
		}
	}

	function addPreflightItem(list, ok, label, type) {
		var li = document.createElement('li');
		var icon = ok ? '✓' : '✕';
		var cls  = ok ? 'shseq-check-ok' : (type === 'warn' ? 'shseq-check-warn' : 'shseq-check-fail');
		li.innerHTML = '<span class="' + cls + '">' + icon + '</span> ' + esc(label);
		list.appendChild(li);
	}

	var publishBtn = $('#shseq-publish-btn');
	if (publishBtn) {
		publishBtn.addEventListener('click', function () {
			clearError();
			setBtn('#shseq-publish-btn', true);
			ajax('shseq_wiz_publish', { nonce: nonces.publish, post_id: postId },
				function (data) {
					setBtn('#shseq-publish-btn', false);
					// Show success state
					var successEl = $('#shseq-publish-success');
					if (successEl) {
						successEl.removeAttribute('hidden');
						var viewBtn = $('#shseq-view-btn');
						if (viewBtn) viewBtn.href = data.permalink || '#';
						var scCode = $('#shseq-sc-code');
						if (scCode) scCode.textContent = data.shortcode || '';
						var scRow = $('#shseq-shortcode-row');
						if (scRow) scRow.removeAttribute('hidden');
					}
					publishBtn.setAttribute('hidden', '');
					announce(i18n.published_title || 'Published!');
					showToast(i18n.published_title || 'Published!', 'success');
				},
				function (msg) { setBtn('#shseq-publish-btn', false); showError(msg); }
			);
		});
	}

	// Preview viewport tabs
	$$('.shseq-pvp-tab').forEach(function (tab) {
		tab.addEventListener('click', function () {
			$$('.shseq-pvp-tab').forEach(function (t) { t.classList.remove('shseq-pvp-tab--active'); t.setAttribute('aria-selected', 'false'); });
			tab.classList.add('shseq-pvp-tab--active');
			tab.setAttribute('aria-selected', 'true');
			var wrap = $('#shseq-preview-frame-wrap');
			if (wrap) wrap.dataset.pvp = tab.dataset.pvp;
		});
	});

	// Shortcode copy
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.shseq-copy-shortcode');
		if (!btn) return;
		var code = ($('#shseq-sc-code') || {}).textContent || '';
		if (!code) return;
		if (navigator.clipboard) {
			navigator.clipboard.writeText(code).then(function () { showToast(i18n.copied || 'Copied!', 'success'); });
		} else {
			showToast(i18n.copy_failed || 'Copy failed.', 'error');
		}
	});

	// ── Escape helper ─────────────────────────────────────────────────────
	function esc(s) {
		return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	// ── Init ──────────────────────────────────────────────────────────────

	// If entering at step > 1 (e.g. from TemplatesPage), go directly
	if (currentStep > 1) {
		goToStep(currentStep);
	}

	// Initial undo snapshot
	saveUndoSnapshot();

}());
