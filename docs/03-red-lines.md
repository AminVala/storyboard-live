# StoryBoard Live — Red Lines

These rules are release-blocking constraints unless a newer approved contract explicitly supersedes them.

## RL-01 — Generic product

No hard-coded dependency on Shahre Honar branding, domain, logo, product IDs, colors, or content in the generic plugin runtime.

## RL-02 — No fake header

The plugin does not create or own the site's real Header, Logo, Navigation, or a substitute/fake Header. It may coordinate with the real Theme/Elementor header through an explicit adapter.

## RL-03 — Frame is visual

Do not place normal UI, text, CTA, navigation, logo, or semantic content inside frame assets as a substitute for HTML/UI.

## RL-04 — No scroll hijacking

Do not take control of native page scrolling or require `preventDefault()` to manufacture a custom page-scroll experience.

## RL-05 — Progressive enhancement

No-JS, runtime failure, and reduced-motion states must have a usable semantic/poster fallback.

## RL-06 — Reduced motion

`prefers-reduced-motion` is a first-class runtime state. Reduced mode must not merely reduce animation duration while retaining an inappropriate motion-heavy experience.

## RL-07 — Golden Master

The approved golden/reference frame is a visual contract. Camera/composition/major-object decisions for the golden handoff must not be silently altered by runtime logic.

## RL-08 — Compatibility

Do not casually rename/remove legacy prefixes, classes, CPTs, options, metadata, or persisted structures. Compatibility and migration take priority over cosmetic cleanup.

## RL-09 — Security boundaries

All user/admin/REST/template inputs are untrusted until validated. Use capability checks, nonces, sanitization, escaping, schema validation, and explicit allowlists where appropriate.

## RL-10 — No hidden telemetry

No hidden analytics, tracking, remote API dependency, or data collection may be introduced without an explicit product/privacy contract.

## RL-11 — M7 lock

M7 work must not begin while critical M6 release gates remain unresolved.

## RL-12 — No release by assertion

A feature or milestone is not PASS merely because code exists. PASS requires reproducible evidence.
