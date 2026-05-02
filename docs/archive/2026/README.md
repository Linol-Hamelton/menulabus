# `docs/archive/2026/` — completed execution-guides

Convention: when a doc transitions from "actionable execution guide" to "historical record of a closed phase", move it here with `git mv` rather than delete. The original git history stays intact, and future devs can read the rationale without it cluttering the active `docs/` listing.

## What belongs here

Move when **all three** are true:

1. The doc described **steps to take**, not standing architecture.
2. Those steps have been **fully executed on production**.
3. There is **no recurring need** to revisit it (e.g. quarterly cadence, compliance audit, runbook).

## Examples (none archived yet — kept at active path until rollout fully confirmed)

- `docs/security-phase-2-inventory.md` — would archive once Phase 2 network policy fully applied + verified on production host.
- `docs/security-phase-commands.md` — same condition.
- `docs/ux-walkthrough-2026-04-28.md` — once walk-through findings are fixed and re-verified.

## What does NOT belong here

- Module reference docs (kds.md, reservations.md, fiscal-54fz.md, etc.) — these document **standing behaviour**, not closed work.
- Roadmaps (project-improvement-roadmap.md, ux-ui-improvement-roadmap.md) — historical sections live IN those docs as `Status: Implemented` rows.
- Audit baselines (feature-audit-matrix.md) — kept current via in-place updates.

## Naming

Keep the original filename. The directory year prefix tells the timeline.
