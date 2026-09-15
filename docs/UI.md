# UI/UX

## Goal and scope

This is a map-first cadastral viewer, not a dashboard. The map is the working
surface; parcel detail appears only after selection. Version one deliberately
omits search, filters, accounts, dashboard, settings, parcel clustering, grid
aggregation, decorative animation and a legend without a concrete purpose.

## Main user flow

1. The app opens on a padded view of the whole Jičín district.
2. The user sees a quiet basemap and KÚ boundaries for orientation.
3. Hovering a KÚ on mouse/trackpad, or tapping it on touch, reveals its name
   and secondary parcel count.
4. Clicking/tapping a KÚ marks it active and calls `fitBounds` with 32 px
   padding and `maxZoom` equal to the current provisional parcel LOD threshold.
   This shortcut moves the map; it does not bypass normal parcel LOD.
5. At or above the parcel zoom threshold, the app attempts a viewport parcel
   request. Only a safe response activates parcel polygons.
6. Clicking/tapping a parcel selects it immediately and loads its context
   detail. Hovering on a fine pointer only highlights it and shows its label.
7. Closing detail clears selection but keeps map position and loaded layers.

No “show parcels” button, mandatory tutorial or confirmation step is needed. A
passive low-zoom hint is optional, never required to operate the map.

## Initial state and map controls

The initial camera fits the known district extent with padding, rather than a
city centre or a single KÚ. The persistent KÚ layer loads first. A compact
top-left title, “Katastrální parcely · Jičín”, identifies the task; it is not a
navigation header.

Navigation uses standard Leaflet interactions: mouse wheel/trackpad zoom and
drag-to-pan on desktop/laptop; pinch-to-zoom and drag-to-pan on touch; standard
double-click zoom remains enabled; and Leaflet zoom `+/-` stays visible. The
labeled accessible “Celý okres” control returns to the padded district
`fitBounds`. Visible OSM attribution and a scale bar remain. Search,
geolocation, fullscreen, export, settings and layer picker have no assignment
need.

### District navigation bounds and bootstrap

Use the shared conservative `DISTRICT_BOUNDS_4326` (D) from API.md. Set
Leaflet `maxBounds` to `D.pad(0.10)` and `maxBoundsViscosity=1.0`: an initial
10% latitude/longitude-span padding on each side, not a measured optimum.
Do not mask or clip the map to the irregular district polygon. Standard
wheel, drag, keyboard, double-click and touch navigation remain enabled.
These bounds keep normal navigation near the district; they are a frontend
UX constraint and provide no API validation/security guarantee.

Initial view and “Celý okres” both call `fitBounds(D)` with 32px screen
padding. Keep `zoomSnap=1`. Set the map's minimum zoom to the integer overview
zoom that fits D with that padding in the current map container. This prevents
zooming out to an unrelated world view while preserving the complete district.
Compute it from the actual container dimensions, not a desktop-only constant;
on resize/orientation change, recompute it and constrain the current view only
as necessary. A return-to-overview uses the new fit, while a valid detail view
is preserved. Respect the basemap maximum zoom independently of parcel LOD.

At overview, an elongated viewport may be wider/taller than maxBounds in one
axis. Allow surrounding basemap to be visible and let Leaflet constrain/centre
that axis rather than zooming in and cutting off part of D. Verify this with
desktop, mobile portrait/landscape, reset and KÚ fitBounds. Exact padding and
container adjustments are implementation-time UX verification, not reasons
to enforce district containment in the backend.

The KÚ layer is loaded once with the fixed D BBOX, regardless of camera padding
or aspect ratio. The complete configured KÚ-code set must be present before
it becomes the persistent fallback. Navigation/resize does not cancel or
replace this bootstrap with a partial viewport fetch. During initial loading
the basemap is usable; failure offers retry of the same D request and does not
enable parcels without their fallback. Success starts one refresh for the
latest viewport. Later low zoom, `too_dense`, reset and pan reuse this complete
layer; only parcel geometry is fetched per viewport. Full API policies still
apply to outside-district requests sent by any client.

Reference: [Leaflet map options and bounds](https://leafletjs.com/reference.html#map-maxbounds).

## KÚ layer

KÚ is a real cadastral territory, not a parcel cluster. It has thin neutral
boundary strokes and very low-opacity fill, preserving the basemap. Permanent
labels are avoided: 240 labels clutter an overview. Name and secondary
`parcel_count` appear in a tooltip/popup on hover with a fine pointer and on
tap/focus otherwise, so touch information never depends on hover. An active KÚ
has a slightly stronger boundary/fill before `fitBounds` completes.

KÚ click/tap is semantic navigation: after `fitBounds`, the resulting `moveend`
uses the exact same LOD/request lifecycle as a manual zoom or pan. If the
viewport is sufficiently detailed and its parcel response is within the safe
limit, a parcel overlay appears. If it is still `too_dense`, KÚ remains and the
user simply continues zooming.

When safe parcel polygons are active, KÚ fill becomes transparent and boundary
lines remain as context. On `too_dense` or an empty response, KÚ fill remains
or returns without a user-facing error. A network/server failure retains the
last usable representation and offers a compact retry action.

## Parcel layer

| State | Visual/interaction behaviour |
| --- | --- |
| Default | Restrained translucent fill and crisp neutral outline over the basemap. |
| Hover, fine pointer | Slightly stronger outline/fill and small label tooltip. |
| Selected | Highest-contrast outline and distinct fill, retained while detail loads. |
| Replacement loading | Existing valid parcel layer is subdued; no full-map spinner. |

Touch does not depend on hover: a tap selects. Colour is not the only state
signal; outline and opacity also change. Final colours are verified against OSM
and focus/selected contrast, rather than becoming a design-system exercise.

## Parcel detail

The panel title is the parcel number. It presents only source-backed fields:

| UI label | API value |
| --- | --- |
| Parcelní číslo | `label` |
| Výměra | `area_m2`, Czech-formatted in `m²` |
| Katastrální území | `cadastral_territory.name`; `ku_code` is secondary text |
| Katastrální reference | `national_cadastral_reference` |

`inspire_id`, `beginLifespanVersion`, `validFrom`, reference point and source
URLs are technical/audit fields and are not displayed. The UI also does not
infer ownership, land use, price or legal conclusions.

Selection appears before the detail request finishes. The panel then shows a
compact loading row, followed by these fields. `404 parcel_not_found` clears
selection and shows a short current-data message, not a service-outage state.
A visible close button clears selection.

## Responsive layout

CSS responds to available width, not device detection: wide `>=1024px` uses a
sidebar; medium `600–1023px` and narrow `<600px` use a bottom sheet. Height and
landscape constraints override a sidebar if it would compromise the map.

### Desktop / laptop

```text
+--------------------------------------------------------------------+
| Katastrální parcely · Jičín             [+] [-] [Celý okres]      |
|                                                                    |
|                         MAPA                         +-----------+ |
|                                                      | Parcela   | |
|  KÚ outline / parcel overlay                         | st. 4678 | |
|                                      attribution     | Výměra   | |
|                                                      | KÚ        | |
|                                                      | Reference | |
|                                                      |       [×] | |
+------------------------------------------------------+-----------+
```

The right panel has a stable readable width and the map stays dominant. With no
selection, no empty sidebar is shown.

### Tablet

```text
+----------------------------------------------------+
| Katastrální parcely · Jičín       [+] [-] [Okres]  |
|                                                    |
|                       MAPA                         |
|                                                    |
|     +------------------------------------------+   |
|     | Parcela st. 4678                      [×] |   |
|     | Výměra · KÚ · Katastrální reference       |   |
|     +------------------------------------------+   |
+----------------------------------------------------+
```

Use a non-modal, content-height bottom sheet. It can expand for text scaling.

### Mobile

```text
+--------------------------------+
| Katastrální parcely · Jičín    |
| [+] [-] [Celý okres]           |
|                                |
|             MAPA               |
|                                |
| +----------------------------+ |
| | Parcela st. 4678        [×]| |
| | Výměra                     | |
| | Katastrální území          | |
| | Katastrální reference      | |
| +----------------------------+ |
+--------------------------------+
```

The sheet is full width and non-modal. A drag handle may be added later, but a
visible close control is always the way to dismiss it. It must not cover map
controls or attribution.

## States

| Situation | Behaviour |
| --- | --- |
| Initial KÚ load | Show base map immediately with small non-blocking indicator. |
| Viewport load | Keep KÚ and existing valid parcels subdued until replacement. |
| Detail load | Keep selected polygon and show a compact panel loading row. |
| Empty parcel viewport | Clear only parcel overlay; KÚ remains; concise contextual text is acceptable. |
| `409 too_dense` | Silently clear/demote parcel overlay and restore KÚ; next viewport change retries naturally. |
| Network / `500` / `503` | Keep last usable map representation and offer one compact retry action. |
| Detail `404` | Clear selection with concise current-data message. |

## Accessibility and touch

### Required

- Keyboard-focusable native close, reset and retry buttons with accessible names.
- On keyboard-opened detail, move focus to its heading/close control; on close,
  return it to the parcel trigger where practical.
- KÚ/parcel information is reachable by click/tap and panel, not hover alone.
- Contrast between default and selected geometry; colour paired with outline and
  opacity. Touch targets are approximately 44 × 44 CSS pixels.
- Politely announce detail status; respect `prefers-reduced-motion` by making
  nonessential transitions immediate.

### Deferred enhancement

- Fully keyboard-navigable individual polygons beyond practical Leaflet defaults.
- Focus trapping or gesture-only bottom-sheet interactions; the sheet remains
  non-modal and always has a normal close button.

## Basemap

Use standard OpenStreetMap raster tiles through Leaflet for the local,
interactive take-home demo: no API key, familiar Czech geographic context and
simple visible attribution. Use the required HTTPS URL; request tiles only for
the user’s current viewport and never prefetch/bulk-download them.

This is reviewer-demo scope, not a production SLA. For public/production usage,
choose an approved provider or self-host tiles according to expected traffic.

## Open implementation confirmations

- Check KÚ/parcel contrast against live OSM tiles.
- Test bottom-sheet height, focus return and touch targets on actual browsers.
- Confirm KÚ `fitBounds` padding and its `maxZoom` bound to the provisional
  parcel LOD threshold, especially for very small KÚ.
- Decide after a small usability check whether the passive low-zoom hint helps;
  omit it if KÚ click/fit-to-bounds is self-explanatory.
