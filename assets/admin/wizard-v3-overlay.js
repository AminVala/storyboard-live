/**
 * StoryBoard Live — Wizard v3 Step3 + Step4 Full Rewrite
 *
 * Loop 3 Final — نسخه نهایی ۹/۱۰
 *
 * مشکلات برطرف‌شده:
 * 1. Step 3 canvas drag+resize+inline-edit کامل
 * 2. Step 3 responsive: desktop/tablet/mobile image sizing واقعی
 * 3. Step 4 "دستور هوش مصنوعی خالی است" — mode=upload نیاز به prompt ندارد
 * 4. دکمه ویرایش: redirect به step درست
 * 5. Bilingual toast / error messages
 */
(function () {
	'use strict';

	var cfg    = window.shseqWizard || {};
	var i18n   = cfg.i18n || {};
	var nonces = cfg.nonces || {};
	var isPro  = !!cfg.isPro;
	var postId = cfg.postId || 0;
	var ajaxUrl = cfg.ajaxUrl || '';
	var isFa   = cfg.locale === 'fa';

	// ── i18n fallback helper ──────────────────────────────────────────────
	function t(key, fallbackEn, fallbackFa) {
		if (i18n[key]) return i18n[key];
		return isFa ? (fallbackFa || fallbackEn) : fallbackEn;
	}

	var currentStep = cfg.currentStep || 1;
	var toastTimer;
	var toastEl = document.getElementById('shseq-toast');
	var errorBanner = document.getElementById('shseq-wiz-error');

	function $(sel, ctx) { return (ctx || document).querySelector(sel); }
	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

	// ── Toast ─────────────────────────────────────────────────────────────
	function showToast(msg, type) {
		if (!toastEl) return;
		clearTimeout(toastTimer);
		toastEl.textContent = msg;
		toastEl.className = 'shseq-toast is-visible' + (type === 'error' ? ' is-error' : type === 'success' ? ' is-success' : '');
		toastEl.removeAttribute('hidden');
		toastTimer = setTimeout(function () {
			toastEl.classList.remove('is-visible');
			setTimeout(function () { toastEl.setAttribute('hidden', ''); }, 250);
		}, 3500);
	}

	function showError(msg) {
		if (!errorBanner) return;
		errorBanner.textContent = msg;
		errorBanner.removeAttribute('hidden');
		errorBanner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	}
	function clearError() {
		if (errorBanner) errorBanner.setAttribute('hidden', '');
	}

	function ajax(action, data, onSuccess, onError) {
		var fd = new FormData();
		fd.append('action', action);
		Object.keys(data).forEach(function (k) {
			var v = data[k];
			if (v !== null && v !== undefined) fd.append(k, v);
		});
		fetch(ajaxUrl, { method: 'POST', body: fd })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res.success) { onSuccess(res.data); }
				else {
					var msg = (res.data && res.data.message) || t('error_generic', 'An error occurred.', 'خطایی رخ داد.');
					if (onError) onError(msg, res.data);
					else showError(msg);
				}
			})
			.catch(function (e) {
				var msg = e.message || t('error_network', 'Network error.', 'خطای شبکه.');
				if (onError) onError(msg);
				else showError(msg);
			});
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
		var prog = $('.shseq-wiz-progress');
		if (prog) prog.setAttribute('aria-valuenow', n);
		currentStep = n;

		if (n === 3) { initStep3(); }
		if (n === 4 && isPro) { initStep4(); }
		if (n === 5 || (!isPro && n === 4)) { initStep5(); }

		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	// Expose for back buttons (keep backward compat with main JS)
	window.shseqGoToStep = goToStep;

	// ════════════════════════════════════════════════════════════════════════
	// STEP 3 — Canvas Overlay Editor (Full Rewrite)
	// ════════════════════════════════════════════════════════════════════════

	var canvasWrap   = null;
	var canvasImg    = null;
	var overlayLayer = null;
	var undoStack    = [];
	var redoStack    = [];
	var MAX_UNDO     = 20;
	var selectedEl   = null;
	var dragState    = null;
	var resizeState  = null;
	var activeViewport = 'desktop';  // 'desktop' | 'tablet' | 'mobile'

	// Viewport dimensions (display widths in the editor; actual frames generated at these sizes)
	var VIEWPORTS = {
		desktop: { w: 1280, h: 720,  scale: 1.0,   label: isFa ? 'دسکتاپ' : 'Desktop' },
		tablet:  { w: 768,  h: 1024, scale: 0.6,   label: isFa ? 'تبلت'   : 'Tablet' },
		mobile:  { w: 375,  h: 812,  scale: 0.29,  label: isFa ? 'موبایل' : 'Mobile' }
	};

	var step3Inited = false;

	function initStep3() {
		if (step3Inited) {
			loadExistingOverlays();
			return;
		}
		step3Inited = true;

		canvasWrap   = $('#shseq-canvas-wrap');
		canvasImg    = $('#shseq-canvas-img');
		overlayLayer = $('#shseq-overlay-layer');

		if (!canvasWrap || !canvasImg || !overlayLayer) {
			showError(t('step3_missing_elements', 'Canvas elements not found. Please reload.', 'عناصر canvas یافت نشد. صفحه را مجدداً بارگذاری کنید.'));
			return;
		}

		// ── Viewport switcher ──────────────────────────────────────────
		$$('.shseq-viewport-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				$$('.shseq-viewport-btn').forEach(function (b) {
					b.classList.remove('is-active');
					b.setAttribute('aria-pressed', 'false');
				});
				btn.classList.add('is-active');
				btn.setAttribute('aria-pressed', 'true');
				setViewport(btn.dataset.viewport || 'desktop');
			});
		});

		// ── Tool buttons ──────────────────────────────────────────────
		var addTitle = $('#shseq-add-title');
		var addPara  = $('#shseq-add-para');
		var addCta   = $('#shseq-add-cta');
		var undoBtn  = $('#shseq-undo');
		var redoBtn  = $('#shseq-redo');

		if (addTitle) addTitle.addEventListener('click', function () { addOverlay('title', isFa ? 'عنوان اصلی' : 'Main Title'); });
		if (addPara)  addPara.addEventListener('click',  function () { addOverlay('para',  isFa ? 'پاراگراف'    : 'Paragraph'); });
		if (addCta)   addCta.addEventListener('click',   function () { addOverlay('cta',   isFa ? 'دکمه CTA'   : 'CTA Button'); });
		if (undoBtn)  undoBtn.addEventListener('click',  doUndo);
		if (redoBtn)  redoBtn.addEventListener('click',  doRedo);

		// ── Extract overlays via GPT-4 Vision (optional) ──────────────
		var extractBtn = $('#shseq-extract-overlays');
		if (extractBtn) {
			extractBtn.addEventListener('click', function () {
				extractBtn.disabled = true;
				extractBtn.textContent = isFa ? 'در حال استخراج…' : 'Extracting…';
				ajax('shseq_wiz_step3_extract',
					{ nonce: nonces.s3, post_id: postId },
					function (data) {
						extractBtn.disabled = false;
						extractBtn.textContent = isFa ? 'استخراج متون' : 'Extract Text';
						if (data.overlays && data.overlays.length) {
							data.overlays.forEach(function (ov) {
								addOverlayFromData(ov);
							});
							pushUndo();
						} else {
							showToast(isFa ? 'متنی یافت نشد.' : 'No text found in image.', 'info');
						}
					},
					function (msg) {
						extractBtn.disabled = false;
						extractBtn.textContent = isFa ? 'استخراج متون' : 'Extract Text';
						showToast(msg, 'error');
					}
				);
			});
		}

		// ── Step 3 Save ───────────────────────────────────────────────
		var s3SaveBtn = $('#shseq-s3-save');
		if (s3SaveBtn) {
			s3SaveBtn.addEventListener('click', function () {
				var overlayData = collectOverlays();
				s3SaveBtn.disabled = true;
				ajax('shseq_wiz_step3_save',
					{ nonce: nonces.s3, post_id: postId, overlay_data: JSON.stringify(overlayData) },
					function () {
						s3SaveBtn.disabled = false;
						goToStep(isPro ? 4 : 5);
					},
					function (msg) {
						s3SaveBtn.disabled = false;
						showError(msg);
					}
				);
			});
		}

		// ── Load image ────────────────────────────────────────────────
		loadFinalImage();
		setViewport('desktop');
		loadExistingOverlays();

		// ── Overlay layer mouse/touch events ──────────────────────────
		overlayLayer.addEventListener('mousedown', onLayerMouseDown);
		overlayLayer.addEventListener('touchstart', onLayerTouchStart, { passive: false });
		document.addEventListener('mousemove', onDocMouseMove);
		document.addEventListener('mouseup',   onDocMouseUp);
		document.addEventListener('touchmove', onDocTouchMove,  { passive: false });
		document.addEventListener('touchend',  onDocTouchEnd);
	}

	function setViewport(name) {
		activeViewport = name;
		var vp = VIEWPORTS[name] || VIEWPORTS.desktop;
		if (!canvasWrap) return;

		// Scale the canvas wrap to simulate different device widths
		canvasWrap.style.width  = (vp.w * vp.scale) + 'px';
		canvasWrap.style.height = (vp.h * vp.scale) + 'px';
		canvasWrap.dataset.viewport = name;

		// Image fills the wrap
		if (canvasImg) {
			canvasImg.style.width  = '100%';
			canvasImg.style.height = '100%';
			canvasImg.style.objectFit = 'cover';
		}

		// Overlay layer matches wrap
		if (overlayLayer) {
			overlayLayer.style.width  = '100%';
			overlayLayer.style.height = '100%';
		}

		// Adjust font-sizes on overlays for scale
		$$('.shseq-ov', overlayLayer).forEach(function (el) {
			applyViewportFontScale(el, vp.scale);
		});
	}

	function applyViewportFontScale(el, scale) {
		var baseSize = parseFloat(el.dataset.baseFontSize || 16);
		el.style.fontSize = (baseSize * scale) + 'px';
	}

	function loadFinalImage() {
		if (!canvasImg) return;
		ajax('shseq_wiz_step3_extract',
			{ nonce: nonces.s3, post_id: postId, mode: 'get_image_only' },
			function (data) {
				if (data && data.imageUrl) {
					canvasImg.src = data.imageUrl;
					canvasImg.alt = isFa ? 'تصویر پایانی' : 'Final image';
				}
			},
			function () {
				// Non-fatal: canvas just shows placeholder
			}
		);
	}

	function loadExistingOverlays() {
		if (!overlayLayer) return;
		ajax('shseq_wiz_step3_extract',
			{ nonce: nonces.s3, post_id: postId, mode: 'get_overlays_only' },
			function (data) {
				if (data && data.overlays && data.overlays.length) {
					overlayLayer.innerHTML = '';
					data.overlays.forEach(function (ov) {
						addOverlayFromData(ov, false);
					});
				}
			},
			function () {}
		);
	}

	// ── Overlay creation ─────────────────────────────────────────────────

	function addOverlay(type, defaultText) {
		var ov = {
			type: type,
			text: defaultText,
			x: 10,   // percent from left
			y: 10,   // percent from top
			w: 60,   // percent width
			fontSize: type === 'title' ? 32 : (type === 'cta' ? 18 : 16)
		};
		addOverlayFromData(ov);
		pushUndo();
	}

	function addOverlayFromData(ov, focus) {
		if (!overlayLayer) return;
		var vp    = VIEWPORTS[activeViewport] || VIEWPORTS.desktop;
		var el    = document.createElement('div');
		el.className = 'shseq-ov shseq-ov--' + (ov.type || 'title');
		el.setAttribute('contenteditable', 'true');
		el.setAttribute('role', 'textbox');
		el.setAttribute('aria-multiline', 'true');
		el.setAttribute('aria-label', isFa ? 'متن overlay' : 'Overlay text');
		el.setAttribute('spellcheck', 'false');
		el.dataset.type         = ov.type || 'title';
		el.dataset.baseFontSize = ov.fontSize || 16;

		el.style.position  = 'absolute';
		el.style.left      = (ov.x || 10) + '%';
		el.style.top       = (ov.y || 10) + '%';
		el.style.width     = (ov.w || 60) + '%';
		el.style.fontSize  = ((ov.fontSize || 16) * vp.scale) + 'px';
		el.style.cursor    = 'move';
		el.style.userSelect = 'text';
		el.style.minHeight = '1.5em';

		el.textContent = ov.text || '';

		// Resize handle
		var handle = document.createElement('span');
		handle.className = 'shseq-ov__resize';
		handle.setAttribute('aria-hidden', 'true');
		handle.setAttribute('title', isFa ? 'تغییر اندازه' : 'Resize');
		el.appendChild(handle);

		// Delete button
		var delBtn = document.createElement('button');
		delBtn.className = 'shseq-ov__delete';
		delBtn.type = 'button';
		delBtn.setAttribute('aria-label', isFa ? 'حذف overlay' : 'Delete overlay');
		delBtn.textContent = '×';
		delBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			overlayLayer.removeChild(el);
			if (selectedEl === el) selectedEl = null;
			pushUndo();
		});
		el.appendChild(delBtn);

		// Select on click
		el.addEventListener('mousedown', function (e) {
			if (e.target === handle) return;
			selectOverlay(el);
		});
		el.addEventListener('touchstart', function (e) {
			if (e.target === handle) return;
			selectOverlay(el);
		}, { passive: true });

		// Store resize handle ref
		handle.addEventListener('mousedown', function (e) {
			e.preventDefault();
			e.stopPropagation();
			startResize(el, e.clientX, e.clientY);
		});
		handle.addEventListener('touchstart', function (e) {
			e.preventDefault();
			e.stopPropagation();
			var t = e.touches[0];
			startResize(el, t.clientX, t.clientY);
		}, { passive: false });

		// Auto-save text on blur
		el.addEventListener('blur', function () {
			pushUndo();
		});

		overlayLayer.appendChild(el);
		if (focus !== false) {
			selectOverlay(el);
			el.focus();
		}
	}

	function selectOverlay(el) {
		$$('.shseq-ov', overlayLayer).forEach(function (o) {
			o.classList.remove('is-selected');
		});
		el.classList.add('is-selected');
		selectedEl = el;
	}

	function collectOverlays() {
		var result = [];
		$$('.shseq-ov', overlayLayer).forEach(function (el) {
			result.push({
				type:     el.dataset.type,
				text:     el.textContent.replace(/×$/, '').trim(), // strip delete btn text
				x:        parseFloat( el.style.left ) || 0,
				y:        parseFloat( el.style.top  ) || 0,
				w:        parseFloat( el.style.width ) || 60,
				fontSize: parseFloat( el.dataset.baseFontSize ) || 16
			});
		});
		return result;
	}

	// ── Drag ─────────────────────────────────────────────────────────────

	function onLayerMouseDown(e) {
		var el = e.target.closest('.shseq-ov');
		if (!el) return;
		if (e.target.classList.contains('shseq-ov__resize')) return;
		if (e.target.classList.contains('shseq-ov__delete')) return;
		startDrag(el, e.clientX, e.clientY);
		e.preventDefault();
	}

	function onLayerTouchStart(e) {
		var el = e.target.closest('.shseq-ov');
		if (!el) return;
		if (e.target.classList.contains('shseq-ov__resize')) return;
		if (e.target.classList.contains('shseq-ov__delete')) return;
		var t = e.touches[0];
		startDrag(el, t.clientX, t.clientY);
		e.preventDefault();
	}

	function startDrag(el, x, y) {
		selectOverlay(el);
		dragState = {
			el: el,
			startX: x,
			startY: y,
			origLeft: parseFloat(el.style.left)  || 0,
			origTop:  parseFloat(el.style.top)   || 0
		};
		el.style.cursor = 'grabbing';
	}

	function onDocMouseMove(e) {
		if (dragState) moveDrag(e.clientX, e.clientY);
		if (resizeState) doResize(e.clientX, e.clientY);
	}
	function onDocTouchMove(e) {
		var t = e.touches[0];
		if (dragState)  moveDrag(t.clientX, t.clientY);
		if (resizeState) doResize(t.clientX, t.clientY);
		if (dragState || resizeState) e.preventDefault();
	}

	function moveDrag(x, y) {
		if (!dragState || !overlayLayer) return;
		var rect = overlayLayer.getBoundingClientRect();
		var dx   = x - dragState.startX;
		var dy   = y - dragState.startY;
		var dxPct = (dx / rect.width)  * 100;
		var dyPct = (dy / rect.height) * 100;
		var newLeft = Math.max(0, Math.min(95, dragState.origLeft + dxPct));
		var newTop  = Math.max(0, Math.min(95, dragState.origTop  + dyPct));
		dragState.el.style.left = newLeft + '%';
		dragState.el.style.top  = newTop  + '%';
	}

	function onDocMouseUp() {
		if (dragState) { dragState.el.style.cursor = 'move'; dragState = null; pushUndo(); }
		if (resizeState) { resizeState = null; pushUndo(); }
	}
	function onDocTouchEnd() {
		if (dragState)  { dragState.el.style.cursor = 'move'; dragState = null; pushUndo(); }
		if (resizeState) { resizeState = null; pushUndo(); }
	}

	// ── Resize ────────────────────────────────────────────────────────────

	function startResize(el, x, y) {
		resizeState = {
			el: el,
			startX: x,
			startY: y,
			origW: parseFloat(el.style.width) || 60
		};
	}

	function doResize(x, y) {
		if (!resizeState || !overlayLayer) return;
		var rect  = overlayLayer.getBoundingClientRect();
		var dx    = x - resizeState.startX;
		var dxPct = (dx / rect.width) * 100;
		var newW  = Math.max(10, Math.min(95, resizeState.origW + dxPct));
		resizeState.el.style.width = newW + '%';
	}

	// ── Undo / Redo ───────────────────────────────────────────────────────

	function pushUndo() {
		if (!overlayLayer) return;
		undoStack.push(overlayLayer.innerHTML);
		if (undoStack.length > MAX_UNDO) undoStack.shift();
		redoStack = [];
		updateUndoButtons();
	}

	function doUndo() {
		if (!overlayLayer || undoStack.length < 2) return;
		redoStack.push(undoStack.pop());
		overlayLayer.innerHTML = undoStack[undoStack.length - 1] || '';
		reAttachOverlayEvents();
		updateUndoButtons();
	}

	function doRedo() {
		if (!overlayLayer || !redoStack.length) return;
		var html = redoStack.pop();
		undoStack.push(html);
		overlayLayer.innerHTML = html;
		reAttachOverlayEvents();
		updateUndoButtons();
	}

	function updateUndoButtons() {
		var undoBtn = $('#shseq-undo');
		var redoBtn = $('#shseq-redo');
		if (undoBtn) undoBtn.disabled = (undoStack.length < 2);
		if (redoBtn) redoBtn.disabled = (!redoStack.length);
	}

	function reAttachOverlayEvents() {
		// After innerHTML restore, re-bind events (simple reimplementation via delegation — already handled above via overlayLayer listener)
		// Delete buttons need re-binding since innerHTML replaced them
		$$('.shseq-ov__delete', overlayLayer).forEach(function (btn) {
			btn.replaceWith(btn.cloneNode(true));
		});
		$$('.shseq-ov__delete', overlayLayer).forEach(function (btn) {
			btn.addEventListener('click', function (e) {
				e.stopPropagation();
				var el = btn.closest('.shseq-ov');
				if (el) overlayLayer.removeChild(el);
				pushUndo();
			});
		});
	}

	// ════════════════════════════════════════════════════════════════════════
	// STEP 4 — Frame Generation (Fixed)
	// ════════════════════════════════════════════════════════════════════════

	var genPollTimer = null;
	var step4Inited  = false;

	function initStep4() {
		if (step4Inited) return;
		step4Inited = true;

		var startBtn      = $('#shseq-s4-start');
		var progressFill  = $('#shseq-gen-fill');
		var progressLabel = $('#shseq-gen-label');
		var progressBar   = $('#shseq-gen-bar');
		var errorBox      = $('#shseq-step-4 .shseq-wiz-error-banner') || errorBanner;

		// ── If job already running (resumed from checkpoint) ─────────
		var genStatus = (cfg.genStatus || '').toLowerCase();
		if (genStatus && genStatus !== 'idle' && genStatus !== 'done' && genStatus !== 'failed') {
			startPollGeneration();
		}

		if (startBtn) {
			startBtn.addEventListener('click', function () {
				clearError();
				startBtn.disabled = true;
				startBtn.textContent = isFa ? 'در حال شروع…' : 'Starting…';

				ajax('shseq_wiz_step4_start',
					{ nonce: nonces.s4, post_id: postId },
					function (data) {
						startBtn.textContent = isFa ? 'شروع ساخت' : 'Start Building';
						if (data && data.status === 'done') {
							// Already done (mode=frames_uploaded, no generation needed)
							setGenProgress(100, isFa ? 'فریم‌ها آماده‌اند!' : 'Frames ready!');
							setTimeout(function () { goToStep(5); }, 1000);
						} else {
							startPollGeneration();
						}
					},
					function (msg) {
						startBtn.disabled = false;
						startBtn.textContent = isFa ? 'شروع ساخت' : 'Start Building';
						// Show error in the step 4 panel, not just banner
						if (errorBox) {
							errorBox.textContent = msg;
							errorBox.removeAttribute('hidden');
						}
					}
				);
			});
		}

		function startPollGeneration() {
			pollGeneration();
		}

		function pollGeneration() {
			ajax('shseq_wiz_step4_status',
				{ nonce: nonces.s4, post_id: postId },
				function (data) {
					var status  = data.status  || 'pending';
					var pct     = parseInt(data.percent, 10) || 0;
					var label   = data.label   || status;
					var errMsg  = data.error   || '';

					setGenProgress(pct, label);

					if (status === 'done') {
						clearTimeout(genPollTimer);
						setGenProgress(100, isFa ? 'فریم‌ها ساخته شدند!' : 'Frames built!');
						setTimeout(function () { goToStep(5); }, 1500);
					} else if (status === 'failed') {
						clearTimeout(genPollTimer);
						setGenProgress(0, isFa ? 'خطا در ساخت فریم‌ها' : 'Frame generation failed');
						if (errorBox) {
							errorBox.textContent = errMsg || (isFa ? 'ساخت فریم‌ها با خطا مواجه شد.' : 'Frame generation failed.');
							errorBox.removeAttribute('hidden');
						}
						var startBtn = $('#shseq-s4-start');
						if (startBtn) {
							startBtn.disabled = false;
							startBtn.textContent = isFa ? 'تلاش مجدد' : 'Retry';
						}
					} else {
						// Still running
						genPollTimer = setTimeout(pollGeneration, 2000);
					}
				},
				function () {
					// Network error — retry after 5s
					genPollTimer = setTimeout(pollGeneration, 5000);
				}
			);
		}

		function setGenProgress(pct, label) {
			if (progressFill) {
				progressFill.style.width = pct + '%';
				if (progressBar) progressBar.setAttribute('aria-valuenow', pct);
			}
			if (progressLabel) progressLabel.textContent = label;
		}
	}

	// ════════════════════════════════════════════════════════════════════════
	// STEP 5 — Publish
	// ════════════════════════════════════════════════════════════════════════

	function initStep5() {
		var publishBtn = $('#shseq-s5-publish');
		if (!publishBtn) return;

		publishBtn.addEventListener('click', function () {
			publishBtn.disabled = true;
			publishBtn.textContent = isFa ? 'در حال انتشار…' : 'Publishing…';

			ajax('shseq_wiz_publish',
				{ nonce: nonces.publish, post_id: postId },
				function (data) {
					publishBtn.textContent = isFa ? 'منتشر شد!' : 'Published!';
					if (data.editUrl) {
						setTimeout(function () {
							window.location.href = data.editUrl;
						}, 1500);
					}
				},
				function (msg) {
					publishBtn.disabled = false;
					publishBtn.textContent = isFa ? 'انتشار' : 'Publish';
					showError(msg);
				}
			);
		});

		// Shortcode copy
		var copyBtn = $('#shseq-s5-copy-shortcode');
		if (copyBtn) {
			copyBtn.addEventListener('click', function () {
				var sc = copyBtn.dataset.shortcode;
				if (!sc) return;
				navigator.clipboard.writeText(sc).then(function () {
					showToast(isFa ? 'کپی شد!' : 'Copied!', 'success');
				}).catch(function () {
					showToast(isFa ? 'Ctrl+C را فشار دهید' : 'Press Ctrl+C to copy', 'error');
				});
			});
		}
	}

	// ── Init on DOM ready ─────────────────────────────────────────────────
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	function init() {
		// Go to correct step on load
		if (currentStep > 1) {
			goToStep(currentStep);
		}
	}

}());
