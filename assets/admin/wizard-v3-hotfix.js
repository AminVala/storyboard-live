/**
 * StoryBoard Live — Wizard V3 Hotfix
 *
 * مشکلات برطرف‌شده:
 * 1. Step 3: canvas overlay interactive نمی‌شد (MutationObserver init)
 * 2. Step 4: "دستور هوش مصنوعی خالی است" برای mode=upload اشتباهاً نشان داده می‌شد
 * 3. Step 2: دکمه "ساخت تصویر" در حین generation disable نمی‌شد
 * 4. Step 2: metadata تصویر بعد از آپلود نشان داده نمی‌شد
 * 5. Step 1: badge "رایگان" روی قالب‌های Free نبود
 */
(function () {
    'use strict';

    var cfg    = window.shseqWizard || {};
    var isFa   = cfg.locale === 'fa';

    document.addEventListener('DOMContentLoaded', function () {

        // ── FIX 1: Step 3 Canvas — init via MutationObserver on panel ────────
        var step3Panel = document.getElementById('shseq-step-3');
        if (step3Panel && window.MutationObserver) {
            var canvasInited = false;

            function tryInitCanvas() {
                if (canvasInited) return;
                if (!step3Panel.classList.contains('is-active')) return;
                canvasInited = true;
                setTimeout(function () {
                    if (typeof window.shseqOverlayInitStep3 === 'function') {
                        window.shseqOverlayInitStep3();
                    }
                }, 60);
            }

            var step3Obs = new MutationObserver(function () { tryInitCanvas(); });
            step3Obs.observe(step3Panel, { attributes: true, attributeFilter: ['class'] });

            // Also fire immediately if page already starts on step 3
            tryInitCanvas();
        }

        // ── FIX 2: Step 4 — suppress AI prompt error for non-AI modes ────────
        var errorBanner = document.getElementById('shseq-wiz-error');
        var step4Panel  = document.getElementById('shseq-step-4');
        if (step4Panel && errorBanner && window.MutationObserver) {
            var step4Obs = new MutationObserver(function () {
                if (!step4Panel.classList.contains('is-active')) return;
                var mode = (cfg.existingData && cfg.existingData.mode) || '';
                if (mode === 'ai') return; // AI mode → show errors normally
                var text = errorBanner.textContent || '';
                var isAiError = text.indexOf('هوش مصنوعی') !== -1 ||
                                text.indexOf('AI prompt')   !== -1 ||
                                text.indexOf('ai prompt')   !== -1 ||
                                text.indexOf('دستور')        !== -1;
                if (isAiError) {
                    errorBanner.setAttribute('hidden', '');
                    errorBanner.textContent = '';
                }
            });
            step4Obs.observe(errorBanner, { childList: true, characterData: true, subtree: true });
            step4Obs.observe(errorBanner, { attributes: true, attributeFilter: ['hidden'] });
        }

        // ── FIX 3: Step 2 — AI Generate button disable during generation ──────
        var aiGenerateBtn = document.getElementById('shseq-ai-generate');
        if (aiGenerateBtn) {
            aiGenerateBtn.addEventListener('click', function () {
                // Disable the button immediately (wizard-v3.js re-enables on error/done)
                var btn = aiGenerateBtn;
                setTimeout(function () {
                    btn.disabled  = true;
                    btn.dataset.origText = btn.dataset.origText || btn.textContent.trim();
                    btn.textContent = isFa ? 'در حال ساخت…' : 'Generating…';
                }, 10);

                // Watch for spinner to be hidden → re-enable button
                var spinnerEl = document.querySelector('.shseq-ai-spinner, #shseq-ai-spinner');
                if (spinnerEl && window.MutationObserver) {
                    var spinObs = new MutationObserver(function () {
                        if (spinnerEl.hasAttribute('hidden') || spinnerEl.style.display === 'none') {
                            btn.disabled    = false;
                            btn.textContent = btn.dataset.origText || (isFa ? 'ساخت تصویر' : 'Generate Image');
                            spinObs.disconnect();
                        }
                    });
                    spinObs.observe(spinnerEl, { attributes: true, attributeFilter: ['hidden', 'style'] });
                }
            }, true); // capture phase
        }

        // ── FIX 4: Step 2 — Show filename + dimensions after image upload ─────
        var finalImg = document.getElementById('shseq-final-img');
        if (finalImg) {
            finalImg.addEventListener('load', function () {
                if (!finalImg.naturalWidth) return;
                var existing = document.getElementById('shseq-img-meta');
                if (existing) existing.remove();
                var meta = document.createElement('p');
                meta.id        = 'shseq-img-meta';
                meta.className = 'shseq-wiz-hint';
                meta.style.marginTop = '6px';
                var filename = finalImg.src ? finalImg.src.split('/').pop().split('?')[0] : '';
                meta.textContent = finalImg.naturalWidth + ' × ' + finalImg.naturalHeight + ' px'
                    + (filename ? ' — ' + decodeURIComponent(filename) : '');
                var preview = document.getElementById('shseq-final-preview');
                if (preview) { preview.appendChild(meta); }
                else { finalImg.insertAdjacentElement('afterend', meta); }
            });
        }

        // ── FIX 5: Step 1 — "رایگان / Free" badge on unlocked templates ───────
        document.querySelectorAll(
            '.shseq-tpl-card:not(.shseq-tpl-card--blank):not(.is-locked)'
        ).forEach(function (card) {
            var thumb = card.querySelector('.shseq-tpl-card__thumb');
            if (!thumb || thumb.querySelector('.shseq-tpl-free-badge')) return;
            thumb.style.position = thumb.style.position || 'relative';
            var badge = document.createElement('span');
            badge.className = 'shseq-tpl-free-badge';
            badge.textContent = isFa ? 'رایگان' : 'Free';
            badge.style.cssText = [
                'position:absolute', 'top:6px', 'left:6px',
                'background:#00a32a', 'color:#fff',
                'font-size:10px', 'font-weight:700',
                'padding:2px 6px', 'border-radius:3px', 'z-index:2'
            ].join(';');
            thumb.appendChild(badge);
        });
    });
})();
