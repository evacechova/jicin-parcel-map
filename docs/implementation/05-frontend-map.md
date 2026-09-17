# 05 — Frontend map

> **Historical implementation plan.** Some phase boundaries and decisions
> evolved during implementation. See the [README](../../README.md),
> [Production Notebook](../PRODUCTION-NOTEBOOK.md),
> [Architecture](../ARCHITECTURE.md) and
> [Implementation Log](../IMPLEMENTATION-LOG.md) for the final/current state.

## Goal

Render the map and KÚ layer with a reliable request lifecycle before parcel
selection UI is added.

## Dependencies

Foundation and KÚ API endpoint.

## Small steps

1. Initialise Leaflet, standard OSM basemap/attribution, scale and initial
   whole-Jičín fit, padded maxBounds and container-dependent overview minZoom
   from UI.md; recompute on resize and use the same fit for district reset.
2. Fetch/verify the complete KÚ layer once using fixed D, independently of
   navigation cancellation. Retain it as persistent map context; add KÚ
   hover/tap tooltip and accessible click-to-fitBounds shortcut.
3. Add debounced `moveend` requests and AbortController cancellation.
4. Add minimal title, zoom/reset controls, non-blocking loading and retryable
   API error state.
5. Keep previous successful geometry until replacement succeeds.
6. Verify keyboard/basic mobile map behaviour.

## Likely files

`frontend/src/main.js`, `map.js`, `api.js`, `styles.css` and frontend tests.

## Functional result and verification

Panning/zooming stays near Jičín and reuses the complete KÚ fallback without
partial viewport reloads. Bootstrap retries use fixed D; successful completion
schedules the latest viewport refresh, not the viewport from request start.

## Risks / confirmations

Confirm tile-provider terms and visual contrast before finalising the basemap.
