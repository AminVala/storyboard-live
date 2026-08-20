# M5 → M6 structural diff evidence

Compared user-supplied artifacts:

- M5: `sh-sequence-engine-m5-0.5.0.zip`
- M6: `sh-sequence-engine-m6-0.6.0(1).zip`

## Artifact-level result

M6 contains all M5 paths plus one new document:

- Added: `M6-SPEC.md`
- Removed: none
- Changed files: 12

## Changed files

1. `BUILD.md`
2. `assets/frontend/runtime-core.css`
3. `assets/frontend/runtime-core.js`
4. `languages/sh-sequence-engine-fa_IR.mo`
5. `languages/sh-sequence-engine-fa_IR.po`
6. `languages/sh-sequence-engine.pot`
7. `readme.txt`
8. `sh-sequence-engine.php`
9. `src/Admin/DashboardPage.php`
10. `src/Frontend/RuntimeAssets.php`
11. `src/Frontend/RuntimeManifest.php`
12. `src/Frontend/RuntimeShortcode.php`

## M6-specific evidence

`M6-SPEC.md` establishes the locked M6 direction: live HTML for meaningful UI, visual/background-only production frames, late real-theme header reveal, safe keyed overlays, whitelisted transforms, overlay progress events, and reduced-motion/fallback behavior.

The runtime implementation also expands the manifest and runtime handling from the M5 artifact. The observed M6 runtime validates `shseq.runtime.manifest` schema version `4`.

## Important provenance statement

This is a file-level comparison of two supplied ZIP artifacts. It is not a Git diff because the original Git history for these ZIPs was not supplied and the repository did not contain the historical source commits.

Therefore the repository must continue to label these versions as reconstructed snapshots until original Git provenance is recovered.
