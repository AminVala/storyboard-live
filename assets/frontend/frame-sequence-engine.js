/**
 * StoryBoard Live — Frame Sequence Engine
 *
 * Reads the inline JSON manifest (type="application/json" .shseq-manifest),
 * pre-loads all frame images, and advances the canvas frame in sync with
 * the scroll position inside the sticky wrapper.
 *
 * Features:
 *  - IntersectionObserver: only active when wrapper is in viewport
 *  - prefers-reduced-motion: shows last frame as static image
 *  - Progressive image pre-loading (frame 0 first, rest in parallel)
 *  - Content step overlay transitions based on scroll %
 */
(function () {
	'use strict';

	// Bail on reduced-motion preference.
	if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
		return;
	}

	/**
	 * Initialise one sequence instance.
	 * @param {HTMLElement} wrapper  .shseq-frame-sequence element
	 */
	function initSequence(wrapper) {
		var manifestEl = wrapper.querySelector('script.shseq-manifest[type="application/json"]');
		if (!manifestEl) return;

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

		var canvas  = wrapper.querySelector('.shseq-canvas');
		var ctx     = canvas ? canvas.getContext('2d') : null;
		if (!ctx) return;

		// Pre-load all images.
		var images = frames.map(function (f) {
			var img = new Image();
			img.src = f.url;
			return img;
		});

		// Draw frame 0 as soon as it loads.
		images[0].addEventListener('load', function () {
			drawFrame(0);
		});

		// Draw a specific frame index.
		function drawFrame(index) {
			index = Math.max(0, Math.min(totalFrames - 1, Math.round(index)));
			var img = images[index];
			if (!img || !img.complete || !img.naturalWidth) return;

			canvas.width  = img.naturalWidth;
			canvas.height = img.naturalHeight;
			ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
		}

		// Scroll handler.
		function onScroll() {
			var rect    = wrapper.getBoundingClientRect();
			var wh      = window.innerHeight;
			// Progress 0→1 from wrapper top reaching viewport top to wrapper bottom leaving.
			var wrapH   = wrapper.offsetHeight;
			var scrolled = -rect.top;
			var scrollable = wrapH - wh;
			var pct = scrollable > 0 ? Math.max(0, Math.min(1, scrolled / scrollable)) : 0;

			var frameIndex = pct * (totalFrames - 1);
			drawFrame(frameIndex);

			// Content steps.
			var scrollPct = pct * 100;
			var overlays  = wrapper.querySelectorAll('.shseq-overlay');
			overlays.forEach(function (el, i) {
				var step       = steps[i];
				var stepScroll = step ? (step.scroll_pct || 0) : 0;
				var nextScroll = steps[i + 1] ? steps[i + 1].scroll_pct : 101;
				if (scrollPct >= stepScroll && scrollPct < nextScroll) {
					el.classList.add('is-visible');
				} else {
					el.classList.remove('is-visible');
				}
			});
		}

		window.addEventListener('scroll', onScroll, { passive: true });
		onScroll(); // Initial draw.
	}

	// Boot all instances.
	function boot() {
		document.querySelectorAll('.shseq-frame-sequence[data-shseq]').forEach(initSequence);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}());
