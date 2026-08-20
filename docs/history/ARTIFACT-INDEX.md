# Historical Artifact Index

This document records the supplied ZIP artifacts used for reconstruction. The original Git history/SHA for these milestones was not supplied, so these are explicitly **reconstructed snapshots**, not claimed original Git commits.

| Milestone | Version | ZIP bytes | Files | ZIP SHA-256 |
|---|---:|---:|---:|---|
| M0 | 0.1.0 | 22,320 | 24 | `694f2fe5a7644adb207cae6e5cb79ce8489c57f081d51f764e3d7c9b7320a67b` |
| M1 | 0.2.0 | 276,128 | 58 | `31a46bf9563e7d999902f18f4823e72565dfd335a50929415021531881d7c631` |
| M2 | 0.3.0 | 281,696 | 59 | `1266f93897d7e79c13e03988ccae15c8fa4a1f8eb78d25044d683362c451591d` |
| M4 | 0.4.0 | 282,908 | 59 | `1bce6dd92505fa54a3625be0221cd50ade66aaabb27db62a5799f762332f155e` |
| M5 | 0.5.0 | 289,430 | 60 | `a9846761c5d11fe8ccea42503eb03982c063a69f9dba43714cbe548f5ee5eb5f` |
| M6 | 0.6.0 | 294,419 | 61 | `ece51d77dc902510e422967590ed0b322aac6bc9aee062f70d74e0c3651e721e` |

## Structural progression

- M0 establishes the WordPress plugin foundation, admin dashboard, capabilities, private content entities, schema management, localization and RTL-aware admin styling.
- M1 adds the first frontend/demo sequence layer: `DemoAssets.php`, `DemoShortcode.php`, `m1-sequence.js`, `m1-sequence.css`, and 30 WebP demo frames.
- M2 replaces the demo layer with the runtime foundation: `RuntimeAssets.php`, `RuntimeManifest.php`, `RuntimeShortcode.php`, `runtime-core.js`, and `runtime-core.css`.
- M4 continues runtime hardening and changes the runtime implementation without adding a new top-level subsystem.
- M5 expands runtime manifest/shortcode/assets behavior and adds `M5-AUDIT.md`.
- M6 adds `M6-SPEC.md` and further expands the runtime manifest, shortcode, assets, JS/CSS and localization artifacts.

## M5 → M6 observed delta

M6 contains all M5 paths plus `M6-SPEC.md`. There are 12 changed paths and no removed paths. The changed paths are:

- `BUILD.md`
- `assets/frontend/runtime-core.css`
- `assets/frontend/runtime-core.js`
- `languages/sh-sequence-engine-fa_IR.mo`
- `languages/sh-sequence-engine-fa_IR.po`
- `languages/sh-sequence-engine.pot`
- `readme.txt`
- `sh-sequence-engine.php`
- `src/Admin/DashboardPage.php`
- `src/Frontend/RuntimeAssets.php`
- `src/Frontend/RuntimeManifest.php`
- `src/Frontend/RuntimeShortcode.php`

## Binary provenance

The supplied artifacts contain compiled `.mo` localization files and WebP demo frames. Their exact bytes are covered by the parent ZIP SHA-256 values above. They must not be regenerated or silently replaced during historical reconstruction.

## Reconstruction rule

Do not invent original commit dates, original Git SHAs, or authorship metadata. When the source Git history is unavailable, use reconstructed commits with explicit artifact provenance and preserve the original ZIP SHA-256.