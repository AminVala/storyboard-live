/**
 * StoryBoard Live — Start Config Panel
 * ─────────────────────────────────────────────────────────────
 * رندر و مدیریت step تنظیم انیمیشن (START_CONFIG)
 * نمایش preview real-time انیمیشن با CSS transform
 *
 * این فایل توسط wizard.js فراخوانی می‌شود.
 * بدون framework — Vanilla JS ES2022
 */

;(function (window) {
  'use strict';

  /**
   * رندر پنل START_CONFIG
   *
   * @param {object} cfg         shseqWizardConfig
   * @param {object} api         آبجکت api از wizard.js
   * @param {HTMLElement} root   #shseq-wizard
   * @param {HTMLElement} wrap   #shseq-panel-wrap
   * @param {Function} navigate  تابع navigate(step) از wizard.js
   */
  window.shseqStartConfig = {

    /**
     * لیست پریست‌ها — از API بارگذاری می‌شود
     * @type {Array|null}
     */
    _presets: null,

    /**
     * مقادیر فعلی form
     */
    _current: {
      preset:      'zoom_out_center',
      zoom_factor: 1.5,
      pan_x_rel:   0.0,
      pan_y_rel:   0.0,
      blur_px:     0.0,
      frame_count: 36,
      easing:      'ease_in_out',
    },

    async render(cfg, api, root, wrap, navigate) {
      this._current = { ...this._current, ...(cfg.startConfig || {}) };

      // بارگذاری پریست‌ها از API
      if (!this._presets) {
        try {
          const res = await fetch(`${cfg.restUrl}/wizard/presets`, {
            headers: { 'X-WP-Nonce': cfg.restNonce },
          });
          this._presets = await res.json();
        } catch (e) {
          this._presets = this._fallbackPresets();
        }
      }

      wrap.innerHTML = this._buildHTML();

      // Preview تصویر Last Frame
      const previewImg = document.querySelector('#shseq-sc-preview-img');
      if (previewImg && cfg.lastFrameUrl) {
        previewImg.src = cfg.lastFrameUrl;
        previewImg.style.display = '';
      }

      this._bindEvents(cfg, api, root, wrap, navigate);
      this._updatePreview();
      this._toggleAdvanced(false);
    },

    _buildHTML() {
      const presetsHtml = (this._presets || this._fallbackPresets()).map(p => `
        <label class="shseq-preset-card ${this._current.preset === p.value ? 'selected' : ''}" data-preset="${p.value}">
          <input type="radio" name="shseq_preset" value="${p.value}" ${this._current.preset === p.value ? 'checked' : ''} style="display:none">
          <div class="preset-icon">${this._presetIcon(p.value)}</div>
          <div class="preset-label">${p.label}</div>
        </label>
      `).join('');

      return `
        <div class="shseq-panel">
          <h2 class="shseq-panel-title">⚙️ تنظیم انیمیشن</h2>
          <p class="shseq-panel-desc">
            انتخاب کنید که سکانس چگونه شروع شود. <strong>Frame آخر (Last Frame)</strong> همیشه ثابت
            است — این تنظیمات فقط نقطه شروع را تغییر می‌دهند.
          </p>

          <!-- Preview پویا -->
          <div style="display:flex;gap:20px;margin-bottom:24px;flex-wrap:wrap">
            <div style="flex:0 0 280px">
              <div id="shseq-sc-preview-wrap" style="position:relative;overflow:hidden;border-radius:8px;border:1px solid #c3c4c7;background:#000;aspect-ratio:16/9;max-width:280px">
                <img id="shseq-sc-preview-img" src="" alt="" style="width:100%;height:100%;object-fit:cover;display:none;transform-origin:center center;transition:transform 1.5s ease-in-out">
                <div style="position:absolute;bottom:6px;left:6px;background:rgba(0,0,0,.6);color:#fff;font-size:10px;padding:2px 8px;border-radius:3px">
                  پیش‌نمایش frame 0
                </div>
              </div>
              <div id="shseq-sc-animate-btn-wrap" style="margin-top:8px;text-align:center">
                <button id="shseq-sc-animate" class="shseq-btn shseq-btn-outline" style="font-size:12px;padding:5px 14px">
                  ▶ نمایش انیمیشن
                </button>
              </div>
            </div>

            <div style="flex:1;min-width:220px">
              <!-- پریست‌ها -->
              <div style="margin-bottom:16px">
                <label style="font-size:13px;font-weight:600;color:#1d2327;display:block;margin-bottom:8px">نوع حرکت:</label>
                <div class="shseq-preset-grid" id="shseq-preset-grid">
                  ${presetsHtml}
                </div>
              </div>

              <!-- تعداد فریم -->
              <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                <label style="font-size:13px;font-weight:500;color:#1d2327;white-space:nowrap">تعداد فریم‌ها:</label>
                <input type="range" id="shseq-frame-count" min="8" max="120" step="4" value="${this._current.frame_count}"
                  style="flex:1">
                <span id="shseq-frame-count-val" style="font-size:13px;font-weight:600;min-width:30px;text-align:center">${this._current.frame_count}</span>
              </div>

              <!-- easing -->
              <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                <label style="font-size:13px;font-weight:500;color:#1d2327;white-space:nowrap">نوع حرکت:</label>
                <select id="shseq-easing" style="border:1px solid #c3c4c7;border-radius:4px;padding:4px 8px;font-size:13px;flex:1">
                  <option value="ease_in_out" ${this._current.easing === 'ease_in_out' ? 'selected' : ''}>نرم (ease in-out)</option>
                  <option value="ease_out"    ${this._current.easing === 'ease_out'    ? 'selected' : ''}>شتاب به آرامی (ease out)</option>
                  <option value="ease_in"     ${this._current.easing === 'ease_in'     ? 'selected' : ''}>شتاب تدریجی (ease in)</option>
                  <option value="linear"      ${this._current.easing === 'linear'      ? 'selected' : ''}>ثابت (linear)</option>
                </select>
              </div>
            </div>
          </div>

          <!-- تنظیمات پیشرفته (قابل toggle) -->
          <button id="shseq-sc-advanced-toggle" style="background:none;border:none;color:#2271b1;font-size:12px;cursor:pointer;padding:0;margin-bottom:12px">
            ▼ تنظیمات پیشرفته (zoom، pan، blur)
          </button>

          <div id="shseq-sc-advanced" style="display:none;background:#f8f9fa;border:1px solid #c3c4c7;border-radius:8px;padding:16px;margin-bottom:16px">

            <!-- Zoom -->
            <div id="shseq-sc-zoom-row" style="margin-bottom:12px">
              <label style="font-size:12px;font-weight:500;display:block;margin-bottom:4px">
                زوم اولیه (frame 0): <span id="shseq-zoom-val">${this._current.zoom_factor}×</span>
              </label>
              <input type="range" id="shseq-zoom" min="1.0" max="3.0" step="0.05" value="${this._current.zoom_factor}" style="width:100%">
            </div>

            <!-- Pan X -->
            <div id="shseq-sc-panx-row" style="margin-bottom:12px">
              <label style="font-size:12px;font-weight:500;display:block;margin-bottom:4px">
                آفست افقی (pan X): <span id="shseq-panx-val">${this._current.pan_x_rel > 0 ? '+' : ''}${Math.round(this._current.pan_x_rel * 100)}٪</span>
              </label>
              <input type="range" id="shseq-panx" min="-0.5" max="0.5" step="0.025" value="${this._current.pan_x_rel}" style="width:100%">
            </div>

            <!-- Pan Y -->
            <div id="shseq-sc-pany-row" style="margin-bottom:12px">
              <label style="font-size:12px;font-weight:500;display:block;margin-bottom:4px">
                آفست عمودی (pan Y): <span id="shseq-pany-val">${this._current.pan_y_rel > 0 ? '+' : ''}${Math.round(this._current.pan_y_rel * 100)}٪</span>
              </label>
              <input type="range" id="shseq-pany" min="-0.5" max="0.5" step="0.025" value="${this._current.pan_y_rel}" style="width:100%">
            </div>

            <!-- Blur -->
            <div id="shseq-sc-blur-row" style="margin-bottom:4px">
              <label style="font-size:12px;font-weight:500;display:block;margin-bottom:4px">
                blur اولیه: <span id="shseq-blur-val">${this._current.blur_px}px</span>
              </label>
              <input type="range" id="shseq-blur" min="0" max="30" step="1" value="${this._current.blur_px}" style="width:100%">
            </div>
          </div>

          <div class="shseq-actions">
            <button id="shseq-sc-save" class="shseq-btn shseq-btn-outline">ذخیره تنظیمات</button>
            <button id="shseq-sc-next" class="shseq-btn shseq-btn-primary">تأیید و شروع ساخت فریم‌ها ←</button>
          </div>
        </div>

        <style>
          .shseq-preset-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 8px;
          }
          .shseq-preset-card {
            border: 2px solid #c3c4c7;
            border-radius: 6px;
            padding: 8px 6px;
            cursor: pointer;
            text-align: center;
            transition: border-color 150ms, background 150ms;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
          }
          .shseq-preset-card:hover { border-color: #2271b1; background: #f0f7ff; }
          .shseq-preset-card.selected { border-color: #2271b1; background: #e8f0fb; }
          .shseq-preset-card .preset-icon { font-size: 20px; }
          .shseq-preset-card .preset-label { font-size: 10px; color: #1d2327; font-weight: 500; line-height: 1.3; }
        </style>
      `;
    },

    _bindEvents(cfg, api, root, wrap, navigate) {
      const $ = (sel) => wrap.querySelector(sel);
      const $$ = (sel) => [...wrap.querySelectorAll(sel)];

      // پریست‌ها
      $$('.shseq-preset-card').forEach(card => {
        card.addEventListener('click', () => {
          $$('.shseq-preset-card').forEach(c => c.classList.remove('selected'));
          card.classList.add('selected');
          this._current.preset = card.dataset.preset;
          card.querySelector('input[type=radio]').checked = true;

          // پر کردن defaults پریست
          const preset = (this._presets || []).find(p => p.value === this._current.preset);
          if (preset) {
            this._current.zoom_factor = preset.defaults.zoom;
            this._current.pan_x_rel  = preset.defaults.pan_x;
            this._current.pan_y_rel  = preset.defaults.pan_y;
            this._current.blur_px    = preset.defaults.blur;
            this._syncSliders(wrap);
          }
          this._toggleAdvanced(false, wrap, preset);
          this._updatePreview();
        });
      });

      // Sliders
      const bindRange = (id, key, display, transform) => {
        const el = $(`#${id}`);
        if (!el) return;
        el.addEventListener('input', () => {
          this._current[key] = parseFloat(el.value);
          const label = $(`#${display}`);
          if (label) label.textContent = transform(el.value);
          this._updatePreview();
        });
      };

      bindRange('shseq-frame-count', 'frame_count', 'shseq-frame-count-val', v => v);
      bindRange('shseq-zoom',  'zoom_factor', 'shseq-zoom-val',  v => parseFloat(v).toFixed(2) + '×');
      bindRange('shseq-panx',  'pan_x_rel',   'shseq-panx-val',  v => { const n = Math.round(v*100); return (n>0?'+':'') + n + '٪'; });
      bindRange('shseq-pany',  'pan_y_rel',   'shseq-pany-val',  v => { const n = Math.round(v*100); return (n>0?'+':'') + n + '٪'; });
      bindRange('shseq-blur',  'blur_px',      'shseq-blur-val',  v => v + 'px');

      const easingEl = $(`#shseq-easing`);
      if (easingEl) {
        easingEl.addEventListener('change', () => {
          this._current.easing = easingEl.value;
        });
      }

      // Advanced toggle
      const advToggle = $(`#shseq-sc-advanced-toggle`);
      const advPanel  = $(`#shseq-sc-advanced`);
      if (advToggle && advPanel) {
        advToggle.addEventListener('click', () => {
          const visible = advPanel.style.display !== 'none';
          advPanel.style.display = visible ? 'none' : '';
          advToggle.textContent = (visible ? '▼' : '▲') + ' تنظیمات پیشرفته (zoom، pan، blur)';
        });
      }

      // Preview animation
      const animBtn = $(`#shseq-sc-animate`);
      if (animBtn) {
        animBtn.addEventListener('click', () => this._playAnimation(wrap));
      }

      // Save
      $(`#shseq-sc-save`)?.addEventListener('click', async () => {
        try {
          await api.post('start-config', this._current);
          // flash success
          const btn = $(`#shseq-sc-save`);
          if (btn) { const t = btn.textContent; btn.textContent = '✓ ذخیره شد'; setTimeout(() => btn.textContent = t, 2000); }
        } catch (e) { alert('خطا در ذخیره: ' + e.message); }
      });

      // Next
      $(`#shseq-sc-next`)?.addEventListener('click', async () => {
        try {
          await api.post('start-config', { ...this._current, confirm: true });
          navigate('frame_generate');
        } catch (e) { alert('خطا: ' + e.message); }
      });
    },

    _syncSliders(wrap) {
      const sync = (id, val) => {
        const el = wrap?.querySelector(`#${id}`);
        if (el) el.value = val;
      };
      sync('shseq-zoom',  this._current.zoom_factor);
      sync('shseq-panx',  this._current.pan_x_rel);
      sync('shseq-pany',  this._current.pan_y_rel);
      sync('shseq-blur',  this._current.blur_px);

      // update labels
      const l = (id, text) => { const el = wrap?.querySelector(`#${id}`); if (el) el.textContent = text; };
      l('shseq-zoom-val',  this._current.zoom_factor.toFixed(2) + '×');
      l('shseq-panx-val',  (this._current.pan_x_rel >= 0 ? '+' : '') + Math.round(this._current.pan_x_rel * 100) + '٪');
      l('shseq-pany-val',  (this._current.pan_y_rel >= 0 ? '+' : '') + Math.round(this._current.pan_y_rel * 100) + '٪');
      l('shseq-blur-val',  this._current.blur_px + 'px');
    },

    /** به‌روزرسانی CSS transform preview */
    _updatePreview() {
      const img = document.querySelector('#shseq-sc-preview-img');
      if (!img) return;

      const { zoom_factor, pan_x_rel, blur_px } = this._current;

      // نمایش frame 0 با CSS transform
      const scale = zoom_factor;
      const tx    = -(pan_x_rel * 50); // percent
      const blurV = blur_px > 0 ? `blur(${blur_px * 0.6}px)` : '';

      img.style.transform = `scale(${scale}) translateX(${tx}%)`;
      img.style.filter    = blurV;
    },

    /** نمایش انیمیشن CSS — از frame 0 به frame N */
    _playAnimation(wrap) {
      const img = document.querySelector('#shseq-sc-preview-img');
      if (!img) return;

      const { zoom_factor, pan_x_rel, blur_px, easing } = this._current;
      const cssEasing = { ease_in_out: 'ease-in-out', ease_out: 'ease-out', ease_in: 'ease-in', linear: 'linear' }[easing] || 'ease-in-out';

      // ابتدا frame 0
      img.style.transition = 'none';
      img.style.transform  = `scale(${zoom_factor}) translateX(${-pan_x_rel * 50}%)`;
      img.style.filter     = blur_px > 0 ? `blur(${blur_px * 0.6}px)` : '';

      // انیمیشن به frame N
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          img.style.transition = `transform 2s ${cssEasing}, filter 2s ${cssEasing}`;
          img.style.transform  = 'scale(1) translateX(0%)';
          img.style.filter     = '';
        });
      });

      // بازگشت به frame 0 بعد از ۳ ثانیه
      setTimeout(() => {
        img.style.transition = 'none';
        this._updatePreview();
      }, 3000);
    },

    _toggleAdvanced(show, wrap, preset) {
      const adv = (wrap || document).querySelector('#shseq-sc-advanced');
      if (!adv) return;

      // اگر پریست blur دارد → advanced را نشان بده
      if (preset?.hasBlur) {
        adv.style.display = '';
      }
    },

    _presetIcon(value) {
      const icons = {
        zoom_out_center: '🔍',
        zoom_out_pan_lr: '↔️',
        zoom_out_pan_rl: '↔️',
        zoom_out_pan_tb: '↕️',
        blur_reveal:     '✨',
        pan_from_left:   '⬅️',
        pan_from_right:  '➡️',
        pan_from_top:    '⬆️',
      };
      return icons[value] || '🎬';
    },

    _fallbackPresets() {
      return [
        { value: 'zoom_out_center', label: 'زوم مرکز', hasZoom: true, hasBlur: false, defaults: { zoom: 1.5, pan_x: 0, pan_y: 0, blur: 0 } },
        { value: 'zoom_out_pan_lr', label: 'زوم + چپ به راست', hasZoom: true, hasBlur: false, defaults: { zoom: 1.4, pan_x: -0.25, pan_y: 0, blur: 0 } },
        { value: 'blur_reveal',     label: 'نمایش از blur', hasZoom: false, hasBlur: true,  defaults: { zoom: 1.0, pan_x: 0, pan_y: 0, blur: 12 } },
        { value: 'pan_from_left',   label: 'ورود از چپ', hasZoom: false, hasBlur: false,  defaults: { zoom: 1.0, pan_x: -0.35, pan_y: 0, blur: 0 } },
      ];
    },
  };

})(window);
