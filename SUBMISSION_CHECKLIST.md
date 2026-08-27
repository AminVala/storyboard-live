# WordPress.org Submission Checklist — StoryBoard Live

## Pre-Submission (Code Review)

### Mandatory Requirements

- [x] `readme.txt` present with all required headers
- [x] `Stable tag` matches the tag in `sh-sequence-engine.php` header (`1.0.0`)
- [x] `Tested up to` is current WP major version (`6.7`)
- [x] Plugin has a unique slug — `sh-sequence-engine` (check at wordpress.org/plugins)
- [x] `License: GPLv2 or later` in both plugin header and readme.txt
- [x] `Text Domain` matches the folder name where appropriate (`sh-sequence-engine`)
- [x] `Domain Path: /languages` declared in plugin header
- [x] Activation / deactivation hooks present (`register_activation_hook`)
- [x] `uninstall.php` present (not `register_uninstall_hook`) — required for data cleanup
- [x] No hardcoded paths — uses `SHSEQ_DIR`, `plugin_dir_path()` etc.
- [x] All user-facing strings are i18n-wrapped (`__()`, `esc_html_e()` etc.)
- [x] Output escaping everywhere (`esc_html`, `esc_url`, `esc_attr`, `wp_kses`)
- [x] All AJAX handlers verify nonce and check `current_user_can()`
- [x] No direct DB calls without `$wpdb->prepare()`
- [x] No `eval()`, `base64_decode()` on user input, or obfuscated code
- [x] No external calls on plugin load (API calls are user-triggered only)
- [x] Privacy disclosure on Settings page for AI providers
- [x] No tracking, analytics, or data collection from visitors

### WordPress Coding Standards

- [ ] Run `composer run phpcs` and fix all errors (warnings acceptable)
- [ ] Verify no use of deprecated WP functions (`the_content_filtered`, etc.)
- [ ] Confirm all `wp_enqueue_*` calls are hooked properly (not in templates)
- [ ] Prefix check: all functions, hooks, options, meta keys use `shseq_` prefix

### File Structure Requirements

```
storyboard-live/
├── sh-sequence-engine.php     ← main plugin file (matches slug)
├── readme.txt                 ← WP.org format
├── uninstall.php
├── composer.json
├── build.sh
├── .distignore
├── languages/                 ← .pot file goes here
├── src/
│   ├── Plugin.php
│   ├── AI/
│   ├── Admin/
│   ├── Blocks/
│   ├── Content/
│   ├── Core/
│   │   ├── Activator.php
│   │   └── Deactivator.php
│   ├── Frontend/
│   ├── I18n/
│   ├── Jobs/
│   ├── License/
│   └── Templates/
├── assets/
│   ├── admin/
│   └── frontend/
└── .wordpress-org/
    ├── banner-772x250.png
    ├── banner-1544x500.png
    ├── icon-128x128.png
    ├── icon-256x256.png
    └── screenshot-*.png
```

## .pot File (i18n)

Generate the .pot file before submission:

```bash
wp i18n make-pot . languages/sh-sequence-engine.pot \
  --domain=sh-sequence-engine \
  --exclude=vendor,node_modules,tests,dist
```

- [ ] `.pot` file present at `languages/sh-sequence-engine.pot`
- [ ] `.pot` file is committed to the SVN `trunk/`

## Assets (Banner, Icon, Screenshots)

These go in the SVN `assets/` directory (NOT `trunk/`):

| File | Size | Notes |
|------|------|-------|
| `banner-772x250.png` | 772×250 | Standard banner |
| `banner-1544x500.png` | 1544×500 | Retina banner |
| `icon-128x128.png` | 128×128 | Plugin icon |
| `icon-256x256.png` | 256×256 | Retina icon |
| `screenshot-1.png` | any | Dashboard |
| `screenshot-2.png` | any | Sequence editor |
| `screenshot-3.png` | any | Template selector |
| `screenshot-4.png` | any | Settings page |
| `screenshot-5.png` | any | Frontend hero |
| `screenshot-6.png` | any | Mobile static |
| `screenshot-7.png` | any | Gutenberg block |
| `screenshot-8.png` | any | AI progress bar |

- [ ] All assets present in `.wordpress-org/` (mapped to SVN `assets/`)
- [ ] Screenshots match readme.txt `== Screenshots ==` section descriptions

## Building the Distribution Zip

```bash
# 1. Install Composer (dev + prod)
composer install

# 2. Run code standards check
composer run phpcs

# 3. Build production zip
./build.sh --zip
# → dist/storyboard-live-1.0.0.zip
```

## SVN Submission

```bash
# 1. Check out your plugin SVN (granted after WP.org review approval)
svn co https://plugins.svn.wordpress.org/sh-sequence-engine wporg-svn
cd wporg-svn

# 2. Copy the built plugin into trunk/
rsync -a --delete path/to/dist/storyboard-live/ trunk/

# 3. Add the i18n pot file
cp path/to/languages/sh-sequence-engine.pot trunk/languages/

# 4. Copy assets (banner, icon, screenshots) to assets/ — NOT trunk/
cp path/to/.wordpress-org/*.png assets/

# 5. Review what changed
svn status

# 6. Add any new files
svn add --force trunk/ assets/

# 7. Commit trunk
svn ci -m "Add version 1.0.0"

# 8. Tag the release
svn cp trunk/ tags/1.0.0/
svn ci -m "Tag version 1.0.0"
```

## Post-Submission

- [ ] Monitor the Plugin Review email (usually 1–4 weeks)
- [ ] Address any reviewer requests promptly — they give ~7 days to respond
- [ ] After approval, update readme.txt `Tested up to` with each new WP release
- [ ] Keep `Stable tag` in sync with SVN tags on every release

## Common Review Rejection Reasons (avoid these)

1. `Stable tag` does not match a real SVN tag — always tag before publishing
2. Missing or wrong `Text Domain` — must exactly match the plugin folder name
3. Output not escaped — reviewers check every `echo`
4. AJAX handlers missing nonce or capability check
5. Options/meta without proper sanitization on save
6. External API calls on `plugins_loaded` without user action
7. Readme.txt "Screenshots" section references files not in SVN `assets/`
8. Generic function names without plugin prefix (e.g. `function activate()`)
