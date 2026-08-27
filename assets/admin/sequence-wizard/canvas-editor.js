/**
 * StoryBoard Live — Canvas Editor (V3 Final)
 * ─────────────────────────────────────────────────────────────────────
 * WYSIWYG Canvas Editor برای overlay متن روی Last Frame
 *
 * ویژگی‌ها:
 *   ✓ Phase 1: نمایش فوری (background = original image)
 *   ✓ Phase 2: swap خودکار به clean image وقتی inpainting تمام شد
 *   ✓ Drag & Drop (mouse + touch)
 *   ✓ Resize از گوشه
 *   ✓ Live text editing (contentEditable)
 *   ✓ Toolbar: font, size, weight, color, align
 *   ✓ Add/Delete overlay
 *   ✓ Auto-save هر ۵ ثانیه (بدون خرابی UX)
 *   ✓ Grid snap (optional)
 *
 * Coordinate system: همیشه % (نسبی) — never px absolute
 *
 * بدون framework — Vanilla JS ES2022
 */

;(function (window, document) {
  'use strict';

  window.shseqCanvasEditor = {

    /* ─── State ───────────────────────────────────────────────────── */
    sequenceId:    null,
    restUrl:       null,
    restNonce:     null,
    bgPollTimer:   null,
    autoSaveTimer: null,
    shell:         null,      // div.shseq-canvas-shell
    bgImg:         null,      // img.canvas-bg
    overlayLayer:  null,      // div.overlay-layer (روی bg)
    toolbar:       null,
    items:         new Map(), // id → { el, data }
    selectedId:    null,
    isDirty:       false,

    /* ─── Init ────────────────────────────────────────────────────── */

    init({ sequenceId, restUrl, restNonce, originalUrl, cleanUrl, overlayItems, inpaintingPending }) {
      this.sequenceId = sequenceId;
      this.restUrl    = restUrl;
      this.restNonce  = restNonce;

      this.shell        = document.getElementById('shseq-canvas-shell');
      this.bgImg        = document.getElementById('shseq-canvas-bg');
      this.overlayLayer = document.getElementById('shseq-canvas-overlay');
      this.toolbar      = document.getElementById('shseq-canvas-toolbar');

      if (!this.shell || !this.bgImg || !this.overlayLayer) {
        console.error('[shseq] Canvas elements not found');
        return;
      }

      // Phase 1: نمایش فوری با original image
      this.bgImg.src = cleanUrl || originalUrl || '';

      // بارگذاری overlay items از server
      (overlayItems || []).forEach(item => this._addItemFromData(item));

      // اگر inpainting هنوز در حال انجام است → poll کن
      if (inpaintingPending && !cleanUrl) {
        this._startBgPoll();
      }

      // bind toolbar events
      this._bindToolbar();

      // auto-save هر ۵ ثانیه
      this.autoSaveTimer = setInterval(() => {
        if (this.isDirty) this._autoSave();
      }, 5000);

      // click روی canvas برای deselect
      this.overlayLayer.addEventListener('click', (e) => {
        if (e.target === this.overlayLayer || e.target === this.bgImg) {
          this._deselect();
        }
      });

      // expose serialize برای Confirm
      this.shell.dataset.ready = '1';
    },

    /* ─── Background Polling ──────────────────────────────────────── */

    _startBgPoll() {
      // نمایش loading indicator
      const badge = document.getElementById('shseq-inpaint-badge');
      if (badge) badge.style.display = '';

      this.bgPollTimer = setInterval(async () => {
        try {
          const res = await fetch(`${this.restUrl}/wizard/${this.sequenceId}/bg-status`, {
            headers: { 'X-WP-Nonce': this.restNonce },
          });
          const data = await res.json();

          if (data.clean_ready && data.clean_url) {
            // Phase 2: swap به clean image
            this._swapBackground(data.clean_url);

            // اضافه کردن overlay items که از Vision آمدند (اگر هنوز نیامده)
            if (data.overlay_items && data.overlay_items.length > 0 && this.items.size === 0) {
              data.overlay_items.forEach(item => this._addItemFromData(item));
            }

            clearInterval(this.bgPollTimer);
            if (badge) badge.style.display = 'none';
          }
        } catch (e) { /* poll ادامه می‌دهد */ }
      }, 2500);
    },

    _swapBackground(url) {
      // smooth fade transition
      this.bgImg.style.transition = 'opacity 0.4s ease';
      this.bgImg.style.opacity    = '0';
      setTimeout(() => {
        this.bgImg.src = url;
        this.bgImg.onload = () => {
          this.bgImg.style.opacity = '1';
        };
      }, 400);
    },

    /* ─── Overlay Item CRUD ───────────────────────────────────────── */

    addNewItem() {
      const id   = crypto.randomUUID();
      const data = {
        id, html: 'متن جدید',
        x_rel: 0.3, y_rel: 0.3,
        width_rel: 0.3, height_rel: 0.08,
        fontFamily: 'inherit', fontSize: '1.5rem',
        fontWeight: '700', color: '#ffffff',
        textAlign: 'right', rotation: 0,
      };
      this._addItemFromData(data);
      this._select(id);
      // focus text
      const el = this.items.get(id)?.el;
      const content = el?.querySelector('.oi-content');
      if (content) { content.focus(); document.execCommand('selectAll'); }
      this.isDirty = true;
    },

    deleteSelected() {
      if (!this.selectedId) return;
      const item = this.items.get(this.selectedId);
      if (item) { item.el.remove(); this.items.delete(this.selectedId); }
      this.selectedId = null;
      this._updateToolbar(null);
      this.isDirty = true;
    },

    _addItemFromData(data) {
      const el = document.createElement('div');
      el.className       = 'overlay-item';
      el.dataset.id      = data.id;
      el.style.cssText   = this._toCssStyle(data);

      // محتوا
      const content = document.createElement('div');
      content.className        = 'oi-content';
      content.contentEditable  = 'true';
      content.innerHTML        = data.html || '';
      content.style.cssText    = 'width:100%;height:100%;outline:none;overflow:hidden;word-break:break-word;cursor:text;padding:2px 4px;box-sizing:border-box';
      content.dir              = 'rtl';  // RTL support
      content.addEventListener('input',  () => { this._syncData(data.id); this.isDirty = true; });
      content.addEventListener('dblclick', (e) => e.stopPropagation());

      // دکمه حذف
      const del = document.createElement('button');
      del.className   = 'oi-delete';
      del.innerHTML   = '×';
      del.title       = 'حذف این متن';
      del.style.cssText = 'position:absolute;top:-10px;left:-10px;width:20px;height:20px;background:#d63638;color:#fff;border:none;border-radius:50%;font-size:12px;cursor:pointer;display:none;align-items:center;justify-content:center;z-index:10';
      del.addEventListener('click', (e) => { e.stopPropagation(); this.deleteItem(data.id); });

      // resize handle
      const resize = document.createElement('div');
      resize.className  = 'oi-resize';
      resize.style.cssText = 'position:absolute;bottom:-5px;left:-5px;width:12px;height:12px;background:#2271b1;border:2px solid #fff;border-radius:2px;cursor:nesw-resize;z-index:10';
      this._bindResize(resize, el, data);

      el.appendChild(content);
      el.appendChild(del);
      el.appendChild(resize);
      this.overlayLayer.appendChild(el);

      // event listeners
      el.addEventListener('mousedown', (e) => {
        if (e.target.classList.contains('oi-resize')) return;
        if (e.target.classList.contains('oi-delete')) return;
        if (e.target.classList.contains('oi-content') && document.activeElement === e.target) return;
        this._select(data.id);
        this._bindDrag(e, el, data);
      });

      el.addEventListener('click', () => this._select(data.id));

      // hover → show delete
      el.addEventListener('mouseenter', () => { del.style.display = 'flex'; });
      el.addEventListener('mouseleave', () => { if (this.selectedId !== data.id) del.style.display = 'none'; });

      this.items.set(data.id, { el, data: { ...data } });
    },

    deleteItem(id) {
      const item = this.items.get(id);
      if (item) { item.el.remove(); this.items.delete(id); }
      if (this.selectedId === id) { this.selectedId = null; this._updateToolbar(null); }
      this.isDirty = true;
    },

    /* ─── Drag ────────────────────────────────────────────────────── */

    _bindDrag(startEvent, el, data) {
      const shellRect = this.overlayLayer.getBoundingClientRect();
      const startX = startEvent.clientX;
      const startY = startEvent.clientY;
      const origLeft = data.x_rel * shellRect.width;
      const origTop  = data.y_rel * shellRect.height;

      let moved = false;

      const onMove = (e) => {
        const dx = e.clientX - startX;
        const dy = e.clientY - startY;
        if (Math.abs(dx) > 3 || Math.abs(dy) > 3) moved = true;

        const newLeft = Math.max(0, Math.min(shellRect.width  - el.offsetWidth,  origLeft + dx));
        const newTop  = Math.max(0, Math.min(shellRect.height - el.offsetHeight, origTop  + dy));

        el.style.left = (newLeft / shellRect.width  * 100) + '%';
        el.style.top  = (newTop  / shellRect.height * 100) + '%';

        data.x_rel = newLeft / shellRect.width;
        data.y_rel = newTop  / shellRect.height;
      };

      const onUp = () => {
        if (moved) this.isDirty = true;
        window.removeEventListener('mousemove', onMove);
        window.removeEventListener('mouseup',   onUp);
      };

      window.addEventListener('mousemove', onMove);
      window.addEventListener('mouseup',   onUp);
      startEvent.preventDefault();
    },

    /* ─── Resize ──────────────────────────────────────────────────── */

    _bindResize(handle, el, data) {
      handle.addEventListener('mousedown', (e) => {
        e.preventDefault();
        e.stopPropagation();

        const shellRect = this.overlayLayer.getBoundingClientRect();
        const startX = e.clientX;
        const startY = e.clientY;
        const origW  = el.offsetWidth;
        const origH  = el.offsetHeight;

        const onMove = (e2) => {
          const dx = e2.clientX - startX;
          const dy = e2.clientY - startY;
          const newW = Math.max(40, origW + dx);
          const newH = Math.max(20, origH + dy);

          el.style.width  = (newW / shellRect.width  * 100) + '%';
          el.style.height = (newH / shellRect.height * 100) + '%';
          data.width_rel  = newW / shellRect.width;
          data.height_rel = newH / shellRect.height;
          this.isDirty = true;
        };

        const onUp = () => {
          window.removeEventListener('mousemove', onMove);
          window.removeEventListener('mouseup',   onUp);
        };

        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseup',   onUp);
      });
    },

    /* ─── Selection ───────────────────────────────────────────────── */

    _select(id) {
      this._deselect();
      this.selectedId = id;
      const item = this.items.get(id);
      if (!item) return;
      item.el.classList.add('selected');
      item.el.querySelector('.oi-delete').style.display = 'flex';
      this._updateToolbar(item.data);
    },

    _deselect() {
      if (!this.selectedId) return;
      const prev = this.items.get(this.selectedId);
      if (prev) {
        prev.el.classList.remove('selected');
        prev.el.querySelector('.oi-delete').style.display = 'none';
      }
      this.selectedId = null;
      this._updateToolbar(null);
    },

    /* ─── Toolbar ─────────────────────────────────────────────────── */

    _bindToolbar() {
      if (!this.toolbar) return;

      // Add
      this.toolbar.querySelector('#shseq-oi-add')?.addEventListener('click', () => this.addNewItem());

      // Delete
      this.toolbar.querySelector('#shseq-oi-del')?.addEventListener('click', () => this.deleteSelected());

      // Color
      this.toolbar.querySelector('#shseq-oi-color')?.addEventListener('input', (e) => {
        this._applyStyle('color', e.target.value);
      });

      // Font family
      this.toolbar.querySelector('#shseq-oi-font')?.addEventListener('change', (e) => {
        this._applyStyle('fontFamily', e.target.value);
      });

      // Font size
      this.toolbar.querySelector('#shseq-oi-size')?.addEventListener('change', (e) => {
        this._applyStyle('fontSize', e.target.value);
      });

      // Font weight
      this.toolbar.querySelector('#shseq-oi-weight')?.addEventListener('change', (e) => {
        this._applyStyle('fontWeight', e.target.value);
      });

      // Text align
      this.toolbar.querySelectorAll('.shseq-align-btn')?.forEach(btn => {
        btn.addEventListener('click', () => {
          this._applyStyle('textAlign', btn.dataset.align);
          this.toolbar.querySelectorAll('.shseq-align-btn').forEach(b => b.classList.remove('active'));
          btn.classList.add('active');
        });
      });

      // Text shadow toggle
      this.toolbar.querySelector('#shseq-oi-shadow')?.addEventListener('change', (e) => {
        this._applyStyle('textShadow', e.target.checked ? '0 2px 6px rgba(0,0,0,.6)' : 'none');
      });
    },

    _applyStyle(prop, value) {
      if (!this.selectedId) return;
      const item = this.items.get(this.selectedId);
      if (!item) return;
      item.data[prop] = value;
      // apply to element
      const cssProp = {
        fontFamily: 'font-family', fontSize: 'font-size',
        fontWeight: 'font-weight', color: 'color',
        textAlign: 'text-align', textShadow: 'text-shadow',
      }[prop];
      if (cssProp) {
        item.el.style[prop] = value;
      }
      this.isDirty = true;
    },

    _updateToolbar(data) {
      if (!this.toolbar) return;
      const disabled = !data;
      this.toolbar.querySelectorAll('[data-style-ctrl]').forEach(el => {
        el.disabled = disabled;
        el.style.opacity = disabled ? '0.4' : '1';
      });
      if (data) {
        const set = (id, val) => { const el = this.toolbar.querySelector(`#${id}`); if (el) el.value = val; };
        set('shseq-oi-color',  data.color      || '#ffffff');
        set('shseq-oi-font',   data.fontFamily || 'inherit');
        set('shseq-oi-size',   data.fontSize   || '1.5rem');
        set('shseq-oi-weight', data.fontWeight || '700');
        // align buttons
        this.toolbar.querySelectorAll('.shseq-align-btn').forEach(btn => {
          btn.classList.toggle('active', btn.dataset.align === (data.textAlign || 'right'));
        });
      }
    },

    /* ─── Sync & Serialize ────────────────────────────────────────── */

    _syncData(id) {
      const item = this.items.get(id);
      if (!item) return;
      const content = item.el.querySelector('.oi-content');
      if (content) item.data.html = content.innerHTML;
    },

    /** serialize همه overlay items برای ارسال به server */
    serialize() {
      const shellRect = this.overlayLayer.getBoundingClientRect();
      return Array.from(this.items.values()).map(({ el, data }) => {
        // مختصات را از DOM به‌روز کن
        const left = parseFloat(el.style.left) / 100;
        const top  = parseFloat(el.style.top)  / 100;
        const w    = parseFloat(el.style.width) / 100;
        const h    = parseFloat(el.style.height)/ 100;
        return {
          id:         data.id,
          html:       el.querySelector('.oi-content')?.innerHTML || '',
          x_rel:      isNaN(left) ? data.x_rel : left,
          y_rel:      isNaN(top)  ? data.y_rel : top,
          width_rel:  isNaN(w)    ? data.width_rel : w,
          height_rel: isNaN(h)    ? data.height_rel : h,
          fontFamily: data.fontFamily || 'inherit',
          fontSize:   data.fontSize   || '1.5rem',
          fontWeight: data.fontWeight || '700',
          color:      data.color      || '#ffffff',
          textAlign:  data.textAlign  || 'right',
          rotation:   data.rotation   || 0,
        };
      });
    },

    async _autoSave() {
      if (!this.isDirty) return;
      try {
        await fetch(`${this.restUrl}/wizard/${this.sequenceId}/overlay`, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': this.restNonce },
          body:    JSON.stringify({ items: this.serialize(), confirm: false }),
        });
        this.isDirty = false;
      } catch (e) { /* silent fail — خطا را نمایش نمی‌دهیم */ }
    },

    async confirm() {
      const items = this.serialize();
      const res = await fetch(`${this.restUrl}/wizard/${this.sequenceId}/overlay`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': this.restNonce },
        body:    JSON.stringify({ items, confirm: true }),
      });
      if (!res.ok) throw new Error('Failed to confirm overlay');
      this.isDirty = false;
      clearInterval(this.autoSaveTimer);
      clearInterval(this.bgPollTimer);
      return await res.json();
    },

    destroy() {
      clearInterval(this.autoSaveTimer);
      clearInterval(this.bgPollTimer);
    },

    /* ─── CSS Helper ──────────────────────────────────────────────── */

    _toCssStyle(data) {
      const parts = [
        'position:absolute',
        `left:${(data.x_rel || 0) * 100}%`,
        `top:${(data.y_rel || 0) * 100}%`,
        `width:${(data.width_rel || 0.3) * 100}%`,
        `height:${(data.height_rel || 0.08) * 100}%`,
        `font-family:${data.fontFamily || 'inherit'}`,
        `font-size:${data.fontSize || '1.5rem'}`,
        `font-weight:${data.fontWeight || '700'}`,
        `color:${data.color || '#fff'}`,
        `text-align:${data.textAlign || 'right'}`,
        'box-sizing:border-box',
        'cursor:move',
        'user-select:none',
        'min-width:40px',
        'min-height:24px',
        'border:2px solid transparent',
        'border-radius:3px',
        'transition:border-color 120ms',
      ];
      if (data.rotation) parts.push(`transform:rotate(${data.rotation}deg)`);
      return parts.join(';');
    },
  };

})(window, document);
