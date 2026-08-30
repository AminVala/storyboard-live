/**
 * StoryBoard Live — Wizard v3 Overlay (Exposed Init) — FULL FILE REPLACEMENT
 *
 * این فایل جایگزین کامل wizard-v3-overlay.js است.
 * تفاوت اصلی با نسخه قبلی:
 *  1. window.shseqOverlayInitStep3 = initStep3 (برای hotfix قابل فراخوانی)
 *  2. اگر صفحه مستقیم روی step 3 باز شود auto-init می‌کند
 *  3. بقیه کد عیناً همان نسخه قبلی است (drag/resize/undo کامل)
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

    function t(key, en, fa) {
        if (i18n[key]) return i18n[key];
        return isFa ? (fa || en) : en;
    }

    var currentStep = cfg.currentStep || 1;
    var toastTimer;
    var toastEl     = document.getElementById('shseq-toast');
    var errorBanner = document.getElementById('shseq-wiz-error');

    function $(sel, ctx) { return (ctx || document).querySelector(sel); }
    function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

    function showToast(msg, type) {
        if (!toastEl) return;
        clearTimeout(toastTimer);
        toastEl.textContent = msg;
        toastEl.className   = 'shseq-toast is-visible' +
            (type === 'error' ? ' is-error' : type === 'success' ? ' is-success' : '');
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
                    var msg = (res.data && res.data.message) ||
                        t('error_generic', 'An error occurred.', 'خطایی رخ داد.');
                    if (onError) onError(msg, res.data); else showError(msg);
                }
            })
            .catch(function (e) {
                var msg = e.message || t('error_network', 'Network error.', 'خطای شبکه.');
                if (onError) onError(msg); else showError(msg);
            });
    }

    // ── Step navigation (also called from wizard-v3.js via window.shseqGoToStep) ──
    function goToStep(n) {
        $$('.shseq-wiz-panel').forEach(function (p) {
            var pStep = parseInt(p.dataset.step, 10);
            p.classList.toggle('is-active', pStep === n);
            p.setAttribute('aria-hidden', pStep === n ? 'false' : 'true');
        });
        $$('.shseq-wiz-step').forEach(function (s) {
            var sN = parseInt(s.dataset.step, 10);
            s.classList.toggle('is-done',   sN < n);
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

    window.shseqGoToStep = goToStep;

    // ═══════════════════════════════════════════════════════════════════════
    // STEP 3 — Canvas Overlay Editor
    // ═══════════════════════════════════════════════════════════════════════

    var canvasWrap     = null;
    var canvasImg      = null;
    var overlayLayer   = null;
    var undoStack      = [];
    var redoStack      = [];
    var MAX_UNDO       = 20;
    var selectedEl     = null;
    var dragState      = null;
    var resizeState    = null;
    var activeViewport = 'desktop';

    var VIEWPORTS = {
        desktop: { w: 1280, h: 720,  scale: 1.0 },
        tablet:  { w: 768,  h: 1024, scale: 0.6 },
        mobile:  { w: 375,  h: 812,  scale: 0.29 }
    };

    var step3Inited = false;

    function initStep3() {
        if (step3Inited) { loadExistingOverlays(); return; }
        step3Inited = true;

        canvasWrap   = $('#shseq-canvas-wrap');
        canvasImg    = $('#shseq-canvas-img');
        overlayLayer = $('#shseq-overlay-layer');

        if (!canvasWrap || !canvasImg || !overlayLayer) {
            showError(t('step3_missing', 'Canvas elements not found.', 'عناصر canvas یافت نشد. صفحه را مجدداً بارگذاری کنید.'));
            return;
        }

        // Viewport switcher
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

        // Toolbar buttons
        var addTitle = $('#shseq-add-title');
        var addPara  = $('#shseq-add-para');
        var addCta   = $('#shseq-add-cta');
        var undoBtn  = $('#shseq-undo');
        var redoBtn  = $('#shseq-redo');
        if (addTitle) addTitle.addEventListener('click', function () { addOverlay('title', isFa ? 'عنوان اصلی' : 'Main Title'); });
        if (addPara)  addPara.addEventListener('click',  function () { addOverlay('para',  isFa ? 'پاراگراف'   : 'Paragraph'); });
        if (addCta)   addCta.addEventListener('click',   function () { addOverlay('cta',   isFa ? 'دکمه CTA'  : 'CTA Button'); });
        if (undoBtn)  undoBtn.addEventListener('click', doUndo);
        if (redoBtn)  redoBtn.addEventListener('click', doRedo);

        // Extract overlays (GPT-4 Vision)
        var extractBtn = $('#shseq-extract-overlays');
        if (extractBtn) {
            extractBtn.addEventListener('click', function () {
                extractBtn.disabled    = true;
                extractBtn.textContent = isFa ? 'در حال استخراج…' : 'Extracting…';
                ajax('shseq_wiz_step3_extract',
                    { nonce: nonces.s3, post_id: postId },
                    function (data) {
                        extractBtn.disabled    = false;
                        extractBtn.textContent = isFa ? 'استخراج متون' : 'Extract Text';
                        if (data.overlays && data.overlays.length) {
                            data.overlays.forEach(function (ov) { addOverlayFromData(ov); });
                            pushUndo();
                        } else {
                            showToast(isFa ? 'متنی یافت نشد.' : 'No text found.', 'info');
                        }
                    },
                    function (msg) {
                        extractBtn.disabled    = false;
                        extractBtn.textContent = isFa ? 'استخراج متون' : 'Extract Text';
                        showToast(msg, 'error');
                    }
                );
            });
        }

        // Step 3 Save
        var s3SaveBtn = $('#shseq-s3-save');
        if (s3SaveBtn) {
            s3SaveBtn.addEventListener('click', function () {
                var data = collectOverlays();
                s3SaveBtn.disabled = true;
                ajax('shseq_wiz_step3_save',
                    { nonce: nonces.s3, post_id: postId, overlay_data: JSON.stringify(data) },
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

        loadFinalImage();
        setViewport('desktop');
        loadExistingOverlays();

        // Pointer events on overlay layer
        overlayLayer.addEventListener('mousedown', onLayerMouseDown);
        overlayLayer.addEventListener('touchstart', onLayerTouchStart, { passive: false });
        document.addEventListener('mousemove', onDocMouseMove);
        document.addEventListener('mouseup',   onDocMouseUp);
        document.addEventListener('touchmove', onDocTouchMove, { passive: false });
        document.addEventListener('touchend',  onDocTouchEnd);
    }

    // Expose so wizard-v3-hotfix.js and MutationObserver can call it
    window.shseqOverlayInitStep3 = initStep3;

    // Auto-init if page loaded directly on step 3
    if ((cfg.currentStep || 1) === 3) {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(initStep3, 80); });
    }

    // ── Viewport ───────────────────────────────────────────────────────────

    function setViewport(name) {
        activeViewport = name;
        var vp = VIEWPORTS[name] || VIEWPORTS.desktop;
        if (!canvasWrap) return;
        canvasWrap.style.width     = (vp.w * vp.scale) + 'px';
        canvasWrap.style.height    = (vp.h * vp.scale) + 'px';
        canvasWrap.dataset.viewport = name;
        if (canvasImg) {
            canvasImg.style.width    = '100%';
            canvasImg.style.height   = '100%';
            canvasImg.style.objectFit = 'cover';
        }
        if (overlayLayer) {
            overlayLayer.style.width  = '100%';
            overlayLayer.style.height = '100%';
        }
        $$('.shseq-ov', overlayLayer).forEach(function (el) { applyScale(el, vp.scale); });
    }

    function applyScale(el, scale) {
        var base = parseFloat(el.dataset.baseFontSize || 16);
        el.style.fontSize = (base * scale) + 'px';
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
            function () {}
        );
    }

    function loadExistingOverlays() {
        if (!overlayLayer) return;
        ajax('shseq_wiz_step3_extract',
            { nonce: nonces.s3, post_id: postId, mode: 'get_overlays_only' },
            function (data) {
                if (data && data.overlays && data.overlays.length) {
                    overlayLayer.innerHTML = '';
                    data.overlays.forEach(function (ov) { addOverlayFromData(ov, false); });
                }
            },
            function () {}
        );
    }

    // ── Overlay creation ────────────────────────────────────────────────────

    function addOverlay(type, text) {
        addOverlayFromData({
            type: type, text: text,
            x: 10, y: 10, w: 60,
            fontSize: type === 'title' ? 32 : (type === 'cta' ? 18 : 16)
        });
        pushUndo();
    }

    function addOverlayFromData(ov, focus) {
        if (!overlayLayer) return;
        var vp = VIEWPORTS[activeViewport] || VIEWPORTS.desktop;
        var el = document.createElement('div');
        el.className = 'shseq-ov shseq-ov--' + (ov.type || 'title');
        el.setAttribute('contenteditable', 'true');
        el.setAttribute('role', 'textbox');
        el.setAttribute('aria-multiline', 'true');
        el.setAttribute('aria-label', isFa ? 'متن overlay' : 'Overlay text');
        el.setAttribute('spellcheck', 'false');
        el.dataset.type         = ov.type || 'title';
        el.dataset.baseFontSize = ov.fontSize || 16;
        el.style.cssText = [
            'position:absolute',
            'left:'      + (ov.x || 10) + '%',
            'top:'       + (ov.y || 10) + '%',
            'width:'     + (ov.w || 60) + '%',
            'font-size:' + ((ov.fontSize || 16) * vp.scale) + 'px',
            'cursor:move',
            'user-select:text',
            'min-height:1.5em',
            'box-sizing:border-box'
        ].join(';');
        el.textContent = ov.text || '';

        // Resize handle
        var handle = document.createElement('span');
        handle.className = 'shseq-ov__resize';
        handle.setAttribute('aria-hidden', 'true');
        el.appendChild(handle);

        // Delete button
        var delBtn = document.createElement('button');
        delBtn.className = 'shseq-ov__delete';
        delBtn.type      = 'button';
        delBtn.setAttribute('aria-label', isFa ? 'حذف' : 'Delete');
        delBtn.textContent = '×';
        delBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            overlayLayer.removeChild(el);
            if (selectedEl === el) selectedEl = null;
            pushUndo();
        });
        el.appendChild(delBtn);

        el.addEventListener('mousedown', function (e) {
            if (e.target === handle || e.target === delBtn) return;
            selectOverlay(el);
        });
        el.addEventListener('touchstart', function (e) {
            if (e.target === handle || e.target === delBtn) return;
            selectOverlay(el);
        }, { passive: true });

        handle.addEventListener('mousedown', function (e) {
            e.preventDefault(); e.stopPropagation();
            startResize(el, e.clientX, e.clientY);
        });
        handle.addEventListener('touchstart', function (e) {
            e.preventDefault(); e.stopPropagation();
            var t = e.touches[0];
            startResize(el, t.clientX, t.clientY);
        }, { passive: false });

        el.addEventListener('blur', function () { pushUndo(); });

        overlayLayer.appendChild(el);
        if (focus !== false) { selectOverlay(el); el.focus(); }
    }

    function selectOverlay(el) {
        $$('.shseq-ov', overlayLayer).forEach(function (o) { o.classList.remove('is-selected'); });
        el.classList.add('is-selected');
        selectedEl = el;
    }

    function collectOverlays() {
        var result = [];
        $$('.shseq-ov', overlayLayer).forEach(function (el) {
            var txt = el.childNodes[0] ? el.childNodes[0].textContent : el.textContent;
            result.push({
                type:     el.dataset.type,
                text:     txt.trim(),
                x:        parseFloat(el.style.left)  || 0,
                y:        parseFloat(el.style.top)   || 0,
                w:        parseFloat(el.style.width)  || 60,
                fontSize: parseFloat(el.dataset.baseFontSize) || 16
            });
        });
        return result;
    }

    // ── Drag ─────────────────────────────────────────────────────────────────

    function onLayerMouseDown(e) {
        var el = e.target.closest ? e.target.closest('.shseq-ov') :
            (function (t) { while (t && !t.classList.contains('shseq-ov')) t = t.parentElement; return t; })(e.target);
        if (!el) return;
        if (e.target.classList.contains('shseq-ov__resize') ||
            e.target.classList.contains('shseq-ov__delete')) return;
        startDrag(el, e.clientX, e.clientY);
        e.preventDefault();
    }

    function onLayerTouchStart(e) {
        var el = e.target.closest ? e.target.closest('.shseq-ov') :
            (function (t) { while (t && !t.classList.contains('shseq-ov')) t = t.parentElement; return t; })(e.target);
        if (!el) return;
        if (e.target.classList.contains('shseq-ov__resize') ||
            e.target.classList.contains('shseq-ov__delete')) return;
        var touch = e.touches[0];
        startDrag(el, touch.clientX, touch.clientY);
        e.preventDefault();
    }

    function startDrag(el, x, y) {
        selectOverlay(el);
        dragState = {
            el: el,
            startX: x, startY: y,
            origLeft: parseFloat(el.style.left) || 0,
            origTop:  parseFloat(el.style.top)  || 0
        };
        el.style.cursor = 'grabbing';
    }

    function onDocMouseMove(e) {
        if (dragState)   moveDrag(e.clientX, e.clientY);
        if (resizeState) doResize(e.clientX, e.clientY);
    }
    function onDocTouchMove(e) {
        var t = e.touches[0];
        if (dragState)   moveDrag(t.clientX, t.clientY);
        if (resizeState) doResize(t.clientX, t.clientY);
        if (dragState || resizeState) e.preventDefault();
    }

    function moveDrag(x, y) {
        if (!dragState || !overlayLayer) return;
        var rect   = overlayLayer.getBoundingClientRect();
        var dxPx   = x - dragState.startX;
        var dyPx   = y - dragState.startY;
        var dxPct  = (dxPx / rect.width)  * 100;
        var dyPct  = (dyPx / rect.height) * 100;
        var newL   = Math.max(0, Math.min(90, dragState.origLeft + dxPct));
        var newT   = Math.max(0, Math.min(90, dragState.origTop  + dyPct));
        dragState.el.style.left = newL + '%';
        dragState.el.style.top  = newT + '%';
    }

    function onDocMouseUp() {
        if (dragState)   { dragState.el.style.cursor = 'move'; pushUndo(); dragState   = null; }
        if (resizeState) { pushUndo(); resizeState = null; }
    }
    function onDocTouchEnd() { onDocMouseUp(); }

    // ── Resize ──────────────────────────────────────────────────────────────

    function startResize(el, x, y) {
        selectOverlay(el);
        resizeState = {
            el:        el,
            startX:    x,
            origWidth: parseFloat(el.style.width) || 60
        };
    }

    function doResize(x) {
        if (!resizeState || !overlayLayer) return;
        var rect   = overlayLayer.getBoundingClientRect();
        var dxPx   = x - resizeState.startX;
        var dxPct  = (dxPx / rect.width) * 100;
        var newW   = Math.max(10, Math.min(100, resizeState.origWidth + dxPct));
        resizeState.el.style.width = newW + '%';
    }

    // ── Undo / Redo ─────────────────────────────────────────────────────────

    function snapshotHTML() {
        return overlayLayer ? overlayLayer.innerHTML : '';
    }

    function pushUndo() {
        if (!overlayLayer) return;
        undoStack.push(snapshotHTML());
        if (undoStack.length > MAX_UNDO) undoStack.shift();
        redoStack = [];
    }

    function doUndo() {
        if (!overlayLayer || undoStack.length < 2) return;
        redoStack.push(undoStack.pop());
        overlayLayer.innerHTML = undoStack[undoStack.length - 1];
        rebindOverlays();
    }

    function doRedo() {
        if (!overlayLayer || !redoStack.length) return;
        var snap = redoStack.pop();
        undoStack.push(snap);
        overlayLayer.innerHTML = snap;
        rebindOverlays();
    }

    function rebindOverlays() {
        // After innerHTML restore, re-attach interactive handlers
        $$('.shseq-ov', overlayLayer).forEach(function (el) {
            var handle = el.querySelector('.shseq-ov__resize');
            var delBtn = el.querySelector('.shseq-ov__delete');
            if (handle) {
                handle.addEventListener('mousedown', function (e) {
                    e.preventDefault(); e.stopPropagation();
                    startResize(el, e.clientX, e.clientY);
                });
            }
            if (delBtn) {
                delBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    overlayLayer.removeChild(el);
                    if (selectedEl === el) selectedEl = null;
                    pushUndo();
                });
            }
            el.addEventListener('mousedown', function (e) {
                if (e.target === handle || e.target === delBtn) return;
                selectOverlay(el);
            });
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // STEP 4 — Frame Generation
    // ═══════════════════════════════════════════════════════════════════════

    var genPollTimer = null;
    var step4Inited  = false;

    function initStep4() {
        if (step4Inited) return;
        step4Inited = true;

        var startBtn   = document.getElementById('shseq-s4-start');
        var progressEl = document.getElementById('shseq-gen-progress');
        var fillEl     = document.getElementById('shseq-gen-fill');
        var labelEl    = document.getElementById('shseq-gen-label');
        var s4NextBtn  = document.getElementById('shseq-s4-next');

        // ── Read mode from existingData (set by PHP via wp_localize_script) ─
        var existingData = cfg.existingData || {};
        var mode         = existingData.mode || '';

        // Clear any "AI prompt is empty" errors that don't apply to upload mode
        if (mode !== 'ai') {
            var errEl = document.getElementById('shseq-wiz-error');
            if (errEl) {
                var txt = errEl.textContent || '';
                if (txt.indexOf('هوش مصنوعی') !== -1 || txt.indexOf('AI prompt') !== -1 ||
                    txt.indexOf('دستور')        !== -1) {
                    errEl.setAttribute('hidden', '');
                    errEl.textContent = '';
                }
            }
        }

        function updateUI(status, percent, label) {
            if (fillEl)  { fillEl.style.width = percent + '%'; }
            if (labelEl) { labelEl.textContent = label; }
            if (progressEl) {
                progressEl.setAttribute('aria-valuenow', percent);
                progressEl.removeAttribute('hidden');
            }
            if (status === 'done') {
                if (s4NextBtn) s4NextBtn.disabled = false;
                showToast(isFa ? 'فریم‌ها آماده‌اند!' : 'Frames are ready!', 'success');
                clearInterval(genPollTimer);
            } else if (status === 'failed') {
                clearInterval(genPollTimer);
                var msg = isFa ? 'خطا در ساخت فریم‌ها.' : 'Frame generation failed.';
                showError(msg);
            }
        }

        function pollStatus() {
            ajax('shseq_wiz_step4_status',
                { nonce: nonces.s4, post_id: postId },
                function (data) {
                    updateUI(data.status, data.percent || 0, data.label || '');
                    if (data.status === 'done' || data.status === 'failed') {
                        clearInterval(genPollTimer);
                    }
                },
                function () {}
            );
        }

        if (startBtn) {
            startBtn.addEventListener('click', function () {
                startBtn.disabled = true;
                ajax('shseq_wiz_step4_start',
                    { nonce: nonces.s4, post_id: postId },
                    function (data) {
                        updateUI(data.status, data.percent || 5, data.label || '');
                        if (data.status !== 'done' && data.status !== 'failed') {
                            genPollTimer = setInterval(pollStatus, 2000);
                        }
                    },
                    function (msg) {
                        startBtn.disabled = false;
                        showError(msg);
                    }
                );
            });
        }

        if (s4NextBtn) {
            s4NextBtn.addEventListener('click', function () {
                goToStep(5);
            });
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // STEP 5 — Preview & Publish
    // ═══════════════════════════════════════════════════════════════════════

    var step5Inited = false;

    function initStep5() {
        if (step5Inited) return;
        step5Inited = true;

        var publishBtn  = document.getElementById('shseq-publish');
        var shortcodeEl = document.getElementById('shseq-shortcode');
        var copyBtn     = document.getElementById('shseq-copy-shortcode');

        if (copyBtn && shortcodeEl) {
            copyBtn.addEventListener('click', function () {
                var txt = shortcodeEl.textContent.trim();
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(txt).then(function () {
                        showToast(isFa ? 'شورتکد کپی شد!' : 'Shortcode copied!', 'success');
                    });
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = txt;
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    showToast(isFa ? 'شورتکد کپی شد!' : 'Shortcode copied!', 'success');
                }
            });
        }

        if (publishBtn) {
            publishBtn.addEventListener('click', function () {
                publishBtn.disabled = true;
                ajax('shseq_wiz_publish',
                    { nonce: nonces.publish, post_id: postId },
                    function (data) {
                        showToast(isFa ? 'سکانس منتشر شد!' : 'Sequence published!', 'success');
                        if (data && data.editUrl) {
                            setTimeout(function () { window.location.href = data.editUrl; }, 1500);
                        }
                    },
                    function (msg) {
                        publishBtn.disabled = false;
                        showError(msg);
                    }
                );
            });
        }
    }

})();
