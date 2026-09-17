# 06 — Parcel layer and detail

> **Historical implementation plan.** Some phase boundaries and decisions
> evolved during implementation. See the [README](../../README.md),
> [Production Notebook](../PRODUCTION-NOTEBOOK.md),
> [Architecture](../ARCHITECTURE.md) and
> [Implementation Log](../IMPLEMENTATION-LOG.md) for the final/current state.

## Goal

Add zoom-gated parcel geometry, selection and responsive parcel detail UI.

## Dependencies

Map phase and parcel/detail endpoints.

## Small steps

1. Add configured parcel zoom threshold and call parcel endpoint only above it.
2. Handle `too_dense`, empty and loading states without silently showing a
   partial layer.
3. Draw/select parcel features, retain KÚ fallback on `too_dense`, and fetch
   metadata detail on click.
4. Build desktop right panel and tablet/mobile bottom sheet from one detail
   component, with Parcelní číslo, Výměra, Katastrální území and Katastrální
   reference only.
5. Add close/reset selection behaviour and accessible loading/error text.
6. Verify no ownership/type/cost field is implied by the UI.

## Likely files

`frontend/src/map.js`, `detail-panel.js`, `styles.css` and browser tests.

## Functional result and verification

A user can select a returned parcel and see supported metadata on desktop and
mobile layouts; low zoom never requests parcels.

## Risks / confirmations

Threshold and feature cap are provisional until performance measurement.
