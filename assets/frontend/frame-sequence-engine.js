/**
 * StoryBoard Live — Frame Sequence Engine
 * Sprint 2 — Real frame-by-frame scroll animation
 *
 * Architecture:
 *   1. Find all [data-shseq] wrappers on the page.
 *   2. Read the inline JSON manifest from .shseq-manifest-data.
 *   3. Preload the first 3 frames immediately, lazy-load the rest.
 *   4. Use IntersectionObserver + scroll events to:
 *      a. Pin the canvas wrapper while the user scrolls through the hero.
 *      b. Map scroll progress [0–1] to frame index.
 *      c. Draw the current frame on <canvas>.
 *      d. Fade Content Step overlays in/out.
 *   5. Respect prefers-reduced-motion → static last frame.
 *   6. Page Visibility API → pause on hidden tab.
 *   7. Fallback on canvas/JS failure → show last frame <img>.
 *
 * No external dependencies — vanilla JS only.
 */

(function () {
  'use strict';

  // ─────────────────────────────────────────────────────────────────────────
  // Constants
  // ─────────────────────────────────────────────────────────────────────────

  const PRELOAD_COUNT   = 3;     // frames preloaded before scroll
  const SCROLL_THROTTLE = 16;    // ~60fps throttle (ms)

  // ─────────────────────────────────────────────────────────────────────────
  // Reduced-motion check
  // ─────────────────────────────────────────────────────────────────────────

  const prefersReducedMotion = window.matchMedia
    ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
    : false;

  // ─────────────────────────────────────────────────────────────────────────
  // Utility helpers
  // ─────────────────────────────────────────────────────────────────────────

  function clamp(val, min, max) {
    return Math.min(Math.max(val, min), max);
  }

  function lerp(a, b, t) {
    return a + (b - a) * t;
  }

  /** Throttle a function to at most once per `wait` ms. */
  function throttle(fn, wait) {
    var last = 0;
    return function () {
      var now = Date.now();
      if (now - last >= wait) {
        last = now;
        fn.apply(this, arguments);
      }
    };
  }

  /** Load one image, returns a Promise<HTMLImageElement>. */
  function loadImage(url) {
    return new Promise(function (resolve, reject) {
      var img = new Image();
      img.onload  = function () { resolve(img); };
      img.onerror = function () { reject(new Error('Failed to load frame: ' + url)); };
      img.src = url;
    });
  }

  // ─────────────────────────────────────────────────────────────────────────
  // SequencePlayer — one instance per [data-shseq] on the page
  // ─────────────────────────────────────────────────────────────────────────

  function SequencePlayer(wrapper, manifest) {
    this.wrapper    = wrapper;
    this.manifest   = manifest;
    this.stage      = wrapper.querySelector('.shseq-stage');
    this.canvas     = wrapper.querySelector('.shseq-canvas');
    this.overlays   = Array.from(wrapper.querySelectorAll('.shseq-overlay'));
    this.ctx        = this.canvas ? this.canvas.getContext('2d') : null;

    this.frames     = manifest.frames;      // [{url, width, height, alt}]
    this.steps      = manifest.steps;       // [{scroll_pct, heading, …}]
    this.totalFrames = manifest.totalFrames;

    this.images     = new Array(this.totalFrames); // HTMLImageElement cache
    this.currentFrame = 0;
    this.raf        = null;
    this.paused     = false;
    this.scrollProgress = 0;

    // Sticky scroll zone
    this.scrollHeight = 0;  // calculated on init
    this.stageTop     = 0;
    this.stageBottom  = 0;

    this._init();
  }

  SequencePlayer.prototype._init = function () {
    var self = this;

    if (!this.canvas || !this.ctx) {
      this._showFallback();
      return;
    }

    // Reduced motion → just show last frame statically
    if (prefersReducedMotion) {
      this._loadFrame(this.totalFrames - 1, function (img) {
        if (img) self._drawFrame(img);
      });
      this._showReducedOverlays();
      return;
    }

    // Set scroll height from manifest (default 420 vh)
    var vh = Math.max(document.documentElement.clientHeight, window.innerHeight || 0);
    this.scrollHeight = (manifest.scrollLengthVh / 100) * vh;

    // Resize canvas to match stage
    this._resizeCanvas();

    // Preload first N frames, then start engine
    this._preloadFirst(PRELOAD_COUNT, function () {
      self._startEngine();
    });

    // Lazy-load the rest in background
    this._lazyLoadAll();

    // Page visibility pause
    document.addEventListener('visibilitychange', function () {
      self.paused = document.hidden;
      if (!self.paused && self.raf === null) {
        self._scheduleRaf();
      }
    });

    // Resize handler
    window.addEventListener('resize', throttle(function () {
      self._resizeCanvas();
      self._updateScrollBounds();
      self._render();
    }, 200));
  };

  /** Resize canvas to match the stage dimensions. */
  SequencePlayer.prototype._resizeCanvas = function () {
    if (!this.stage || !this.canvas) return;
    var rect = this.stage.getBoundingClientRect();
    var dpr  = window.devicePixelRatio || 1;
    this.canvas.width  = Math.round(rect.width  * dpr);
    this.canvas.height = Math.round(rect.height * dpr);
    this.canvas.style.width  = rect.width  + 'px';
    this.canvas.style.height = rect.height + 'px';
    this.ctx.scale(dpr, dpr);
  };

  /** Calculate where the sequence starts/ends in document scroll space. */
  SequencePlayer.prototype._updateScrollBounds = function () {
    var rect     = this.wrapper.getBoundingClientRect();
    var scrollY  = window.scrollY || window.pageYOffset;
    this.stageTop    = rect.top + scrollY;
    this.stageBottom = this.stageTop + this.scrollHeight;
  };

  // ─────────────────── Frame Loading ───────────────────────────────────────

  SequencePlayer.prototype._loadFrame = function (index, callback) {
    var self = this;
    if (index < 0 || index >= this.totalFrames) { callback && callback(null); return; }
    if (this.images[index]) { callback && callback(this.images[index]); return; }

    var frameData = this.frames[index];
    if (!frameData || !frameData.url) { callback && callback(null); return; }

    loadImage(frameData.url).then(function (img) {
      self.images[index] = img;
      callback && callback(img);
    }).catch(function () {
      callback && callback(null);
    });
  };

  SequencePlayer.prototype._preloadFirst = function (count, done) {
    var loaded = 0;
    var target = Math.min(count, this.totalFrames);
    if (target === 0) { done && done(); return; }

    for (var i = 0; i < target; i++) {
      this._loadFrame(i, function () {
        loaded++;
        if (loaded >= target) { done && done(); }
      });
    }
  };

  SequencePlayer.prototype._lazyLoadAll = function () {
    var self = this;
    var i = PRELOAD_COUNT;
    (function loadNext() {
      if (i >= self.totalFrames) return;
      self._loadFrame(i, function () {
        i++;
        // Use requestIdleCallback if available, otherwise setTimeout
        if (window.requestIdleCallback) {
          window.requestIdleCallback(loadNext, { timeout: 500 });
        } else {
          setTimeout(loadNext, 80);
        }
      });
    })();
  };

  // ─────────────────── Engine Start ────────────────────────────────────────

  SequencePlayer.prototype._startEngine = function () {
    var self = this;

    // Calculate scroll bounds
    this._updateScrollBounds();

    // Throttled scroll handler
    var onScroll = throttle(function () {
      self._onScroll();
    }, SCROLL_THROTTLE);

    window.addEventListener('scroll', onScroll, { passive: true });

    // Trigger once on start
    this._onScroll();
  };

  SequencePlayer.prototype._onScroll = function () {
    var scrollY = window.scrollY || window.pageYOffset;

    // Progress = 0 when at top of sequence, 1 at bottom
    var progress = clamp(
      (scrollY - this.stageTop) / (this.stageBottom - this.stageTop),
      0, 1
    );

    this.scrollProgress = progress;

    // Map progress to frame index
    var frameIndex = Math.round(progress * (this.totalFrames - 1));

    if (frameIndex !== this.currentFrame) {
      this.currentFrame = frameIndex;
      this._scheduleRaf();
    }

    // Update overlays
    this._updateOverlays(progress * 100); // scroll_pct
  };

  SequencePlayer.prototype._scheduleRaf = function () {
    if (this.raf !== null || this.paused) return;
    var self = this;
    this.raf = requestAnimationFrame(function () {
      self.raf = null;
      self._render();
    });
  };

  // ─────────────────── Rendering ───────────────────────────────────────────

  SequencePlayer.prototype._render = function () {
    var img = this.images[this.currentFrame];
    if (img) {
      this._drawFrame(img);
    } else {
      // Frame not loaded yet — try nearest loaded neighbour
      var fallback = this._nearestLoaded(this.currentFrame);
      if (fallback) this._drawFrame(fallback);
    }
  };

  SequencePlayer.prototype._drawFrame = function (img) {
    if (!this.ctx || !this.canvas) return;

    var cw = this.canvas.width  / (window.devicePixelRatio || 1);
    var ch = this.canvas.height / (window.devicePixelRatio || 1);
    var iw = img.naturalWidth;
    var ih = img.naturalHeight;

    // Cover fit: scale image to fill canvas, centred
    var scale = Math.max(cw / iw, ch / ih);
    var sw    = iw * scale;
    var sh    = ih * scale;
    var sx    = (cw - sw) / 2;
    var sy    = (ch - sh) / 2;

    this.ctx.clearRect(0, 0, cw, ch);
    this.ctx.drawImage(img, sx, sy, sw, sh);
  };

  SequencePlayer.prototype._nearestLoaded = function (index) {
    // Search outward from index for a loaded frame
    for (var d = 1; d < this.totalFrames; d++) {
      if (index - d >= 0 && this.images[index - d])   return this.images[index - d];
      if (index + d < this.totalFrames && this.images[index + d]) return this.images[index + d];
    }
    return null;
  };

  // ─────────────────── Content Step Overlays ───────────────────────────────

  SequencePlayer.prototype._updateOverlays = function (currentPct) {
    if (!this.overlays.length || !this.steps.length) return;

    for (var i = 0; i < this.overlays.length; i++) {
      var step   = this.steps[i];
      var el     = this.overlays[i];
      if (!step || !el) continue;

      var stepPct   = step.scroll_pct;
      // Show from stepPct - 5% to stepPct + 15%
      var showStart = stepPct - 5;
      var showEnd   = stepPct + 15;
      var visible   = currentPct >= showStart && currentPct <= showEnd;

      if (visible) {
        el.classList.add('shseq-overlay--visible');
        // Fade opacity based on proximity to stepPct
        var alpha = 1 - Math.abs(currentPct - stepPct) / 15;
        el.style.opacity = clamp(alpha, 0, 1).toFixed(3);
      } else {
        el.classList.remove('shseq-overlay--visible');
        el.style.opacity = '0';
      }
    }
  };

  /** Reduced motion: show all overlays stacked, no fade logic. */
  SequencePlayer.prototype._showReducedOverlays = function () {
    this.overlays.forEach(function (el) {
      el.classList.add('shseq-overlay--visible');
      el.style.opacity = '1';
    });
  };

  // ─────────────────── Fallback ────────────────────────────────────────────

  SequencePlayer.prototype._showFallback = function () {
    // Show the noscript element content directly if canvas is unavailable
    var noscript = this.wrapper.querySelector('noscript');
    if (noscript) {
      var div = document.createElement('div');
      div.innerHTML = noscript.textContent || noscript.innerText || '';
      this.wrapper.appendChild(div.firstChild);
    }
  };

  // ─────────────────────────────────────────────────────────────────────────
  // Bootstrap — find all sequences on the page
  // ─────────────────────────────────────────────────────────────────────────

  function init() {
    var wrappers = document.querySelectorAll('[data-shseq]');
    if (!wrappers.length) return;

    wrappers.forEach(function (wrapper) {
      var manifestEl = wrapper.querySelector('.shseq-manifest-data');
      if (!manifestEl) return;

      var manifest;
      try {
        manifest = JSON.parse(manifestEl.textContent || manifestEl.innerText);
      } catch (e) {
        console.warn('[StoryBoard Live] Invalid manifest JSON', e);
        return;
      }

      if (!manifest || !manifest.frames || !manifest.frames.length) return;

      // eslint-disable-next-line no-new
      new SequencePlayer(wrapper, manifest);
    });
  }

  // Run after DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
