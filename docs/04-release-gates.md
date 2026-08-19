# StoryBoard Live — Release Gates

A release gate is one of: `PASS`, `FAIL`, `BLOCKED`, `UNVERIFIED`, `N/A`.

`PASS` requires evidence. `UNVERIFIED` is not equivalent to PASS. Any Critical gate that is FAIL or BLOCKED prevents release.

## Gate matrix

| ID | Gate | Minimum evidence |
|---|---|---|
| SPEC-001 | Product contract | Contract reviewed and locked |
| ARCH-001 | Architecture conformance | Requirement-to-code matrix |
| COMPAT-001 | Backward compatibility | Install/upgrade/downgrade/data checks |
| SEC-001 | Security | Static + dynamic/adversarial checks |
| A11Y-001 | Accessibility | Keyboard, focus, semantic/fallback and reduced-motion checks |
| PERF-001 | Performance | Controlled desktop/mobile measurements and real-device checks |
| RESP-001 | Responsive | Desktop/tablet/mobile portrait/mobile landscape checks |
| RUNTIME-001 | Runtime correctness | Frame, timeline, loading, caching and failure-path checks |
| HEADER-001 | Theme header handoff | Real-header integration and failure-path evidence |
| TEMPLATE-001 | Template contract | Schema/semantic validation and versioning evidence |
| WPORG-001 | WordPress.org readiness | Plugin Check / coding standards / packaging review |
| PKG-001 | Package integrity | Clean install ZIP, no dev artifacts/secrets |
| GIT-001 | Git provenance | Snapshot source, hash and commit/tag mapping |
| QA-001 | Release candidate | Reproducible test report |

## Evidence record

Every PASS should record:

```text
Gate ID
Version
Commit SHA
Environment
Test/tool
Input or scenario
Result
Artifact/evidence reference
Date
Reviewer
```

## Historical snapshot rule

A ZIP recovered from an archive is evidence of a historical artifact. It must not be represented as an original Git commit unless the original Git commit is actually known and verified.

When original Git provenance is unavailable, use a reconstructed snapshot commit with explicit provenance metadata and a cryptographic artifact hash.

## M7 rule

M7 remains LOCKED until all critical M6 gates are PASS and the M6 release candidate is accepted.
