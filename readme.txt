=== StoryBoard Live ===
Contributors: aminvala
Tags: scroll animation, hero, frame sequence, parallax, scroll storytelling
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn any page hero into a cinematic frame-sequence animation that plays as the visitor scrolls — no video, no JavaScript bloat.

== Description ==

**StoryBoard Live** lets you replace the static hero image on any WordPress page with a scroll-driven frame sequence — exactly like the effect used by Apple's product pages, scrollsequence.com, and high-converting landing pages.

Upload a set of WebP images (your "frames") and the plugin handles everything else:

* The hero is pinned while the visitor scrolls
* Frames advance frame-by-frame in sync with the scroll position
* Text and CTA overlays fade in at exactly the right frame
* Works with any theme via shortcode or Gutenberg block

**No jQuery. No external CDN. No video file to encode.**

= How it works =

1. Create a new **Sequence** in the admin
2. Upload your WebP frames (24–36 recommended)
3. Set the Golden Master (the final frame — your design target)
4. Add overlay content steps (headline, CTA, badge text)
5. Paste `[storyboard_live id="123"]` or use the block

The plugin validates every upload against the Golden Master automatically, so your frames always arrive at the exact final composition.

= Pro: AI-Assisted Frame Generation =

On the Pro plan, you do not need to pre-render frames. Just:

1. Upload your Golden Master (End Frame)
2. Write a short text prompt describing the opening scene
3. Click **Generate** — the plugin creates the Start Frame with DALL·E 3 and interpolates all 24–36 frames with the FILM model via Replicate
4. Generation runs in the background (Action Scheduler) — you are notified when frames are ready

BYOK (Bring Your Own Key): your OpenAI and Replicate API keys are stored on your server and never pass through our infrastructure. A privacy notice is shown on the Settings page.

= Templates =

Three built-in overlay templates are included:

* **Creative Studio** — headline · subtitle · CTA
* **Art Store Hero** — eyebrow · main-title · subtitle · dual CTA · trust badges
* **Minimal Portfolio** — single title · tagline

= Shortcode =

`[storyboard_live id="123"]`

Optionally: `scroll_length="300"` (vh units, default 200), `mobile="false"` to show static on mobile.

= Block =

Search for **StoryBoard Live** in the Gutenberg inserter. The block exposes the same options as the shortcode.

= System Requirements =

* WordPress 6.2 or later
* PHP 8.0 or later
* WebP-capable server (GD or Imagick)
* For AI generation (Pro): [Action Scheduler](https://wordpress.org/plugins/action-scheduler/) plugin (or WooCommerce)

= Privacy =

This plugin does not collect visitor data. The Pro AI generation feature sends images and prompts to OpenAI and Replicate under the administrator's own API accounts. See the Settings page for the full disclosure notice.

== Installation ==

1. Upload the `storyboard-live` folder to the `/wp-content/plugins/` directory, or install it through the WordPress plugin screen.
2. Activate the plugin through the **Plugins** screen.
3. Navigate to **StoryBoard Live → Dashboard** to get started.
4. (Pro only) Go to **StoryBoard Live → Settings** and enter your OpenAI and Replicate API keys.

== Frequently Asked Questions ==

= What image format should I use for frames? =

WebP is strongly recommended — it gives the best balance of quality and file size. JPEG and PNG are also accepted but produce larger payloads. Aim for frames under 100 KB each.

= How many frames do I need? =

24 frames produce a smooth 2-second animation. 36 frames give a more cinematic result. The Free plan supports up to 24 frames; Pro supports 36.

= Does this work with page builders? =

Yes. Use the `[storyboard_live id="123"]` shortcode inside any page builder text/shortcode element. Native Elementor and Divi widget support is planned for a future release.

= Does the animation work on mobile? =

Yes, by default. You can disable it on mobile from **Settings → Mobile animation** — the last frame (your Golden Master) is shown as a static image instead.

= Do I need WooCommerce for AI generation? =

Not necessarily. You need the **Action Scheduler** plugin, which is bundled with WooCommerce but also available as a standalone plugin. The Free plan manual frame upload does not require Action Scheduler at all.

= Will my API keys leave my server? =

No. Your OpenAI and Replicate API keys are stored in your WordPress database and are called directly from your server to the provider's API. StoryBoard Live's infrastructure never sees your keys.

= Can I duplicate a sequence? =

Yes. Use the **Duplicate** row action on the Sequences list screen. Frames are shared (not copied) to keep storage usage low.

= What happens if a frame fails the Golden Master validation? =

The plugin shows a warning in the media upload area and flags the frame with a yellow border on the upload grid. You can still save and publish — the validation is advisory, not blocking.

= Is there a preview mode? =

Yes. Use the **Preview** link in the Sequence editor. Preview pages are protected by a short-lived token and are not indexable.

== Screenshots ==

1. Dashboard — sequences at a glance with frame count, status and quick actions
2. Sequence editor — Golden Master upload, frame upload grid, overlay content steps
3. Template selector — choose a built-in overlay template as a starting point
4. Settings — API keys for AI generation with live Test Connection buttons
5. Frontend — Art Store Hero template mid-scroll with dual CTA visible
6. Frontend mobile — static Golden Master shown on a 375px screen
7. Gutenberg block — StoryBoard Live block with sidebar controls
8. AI generation progress — three-stage progress bar in the meta box

== Changelog ==

= 1.0.0 =
* Initial release
* Frame sequence engine (24–36 WebP frames, scroll-pinned hero)
* Golden Master validation system
* Overlay content steps (headline, CTA, trust badges)
* Three built-in templates (Creative Studio, Art Store Hero, Minimal Portfolio)
* Gutenberg block + shortcode
* Sequence preview with nonce-protected URLs
* Sequence duplicator
* Admin bar quick-access on preview pages
* Pro: AI-assisted frame generation (OpenAI DALL·E 3 + Replicate FILM)
* Pro: Background generation via Action Scheduler with progress tracking
* BYOK API key management with live Test Connection
* Security: IDOR protection, capability checks, nonce verification throughout
* Accessibility: ARIA roles, keyboard navigation, reduced-motion support
* i18n-ready (text domain: sh-sequence-engine)

== Upgrade Notice ==

= 1.0.0 =
Initial release — no upgrade steps required.
