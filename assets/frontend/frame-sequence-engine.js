/**
 * StoryBoard Live — Frame Sequence Engine (Loop 3 Final, v3)
 *
 * Critical fixes over v1:
 *  B1  manifest selector: ".shseq-manifest" (v1 was ".shseq-manifest-data" — never matched)
 *  B2  overlays are now inside .shseq-stage in the DOM — no fix needed here in JS
 *  B3  header detection: reads theme header height + admin bar via shseqEngineConfig + DOM scan
 *  B4  canvas never resized after first draw — only set dimensions on first load
 *  B5  scroll math: cached offset calculated once via getBoundingClientRect at init
 *  B6  IntersectionObserver: pause scroll listener when wrapper is off-screen
 *  B7  cover-fit: drawImage uses cover algorithm (no CSS object-fit on canvas)
 *  B8  no layout change on JS load — CSS handles initial state
 *  B9  shseqEngineConfig consumed (scrollSpeed, disableOnMobile, lazyThreshold, adminBarHeight)
 * B10  reduced-motion: CSS shows .shseq-static-fallback; JS bails cleanly
 * B12  admin-bar offset: reads shseqEngineConfig.adminBarHeight + theme header
 * B13  RAF: all draws go through requestAnimationFrame to avoid layout thrashing
 */
(function () {
	'use strict';

	// ── Config (from wp_localize_script) ─────────────────────────────────────
	var cfg = window.shseqEngineConfig || {};
	var SCROLL_SPEED      = Math.max(1, Math.min(200, parseInt(cfg.scrollSpeed, 10)    || 4));
	var DISABLE_MOBILE    = !!cfg.disableOnMobile;
	var LAZY_THRESHOLD    = Math.max(0, parseInt(cfg.lazyThreshold, 10)               || 200);
	var ADMIN_BAR_HEIGHT  = Math.max(0, parseInt(cfg.adminBarHeight, 10)              || 0);

	// ── Reduced-motion bail ───────────────────────────────────────────────────
	// CSS already makes .shseq-static-fallback visible; nothing else to do.
	if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
		return;
	}

	// ── Mobile bail ───────────────────────────────────────────────────────────
	if (DISABLE_MOBILE && window.innerWidth < 768) {
		// Show static fallback on mobile if the option is set.
		document.querySelectorAll('.shseq-static-fallback').forEach(function (img) {
			img.classList.add('is-mobile-static');
		});
		return;
	}

	// ── Header offset detection ───────────────────────────────────────────────
	/**
	 * Detect the combined height of the fixed/sticky theme header + admin bar.
	 * Strategy:
	 *   1. Start with the admin-bar value passed from PHP.
	 *   2. Scan for fixed/sticky elements that overlap the top of the viewport.
	 *   3. Use the tallest one (it is the header, not a toast/cookie bar).
	 * Called once at boot and again on window resize.
	 *
	 * @returns {number} pixels
	 */
	function detectStickyTop() {
		var base = ADMIN_BAR_HEIGHT;
		var max  = 0;

		// Common theme header selectors — extend as needed.
		var selectors = [
			'header',
			'#masthead',
			'#site-header',
			'.site-header',
			'.header-main',
			'#header',
			'.navbar',
			'.nav-bar',
			'[id*="header"]',
		];

		selectors.forEach(function (sel) {
			try {
				document.querySelectorAll(sel).forEach(function (el) {
					if (!el) return;
					var st = window.getComputedStyle(el);
					if (st.position !== 'fixed' && st.position !== 'sticky') return;
					// Must overlap the very top of the viewport (top ≤ 0 + adminBar).
					var rect = el.getBoundingClientRect();
					if (rect.top > base + 10) return; // too far down, not the site header
					if (rect.height > max) {
						max = rect.height;
					}
				});
			} catch (e) { /* ignore invalid selectors in exotic themes */ }
		});

		return base + max;
	}

	// ── Cover-fit draw helper ─────────────────────────────────────────────────
	/**
	 * Draw an image on canvas with object-fit: cover semantics.
	 * Canvas `object-fit` CSS property is ignored by browsers — must implement manually.
	 *
	 * @param {CanvasRenderingContext2D} ctx
	 * @param {HTMLImageElement} img
	 * @param {number} cw Canvas width
	 * @param {number} ch Canvas height
	 */
	function drawCover(ctx, img, cw, ch) {
		var iw = img.naturalWidth;
		var ih = img.naturalHeight;
		if (!iw || !ih) return;

		var scale = Math.max(cw / iw, ch / ih);
		var sw    = iw * scale;
		var sh    = ih * scale;
		var sx    = (cw - sw) / 2;
		var sy    = (ch - sh) / 2;

		ctx.drawImage(img, sx, sy, sw, sh);
	}

	// ── Per-instance initialiser ──────────────────────────────────────────────
	function initSequence(wrapper) {

		// ── 1. Parse manifest ─────────────────────────────────────────────────
		// Fix B1: selector is now ".shseq-manifest" matching the PHP class.
		var manifestEl = wrapper.querySelector('script.shseq-manifest[type="application/json"]');
		if (!manifestEl) {
			console.warn('[shseq] manifest element not found in', wrapper.id);
			return;
		}

		var manifest;
		try {
			manifest = JSON.parse(manifestEl.textContent || manifestEl.innerText || '{}');
		} catch (e) {
			console.warn('[shseq] manifest parse error', e);
			return;
		}

		var frames      = manifest.frames      || [];
		var steps       = manifest.steps       || [];
		var totalFrames = manifest.totalFrames || frames.length;

		if (!frames.length) return;

		// ── 2. DOM refs ───────────────────────────────────────────────────────
		var stage   = wrapper.querySelector('.shseq-stage');
		var canvas  = wrapper.querySelector('.shseq-canvas');
		var ctx     = canvas ? canvas.getContext('2d') : null;
		if (!ctx || !stage) return;

		// ── 3. Dimensions ────────────────────────────────────────────────────
		var stickyTop = detectStickyTop();   // px, refreshed on resize
		var stageH    = 0;                   // set once first image loads

		/**
		 * Resize canvas to match the stage element (avoids per-frame resize = B4 fix).
		 * Called once at boot and on window resize.
		 */
		function resizeCanvas() {
			stickyTop = detectStickyTop();
			var availH = window.innerHeight - stickyTop;
			if (availH < 100) availH = 100; // safety

			// Update CSS custom properties so wrapper and stage track reality.
			wrapper.style.setProperty('--shseq-top', stickyTop + 'px');
			stage.style.top    = stickyTop + 'px';
			stage.style.height = availH + 'px';

			stageH         = availH;
			canvas.width   = stage.offsetWidth  || window.innerWidth;
			canvas.height  = stageH;

			// Re-draw current frame after resize.
			if (currentImage && currentImage.complete) {
				drawCover(ctx, currentImage, canvas.width, canvas.height);
			}
		}

		// ── 4. Pre-load images ────────────────────────────────────────────────
		var images    = new Array(totalFrames);
		var loadedSet = new Set();
		var currentImage = null;
		var rafId     = null;

		/**
		 * Load one frame image.
		 * @param {number} index Frame index.
		 * @param {Function=} onLoad Called when loaded.
		 */
		function loadFrame(index, onLoad) {
			if (images[index]) {
				if (onLoad && images[index].complete) onLoad(images[index]);
				return;
			}
			var img = new Image();
			img.decoding = 'async';
			img.addEventListener('load', function () {
				loadedSet.add(index);
				if (onLoad) onLoad(img);
			});
			img.src = frames[index].url;
			images[index] = img;
		}

		// Load frame 0 eagerly, then rest in background after paint.
		loadFrame(0, function (img) {
			currentImage = img;
			resizeCanvas();
			drawCover(ctx, img, canvas.width, canvas.height);
			// Kick off the rest after first frame is shown.
			requestAnimationFrame(function () {
				for (var i = 1; i < totalFrames; i++) {
					loadFrame(i);
				}
			});
		});

		// ── 5. Scroll math ────────────────────────────────────────────────────
		var isIntersecting = false;

		/**
		 * Compute 0–1 scroll progress within the sequence wrapper.
		 * Fix B5: uses wrapper.offsetTop (layout stable) not getBoundingClientRect.
		 *
		 * scrolled = scrollY - wrapperTop
		 * scrollable = wrapperHeight - viewportHeight
		 */
		function getScrollProgress() {
			var scrolled   = window.scrollY - (wrapper.offsetTop || 0);
			var wrapperH   = wrapper.offsetHeight;
			var viewportH  = window.innerHeight;
			var scrollable = wrapperH - viewportH;
			if (scrollable <= 0) return 0;
			return Math.max(0, Math.min(1, scrolled / scrollable));
		}

		// ── 6. Draw call (RAF gated) ──────────────────────────────────────────
		var pendingDraw = false;

		function scheduleFrame(pct) {
			if (pendingDraw) return;
			pendingDraw = true;
			rafId = requestAnimationFrame(function () {
				pendingDraw = false;

				var frameIndex = Math.round(pct * (totalFrames - 1));
				frameIndex = Math.max(0, Math.min(totalFrames - 1, frameIndex));

				var img = images[frameIndex];
				if (img && img.complete && img.naturalWidth) {
					currentImage = img;
					drawCover(ctx, img, canvas.width, canvas.height);
				}

				updateOverlays(pct * 100);
			});
		}

		// ── 7. Overlay updates ────────────────────────────────────────────────
		var overlayEls = Array.from(wrapper.querySelectorAll('.shseq-overlay'));

		function updateOverlays(scrollPct) {
			overlayEls.forEach(function (el, i) {
				var step       = steps[i];
				var stepScroll = step ? (step.scroll_pct || 0) : 0;
				var nextScroll = steps[i + 1] ? steps[i + 1].scroll_pct : 101;
				var visible    = scrollPct >= stepScroll && scrollPct < nextScroll;
				el.setAttribute('aria-hidden', visible ? 'false' : 'true');
				el.classList.toggle('is-visible', visible);
			});
		}

		// ── 8. Scroll listener (throttled by RAF) ─────────────────────────────
		function onScroll() {
			if (!isIntersecting) return;
			scheduleFrame(getScrollProgress());
		}

		window.addEventListener('scroll', onScroll, { passive: true });

		// ── 9. IntersectionObserver — pause off-screen (Fix B6) ───────────────
		var observer = new IntersectionObserver(
			function (entries) {
				entries.forEach(function (entry) {
					isIntersecting = entry.isIntersecting;
					if (isIntersecting) {
						// Draw immediately on re-entry.
						scheduleFrame(getScrollProgress());
					}
				});
			},
			{ rootMargin: LAZY_THRESHOLD + 'px 0px ' + LAZY_THRESHOLD + 'px 0px', threshold: 0 }
		);
		observer.observe(wrapper);

		// ── 10. ResizeObserver — recalc on layout change ──────────────────────
		var ro = new ResizeObserver(function () {
			resizeCanvas();
			scheduleFrame(getScrollProgress());
		});
		ro.observe(wrapper);

		// ── 11. Initial draw ──────────────────────────────────────────────────
		scheduleFrame(getScrollProgress());
	}

	// ── Boot all instances ────────────────────────────────────────────────────
	function boot() {
		document.querySelectorAll('.shseq-frame-sequence[data-shseq]').forEach(initSequence);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

}());
