=== استوری برد زنده | StoryBoard Live ===
Contributors: aminakhyar
Tags: scroll, sequence, storytelling, canvas, rtl
Stable tag: 0.7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

روایت‌های تصویری زنده، روان و ماندگار؛ همگام با اسکرول.

== Description ==

استوری برد زنده | StoryBoard Live افزونه‌ای سبک برای روایت تصویری همگام با اسکرول است؛ با هدر واقعی قالب، تحویل طلایی بدون پرش و تجربه واکنش‌گرای سازگار با لمس.

== Changelog ==

= 0.7.1 =
* Fixed the real theme-header reveal in the single-image Golden Master runtime: the engine now progressively reveals the actual theme/Elementor header element near the end of the story instead of only setting an unused CSS variable.
* Fixed a dangling aria-labelledby reference: the section now references the heading only when an h1/h2 overlay is actually rendered, and falls back to aria-label otherwise.
* Fixed a dead siteHeader.enabled condition so the header reveal can genuinely be disabled from the manifest.
* The Golden Master media picker title/button are now translatable, and the preview restores its "No image selected" placeholder after removing an image.

= 0.7.0 =
* Added the single-image Golden Master workflow: the administrator uploads one confirmed final image (frame 120) and the plugin applies the Storyboard Production Sheet rules to it — cinematic scroll-driven entry transform up to the locked master frame, HTML overlay reveals at their frame thresholds, real theme-header reveal, and a reversible golden handoff.
* Added a Golden Master meta box with a desktop-first confirm gate: tablet and mobile masters unlock only after the desktop master is confirmed, and unconfirmed variants safely fall back to the confirmed desktop image.
* Added a Live overlay content editor so every text/heading/CTA stays a live HTML overlay, never baked into the image.
* Added the [storyboard_live id=".." variant=".."] shortcode and an on-demand single-image runtime engine that respects prefers-reduced-motion.

= 0.6.7.1 =
* Added the first Ready Templates workflow without starting M7.
* Added a built-in Creative Studio Production Sheet template based on a 120-frame, 12-beat, four-scene reference-preserving structure.
* Selecting a ready template now creates an independent editable Sequence draft instead of mutating the built-in template.
* Added a no-JavaScript Story Structure editor for scenes, beats, keyframes, real theme-header reveal, and golden handoff fields.
* Kept template content brand-neutral and local for WordPress.org readiness.


= 0.6.7 =
* Added a dedicated portrait demo frame set instead of cropping the desktop sequence at runtime.
* Hardened real theme-header discovery and final-stage reveal without generating a duplicate header.
* Replaced fixed WordPress admin-bar offsets with a runtime-measured offset.
* Added mobile composition rules that keep the visual scene and live-content panel within one viewport composition.
* Updated the bundled demo contract for public WordPress.org use with generic creative-studio content.
* Manifest schema 9 adds explicit desktop/mobile frame sets and final-stage header progress thresholds.

= 0.6.6 =
* Refined mobile and tablet hero geometry so the live content shell sits within a tighter, more user-friendly viewport rhythm on tall screens.
* Added responsive bootstrap scroll-length caps so non-desktop viewports no longer inherit desktop-first spacing before the full runtime loads.
* Replaced project-specific dashboard branding with generic StoryBoard Live product branding suitable for WordPress.org distribution.

= 0.6.5 =
* Fixed the M6.4 preflight timing bug by printing the capability/header preflight directly at the start of wp_head.
* Deferred the heavy Canvas runtime until real wheel, touch, pointer, keyboard, focus, or restored-scroll intent.
* Added progressive static behavior for no-JavaScript, reduced-motion, and runtime-load failures.
* Added visibility-safe header preflight so a visually hidden real theme header is not keyboard-focusable before runtime attachment.
* Compacted the manifest by sharing one frame-set URL collection across responsive variants.
* Deduplicated identical responsive poster source markup and removed forced asynchronous LCP image decoding.
* Memoized and simplified runtime page detection to reduce repeated shortcode parsing during the HTML response.
* Switched the production runtime, bootstrap, preflight, and inline CSS paths to minified build assets.
* Added versioned demo frame URLs for safe cache busting when long-lived server cache headers are configured.
* Preserved the approved desktop scroll length, independent mobile/tablet policies, Golden Handoff, RTL, theme-font inheritance, and the real theme/Elementor header.

= 0.6.4 =
* Rebuilt the responsive demo presentation around a calm editorial UI foundation with safe mobile composition.
* Replaced baked-text demo frames with text-free, crop-safe WebP frames.
* Reserved sequence geometry before first layout to address the measured desktop layout shift.
* Added media-aware entry/golden poster preload hints after Lighthouse identified the poster as the LCP element.
* Reduced initial sequence contention to the target frame plus one warm frame and lowered concurrent frame loading.
* Cached scroll geometry so ordinary scroll ticks no longer request sequence layout measurements.
* Inlined the small page-scoped runtime stylesheet to remove its extra render-blocking network request.
* Kept the real theme or Elementor header as the only site header.
* Preserved reduced-motion, fallback, reverse scroll, Golden Handoff, RTL, and theme-font inheritance.
* Documented Lighthouse findings that belong to the site/server separately from plugin-owned runtime issues.

= 0.6.3 =
* Removed duplicated responsive demo frame sets and returned the package close to the M6.1 footprint.
* Kept one approved demo sequence while preserving responsive runtime policies for future production assets.
* Refined tablet, mobile portrait, and mobile landscape layouts for shorter scrolling and touch-friendly controls.
* Made Skip Story visible on compact touch layouts and moved it inside the sticky story viewport.
* Removed the public debug HUD from demo markup.
* Stopped reacting to VisualViewport address-bar resize noise to reduce mobile canvas churn.
* Updated the WordPress plugin-list description.


= 0.6.2 =
* Removed all plugin-generated demo header markup and styles.
* Added a real theme-header adapter for Elementor Theme Builder and Hello Elementor header output.
* Added a fail-open head preflight to prevent header flash without leaving navigation hidden if runtime initialization fails.
* Added exact accessibility restoration for the real header and its focusable descendants.
* Added safe header timeline configuration to manifest schema v5 without arbitrary selectors.
* Added header pinning only when the original theme header is not already fixed/sticky.
* Added WordPress admin-bar offsets while the real header is runtime-pinned.
* Preserved reverse-scroll behavior, reduced-motion, fallback, and Golden Handoff with the same live theme header.

= 0.6.0 =
* Added manifest schema v3 with an explicit Golden Handoff contract.
* Added HANDOFF and COMPLETE runtime states.
* Handoff now begins only after the configured final frame is actually rendered.
* Golden poster is required to use the exact handoff-frame asset in the M6 contract.
* Added reversible handoff for upward scrolling.
* Added release of decoded frame cache after successful handoff.
* Moved scroll calculations and frame planning into requestAnimationFrame.
* Added stricter manifest validation and safer runtime initialization fallback.
* Added near-viewport activation and loader pausing when the document is hidden or the sequence is off-screen.
* Added stale-request cancellation without counting cancellation as a frame failure.
* Hardened ImageBitmap cleanup during cancelled requests.
* Added final-frame failure fallback instead of leaving the runtime hanging at the end.
* Preserved live HTML during JavaScript-disabled, fallback, reduced-motion, and Golden Handoff modes.
* Corrected the dashboard heading and branding copy used in earlier milestone builds.

= 0.4.0 =
* Added golden-poster fallback policy, no-JavaScript fallback, reduced-motion static mode, and dynamic motion-preference handling.

= 0.3.0 =
* Added manifest-driven runtime core, scheduler, direction-aware loading, decoded-memory budget cache, and runtime state machine.
