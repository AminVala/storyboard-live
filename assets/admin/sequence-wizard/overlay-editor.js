/**
 * StoryBoard Live — Overlay Editor
 * ─────────────────────────────────────────────────────────────
 * مدیریت overlay itemها روی تصویر Golden Master
 * ویژگی‌ها:
 *   - drag & drop برای جابجایی
 *   - resize از گوشه
 *   - edit HTML مستقیم (double-click)
 *   - تغییر font, size, color از toolbar
 *   - حذف آیتم
 *   - serialize/deserialize برای ذخیره در server
 *
 * بدون هیچ framework خارجی — Vanilla JS ES2022
 */

;(function (window) {
  'use strict';

  const shseqOE = {
    /** @type {HTMLElement|null} */
    container: null,
    /** @type {HTMLElement|null} */
    shell: null,
    /** @type {Map<string, object>} */
    items: new Map(),
    /** @type {string|null} */
    selectedId: null,

    /** مقداردهی اولیه */
    init(shellEl, containerEl, initialItems = []) {
      this.shell     = shellEl;
      this.container = containerEl;
      this.items.clear();
      this.selectedId = null;

      // بارگذاری آیتم‌های اولیه
      initialItems.forEach(item => this._createItem(item));

      // کلیک روی پس‌زمینه → deselect
      shellEl.addEventListener('click', (e) => {
        if (e.target === shellEl || e.target.tagName === 'IMG') {
          this._deselect();
        }
      });

      // دکمه Add از toolbar
      document.addEventListener('shseq:overlay:add', () => this._addNewItem());
      document.addEventListener('shseq:overlay:delete-selected', () => {
        if (this.selectedId) this._deleteItem(this.selectedId);
      });
      document.addEventListener('shseq:overlay:style-change', (e) => {
        this._applyStyle(e.detail);
      });
    },

    /** اضافه کردن آیتم جدید در مرکز */
    _addNewItem() {
      const id = crypto.randomUUID();
      const item = {
        id,
        html:       'متن جدید',
        position: { x_rel: 0.3, y_rel: 0.3, width_rel: 0.3, height_rel: 0.08 },
        fontFamily: 'inherit',
        fontSize:   '1.5rem',
        color:      '#ffffff',
      };
      this._createItem(item);
      this._select(id);
      // focus برای ویرایش فوری
      const el = this.container.querySelector(`[data-id="${id}"] .item-content`);
      if (el) { el.focus(); document.execCommand('selectAll'); }
    },

    /** حذف آیتم */
    _deleteItem(id) {
      const el = this.container.querySelector(`[data-id="${id}"]`);
      if (el) el.remove();
      this.items.delete(id);
      this.selectedId = null;
      this._notifyChange();
    },

    /** ساخت DOM element برای یک overlay item */
    _createItem(itemData) {
      const shellRect   = this.shell.getBoundingClientRect();
      const imgEl       = this.shell.querySelector('img');
      const imgW        = imgEl ? imgEl.offsetWidth  : this.shell.offsetWidth;
      const imgH        = imgEl ? imgEl.offsetHeight : this.shell.offsetHeight;

      const p = itemData.position;

      const el = document.createElement('div');
      el.className          = 'shseq-overlay-item';
      el.dataset.id         = itemData.id;
      el.style.left         = `${p.x_rel * imgW}px`;
      el.style.top          = `${p.y_rel * imgH}px`;
      el.style.width        = `${p.width_rel * imgW}px`;
      el.style.height       = `${p.height_rel * imgH}px`;
      el.style.color        = itemData.color      || '#fff';
      el.style.fontFamily   = itemData.fontFamily || 'inherit';
      el.style.fontSize     = itemData.fontSize   || '1.5rem';
      el.style.textShadow   = '0 1px 4px rgba(0,0,0,.7)';

      // محتوا
      const content = document.createElement('div');
      content.className     = 'item-content';
      content.contentEditable = 'true';
      content.innerHTML     = itemData.html;
      content.style.cssText = 'width:100%;height:100%;padding:4px 6px;outline:none;overflow:hidden;word-break:break-word;';
      content.addEventListener('input', () => this._notifyChange());
      content.addEventListener('dblclick', (e) => e.stopPropagation());

      // دکمه حذف
      const delBtn = document.createElement('button');
      delBtn.className = 'delete-btn';
      delBtn.innerHTML = '×';
      delBtn.title     = 'حذف';
      delBtn.addEventListener('click', (e) => { e.stopPropagation(); this._deleteItem(itemData.id); });

      // resize handle
      const resizeH = document.createElement('div');
      resizeH.className = 'resize-handle';
      this._bindResize(el, resizeH, imgW, imgH);

      el.appendChild(content);
      el.appendChild(delBtn);
      el.appendChild(resizeH);
      this.container.appendChild(el);

      // drag
      this._bindDrag(el, imgW, imgH);

      // select on click
      el.addEventListener('mousedown', (e) => {
        if (! e.target.classList.contains('resize-handle') &&
            ! e.target.classList.contains('delete-btn')) {
          this._select(itemData.id);
        }
      });

      // ذخیره state
      this.items.set(itemData.id, {
        ...itemData,
        _el: el,
        _content: content,
      });
    },

    /** drag */
    _bindDrag(el, imgW, imgH) {
      let startX, startY, origLeft, origTop, dragging = false;

      el.addEventListener('mousedown', (e) => {
        if (e.target.classList.contains('resize-handle')) return;
        if (e.target.classList.contains('delete-btn'))   return;
        if (e.target.classList.contains('item-content')) return; // allow text edit

        dragging  = true;
        startX    = e.clientX;
        startY    = e.clientY;
        origLeft  = parseInt(el.style.left)  || 0;
        origTop   = parseInt(el.style.top)   || 0;
        e.preventDefault();

        const onMove = (e2) => {
          if (!dragging) return;
          const dx = e2.clientX - startX;
          const dy = e2.clientY - startY;
          const newLeft = Math.max(0, Math.min(imgW - el.offsetWidth,  origLeft + dx));
          const newTop  = Math.max(0, Math.min(imgH - el.offsetHeight, origTop  + dy));
          el.style.left = `${newLeft}px`;
          el.style.top  = `${newTop}px`;
        };

        const onUp = () => {
          dragging = false;
          this._notifyChange();
          window.removeEventListener('mousemove', onMove);
          window.removeEventListener('mouseup', onUp);
        };

        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseup', onUp);
      });
    },

    /** resize */
    _bindResize(el, handle, imgW, imgH) {
      let startX, startY, origW, origH, resizing = false;

      handle.addEventListener('mousedown', (e) => {
        resizing = true;
        startX   = e.clientX;
        startY   = e.clientY;
        origW    = el.offsetWidth;
        origH    = el.offsetHeight;
        e.preventDefault();
        e.stopPropagation();

        const onMove = (e2) => {
          if (!resizing) return;
          const dx   = e2.clientX - startX;
          const dy   = e2.clientY - startY;
          const newW = Math.max(60,  Math.min(imgW - parseInt(el.style.left), origW + dx));
          const newH = Math.max(24,  Math.min(imgH - parseInt(el.style.top),  origH + dy));
          el.style.width  = `${newW}px`;
          el.style.height = `${newH}px`;
        };

        const onUp = () => {
          resizing = false;
          this._notifyChange();
          window.removeEventListener('mousemove', onMove);
          window.removeEventListener('mouseup', onUp);
        };

        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseup', onUp);
      });
    },

    /** اعمال style از toolbar به آیتم انتخاب‌شده */
    _applyStyle({ prop, value }) {
      if (!this.selectedId) return;
      const data = this.items.get(this.selectedId);
      if (!data) return;

      const el = data._el;
      const propMap = {
        color:      () => { el.style.color      = value; data.color      = value; },
        fontFamily: () => { el.style.fontFamily = value; data.fontFamily = value; },
        fontSize:   () => { el.style.fontSize   = value; data.fontSize   = value; },
      };

      if (propMap[prop]) { propMap[prop](); this._notifyChange(); }
    },

    /** select آیتم */
    _select(id) {
      this._deselect();
      this.selectedId = id;
      const data = this.items.get(id);
      if (data) {
        data._el.classList.add('selected');
        this._emitSelection(data);
      }
    },

    _deselect() {
      if (this.selectedId) {
        const data = this.items.get(this.selectedId);
        if (data) data._el.classList.remove('selected');
      }
      this.selectedId = null;
    },

    _emitSelection(data) {
      document.dispatchEvent(new CustomEvent('shseq:overlay:selected', {
        detail: { id: data.id, color: data.color, fontFamily: data.fontFamily, fontSize: data.fontSize },
      }));
    },

    /** serialize برای ارسال به server */
    serialize() {
      const imgEl = this.shell.querySelector('img');
      const imgW  = imgEl ? imgEl.offsetWidth  : this.shell.offsetWidth;
      const imgH  = imgEl ? imgEl.offsetHeight : this.shell.offsetHeight;

      return Array.from(this.items.values()).map(data => {
        const el = data._el;
        return {
          id:         data.id,
          html:       data._content.innerHTML,
          fontFamily: data.fontFamily,
          fontSize:   data.fontSize,
          color:      data.color,
          position: {
            x_rel:      parseFloat(el.style.left)   / imgW,
            y_rel:      parseFloat(el.style.top)    / imgH,
            width_rel:  parseFloat(el.style.width)  / imgW,
            height_rel: parseFloat(el.style.height) / imgH,
          },
        };
      });
    },

    _notifyChange() {
      document.dispatchEvent(new CustomEvent('shseq:overlay:changed', {
        detail: this.serialize(),
      }));
    },
  };

  window.shseqOverlayEditor = shseqOE;

})(window);
