# StoryBoard Live — Architecture Contract

**Contract status:** Draft for historical-baseline reconstruction  
**Baseline target:** 0.6.7.1

## 1. Layer model

```text
Core
 ├─ Bootstrap
 ├─ Contracts
 ├─ Compatibility
 └─ Settings

Story
 ├─ Manifest / Definition
 ├─ Scenes
 ├─ Beats
 └─ Timeline

Rendering
 ├─ Canvas
 ├─ Poster / Golden Frame
 └─ Frame Loading / Caching

Interaction
 ├─ Scroll
 ├─ Pointer / Touch
 └─ Keyboard / Intent

Accessibility
 ├─ Reduced Motion
 ├─ Focus / Keyboard
 └─ Semantic Fallback

Responsive
 ├─ Desktop
 ├─ Tablet
 ├─ Mobile Portrait
 └─ Mobile Landscape

WordPress
 ├─ CPT / Persistence
 ├─ Admin
 ├─ REST where required
 └─ Capabilities / Nonces

Adapters
 └─ Elementor / Theme integrations

Templates
 ├─ Registry
 ├─ Validation
 ├─ Versioning
 └─ Editing / Import workflows
```

## 2. Dependency direction

Core must not depend on Elementor, WooCommerce, or a specific site theme. Integrations are adapters around the core contracts.

Templates must not bypass the canonical Story Definition/Contract to manipulate the runtime through ad-hoc fields.

## 3. Canonical story model

There must be one canonical conceptual Story Definition containing, as applicable:
- frames/assets
- scenes
- beats
- keyframes/timeline rules
- overlays/UI references
- responsive variants
- theme-header handoff rules
- golden/reference frame information
- production constraints

A runtime manifest may be a serialized/optimized representation of this definition, but it must not become a competing source of truth.

## 4. Frame/rendering contract

Frame = visual asset.  
HTML/UI = semantic content and interface.  
Theme = real site header/navigation ownership.

Golden handoff must be deterministic and must not rely solely on an arbitrary scroll percentage when the actual rendered frame can be verified.

## 5. Header contract

The plugin must never manufacture a fake site header. Theme/Elementor header integration must use an explicit adapter and handoff state.

Preflight behavior must never leave the real header inaccessible merely because a StoryBoard instance exists on the page.

## 6. Performance contract

Heavy runtime assets should be loaded according to actual user intent. Network caching and decoded-frame memory caching are separate concerns. Memory usage must be bounded by an explicit policy.

## 7. Responsive contract

Responsive variants share the story model but may use distinct visual assets and composition rules. Mobile Portrait is not required to be a blind crop of Desktop.

## 8. Compatibility contract

Legacy identifiers, persisted data, CPTs, options, and public behavior must not be renamed or removed merely for cleanliness. Any breaking data change requires an explicit migration/compatibility strategy.

## 9. Runtime failure contract

If the enhanced runtime cannot initialize, the semantic/poster fallback must remain usable.
