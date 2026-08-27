# StoryBoard Live

> Scroll-driven frame-sequence hero animation for WordPress — no video, no JavaScript bloat.

[![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-blue?logo=wordpress)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php)](https://php.net)
[![License](https://img.shields.io/badge/License-GPL%20v2%2B-green)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-1.0.0-orange)](https://github.com/AminVala/storyboard-live/releases)
[![i18n](https://img.shields.io/badge/i18n-Persian%20%7C%20English-purple)](./languages)

---

## Table of Contents

1. [What is StoryBoard Live?](#what-is-storyboard-live)
2. [How it works](#how-it-works)
3. [Architecture overview](#architecture-overview)
4. [Directory structure](#directory-structure)
5. [Installation](#installation)
6. [Requirements](#requirements)
7. [Free vs Pro](#free-vs-pro)
8. [Wizard walkthrough](#wizard-walkthrough)
9. [Golden Master system](#golden-master-system)
10. [AI frame generation (Pro)](#ai-frame-generation-pro)
11. [Shortcode reference](#shortcode-reference)
12. [Gutenberg block](#gutenberg-block)
13. [Hooks & filters](#hooks--filters)
14. [Data model](#data-model)
15. [Security](#security)
16. [Accessibility](#accessibility)
17. [Internationalization](#internationalization)
18. [Building for distribution](#building-for-distribution)
19. [Contributing](#contributing)
20. [Changelog](#changelog)
21. [License](#license)

---

## What is StoryBoard Live?

**StoryBoard Live** replaces the static hero image on any WordPress page with a cinematic, scroll-driven frame sequence — the same effect used by Apple's product pages, Scrollsequence.com, and high-converting SaaS landing pages.

The visitor scrolls → the hero is pinned → WebP frames advance one by one in perfect sync → text overlays fade in at the exact scroll positions you choose.

```
┌─────────────────────────────┐
│         Page content        │
├─────────────────────────────┤  ← Sequence starts (sticky)
│                             │
│   [Frame 1 → 2 → … → 36]   │  ← Canvas renders each frame
│                             │     as user scrolls past
│   ┌─────────────────┐       │
│   │  "Your Headline"│  ←────┼── Overlay fades in at scroll 40%
│   │  [Get started]  │       │
│   └─────────────────┘       │
│                             │
├─────────────────────────────┤  ← Sequence ends, page continues
│         Page content        │
└─────────────────────────────┘
```

**No video encoding. No canvas library to maintain. No jQuery. No CDN dependency.**

---

## How it works

### Production pipeline

```
Admin uploads WebP frames ──→ FrameManager stores attachment IDs
                                        │
                          ┌─────────────▼──────────────┐
                          │  _shseq_frames = [1,2,…,36] │  wp_postmeta
                          └─────────────┬──────────────┘
                                        │
                          ┌─────────────▼──────────────┐
                          │   FrameSequenceManifest     │
                          │   builds JSON per-page      │
                          └─────────────┬──────────────┘
                                        │
                    [storyboard_live id="123"]
                                        │
                          ┌─────────────▼──────────────┐
                          │  <script type="app/json">   │  inline manifest
                          │  frame-sequence-engine.js   │  reads + renders
                          └─────────────────────────────┘
```

### Runtime rendering

The frontend JavaScript engine:

1. Reads the inline JSON manifest (no AJAX call)
2. Pre-loads all WebP frames using `new Image()`
3. On `scroll` event: calculates `progress = scrolled / scrollable` (0→1)
4. Maps progress to frame index: `index = progress × (totalFrames - 1)`
5. Draws the frame on a `<canvas>` element via `ctx.drawImage()`
6. Transitions content step overlays at defined scroll-% thresholds
7. On `prefers-reduced-motion`: shows the last frame as a static image

---

## Architecture overview

```
StoryBoard Live
│
├── Boot (plugins_loaded)
│   └── Plugin::boot()
│       ├── Core
│       │   ├── SchemaManager          DB schema versioning
│       │   ├── SequencePostType       CPT: shseq_sequence
│       │   └── RevisionPostType       CPT: shseq_revision (M7+)
│       │
│       ├── AI Pipeline
│       │   ├── OpenAIProvider         DALL·E 3 start-frame generation
│       │   ├── ReplicateProvider      FILM/RIFE frame interpolation
│       │   └── FrameGenerationJob     Action Scheduler async handler
│       │
│       ├── Frontend
│       │   ├── FrameSequenceAssets    Conditional CSS/JS registration
│       │   ├── FrameSequenceManifest  Per-sequence JSON builder
│       │   ├── FrameSequenceShortcode [storyboard_live] renderer
│       │   ├── FrameSequenceBlock     Gutenberg block (server-side render)
│       │   └── DemoPlaceholder        Fallback when demo frames are absent
│       │
│       └── Admin
│           ├── SequenceWizard         5-step creation wizard
│           ├── AdminMenu              Top-level menu (shseq-dashboard)
│           ├── DashboardPage          Stats + sequences table + quick links
│           ├── TemplatesPage          Template card grid
│           ├── GoldenMasterMetaBox    End-frame picker (desktop/tablet/mobile)
│           ├── ContentStepsMetaBox    Overlay steps editor
│           ├── FrameUploadMetaBox     Frame grid + AI prompt
│           ├── SequenceStructureMetaBox Production-sheet editor
│           ├── GoldenMasterValidation Static utility — size/MIME/dimension checks
│           ├── SequencePreview        Nonce-protected preview URL
│           ├── SequenceDuplicator     Row-action duplicate
│           ├── AdminBar               Frontend admin bar for editors
│           ├── AdminAssets            Scoped CSS/JS enqueue
│           ├── PluginLinks            Plugin row action links
│           ├── FallbackNotice         Admin notice for variant fallbacks
│           └── SettingsPage           API keys + Pro toggle + general settings
│
├── Frames
│   ├── FrameManager                   CRUD for _shseq_frames meta array
│   └── FrameNormalizer                MIME / size / dimension validator + resizer
│
├── License
│   └── LicenseManager                 Free/Pro limits (static, no instance needed)
│
├── Templates
│   └── TemplateCatalog                Immutable built-in template definitions
│
└── I18n
    └── I18n                           load_plugin_textdomain() wrapper
```

### Post types

| Post type | Purpose | Visibility |
|-----------|---------|-----------|
| `shseq_sequence` | One hero animation | `show_ui: true`, hidden from front-end |
| `shseq_revision` | Future immutable snapshots (M7) | Completely hidden |

### Custom capabilities

All capabilities are prefixed `shseq_` and are assigned to roles on activation:

| Capability | Administrator | Editor |
|-----------|:---:|:---:|
| `edit_shseq_sequences` | ✓ | ✓ |
| `create_shseq_sequences` | ✓ | ✓ |
| `publish_shseq_sequences` | ✓ | — |
| `delete_shseq_sequences` | ✓ | — |
| `manage_shseq_settings` | ✓ | — |

---

## Directory structure

```
storyboard-live/
│
├── sh-sequence-engine.php          Main plugin file (plugin header)
├── uninstall.php                   Data cleanup on plugin deletion
├── composer.json                   PSR-4 autoload + dev tools
├── build.sh                        Distribution zip builder
├── .distignore                     Files excluded from zip
├── readme.txt                      WordPress.org submission format
├── SUBMISSION_CHECKLIST.md         WP.org pre-submission checklist
│
├── src/
│   ├── Plugin.php                  Composition root
│   ├── AI/
│   │   ├── ProviderInterface.php
│   │   ├── OpenAIProvider.php      DALL·E 3 integration
│   │   └── ReplicateProvider.php   FILM interpolation
│   ├── Admin/
│   │   ├── AdminAssets.php
│   │   ├── AdminBar.php
│   │   ├── AdminMenu.php
│   │   ├── ContentStepsMetaBox.php
│   │   ├── DashboardPage.php
│   │   ├── FallbackNotice.php
│   │   ├── FrameUploadMetaBox.php
│   │   ├── GoldenMasterMetaBox.php
│   │   ├── GoldenMasterValidation.php
│   │   ├── PluginLinks.php
│   │   ├── SequenceDuplicator.php
│   │   ├── SequencePreview.php
│   │   ├── SequenceStructureMetaBox.php
│   │   ├── SequenceWizard.php      ← 5-step creation wizard
│   │   ├── SettingsPage.php
│   │   └── TemplatesPage.php
│   ├── Blocks/
│   │   └── FrameSequenceBlock.php
│   ├── Content/
│   │   ├── RevisionPostType.php
│   │   └── SequencePostType.php
│   ├── Core/
│   │   ├── Activator.php
│   │   ├── Deactivator.php
│   │   └── SchemaManager.php
│   ├── Frames/
│   │   ├── FrameManager.php
│   │   └── FrameNormalizer.php
│   ├── Frontend/
│   │   ├── DemoPlaceholder.php
│   │   ├── FrameSequenceAssets.php
│   │   ├── FrameSequenceManifest.php
│   │   └── FrameSequenceShortcode.php
│   ├── I18n/
│   │   └── I18n.php
│   ├── Jobs/
│   │   └── FrameGenerationJob.php  Action Scheduler handler
│   ├── License/
│   │   └── LicenseManager.php
│   └── Templates/
│       └── TemplateCatalog.php
│
├── assets/
│   ├── admin/
│   │   ├── dashboard.css
│   │   ├── dashboard-rtl.css
│   │   ├── golden-master.min.js    Media picker for Golden Master
│   │   └── blocks/
│   │       └── frame-sequence/
│   │           ├── index.js        Block editor script
│   │           └── index.css
│   └── frontend/
│       ├── frame-sequence.css      Layout + overlay styles
│       └── frame-sequence-engine.js  Canvas scroll engine
│
└── languages/
    ├── sh-sequence-engine-fa_IR.po  Persian translation source
    └── sh-sequence-engine-fa_IR.mo  Persian translation (compiled)
```

---

## Installation

### From GitHub (development)

```bash
# Clone into your plugins directory
cd wp-content/plugins/
git clone https://github.com/AminVala/storyboard-live.git storyboard-live

# Install Composer dependencies (none required for production — only dev tools)
cd storyboard-live
composer install --no-dev
```

### From distribution zip

```bash
# Build the zip
./build.sh --zip
# → dist/storyboard-live-1.0.0.zip

# Upload via WP Admin → Plugins → Add New → Upload Plugin
```

### From WordPress.org (after review approval)

Search for **StoryBoard Live** in the WordPress plugin directory, click Install, then Activate.

### Post-activation

1. Navigate to **StoryBoard Live → Dashboard**
2. Click **+ New Sequence** to open the creation wizard
3. (Pro only) Go to **Settings** and add your OpenAI + Replicate API keys

---

## Requirements

| Requirement | Minimum | Recommended |
|-------------|---------|-------------|
| WordPress | 6.2 | 6.7+ |
| PHP | 8.0 | 8.2+ |
| MySQL | 5.7 | 8.0 |
| PHP extension | GD or Imagick | Imagick |
| (Pro AI) Action Scheduler | 3.6+ | Latest |

> **Action Scheduler** is bundled with WooCommerce. If you don't use WooCommerce, install the standalone [Action Scheduler](https://wordpress.org/plugins/action-scheduler/) plugin. It is only required for AI frame generation on the Pro plan.

---

## Free vs Pro

| Feature | Free | Pro |
|---------|:----:|:---:|
| Sequences (heroes) per site | 1 | 15 |
| Frames per sequence | 24 | 36 |
| Content overlay steps | 3 | 10 |
| Manual frame upload | ✓ | ✓ |
| Gutenberg block | ✓ | ✓ |
| Shortcode | ✓ | ✓ |
| Golden Master validation | ✓ | ✓ |
| Sequence preview | ✓ | ✓ |
| Sequence duplicator | ✓ | ✓ |
| Responsive variant masters | ✓ | ✓ |
| AI Start Frame (DALL·E 3) | — | ✓ |
| AI interpolation (FILM/RIFE) | — | ✓ |
| Background generation (Action Scheduler) | — | ✓ |
| BYOK API keys | — | ✓ |

To enable Pro, go to **StoryBoard Live → Settings → Plan → Enable Pro**.

> In the current release, the Pro toggle is a manual flag. A verified license-key system will replace it in a future update.

---

## Wizard walkthrough

The **5-step wizard** (`StoryBoard Live → Create New`) is the primary creation path:

### Step 1 — Name & Template

- Give the sequence a name (internal; not shown to visitors)
- Optionally pick a built-in template (Creative Studio, Art Store Hero, Minimal Portfolio)
- The template pre-fills the story structure (scenes, beats, overlay slots, frame contract)
- Click **Save & Continue →**

### Step 2 — Upload Frames

- Select images from the WordPress Media Library (multi-select supported)
- Frames are stored as ordered attachment IDs in `_shseq_frames`
- Pro users can enter a text prompt for AI generation (see [AI frame generation](#ai-frame-generation-pro))
- Recommended: WebP at 1280×720 px, under 100 KB per frame

### Step 3 — Content Steps

- Add up to 3 (Free) or 10 (Pro) overlay steps
- Each step defines: **scroll position** (0–100%), **heading**, **paragraph**, **CTA button**, **badge/price**
- Steps fade in when the scroll position reaches the defined threshold

### Step 4 — Golden Master

- Upload the **end frame** (the final frame of the animation) for each responsive variant
- Desktop must be confirmed first; Tablet and Mobile fall back to Desktop if not set
- GoldenMasterValidation checks: MIME type (WebP/JPEG/PNG/AVIF), file size (≤ 8 MB), dimensions (≤ 8000 px)

### Step 5 — Preview & Publish

- A **pre-flight checklist** shows: frames uploaded ✓, desktop Golden Master confirmed ✓, published status
- **Open Preview** generates a nonce-protected, full-fidelity preview URL
- **Publish** sets the sequence to `publish` status (requires at least 1 frame + confirmed desktop master)
- Copy the **embed shortcode** to use on any page or post

---

## Golden Master system

The **Golden Master** is the confirmed final frame of the animation — the exact pixel composition the viewer sees when scrolling is complete.

### Why it matters

- Validates that uploaded frames converge to the same final composition
- Ensures tablet and mobile visitors see an appropriate fallback if variant-specific frames are unavailable
- Used as the `<noscript>` static image fallback

### Variants

| Variant | Breakpoint | Gate |
|---------|-----------|------|
| Desktop | ≥ 1024 px | Must be confirmed first |
| Tablet | 768–1023 px | Unlocked after desktop confirmation |
| Mobile | < 768 px | Unlocked after desktop confirmation |

### Fallback chain

```
Mobile master confirmed?
  ├── Yes → use mobile master
  └── No → use desktop master + emit FallbackNotice (admin notice + HTML comment)
```

### Validation rules

| Rule | Limit | Filterable |
|------|-------|-----------|
| Max file size | 8 MB | `shseq_golden_master_max_bytes` |
| Max dimension | 8000 px (either side) | `shseq_golden_master_max_pixels` |
| Allowed MIME types | JPEG, PNG, WebP, AVIF | — |

---

## AI frame generation (Pro)

The Pro AI pipeline uses **BYOK** (Bring Your Own Key) — your API keys are stored in your WordPress database and called directly from your server. StoryBoard Live's infrastructure never handles your keys.

### Pipeline

```
1. Admin enters a text prompt describing the opening scene
   └── Saved to: _shseq_ai_prompt

2. Admin saves the sequence (with Generate nonce present)
   └── FrameGenerationJob::schedule() queues an async Action Scheduler action

3. Background job fires (shseq_generate_frames hook)

4. Stage 1 — OpenAIProvider::generate_start_frame()
   ├── POST https://api.openai.com/v1/images/generations
   ├── Model: dall-e-3, size: 1792×1024, quality: standard
   └── Result: attachment ID (sideloaded into Media Library)

5. Stage 2 — ReplicateProvider::interpolate()
   ├── POST https://api.replicate.com/v1/predictions
   ├── Model: afiaka87/film-interpolation (FILM)
   ├── Inputs: start_frame URL, end_frame URL (Golden Master)
   ├── Polls until succeeded / failed (max 2 minutes)
   └── Result: array of frame URLs → sideloaded → attachment IDs

6. Stage 3 — FrameManager::set_frames()
   └── Ordered attachment IDs stored in _shseq_frames

7. Status updated to 'done' — admin sees progress bar reach 100%
```

### Status tracking

The generation status is stored in `_shseq_generation_status`:

| Status | Meaning | Progress |
|--------|---------|---------|
| `idle` | No job scheduled | 0% |
| `pending` | Queued, not started | 5% |
| `stage1` | Generating Start Frame (OpenAI) | 20% |
| `stage2` | Interpolating frames (Replicate) | 60% |
| `stage3` | Saving frames to Media Library | 90% |
| `done` | Complete — frames ready | 100% |
| `failed` | Error (see `_shseq_generation_error`) | — |

### Configuration

Go to **StoryBoard Live → Settings → AI Generation**:

| Field | Description |
|-------|-------------|
| OpenAI API Key | `sk-…` from [platform.openai.com/api-keys](https://platform.openai.com/api-keys) |
| Replicate API Token | `r8_…` from [replicate.com/account/api-tokens](https://replicate.com/account/api-tokens) |

Both fields have a **Test Connection** button that validates the credential live without saving.

### Privacy disclosure

When AI generation runs, your images and prompt are sent to OpenAI and Replicate under your own API accounts. Their respective privacy policies apply. A disclosure notice is shown on the Settings page in compliance with WordPress.org guidelines.

---

## Shortcode reference

```
[storyboard_live id="123"]
```

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `id` | int | — | **Required.** Post ID of the Sequence |
| `class` | string | `""` | Extra CSS class added to the wrapper `<div>` |
| `height` | string | `100vh` | CSS `height` of the sticky wrapper (scroll sentinel) |

### Examples

```
[storyboard_live id="42"]
[storyboard_live id="42" height="150vh" class="my-hero"]
```

### Legacy alias

The shortcode `[shseq_sequence]` is registered as an alias for backward compatibility.

### Output structure

```html
<div class="shseq-frame-sequence" id="shseq-42-1"
     data-shseq="true" role="region"
     aria-label="Homepage Hero — scroll animation"
     style="--shseq-height:100vh;">

  <!-- Sticky canvas (pinned during scroll) -->
  <div class="shseq-stage" aria-hidden="true">
    <canvas class="shseq-canvas" aria-label="Homepage Hero"></canvas>
  </div>

  <!-- Content step overlays -->
  <div class="shseq-overlays" aria-hidden="true">
    <div class="shseq-overlay" data-step="0" data-scroll="0">
      <h2 class="shseq-overlay__heading">Your Headline</h2>
      <p class="shseq-overlay__paragraph">Subtext here</p>
      <a class="shseq-overlay__cta" href="/get-started">Get started</a>
    </div>
    <!-- … more steps … -->
  </div>

  <!-- Noscript / reduced-motion fallback -->
  <noscript>
    <img class="shseq-noscript-fallback" src="…last-frame.webp" alt="Homepage Hero">
  </noscript>

  <!-- Inline manifest for the JS engine -->
  <script type="application/json" class="shseq-manifest">
    {"schema":"shseq.frames.manifest","schemaVersion":1,"totalFrames":24,…}
  </script>
</div>
```

---

## Gutenberg block

The **StoryBoard Hero** block (`shseq/frame-sequence`) is available in the block inserter under the **Media** category.

### Block attributes

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `sequenceId` | number | `0` | Sequence post ID |
| `height` | string | `100vh` | Wrapper height |
| `className` | string | `""` | Extra CSS class |

### Block rendering

The block is a **dynamic block** (server-side render). `render_callback` delegates to `FrameSequenceShortcode::render()`, so block and shortcode output are always identical.

The block editor shows a dark placeholder with the sequence ID when a sequence is selected, and a prompt to set the ID when none is selected.

---

## Hooks & filters

### Actions

| Hook | Arguments | Description |
|------|-----------|-------------|
| `shseq_generate_frames` | `int $post_id` | Fired by Action Scheduler to run the AI generation pipeline |
| `wp_ajax_shseq_generation_status` | — | AJAX endpoint: returns generation progress for a sequence |
| `wp_ajax_shseq_test_openai` | — | AJAX: validates an OpenAI API key |
| `wp_ajax_shseq_test_replicate` | — | AJAX: validates a Replicate API token |

### Filters

| Filter | Arguments | Default | Description |
|--------|-----------|---------|-------------|
| `shseq_golden_master_max_bytes` | `int $bytes` | `8388608` (8 MB) | Max file size for Golden Master uploads |
| `shseq_golden_master_max_pixels` | `int $px` | `8000` | Max pixel dimension for Golden Master uploads |

### Usage example

```php
// Raise the Golden Master size limit to 16 MB on high-memory hosts:
add_filter( 'shseq_golden_master_max_bytes', fn() => 16 * 1024 * 1024 );

// Restrict frame uploads to 720 px max dimension:
add_filter( 'shseq_golden_master_max_pixels', fn() => 720 );
```

---

## Data model

All data is stored in standard WordPress tables — no custom database tables.

### `wp_posts`

| Column | Value |
|--------|-------|
| `post_type` | `shseq_sequence` |
| `post_status` | `draft` / `publish` / `private` |
| `post_title` | Internal sequence name |

### `wp_postmeta`

| Meta key | Type | Description |
|----------|------|-------------|
| `_shseq_frames` | `int[]` | Ordered array of Media Library attachment IDs |
| `_shseq_content_steps` | `array[]` | Array of overlay step objects |
| `_shseq_golden_master` | `array` | `{desktop: int, tablet: int, mobile: int}` attachment IDs |
| `_shseq_variant_confirmed` | `array` | `{desktop: bool, tablet: bool, mobile: bool}` |
| `_shseq_structure` | `array` | Production-sheet structure from template |
| `_shseq_template_id` | `string` | Template slug applied to this sequence |
| `_shseq_template_version` | `string` | Template version at time of application |
| `_shseq_ai_prompt` | `string` | Text prompt for AI Start Frame generation |
| `_shseq_ai_prompt_revised` | `string` | Revised prompt returned by DALL·E |
| `_shseq_generation_status` | `string` | `idle` / `pending` / `stage1` / `stage2` / `stage3` / `done` / `failed` |
| `_shseq_generation_error` | `string` | Error message when status is `failed` |
| `_shseq_generation_job_id` | `int` | Action Scheduler action ID |

### `wp_options`

| Option key | Type | Description |
|-----------|------|-------------|
| `shseq_version` | `string` | Installed plugin version |
| `shseq_schema_version` | `int` | DB schema version for future migrations |
| `shseq_is_pro` | `bool` | Pro plan flag |
| `shseq_openai_api_key` | `string` | OpenAI API key (encrypted at rest by WordPress) |
| `shseq_replicate_api_token` | `string` | Replicate API token |
| `shseq_delete_on_uninstall` | `bool` | Delete all data on plugin uninstall |
| `shseq_disable_on_mobile` | `bool` | Show static last frame on mobile |
| `shseq_as_missing_notice` | `bool` | Flag to show Action Scheduler missing notice |

### Manifest schema

The inline JSON manifest injected into shortcode output:

```json
{
  "schema": "shseq.frames.manifest",
  "schemaVersion": 1,
  "instanceId": "shseq-42-1",
  "totalFrames": 24,
  "frames": [
    { "url": "https://…/frame-01.webp", "width": 1280, "height": 720, "alt": "" },
    …
  ],
  "steps": [
    {
      "scroll_pct": 0,
      "heading": "Your Headline",
      "paragraph": "Subtext",
      "cta_text": "Get started",
      "cta_url": "https://…",
      "logo_url": "",
      "badge_text": ""
    }
  ],
  "scrollLengthVh": 420,
  "motion": {
    "respectReducedMotion": true,
    "reducedMode": "last-frame-static"
  }
}
```

> **Security note:** Attachment IDs are never included in the public manifest. Only URLs and metadata that are already public are exposed.

---

## Security

| Area | Mechanism |
|------|-----------|
| All AJAX handlers | `check_ajax_referer()` + `current_user_can()` |
| All meta-box saves | `wp_verify_nonce()` + `current_user_can('edit_post', $id)` |
| Autosave/revision guard | `wp_is_post_autosave()` + `wp_is_post_revision()` |
| Admin page access | `current_user_can()` gate at top of every `render()` |
| Golden Master attachment | `get_post_type() === 'attachment'` + `wp_attachment_is_image()` |
| Sequence preview URL | Nonce-protected (`wp_create_nonce` / `wp_verify_nonce`) |
| Public manifest | Attachment IDs excluded; only resolved URLs exposed |
| SQL queries | All via `$wpdb->prepare()` in `uninstall.php` |
| Output escaping | `esc_html()`, `esc_url()`, `esc_attr()`, `wp_json_encode()` throughout |
| AI API keys | Stored in `wp_options`; never echoed in page source |
| Duplicate action | `admin-post.php` + nonce per post ID |
| Capability model | Custom primitive caps; `map_meta_cap: true` for meta caps |

---

## Accessibility

| Feature | Implementation |
|---------|---------------|
| Sequence wrapper | `role="region"` + `aria-label="{title} — scroll animation"` |
| Canvas | `aria-label="{alt text of last frame}"` |
| Overlays | `aria-hidden="true"` (visual decoration; content is separate) |
| Noscript fallback | `<noscript><img alt="…"></noscript>` with meaningful alt text |
| Reduced motion | `prefers-reduced-motion: reduce` → engine shows static last frame |
| CSS reduced motion | `@media (prefers-reduced-motion: reduce)` in `frame-sequence.css` |
| Admin controls | All buttons have `aria-label` or visible labels |
| Golden Master lock | `disabled` attribute on locked inputs; lock note text in DOM |

---

## Internationalization

The plugin is fully i18n-ready. All user-facing strings pass through WordPress translation functions (`__()`, `esc_html_e()`, `esc_html__()`, etc.).

### Supported languages

| Language | Locale | File |
|----------|--------|------|
| English | `en_US` | (source strings) |
| Persian (Farsi) | `fa_IR` | `languages/sh-sequence-engine-fa_IR.mo` |

### Adding a new translation

```bash
# 1. Generate the POT file
wp i18n make-pot . languages/sh-sequence-engine.pot \
  --domain=sh-sequence-engine \
  --exclude=vendor,node_modules,tests,dist

# 2. Copy the POT to your locale
cp languages/sh-sequence-engine.pot languages/sh-sequence-engine-de_DE.po

# 3. Edit the .po file with Poedit or any gettext editor

# 4. Compile to .mo
msgfmt languages/sh-sequence-engine-de_DE.po \
       -o languages/sh-sequence-engine-de_DE.mo
```

WordPress will automatically load the `.mo` file when the site language matches the locale.

---

## Building for distribution

```bash
# Prerequisites: composer, zip, msgfmt (gettext)

# 1. Install Composer (dev + prod)
composer install

# 2. Run WordPress Coding Standards check
composer run phpcs

# 3. Generate/update the POT file
wp i18n make-pot . languages/sh-sequence-engine.pot --domain=sh-sequence-engine

# 4. Compile .mo files
msgfmt languages/sh-sequence-engine-fa_IR.po \
       -o languages/sh-sequence-engine-fa_IR.mo

# 5. Build the distribution zip
./build.sh --zip
# → dist/storyboard-live-1.0.0.zip
```

### SVN release (WordPress.org)

```bash
# Check out your SVN repository (granted after review approval)
svn co https://plugins.svn.wordpress.org/sh-sequence-engine wporg-svn
cd wporg-svn

# Copy built plugin to trunk
rsync -a --delete path/to/dist/storyboard-live/ trunk/

# Copy assets (banner, icon, screenshots) to assets/ — NOT trunk/
cp path/to/.wordpress-org/*.png assets/

# Add new files and commit
svn add --force trunk/ assets/
svn ci -m "Add version 1.0.0"

# Tag the release
svn cp trunk/ tags/1.0.0/
svn ci -m "Tag version 1.0.0"
```

---

## Contributing

Pull requests are welcome. Before opening a PR:

1. **Check coding standards**
   ```bash
   composer run phpcs
   ```

2. **Run tests**
   ```bash
   composer run test
   ```

3. **Follow WordPress Coding Standards** — [WPCS documentation](https://github.com/WordPress/WordPress-Coding-Standards)

4. **Branch naming**
   - `feat/your-feature-name`
   - `fix/short-bug-description`
   - `chore/what-you-changed`

5. **Commit message format**
   ```
   type(scope): short description

   # Examples:
   feat(wizard): add step 5 preview with preflight checklist
   fix(content-steps): escape %% in printf format string
   chore(i18n): add fa_IR Persian translation
   ```

### Reporting a security vulnerability

Do **not** open a public GitHub issue for security vulnerabilities. Email [Amindeablo@gmail.com](mailto:Amindeablo@gmail.com) with the subject line `[StoryBoard Live] Security`.

---

## Changelog

### 1.0.0 — 2026-08-27

#### Added
- Frame sequence engine: 24–36 WebP frames, sticky canvas, scroll-driven rendering
- 5-step creation wizard (Name → Frames → Content Steps → Golden Master → Preview & Publish)
- Golden Master validation system (MIME, size, dimensions per variant)
- Content Steps meta box: up to 3 (Free) / 10 (Pro) overlay steps with scroll-% trigger
- Three built-in templates: Creative Studio, Art Store Hero, Minimal Portfolio
- Gutenberg block (`shseq/frame-sequence`) — server-side render via shortcode
- Shortcode `[storyboard_live id="N"]` (alias: `[shseq_sequence]`)
- Sequence preview with nonce-protected URLs
- Sequence duplicator row action
- Admin bar quick-access on frontend pages with embedded sequences
- Pro: AI Start Frame generation via OpenAI DALL·E 3 (BYOK)
- Pro: Frame interpolation via Replicate FILM model (BYOK)
- Pro: Background generation with Action Scheduler + 3-stage progress tracking
- BYOK API key management with live Test Connection buttons in Settings
- Bilingual UI: English + Persian (fa_IR)
- Custom capability model with role assignment on activation
- Security: IDOR protection, nonce verification, capability checks throughout
- Accessibility: ARIA roles, reduced-motion support, noscript fallback
- WordPress.org submission assets: `readme.txt`, `uninstall.php`, `build.sh`, `SUBMISSION_CHECKLIST.md`

#### Fixed
- `printf()` ValueError on PHP 8: `%y` in format string (escape to `%%`)
- `GoldenMasterValidation::register_hooks()` fatal — class is static utility, no instantiation
- `SHSEQ_BASENAME` constant undefined — added to main plugin file
- `SHSEQ_SCHEMA_VERSION` constant undefined — added to main plugin file
- Custom capabilities never assigned to roles — `Activator::assign_capabilities()` added
- `manage_shseq_settings` undefined capability — replaced with `manage_options`

---

## License

StoryBoard Live is licensed under the [GNU General Public License v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

```
StoryBoard Live — Scroll-driven frame-sequence hero animation for WordPress
Copyright (C) 2026  Amin Vala

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
```

---

<div align="center">

**Made with ♥ in Tehran**

[GitHub](https://github.com/AminVala/storyboard-live) · [Issues](https://github.com/AminVala/storyboard-live/issues) · [WordPress.org](https://wordpress.org/plugins/sh-sequence-engine)

</div>
