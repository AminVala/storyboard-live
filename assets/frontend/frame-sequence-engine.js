/** StoryBoard Live: progressive, bounded, orientation-aware scroll playback. */
(() => {
  'use strict';
  const config = window.shseqEngineConfig || {};
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  const instances = new Map();
  const clamp = (n, min, max) => Math.max(min, Math.min(max, n));
  const threshold = Number.isFinite(Number(config.lazyThreshold)) ? Math.max(0, Number(config.lazyThreshold)) : 200;

  function stickyTop() {
    const candidates = [...document.querySelectorAll('#wpadminbar, header, #masthead, #site-header, .site-header, .elementor-location-header, .shseq-preview-bar')];
    const rects = candidates.filter(el => {
      const style = getComputedStyle(el);
      return ['fixed', 'sticky'].includes(style.position) && style.display !== 'none' && style.visibility !== 'hidden';
    }).map(el => el.getBoundingClientRect()).filter(rect => rect.width && rect.height && rect.bottom > 0);
    let bottom = 0;
    // Count overlapping/stacked bars once, including the 46px mobile admin bar.
    rects.sort((a, b) => a.top - b.top).forEach(rect => {
      if (rect.top <= bottom + 1) bottom = Math.max(bottom, rect.bottom);
    });
    return Math.min(bottom, window.innerHeight / 2);
  }

  function init(wrapper) {
    if (instances.has(wrapper)) return;
    let manifest;
    try { manifest = JSON.parse(wrapper.querySelector('.shseq-manifest').textContent); }
    catch (_) { return; } // Server-rendered image and links remain usable.
    const frames = (manifest.frames || []).filter(frame => frame && typeof frame.url === 'string' && frame.url);
    const stage = wrapper.querySelector('.shseq-stage');
    const visual = wrapper.querySelector('.shseq-visual');
    const canvas = wrapper.querySelector('.shseq-canvas');
    const ctx = canvas?.getContext('2d', { alpha: false });
    if (!frames.length || !stage || !visual || !ctx) return;
    wrapper.classList.remove('has-frame');
    const overlays = [...wrapper.querySelectorAll('.shseq-overlay')];
    const steps = manifest.steps || [];
    const cache = new Map();
    const loading = new Map();
    const failed = new Set();
    const disposers = [];
    let queue = [], enabled = false, active = false, destroyed = false;
    let wanted = -1, painted = -1, raf = 0, top = 0, width = 0, height = 0, ratio = 1;
    let layout = '', needsPaint = true;

    function listen(target, name, handler, options) {
      if (!target?.addEventListener) return;
      target.addEventListener(name, handler, options);
      disposers.push(() => target.removeEventListener(name, handler, options));
    }
    function showOverlays(index) {
      const pct = frames.length === 1 ? 100 : index / (frames.length - 1) * 100;
      let chosen = -1;
      steps.forEach((step, i) => { if (Number(step.scroll_pct || 0) <= pct) chosen = i; });
      // Do not hide a link while a keyboard user is interacting with its card.
      const focused = overlays.findIndex(el => el.contains(document.activeElement));
      if (focused >= 0) chosen = focused;
      overlays.forEach((el, i) => {
        const visible = !enabled || i === chosen;
        el.hidden = !visible;
        el.inert = !visible;
        el.setAttribute('aria-hidden', String(!visible));
      });
    }
    function draw(index) {
      const img = cache.get(index);
      if (!img || !width || !height) return;
      const scale = (layout === 'desktop' ? Math.max : Math.min)(width / img.naturalWidth, height / img.naturalHeight);
      ctx.fillStyle = '#faf9f6';
      ctx.fillRect(0, 0, width, height);
      ctx.drawImage(img, (width - img.naturalWidth * scale) / 2, (height - img.naturalHeight * scale) / 2, img.naturalWidth * scale, img.naturalHeight * scale);
      painted = index;
      needsPaint = false;
      cache.delete(index); cache.set(index, img);
      wrapper.classList.add('has-frame');
      showOverlays(index);
    }
    function resize() {
      top = stickyTop();
      const available = Math.max(120, window.innerHeight - top);
      layout = window.innerHeight >= window.innerWidth ? 'portrait' : (window.innerWidth <= 1366 ? 'landscape' : 'desktop');
      wrapper.dataset.layout = layout;
      wrapper.style.setProperty('--shseq-top', top + 'px');
      wrapper.style.setProperty('--shseq-stage-height', available + 'px');
      const speed = clamp(Number(config.scrollSpeed) || 4, 1, 200);
      wrapper.style.setProperty('--shseq-auto-height', (available + (frames.length - 1) * speed) + 'px');
      const rect = visual.getBoundingClientRect();
      width = Math.max(1, rect.width); height = Math.max(1, rect.height);
      ratio = Math.min(window.devicePixelRatio || 1, 2, 2560 / width, 1920 / height);
      const cw = Math.round(width * ratio), ch = Math.round(height * ratio);
      if (canvas.width !== cw || canvas.height !== ch) {
        canvas.width = cw; canvas.height = ch;
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
      }
      needsPaint = true;
      schedule();
    }
    function trim() {
      while (cache.size > 8) {
        const key = [...cache.keys()].find(index => index !== painted && index !== wanted);
        cache.delete(key);
      }
    }
    function pump() {
      if (!enabled || !active || destroyed) return;
      while (loading.size < 2 && queue.length) {
        const index = queue.shift();
        if (cache.has(index) || loading.has(index) || failed.has(index)) continue;
        const img = new Image();
        img.decoding = 'async';
        let complete = false;
        const timer = setTimeout(() => finish(false), 12000);
        function finish(ok) {
          if (complete) return;
          complete = true; clearTimeout(timer);
          img.onload = img.onerror = null;
          loading.delete(index);
          if (ok && enabled && !destroyed) { cache.set(index, img); trim(); schedule(); }
          else { failed.add(index); img.removeAttribute('src'); }
          pump();
        }
        loading.set(index, () => {
          complete = true; clearTimeout(timer); img.onload = img.onerror = null; img.removeAttribute('src');
        });
        img.onload = () => finish(img.naturalWidth > 0);
        img.onerror = () => finish(false);
        img.src = frames[index].url;
      }
    }
    function tick() {
      raf = 0;
      if (!enabled || !active || destroyed) return;
      const currentTop = stickyTop();
      if (top !== currentTop) resize();
      const rect = wrapper.getBoundingClientRect();
      const distance = Math.max(1, rect.height - stage.getBoundingClientRect().height);
      wanted = Math.round(clamp((top - rect.top) / distance, 0, 1) * (frames.length - 1));
      // Replace stale queued work after fast scrolling. In-flight work is capped at two.
      queue = [wanted, wanted + 1, wanted - 1, wanted + 2, wanted - 2].filter(index => index >= 0 && index < frames.length && !cache.has(index) && !loading.has(index) && !failed.has(index));
      if (cache.has(wanted)) { if (needsPaint || painted !== wanted) draw(wanted); }
      else if (painted < 0 && cache.size) draw([...cache.keys()].sort((a, b) => Math.abs(a - wanted) - Math.abs(b - wanted))[0]);
      else if (needsPaint && painted >= 0) draw(painted);
      // Before the first decoded frame, keep the final-frame poster and its matching content.
      if (painted < 0) showOverlays(frames.length - 1);
      pump();
    }
    function schedule() { if (enabled && active && !raf) raf = requestAnimationFrame(tick); }
    function clearImages() {
      loading.forEach(cancel => cancel()); loading.clear(); cache.clear(); queue = [];
      wanted = painted = -1;
      wrapper.classList.remove('has-frame');
    }
    function reconcile() {
      const allow = !reduced.matches && !navigator.connection?.saveData && !(config.disableOnMobile && Math.min(window.innerWidth, window.innerHeight) < 768);
      if (allow !== enabled) {
        enabled = allow;
        wrapper.classList.toggle('is-enhanced', enabled);
        if (!enabled) clearImages();
        failed.clear();
        showOverlays(frames.length - 1);
      }
      resize(); schedule();
    }
    const io = typeof IntersectionObserver === 'function' ? new IntersectionObserver(entries => {
      active = entries[0].isIntersecting;
      if (active) { schedule(); pump(); }
    }, { rootMargin: threshold + 'px 0px' }) : null;
    if (io) io.observe(wrapper); else active = true;
    const ro = typeof ResizeObserver === 'function' ? new ResizeObserver(resize) : null;
    ro?.observe(visual);
    listen(window, 'scroll', schedule, { passive: true });
    listen(window, 'resize', reconcile, { passive: true });
    listen(window, 'online', () => { failed.clear(); schedule(); });
    listen(reduced, 'change', reconcile);
    listen(navigator.connection, 'change', reconcile);
    listen(wrapper, 'focusout', () => setTimeout(() => { if (enabled) showOverlays(painted < 0 ? frames.length - 1 : painted); }, 0));
    instances.set(wrapper, () => {
      destroyed = true; cancelAnimationFrame(raf); clearImages(); io?.disconnect(); ro?.disconnect();
      disposers.forEach(dispose => dispose()); instances.delete(wrapper);
    });
    reconcile();
  }
  function boot() {
    document.querySelectorAll('.shseq-frame-sequence[data-shseq]').forEach(init);
    // Elementor can replace a widget without a page navigation.
    const changes = new MutationObserver(records => {
      instances.forEach((destroy, node) => { if (!node.isConnected) destroy(); });
      records.forEach(record => record.addedNodes.forEach(node => {
        if (node.nodeType !== 1) return;
        if (node.matches('.shseq-frame-sequence[data-shseq]')) init(node);
        node.querySelectorAll('.shseq-frame-sequence[data-shseq]').forEach(init);
      }));
    });
    changes.observe(document.body, { childList: true, subtree: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();
