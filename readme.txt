=== StoryBoard Live — Scroll-Driven Visual Storytelling ===
Contributors: aminakhyar
Tags: scroll, animation, storytelling, parallax, visual
Requires at least: 6.4
Tested up to: 6.8
Stable tag: 0.7.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create scroll-driven visual stories from a single confirmed image. No frames to export, no JavaScript to write.

== Description ==

**StoryBoard Live** turns one confirmed "Golden Master" image into a full scroll-driven visual experience — with live HTML overlays, a cinematic camera animation, and a smooth theme-header reveal — all without baking text or logos into your image.

= How it works =

1. Choose a ready-made Production Sheet template (or start from scratch).
2. Upload your final image as the Golden Master.
3. Confirm it — then add your live text, headings, and CTA links.
4. Embed with `[storyboard_live id="YOUR_ID"]`.

That's it. The plugin handles the scroll timing, overlay reveals, and the handoff to your real site content.

= Key features =

* **Single-image workflow** — No frame sequences to export. Upload one final image and the plugin derives the entire animation from it.
* **Live HTML overlays** — Text, headings, and call-to-action buttons are always real HTML, never baked into the image. Screen readers can read them. Search engines can index them.
* **Cinematic camera animation** — A smooth scale + opacity entry transform plays up to the locked "master frame", then freezes — matching the Production Sheet rule that the hero is locked at the reference frame.
* **Real theme-header reveal** — Your existing WordPress or Elementor header slides in naturally near the end of the story. No duplicate headers, no hidden navigation.
* **Golden Handoff** — The story hands off cleanly to your real page content without a hard cut.
* **Responsive** — Desktop, tablet, and mobile variants with independent Golden Master images and confirmation gates.
* **Reduced-motion aware** — Visitors who prefer reduced motion see the final Golden Master image instead of the animation.
* **RTL ready** — Full right-to-left layout support.
* **No external requests** — No telemetry, no CDN calls, no remote APIs. All assets are local.

= Production Sheet templates =

The plugin ships with a built-in **Creative Studio Production Sheet** template:

* 120 frames, 12 beats, 4 scenes
* 5 reference keyframes (A → E)
* 6 HTML overlay slots (story-mark, eyebrow, title, subtitle, actions, trust)
* Real theme-header reveal starting at frame 109
* Reversible golden handoff at frame 120

= Shortcodes =

* `[storyboard_live id="123"]` — Embed a published sequence by its ID.
* `[storyboard_live id="123" variant="tablet"]` — Request a specific responsive variant.
* `[storyboard_live_demo]` — Embed the bundled demo sequence (for testing).

= For developers =

StoryBoard Live is built with clean, namespaced PHP (PSR-4 autoloading), no global state, and no jQuery dependency. All admin and frontend assets are scoped to their pages — nothing loads globally.

The plugin uses custom post type capabilities, so you can grant or restrict access per role from **StoryBoard Live → Settings → Access**.

= Privacy =

This plugin does not collect, store, or transmit any personal data about site visitors. No cookies are set by the plugin. No external requests are made.

== Installation ==

= Automatic installation (recommended) =

1. Go to **Plugins → Add New** in your WordPress admin.
2. Search for **StoryBoard Live**.
3. Click **Install Now**, then **Activate**.

= Manual installation =

1. Download the plugin ZIP from WordPress.org.
2. Go to **Plugins → Add New → Upload Plugin**.
3. Select the ZIP file and click **Install Now**.
4. Click **Activate Plugin**.

= After activation =

1. Go to **StoryBoard Live** in the left-hand admin menu.
2. Click **Start from a ready template** to create your first sequence from the built-in Creative Studio Production Sheet.
3. Upload a Golden Master image (your final hero image) and confirm it.
4. Add your overlay text in the **Live overlay content** meta box.
5. Embed the sequence on any page or post with `[storyboard_live id="YOUR_ID"]`.

= Requirements =

* WordPress 6.4 or later
* PHP 7.4 or later
* A Composer-built package (the `vendor/autoload.php` file must be present). If you installed from WordPress.org, this is already included. If you cloned from GitHub, run `composer install` first.

== Frequently Asked Questions ==

= Do I need to export video or image frames? =

No. StoryBoard Live works from a single confirmed image — your Golden Master. The plugin derives the scroll-driven animation from the Production Sheet structure you define.

= What is a Golden Master? =

The Golden Master is your final, fully-composed hero image (typically the last frame of the story). It must be free of baked text, logos, and UI elements — those are added as live HTML overlays by the plugin.

= Can I use my own image? =

Yes. Any image in the WordPress Media Library can be used as a Golden Master. For best results, use a landscape image at 1920×1080 px for desktop, with a separate portrait version for mobile.

= What happens on mobile? =

If you confirm separate tablet and mobile Golden Masters, each variant uses its own image. If you only confirm the desktop master, all variants fall back to it — and an admin notice tells you which variants are using the fallback.

= Does it work with Elementor? =

Yes. The shortcode `[storyboard_live id="123"]` can be placed inside any Elementor text or shortcode widget. The plugin also detects Elementor page builder data to correctly load assets only on pages that contain a sequence.

= Does it work with the Block Editor (Gutenberg)? =

Yes. Add a **Shortcode** block and paste `[storyboard_live id="123"]`.

= Can I add the sequence to a Full Site Editing (FSE) template? =

Yes, using a Shortcode block in the Site Editor. Note that in FSE contexts, the shortcode must be placed inside a block that renders the `do_shortcode()` output.

= What if JavaScript is disabled? =

The plugin renders a static fallback image (`<noscript>`) so the page is never blank. The Skip Story link and all overlay text remain accessible via keyboard.

= Is it WCAG 2.1 AA accessible? =

The plugin follows WordPress accessibility standards:
* Skip Story link visible on focus.
* Progress bar with `role="progressbar"` and `aria-valuenow`.
* All overlay text uses semantic HTML (h1–h3, p, a).
* `aria-labelledby` or `aria-label` on the story section.
* Reduced-motion mode shows a static image for users who prefer it.

= Can multiple sequences appear on the same page? =

Each shortcode instance gets a unique ID, so multiple sequences can coexist on one page without conflicts.

= How do I preview a sequence before publishing it? =

In the sequence editor, click the **Preview** button (next to the shortcode) to open a full-screen preview at a unique signed URL. The preview respects draft/pending status, so you don't need to publish first.

= Can I duplicate a sequence? =

Yes. In **Sequences → All Sequences**, hover over any sequence and click **Duplicate**. The copy inherits the structure and overlay content, but confirmations are reset — you must re-confirm each Golden Master.

= Can editors (not just admins) manage sequences? =

By default, only Administrators can create and edit sequences. Go to **StoryBoard Live → Settings → Access** to grant the Editor role the same permissions.

= Does it work on WordPress Multisite? =

The plugin must be activated per site. Network-wide (global) activation is not supported in this version. Each site on the network activates and manages its own sequences independently.

= Where can I get support? =

Post in the WordPress.org support forum for this plugin. Please include your WordPress version, PHP version, active theme, and a description of the issue.

== Screenshots ==

1. **Dashboard** — Onboarding card for new users; stats and recent sequences for returning users.
2. **Ready Templates** — Choose a built-in Production Sheet to create an editable draft in one click.
3. **Story Structure editor** — Edit scenes, beats, keyframes, overlay timeline, and header/handoff timing.
4. **Golden Master meta box** — Upload and confirm your final image per responsive variant.
5. **Live overlay content** — Enter live text, headings, and CTAs that appear as real HTML on top of your image.
6. **Settings page** — Control access, scroll defaults, CDN base URL, and data options.
7. **Frontend — desktop** — The sequence in action: scroll-driven camera animation with live overlay reveals.
8. **Frontend — mobile** — The same sequence on a mobile viewport with the portrait Golden Master.

== Changelog ==

= 0.7.1 =
* Fixed the real theme-header reveal in the single-image Golden Master runtime.
* Fixed a dangling aria-labelledby reference when no heading overlay is rendered.
* Fixed a dead siteHeader.enabled condition in the manifest.
* Made the Golden Master media picker title/button translatable.

= 0.7.0 =
* Added the single-image Golden Master workflow.
* Added a desktop-first Golden Master confirm gate.
* Added the Live overlay content editor.
* Added the [storyboard_live] shortcode and single-image runtime engine.

= 0.6.7.1 =
* Added Ready Templates with the Creative Studio Production Sheet.
* Added a no-JavaScript Story Structure editor.

= 0.6.7 =
* Added a dedicated portrait demo frame set.
* Hardened real theme-header discovery and reveal.
* Added mobile composition rules.

See `changelog.txt` for the full version history.

== Upgrade Notice ==

= 0.7.1 =
Fixes the theme-header reveal and a dangling ARIA reference. Recommended for all users.

== Privacy Policy ==

StoryBoard Live does not collect, store, or transmit any personal data. It sets no cookies and makes no external network requests on behalf of site visitors or administrators. All plugin assets are served locally.
