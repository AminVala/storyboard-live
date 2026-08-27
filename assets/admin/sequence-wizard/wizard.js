/**
 * StoryBoard Live — Sequence Wizard
 * ─────────────────────────────────────────────────────────────
 * State Machine JS برای مدیریت مراحل ساخت سکانس
 * بدون framework — Vanilla JS ES2022
 */

;(function (window, document) {
  'use strict';

  const { shseqWizardConfig: cfg, shseqOverlayEditor: overlayEditor } = window;
  if (!cfg) return; // اگر config وجود نداشت abort

  // ─── State ────────────────────────────────────────────────────
  let state = {
    sequenceId:    cfg.sequenceId,
    step:          cfg.currentStep || 'mode_select',
    mode:          cfg.currentMode || null,
    jobPolling:    null,
    overlayItems:  cfg.overlayItems || [],
    frames:        cfg.frames || [],
  };

  // ─── DOM refs ─────────────────────────────────────────────────
  const $ = (sel, ctx = document) => ctx.querySelector(sel);
  const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

  const root      = $('#shseq-wizard');
  const panelWrap = $('#shseq-panel-wrap', root);

  if (!root || !panelWrap) return;

  // ─── API ───────────────────────────────────────────────────── 
  const api = {
    base: cfg.restUrl,   // /wp-json/shseq/v1
    nonce: cfg.restNonce,

    async post(endpoint, body) {
      const res = await fetch(`${this.base}/wizard/${state.sequenceId}/${endpoint}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': this.nonce },
        body: JSON.stringify(body),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
      return data;
    },

    async get(endpoint) {
      const res = await fetch(`${this.base}/wizard/${state.sequenceId}/${endpoint}`, {
        headers: { 'X-WP-Nonce': this.nonce },
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
      return data;
    },
  };

  // ─── Progress Bar ──────────────────────────────────────────── 
  function renderProgress() {
    const steps = getStepsForMode(state.mode);
    const track = $('.shseq-progress-track', root);
    if (!track) return;
    track.innerHTML = '';

    steps.forEach(({ key, label }) => {
      const div = document.createElement('div');
      div.className = 'shseq-progress-step';

      const isCurrent = key === state.step;
      const isDone = isStepBefore(key, state.step, steps);

      if (isCurrent) div.classList.add('active');
      if (isDone)    div.classList.add('done');
      if (state.step === 'failed') div.classList.add('failed');

      div.innerHTML = `
        <span class="step-icon">${isDone ? '✓' : ''}</span>
        <span>${label}</span>
      `;
      track.appendChild(div);
    });
  }

  function getStepsForMode(mode) {
    const common = [
      { key: 'mode_select',    label: 'انتخاب حالت' },
    ];
    const modeSteps = {
      golden_master: [
        { key: 'gm_upload',   label: 'آپلود PNG' },
        { key: 'gm_extract',  label: 'استخراج متن' },
        { key: 'gm_inpaint',  label: 'پاک‌سازی' },
        { key: 'gm_overlay',  label: 'overlay' },
        { key: 'frame_generate', label: 'فریم‌ها' },
      ],
      frame_upload: [
        { key: 'fu_upload',   label: 'آپلود فریم' },
      ],
      ai_generate: [
        { key: 'ai_prompt',   label: 'prompt' },
        { key: 'ai_generate', label: 'ساخت تصویر' },
        { key: 'frame_generate', label: 'فریم‌ها' },
      ],
    };
    const end = [
      { key: 'content_steps', label: 'محتوا' },
      { key: 'preview',       label: 'پیش‌نمایش' },
      { key: 'published',     label: 'انتشار' },
    ];
    return [...common, ...(modeSteps[mode] || []), ...end];
  }

  function isStepBefore(key, currentKey, steps) {
    const keys = steps.map(s => s.key);
    return keys.indexOf(key) < keys.indexOf(currentKey);
  }

  // ─── Panel Router ──────────────────────────────────────────── 
  function renderPanel() {
    renderProgress();
    clearError();

    const renderMap = {
      mode_select:    renderModeSelect,
      gm_upload:      renderGMUpload,
      gm_extract:     renderAsyncJob,
      gm_inpaint:     renderAsyncJob,
      gm_overlay:     renderOverlayEditor,
      fu_upload:      renderFrameUpload,
      ai_prompt:      renderAIPrompt,
      ai_generate:    renderAsyncJob,
      frame_generate: renderAsyncJob,
      content_steps:  renderContentSteps,
      preview:        renderPreview,
      published:      renderPublished,
      failed:         renderFailed,
    };

    const fn = renderMap[state.step];
    if (fn) fn();
    else panelWrap.innerHTML = `<div class="shseq-alert shseq-alert-info">در حال بارگذاری...</div>`;
  }

  // ─── Step: Mode Select ─────────────────────────────────────── 
  function renderModeSelect() {
    panelWrap.innerHTML = `
      <div class="shseq-panel">
        <h2 class="shseq-panel-title">چگونه می‌خواهید سکانس را بسازید؟</h2>
        <p class="shseq-panel-desc">یکی از ۳ روش زیر را انتخاب کنید. پس از انتخاب حالت می‌توانید وارد مرحله بعدی شوید.</p>
        <div class="shseq-mode-grid">
          ${renderModeCard('golden_master', '🖼', 'Golden Master PNG', 'یک تصویر طرح نهایی آپلود کنید. متن‌ها به‌صورت خودکار استخراج و overlay می‌شوند.')}
          ${renderModeCard('frame_upload', '📂', 'فریم‌های آماده', 'فریم‌های WebP/JPEG را یک‌به‌یک و به ترتیب آپلود کنید.')}
          ${renderModeCard('ai_generate', '✨', 'ساخت با هوش مصنوعی', 'طرح مورد نظر خود را توضیح دهید — AI تصویر اولیه را می‌سازد.')}
        </div>
        <div class="shseq-actions">
          <button id="shseq-mode-next" class="shseq-btn shseq-btn-primary" disabled>ادامه ←</button>
        </div>
      </div>
    `;

    $$('.shseq-mode-card', root).forEach(card => {
      card.addEventListener('click', () => {
        $$('.shseq-mode-card', root).forEach(c => c.classList.remove('selected'));
        card.classList.add('selected');
        state.mode = card.dataset.mode;
        $('#shseq-mode-next', root).disabled = false;
      });
    });

    $('#shseq-mode-next', root).addEventListener('click', async () => {
      if (!state.mode) return;
      await withLoading(async () => {
        await api.post('mode', { mode: state.mode });
        state.step = modeFirstStep(state.mode);
        renderPanel();
      });
    });
  }

  function renderModeCard(mode, icon, title, desc) {
    const sel = state.mode === mode ? ' selected' : '';
    return `
      <div class="shseq-mode-card${sel}" data-mode="${mode}">
        <div class="mode-icon">${icon}</div>
        <div class="mode-title">${title}</div>
        <div class="mode-desc">${desc}</div>
      </div>
    `;
  }

  function modeFirstStep(mode) {
    return { golden_master: 'gm_upload', frame_upload: 'fu_upload', ai_generate: 'ai_prompt' }[mode] || 'mode_select';
  }

  // ─── Step: Golden Master Upload ───────────────────────────── 
  function renderGMUpload() {
    panelWrap.innerHTML = `
      <div class="shseq-panel">
        <h2 class="shseq-panel-title">آپلود Golden Master PNG</h2>
        <p class="shseq-panel-desc">تصویر نهایی طرح خود را آپلود کنید. این تصویر باید شامل تمام متن‌هایی باشد که می‌خواهید overlay شوند.</p>
        <div class="shseq-upload-zone" id="shseq-gm-zone">
          <div class="upload-icon">🖼️</div>
          <div class="upload-title">اینجا کلیک کنید یا تصویر را بکشید</div>
          <div class="upload-sub">PNG، JPEG، WebP — حداکثر ۱۰ مگابایت</div>
          <input type="file" id="shseq-gm-file" accept="image/png,image/jpeg,image/webp" style="display:none">
        </div>
        <div id="shseq-gm-preview" style="display:none;margin-bottom:16px">
          <img id="shseq-gm-img" src="" alt="" style="max-width:100%;border-radius:8px;border:1px solid #c3c4c7">
        </div>
        <div class="shseq-actions">
          <button id="shseq-gm-next" class="shseq-btn shseq-btn-primary" disabled>آپلود و ادامه ←</button>
        </div>
      </div>
    `;

    let selectedFile = null;

    const zone  = $('#shseq-gm-zone', root);
    const input = $('#shseq-gm-file', root);
    const prev  = $('#shseq-gm-preview', root);
    const img   = $('#shseq-gm-img', root);
    const next  = $('#shseq-gm-next', root);

    zone.addEventListener('click', () => input.click());

    zone.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', (e) => {
      e.preventDefault();
      zone.classList.remove('drag-over');
      const file = e.dataTransfer.files[0];
      if (file) onFileSelected(file);
    });

    input.addEventListener('change', () => { if (input.files[0]) onFileSelected(input.files[0]); });

    function onFileSelected(file) {
      if (file.size > 10 * 1024 * 1024) { showError('حجم فایل باید کمتر از ۱۰ مگابایت باشد'); return; }
      selectedFile = file;
      const url = URL.createObjectURL(file);
      img.src = url;
      prev.style.display = '';
      next.disabled = false;
    }

    next.addEventListener('click', async () => {
      if (!selectedFile) return;
      await withLoading(async () => {
        // آپلود با WP Media API
        const formData = new FormData();
        formData.append('file', selectedFile);
        formData.append('title', `shseq-gm-${state.sequenceId}`);

        const uploadRes = await fetch(cfg.uploadUrl, {
          method: 'POST',
          headers: { 'X-WP-Nonce': cfg.restNonce },
          body: formData,
        });
        const uploaded = await uploadRes.json();
        if (!uploadRes.ok) throw new Error(uploaded.message || 'آپلود ناموفق');

        await api.post('golden-master', { attachment_id: uploaded.id });
        state.step = 'gm_extract';
        startJobPolling();
        renderPanel();
      });
    });
  }

  // ─── Step: Async Job ───────────────────────────────────────── 
  function renderAsyncJob() {
    const labels = {
      gm_extract:     { title: 'در حال استخراج متن‌ها...', icon: '🔍' },
      gm_inpaint:     { title: 'در حال پاک‌سازی تصویر...', icon: '🎨' },
      ai_generate:    { title: 'در حال ساخت تصویر با AI...', icon: '✨' },
      frame_generate: { title: 'در حال ساخت فریم‌ها...', icon: '🎬' },
    };
    const { title, icon } = labels[state.step] || { title: 'در حال پردازش...', icon: '⏳' };

    panelWrap.innerHTML = `
      <div class="shseq-panel">
        <h2 class="shseq-panel-title">${icon} ${title}</h2>
        <div class="shseq-job-progress">
          <div class="job-title">لطفاً صبر کنید</div>
          <div class="shseq-progress-bar-wrap">
            <div class="shseq-progress-bar-fill" id="shseq-job-bar" style="width:0%"></div>
          </div>
          <div class="shseq-progress-pct" id="shseq-job-pct">0٪</div>
        </div>
      </div>
    `;

    startJobPolling();
  }

  function startJobPolling() {
    stopJobPolling();
    state.jobPolling = setInterval(async () => {
      try {
        const data = await api.get('job-status');
        updateJobProgress(data.progress);

        if (data.error) {
          stopJobPolling();
          state.step = 'failed';
          renderPanel();
          return;
        }

        // اگر step عوض شده → ادامه
        if (data.step !== state.step) {
          stopJobPolling();
          state.step = data.step;
          renderPanel();
        }
      } catch (err) {
        // موقتاً نادیده می‌گیریم — polling ادامه می‌دهد
      }
    }, 1500);
  }

  function stopJobPolling() {
    if (state.jobPolling) { clearInterval(state.jobPolling); state.jobPolling = null; }
  }

  function updateJobProgress(pct) {
    const bar = $('#shseq-job-bar', root);
    const pctEl = $('#shseq-job-pct', root);
    if (bar) bar.style.width = `${pct}%`;
    if (pctEl) pctEl.textContent = `${pct}٪`;
  }

  // ─── Step: Overlay Editor ──────────────────────────────────── 
  function renderOverlayEditor() {
    panelWrap.innerHTML = `
      <div class="shseq-panel">
        <h2 class="shseq-panel-title">تنظیم Overlay</h2>
        <p class="shseq-panel-desc">متن‌های استخراج‌شده را با کشیدن جابجا کنید. روی هر متن دوبار کلیک کنید تا آن را ویرایش کنید.</p>
        
        <!-- Toolbar -->
        <div class="shseq-toolbar">
          <button class="shseq-btn shseq-btn-outline" id="shseq-oe-add" style="font-size:12px;padding:5px 12px">+ افزودن متن</button>
          <div class="toolbar-sep"></div>
          <label>رنگ متن:</label>
          <input type="color" id="shseq-oe-color" value="#ffffff">
          <label>فونت:</label>
          <select id="shseq-oe-font">
            <option value="inherit">پیش‌فرض</option>
            <option value="'Vazirmatn', sans-serif">Vazirmatn</option>
            <option value="'IRANSans', sans-serif">IRANSans</option>
            <option value="Arial, sans-serif">Arial</option>
          </select>
          <label>اندازه:</label>
          <select id="shseq-oe-size">
            <option value="0.8rem">کوچک</option>
            <option value="1rem">متوسط</option>
            <option value="1.5rem" selected>بزرگ</option>
            <option value="2rem">خیلی بزرگ</option>
            <option value="3rem">فوق‌العاده بزرگ</option>
          </select>
          <div class="toolbar-sep"></div>
          <button class="shseq-btn" id="shseq-oe-delete" style="font-size:12px;padding:5px 12px;background:#fce8e8;color:#d63638;border-color:#f5c6c6">× حذف انتخابی</button>
        </div>

        <!-- Canvas -->
        <div class="shseq-overlay-shell" id="shseq-overlay-shell">
          <img id="shseq-oe-bg" src="${cfg.cleanBackgroundUrl || ''}" alt="پس‌زمینه">
          <div id="shseq-overlay-container" style="position:absolute;inset:0;overflow:hidden"></div>
        </div>

        <div class="shseq-actions">
          <button id="shseq-oe-save" class="shseq-btn shseq-btn-outline">ذخیره</button>
          <button id="shseq-oe-confirm" class="shseq-btn shseq-btn-primary">تأیید و ساخت فریم‌ها ←</button>
        </div>
      </div>
    `;

    // init overlay editor
    const shell     = $('#shseq-overlay-shell', root);
    const container = $('#shseq-overlay-container', root);
    overlayEditor.init(shell, container, cfg.overlayItems || []);

    // toolbar events
    document.addEventListener('shseq:overlay:selected', (e) => {
      $('#shseq-oe-color', root).value = e.detail.color || '#ffffff';
      $('#shseq-oe-font', root).value  = e.detail.fontFamily || 'inherit';
      $('#shseq-oe-size', root).value  = e.detail.fontSize   || '1.5rem';
    });

    $('#shseq-oe-add', root).addEventListener('click', () =>
      document.dispatchEvent(new CustomEvent('shseq:overlay:add')));
    $('#shseq-oe-delete', root).addEventListener('click', () =>
      document.dispatchEvent(new CustomEvent('shseq:overlay:delete-selected')));

    const applyStyle = (prop) => (e) =>
      document.dispatchEvent(new CustomEvent('shseq:overlay:style-change', { detail: { prop, value: e.target.value } }));

    $('#shseq-oe-color', root).addEventListener('input', applyStyle('color'));
    $('#shseq-oe-font', root).addEventListener('change', applyStyle('fontFamily'));
    $('#shseq-oe-size', root).addEventListener('change', applyStyle('fontSize'));

    $('#shseq-oe-save', root).addEventListener('click', async () => {
      await withLoading(async () => {
        await api.post('overlay', { items: overlayEditor.serialize(), confirm: false });
        showSuccess('overlay ذخیره شد');
      });
    });

    $('#shseq-oe-confirm', root).addEventListener('click', async () => {
      await withLoading(async () => {
        await api.post('overlay', { items: overlayEditor.serialize(), confirm: true });
        state.step = 'frame_generate';
        renderPanel();
      });
    });
  }

  // ─── Step: Frame Upload ────────────────────────────────────── 
  function renderFrameUpload() {
    panelWrap.innerHTML = `
      <div class="shseq-panel">
        <h2 class="shseq-panel-title">آپلود فریم‌ها</h2>
        <p class="shseq-panel-desc">فریم‌ها را به ترتیب اجرا آپلود کنید. حداقل ۲ فریم لازم است. می‌توانید با کشیدن ترتیب را تغییر دهید.</p>
        <div class="shseq-upload-zone" id="shseq-fu-zone" style="margin-bottom:12px">
          <div class="upload-icon">📂</div>
          <div class="upload-title">چندین فایل را انتخاب یا اینجا بکشید</div>
          <div class="upload-sub">WebP، JPEG، PNG — تا ۱۲۰ فریم</div>
          <input type="file" id="shseq-fu-input" accept="image/*" multiple style="display:none">
        </div>
        <div class="shseq-frame-grid" id="shseq-frame-grid"></div>
        <div id="shseq-fu-counter" style="font-size:13px;color:#6b7280;margin-bottom:16px">۰ فریم انتخاب‌شده</div>
        <div class="shseq-actions">
          <button id="shseq-fu-next" class="shseq-btn shseq-btn-primary" disabled>آپلود و ادامه ←</button>
        </div>
      </div>
    `;

    const frames = [];
    const grid   = $('#shseq-frame-grid', root);
    const counter= $('#shseq-fu-counter', root);
    const next   = $('#shseq-fu-next', root);
    const zone   = $('#shseq-fu-zone', root);
    const input  = $('#shseq-fu-input', root);

    zone.addEventListener('click', () => input.click());
    zone.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', (e) => { e.preventDefault(); zone.classList.remove('drag-over'); addFiles([...e.dataTransfer.files]); });
    input.addEventListener('change', () => addFiles([...input.files]));

    function addFiles(files) {
      files.filter(f => f.type.startsWith('image/')).forEach(file => {
        if (frames.length >= 120) return;
        const url  = URL.createObjectURL(file);
        const idx  = frames.length;
        frames.push({ file, url, attachmentId: null });

        const thumb = document.createElement('div');
        thumb.className   = 'shseq-frame-thumb';
        thumb.dataset.idx = idx;
        thumb.innerHTML   = `
          <img src="${url}" alt="فریم ${idx + 1}">
          <span class="frame-num">${idx + 1}</span>
          <button class="remove-frame" title="حذف">×</button>
        `;
        thumb.querySelector('.remove-frame').addEventListener('click', () => {
          frames.splice(idx, 1);
          thumb.remove();
          updateCounter();
        });
        grid.appendChild(thumb);
      });
      updateCounter();
    }

    function updateCounter() {
      const n = frames.length;
      counter.textContent = `${n} فریم انتخاب‌شده`;
      next.disabled = n < 2;
    }

    next.addEventListener('click', async () => {
      await withLoading(async () => {
        const ids = [];
        for (const frame of frames) {
          const fd = new FormData();
          fd.append('file', frame.file);
          const res = await fetch(cfg.uploadUrl, { method: 'POST', headers: { 'X-WP-Nonce': cfg.restNonce }, body: fd });
          const d   = await res.json();
          if (!res.ok) throw new Error(d.message || 'آپلود ناموفق');
          ids.push(d.id);
        }
        await api.post('frames', { attachment_ids: ids });
        state.step = 'content_steps';
        renderPanel();
      });
    });
  }

  // ─── Step: AI Prompt ──────────────────────────────────────── 
  function renderAIPrompt() {
    panelWrap.innerHTML = `
      <div class="shseq-panel">
        <h2 class="shseq-panel-title">✨ توضیح طرح برای AI</h2>
        <p class="shseq-panel-desc">طرح مورد نظر خود را به فارسی یا انگلیسی توضیح دهید. هرچه دقیق‌تر باشید، نتیجه بهتری خواهید گرفت.</p>
        <div class="shseq-ai-prompt-wrap">
          <textarea id="shseq-ai-textarea" maxlength="1000" placeholder="مثال: یک فروشگاه آنلاین مدرن با پس‌زمینه تیره، محصولات الکترونیکی، نور آبی، سبک مینیمال..."></textarea>
          <div class="shseq-char-count"><span id="shseq-ai-chars">0</span> / 1000</div>
        </div>
        <div class="shseq-actions">
          <button id="shseq-ai-next" class="shseq-btn shseq-btn-primary" disabled>ساخت تصویر ←</button>
        </div>
      </div>
    `;

    const textarea = $('#shseq-ai-textarea', root);
    const chars    = $('#shseq-ai-chars', root);
    const next     = $('#shseq-ai-next', root);

    textarea.addEventListener('input', () => {
      const len = textarea.value.length;
      chars.textContent = len;
      next.disabled = len < 10;
    });

    next.addEventListener('click', async () => {
      await withLoading(async () => {
        await api.post('ai-prompt', { prompt: textarea.value });
        state.step = 'ai_generate';
        renderPanel();
      });
    });
  }

  // ─── Step: Content Steps ───────────────────────────────────── 
  function renderContentSteps() {
    panelWrap.innerHTML = `
      <div class="shseq-panel">
        <h2 class="shseq-panel-title">تعریف محتوا روی فریم‌ها</h2>
        <p class="shseq-panel-desc">برای هر فریم می‌توانید متن HTML تعریف کنید که هنگام scroll نمایش داده می‌شود.</p>
        <div id="shseq-cs-list"></div>
        <button id="shseq-cs-add" class="shseq-btn shseq-btn-outline" style="margin-bottom:16px">+ افزودن محتوا</button>
        <div class="shseq-actions">
          <button id="shseq-cs-save" class="shseq-btn shseq-btn-outline">ذخیره پیش‌نویس</button>
          <button id="shseq-cs-next" class="shseq-btn shseq-btn-primary">پیش‌نمایش ←</button>
        </div>
      </div>
    `;

    const list  = $('#shseq-cs-list', root);
    const steps = [...(cfg.contentSteps || [])];

    function renderStepRow(step, idx) {
      const row = document.createElement('div');
      row.style.cssText = 'display:flex;gap:10px;align-items:flex-start;margin-bottom:10px;padding:12px;border:1px solid #c3c4c7;border-radius:8px;background:#f8f9fa';
      row.innerHTML = `
        <div style="flex:0 0 80px">
          <label style="font-size:12px;color:#6b7280">فریم #</label>
          <input type="number" min="0" value="${step.frame_index}" style="width:70px;padding:4px 6px;border:1px solid #c3c4c7;border-radius:4px">
        </div>
        <div style="flex:1">
          <label style="font-size:12px;color:#6b7280">HTML محتوا</label>
          <textarea style="width:100%;min-height:60px;border:1px solid #c3c4c7;border-radius:4px;padding:6px;font-size:13px;resize:vertical">${step.html}</textarea>
        </div>
        <button style="margin-top:18px;background:#fce8e8;color:#d63638;border:1px solid #f5c6c6;border-radius:4px;padding:4px 8px;cursor:pointer">حذف</button>
      `;
      row.querySelector('button').addEventListener('click', () => { steps.splice(idx, 1); rerenderList(); });
      return row;
    }

    function rerenderList() {
      list.innerHTML = '';
      steps.forEach((step, idx) => list.appendChild(renderStepRow(step, idx)));
    }

    rerenderList();

    $('#shseq-cs-add', root).addEventListener('click', () => {
      steps.push({ frame_index: steps.length, html: '', css_class: '' });
      rerenderList();
    });

    async function collectAndSave(confirm = false) {
      const rows   = $$('[data-step-row]', list);
      const inputs = $$('input[type=number], textarea', list);
      const result = [];
      for (let i = 0; i < inputs.length; i += 2) {
        result.push({ frame_index: parseInt(inputs[i].value) || 0, html: inputs[i+1].value, css_class: '' });
      }
      await api.post('content', { steps: result, confirm });
    }

    $('#shseq-cs-save', root).addEventListener('click', async () => {
      await withLoading(() => collectAndSave(false));
      showSuccess('پیش‌نویس ذخیره شد');
    });

    $('#shseq-cs-next', root).addEventListener('click', async () => {
      await withLoading(async () => {
        await collectAndSave(true);
        state.step = 'preview';
        renderPanel();
      });
    });
  }

  // ─── Step: Preview ─────────────────────────────────────────── 
  function renderPreview() {
    const viewports = [
      { key: 'desktop', label: '🖥 دسکتاپ',  w: 1440, h: 900  },
      { key: 'tablet',  label: '📱 تبلت',    w: 768,  h: 1024 },
      { key: 'mobile',  label: '📱 موبایل',  w: 375,  h: 812  },
    ];

    panelWrap.innerHTML = `
      <div class="shseq-panel">
        <h2 class="shseq-panel-title">پیش‌نمایش سکانس</h2>
        <p class="shseq-panel-desc">پیش‌نمایش دقیقاً همان چیزی است که بازدیدکنندگان می‌بینند.</p>
        <div class="shseq-preview-pane">
          <div class="shseq-preview-viewport-tabs">
            ${viewports.map(v => `<button class="vp-tab ${v.key === 'desktop' ? 'active' : ''}" data-vp="${v.key}" data-w="${v.w}" data-h="${v.h}">${v.label}</button>`).join('')}
          </div>
          <div class="shseq-preview-iframe-wrap" id="shseq-iframe-wrap">
            <iframe id="shseq-preview-iframe" width="1440" height="900"
              src="${cfg.previewUrl}&viewport=desktop"
              style="width:1440px;height:900px;transform-origin:top center;transform:scale(0.6)">
            </iframe>
          </div>
        </div>
        <div class="shseq-actions">
          <button id="shseq-back-content" class="shseq-btn shseq-btn-outline">← ویرایش محتوا</button>
          <button id="shseq-publish-btn" class="shseq-btn shseq-btn-success">🚀 انتشار</button>
        </div>
      </div>
    `;

    $$('.vp-tab', root).forEach(btn => {
      btn.addEventListener('click', () => {
        $$('.vp-tab', root).forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const iframe = $('#shseq-preview-iframe', root);
        const w = btn.dataset.w;
        const h = btn.dataset.h;
        const scale = Math.min(1, (root.offsetWidth - 80) / w);
        iframe.style.width     = `${w}px`;
        iframe.style.height    = `${h}px`;
        iframe.style.transform = `scale(${scale})`;
        iframe.src = `${cfg.previewUrl}&viewport=${btn.dataset.vp}`;
      });
    });

    $('#shseq-back-content', root).addEventListener('click', () => { state.step = 'content_steps'; renderPanel(); });

    $('#shseq-publish-btn', root).addEventListener('click', async () => {
      if (!confirm('آیا از انتشار این سکانس مطمئن هستید؟')) return;
      await withLoading(async () => {
        const res = await api.post('publish', {});
        state.step = 'published';
        cfg.publishedUrl = res.post_url;
        renderPanel();
      });
    });
  }

  // ─── Step: Published ───────────────────────────────────────── 
  function renderPublished() {
    panelWrap.innerHTML = `
      <div class="shseq-panel" style="text-align:center;padding:40px">
        <div style="font-size:48px;margin-bottom:16px">🎉</div>
        <h2 class="shseq-panel-title">سکانس با موفقیت منتشر شد!</h2>
        <p class="shseq-panel-desc">سکانس شما آماده و در دسترس بازدیدکنندگان است.</p>
        ${cfg.publishedUrl ? `<a href="${cfg.publishedUrl}" target="_blank" class="shseq-btn shseq-btn-primary">مشاهده صفحه ←</a>` : ''}
      </div>
    `;
  }

  // ─── Step: Failed ──────────────────────────────────────────── 
  function renderFailed() {
    panelWrap.innerHTML = `
      <div class="shseq-panel">
        <div class="shseq-alert shseq-alert-error">⚠️ خطا: ${cfg.errorMessage || 'یک خطای ناشناخته رخ داد'}</div>
        <div class="shseq-actions">
          <button id="shseq-retry-btn" class="shseq-btn shseq-btn-primary">تلاش مجدد از ابتدا</button>
        </div>
      </div>
    `;
    $('#shseq-retry-btn', root).addEventListener('click', async () => {
      await withLoading(async () => {
        await api.post('mode', { mode: 'golden_master' }); // reset
        state.step = 'mode_select';
        state.mode = null;
        renderPanel();
      });
    });
  }

  // ─── Utils ─────────────────────────────────────────────────── 
  async function withLoading(fn) {
    const btn = $$('.shseq-btn', root).filter(b => !b.disabled);
    btn.forEach(b => { b.disabled = true; b._prevText = b.textContent; b.textContent = '...'; });
    clearError();
    try {
      await fn();
    } catch (err) {
      showError(err.message || 'خطای ناشناخته');
    } finally {
      btn.forEach(b => { b.disabled = false; if (b._prevText) b.textContent = b._prevText; });
    }
  }

  function showError(msg) {
    let el = $('#shseq-global-error', root);
    if (!el) {
      el = document.createElement('div');
      el.id        = 'shseq-global-error';
      el.className = 'shseq-alert shseq-alert-error';
      root.prepend(el);
    }
    el.textContent = '⚠️ ' + msg;
  }

  function showSuccess(msg) {
    let el = $('#shseq-global-success', root);
    if (!el) {
      el = document.createElement('div');
      el.id        = 'shseq-global-success';
      el.className = 'shseq-alert shseq-alert-success';
      root.prepend(el);
    }
    el.textContent = '✓ ' + msg;
    setTimeout(() => el.remove(), 3000);
  }

  function clearError() {
    const el = $('#shseq-global-error', root);
    if (el) el.remove();
  }

  // ─── Boot ──────────────────────────────────────────────────── 
  renderPanel();

})(window, document);
