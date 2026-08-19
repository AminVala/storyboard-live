# StoryBoard Live — Product Contract

**Contract status:** Draft for historical-baseline reconstruction  
**Baseline target:** 0.6.7.1  
**M7:** Locked until all M6 release gates pass

## 1. Product identity

StoryBoard Live is a generic WordPress plugin for creating and rendering scroll-driven visual story experiences. It is not a site-specific implementation for Shahre Honar.

## 2. Responsibilities

The plugin owns:
- Story/sequence data and runtime behavior.
- Frame-based visual rendering.
- Scroll/interaction orchestration.
- Responsive story variants.
- Progressive enhancement and reduced-motion behavior.
- Integration with the real WordPress/Theme header through an adapter/contract.
- Template structure and editing workflows where explicitly included by the current milestone.

The plugin does not own:
- The site's real Theme/Elementor header markup.
- A fake header, navigation, or logo rendered as part of the story.
- Site-specific branding, URLs, product IDs, or content.
- Unrequested WooCommerce business logic.

## 3. Visual/content boundary

Frame assets are visual-only. Text, UI, CTA, navigation, logo, and semantic content belong in HTML/UI layers, not baked into frame assets unless explicitly defined as visual artwork.

## 4. Progressive enhancement

The experience must remain usable when:
- JavaScript is unavailable.
- Runtime loading fails.
- Reduced motion is requested.

The fallback is the semantic/content layer plus an appropriate poster/golden visual representation.

## 5. Interaction

Native page scrolling remains authoritative. The plugin must not hijack page scrolling.

## 6. Accessibility

Reduced motion, keyboard/focus behavior, semantic fallback, and usable content must be treated as product requirements, not optional polish.

## 7. Release discipline

A milestone is not considered released merely because its code exists. Critical release gates must have explicit evidence and a PASS status.

## 8. Scope discipline

No new feature is admitted to a release unless it is required by the current contract, a confirmed defect, security, accessibility, performance, compatibility, or release compliance.
