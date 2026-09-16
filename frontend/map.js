import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

import {
  BASEMAP_MAX_ZOOM,
  DISTRICT_BOUNDS,
  DISTRICT_PADDING_PX,
  NAVIGATION_BOUNDS_PADDING,
  PARCEL_MIN_ZOOM,
} from './config.js';

const TERRITORY_STYLE = Object.freeze({
  color: '#455b54',
  weight: 1.1,
  opacity: 0.82,
  fillColor: '#bad3c4',
  fillOpacity: 0.12,
});

const TERRITORY_ACTIVE_STYLE = Object.freeze({
  color: '#163f34',
  weight: 2.4,
  opacity: 1,
  fillColor: '#69a889',
  fillOpacity: 0.25,
});

const PARCEL_STYLE = Object.freeze({
  color: '#875f24',
  weight: 1.15,
  opacity: 0.92,
  fillColor: '#e8b85c',
  fillOpacity: 0.22,
});

const PARCEL_SELECTED_STYLE = Object.freeze({
  color: '#722f16',
  weight: 3,
  opacity: 1,
  fillColor: '#f28c52',
  fillOpacity: 0.48,
});

export class MapView {
  constructor(elements) {
    this.elements = elements;
    this.#assertElements();
    this.activeTerritoryLayer = null;
    this.territoryLayer = null;
    this.parcelLayer = null;
    this.selectedParcelLayer = null;
    this.selectedParcelElement = null;
    this.parcelsActive = false;
    this.retryHandler = null;
    this.detailRetryHandler = null;
    this.parcelSelectHandler = null;
    this.detailCloseHandler = null;
    this.clearSelectionHandler = null;

    this.districtBounds = L.latLngBounds(
      [DISTRICT_BOUNDS.south, DISTRICT_BOUNDS.west],
      [DISTRICT_BOUNDS.north, DISTRICT_BOUNDS.east],
    );
    this.map = L.map(elements.map, {
      maxBounds: this.districtBounds.pad(NAVIGATION_BOUNDS_PADDING),
      maxBoundsViscosity: 1,
      maxZoom: BASEMAP_MAX_ZOOM,
      zoomSnap: 1,
      zoomDelta: 1,
      zoomControl: false,
    });

    this.#addBasemap();
    L.control.zoom({ position: 'topright' }).addTo(this.map);
    L.control.scale({ position: 'bottomleft', imperial: false }).addTo(this.map);
    this.#addDistrictResetControl();
    this.#bindPanelControls();
    this.#observeDetailSize();
    this.#recomputeMinimumZoom();
    this.fitDistrict({ animate: false });
    this.#observeSize(elements.map);
  }

  onMoveEnd(listener) {
    this.map.on('moveend', listener);
  }

  setParcelSelectHandler(handler) {
    this.parcelSelectHandler = handler;
  }

  setDetailCloseHandler(handler) {
    this.detailCloseHandler = handler;
  }

  setClearSelectionHandler(handler) {
    this.clearSelectionHandler = handler;
  }

  readViewport() {
    const bounds = this.map.getBounds();
    const viewport = Object.freeze({
      bbox: [bounds.getWest(), bounds.getSouth(), bounds.getEast(), bounds.getNorth()],
      zoom: Math.round(this.map.getZoom()),
    });
    this.elements.map.dataset.zoom = String(viewport.zoom);
    return viewport;
  }

  showTerritoryLoading() {
    this.#showMapStatus('Načítám hranice katastrálních území…', 'loading');
    this.elements.guidance.textContent = 'Mapa zůstává během načítání ovladatelná.';
  }

  showTerritoryError(error, retry) {
    this.#showMapStatus(this.#mapErrorMessage('Katastrální území se nepodařilo načíst.', error), 'error', retry);
    this.elements.guidance.textContent = 'Bez úplné vrstvy katastrálních území se parcely nenačítají.';
  }

  publishTerritories(collection) {
    this.territoryLayer?.remove();
    this.territoryLayer = L.geoJSON(collection, {
      style: () => TERRITORY_STYLE,
      onEachFeature: (feature, layer) => this.#configureTerritoryFeature(feature, layer),
    }).addTo(this.map);
    this.#hideMapStatus();
    this.elements.guidance.textContent = 'Vyberte katastrální území nebo přibližte mapu.';
  }

  showParcelLoading() {
    if (this.parcelLayer !== null) {
      this.parcelLayer.eachLayer((layer) => layer.setStyle({ opacity: 0.42, fillOpacity: 0.1 }));
    }
    this.#showMapStatus('Načítám parcely v aktuálním výřezu…', 'loading');
  }

  publishParcels(collection) {
    const started = now();
    this.clearSelectionHandler?.();

    if (collection.features.length === 0) {
      this.parcelLayer?.remove();
      this.parcelLayer = null;
      this.parcelsActive = false;
      this.#refreshTerritoryStyles();
      this.#hideMapStatus();
      this.elements.guidance.textContent = 'V tomto výřezu nejsou parcely k zobrazení.';
      this.#recordParcelRender(started, 0);
      return;
    }

    const replacement = L.geoJSON(collection, {
      style: () => PARCEL_STYLE,
      onEachFeature: (feature, layer) => this.#configureParcelFeature(feature, layer),
    }).addTo(this.map);
    this.parcelLayer?.remove();
    this.parcelLayer = replacement;
    this.parcelsActive = true;
    this.#refreshTerritoryStyles();
    this.#hideMapStatus();
    const count = new Intl.NumberFormat('cs-CZ').format(collection.features.length);
    this.elements.guidance.textContent = `Zobrazeno ${count} parcel. Vyberte parcelu pro detail.`;
    this.#recordParcelRender(started, collection.features.length);
  }

  showTerritoryOnly(reason) {
    this.clearSelectionHandler?.();
    this.parcelLayer?.remove();
    this.parcelLayer = null;
    this.parcelsActive = false;
    this.#refreshTerritoryStyles();
    this.#hideMapStatus();

    const messages = {
      zoom: 'Vyberte katastrální území nebo přibližte mapu pro zobrazení parcel.',
      too_dense: 'V tomto výřezu zůstávají hranice KÚ. Pro parcely mapu dále přibližte.',
      policy: 'Pro tento výřez zůstávají zobrazené hranice katastrálních území.',
    };
    this.elements.guidance.textContent = messages[reason] ?? messages.policy;
  }

  showParcelError(error, retry) {
    if (this.parcelLayer !== null) {
      this.parcelLayer.eachLayer((layer) => {
        layer.setStyle(layer === this.selectedParcelLayer ? PARCEL_SELECTED_STYLE : PARCEL_STYLE);
      });
    }
    this.#showMapStatus(this.#mapErrorMessage('Parcely se nepodařilo načíst.', error), 'error', retry);
    this.elements.guidance.textContent = 'Poslední použitelná mapová data zůstávají zobrazená.';
  }

  showDetailLoading(selection) {
    const { detail, detailTitle, detailStatus, detailFields, detailRetry, detailClose } = this.elements;
    detail.hidden = false;
    this.elements.detail.closest('.app-shell')?.classList.add('has-parcel-detail');
    detailTitle.textContent = selection.label || 'Detail parcely';
    detailStatus.textContent = 'Načítám údaje parcely…';
    detailFields.hidden = true;
    detailRetry.hidden = true;
    this.detailRetryHandler = null;
    if (selection.keyboard) queueMicrotask(() => detailClose.focus());
  }

  publishDetail(detail) {
    this.elements.detailTitle.textContent = detail.label;
    this.elements.detailStatus.textContent = '';
    this.elements.detailFields.hidden = false;
    this.elements.detailRetry.hidden = true;
    this.elements.detailFields.querySelector('[data-detail="label"]').textContent = detail.label;
    this.elements.detailFields.querySelector('[data-detail="area"]').textContent = `${new Intl.NumberFormat('cs-CZ', {
      maximumFractionDigits: 2,
    }).format(detail.area_m2)} m²`;
    this.elements.detailFields.querySelector('[data-detail="territory"]').textContent = `${detail.cadastral_territory.name} · ${detail.cadastral_territory.ku_code}`;
    this.elements.detailFields.querySelector('[data-detail="reference"]').textContent = detail.national_cadastral_reference;
  }

  showDetailError(error, retry) {
    this.elements.detailStatus.textContent = this.#mapErrorMessage('Detail parcely se nepodařilo načíst.', error);
    this.elements.detailFields.hidden = true;
    this.elements.detailRetry.hidden = false;
    this.detailRetryHandler = retry;
  }

  showParcelGone() {
    this.closeDetail({ restoreFocus: false });
    this.elements.guidance.textContent = 'Vybraná parcela už v aktuálních datech není dostupná.';
  }

  closeDetail({ restoreFocus = true } = {}) {
    const focusTarget = this.selectedParcelElement;
    if (this.selectedParcelLayer !== null) this.selectedParcelLayer.setStyle(PARCEL_STYLE);
    this.selectedParcelLayer = null;
    this.selectedParcelElement = null;
    this.elements.detail.hidden = true;
    this.elements.detail.closest('.app-shell')?.classList.remove('has-parcel-detail');
    this.elements.detailStatus.textContent = '';
    this.elements.detailFields.hidden = true;
    this.elements.detailRetry.hidden = true;
    this.detailRetryHandler = null;
    if (restoreFocus && focusTarget?.isConnected) focusTarget.focus();
  }

  fitDistrict({ animate = true } = {}) {
    this.map.fitBounds(this.districtBounds, {
      padding: [DISTRICT_PADDING_PX, DISTRICT_PADDING_PX],
      animate: animate && !prefersReducedMotion(),
    });
  }

  #assertElements() {
    const missing = Object.entries(this.elements)
      .filter(([, value]) => !(value instanceof HTMLElement))
      .map(([name]) => name);
    if (missing.length > 0) throw new Error(`Missing UI elements: ${missing.join(', ')}`);
  }

  #addBasemap() {
    const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: BASEMAP_MAX_ZOOM,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(this.map);
    tiles.on('tileerror', () => {
      this.elements.basemapStatus.hidden = false;
    });
    tiles.on('load', () => {
      this.elements.basemapStatus.hidden = true;
    });
  }

  #configureTerritoryFeature(feature, layer) {
    const { ku_code: code, name, parcel_count: parcelCountValue } = feature.properties;
    const parcelCount = new Intl.NumberFormat('cs-CZ').format(parcelCountValue);
    layer.bindTooltip(this.#tooltipContent(name, `${parcelCount} parcel · KÚ ${code}`), {
      direction: 'top',
      sticky: true,
      className: 'map-tooltip',
    });

    const activate = () => {
      this.clearSelectionHandler?.();
      if (this.activeTerritoryLayer !== null && this.activeTerritoryLayer !== layer) {
        this.activeTerritoryLayer.setStyle(this.#territoryStyle(this.activeTerritoryLayer));
      }
      this.activeTerritoryLayer = layer;
      layer.setStyle(this.#territoryStyle(layer));
      layer.bringToFront();
      layer.openTooltip();
      this.map.fitBounds(layer.getBounds(), {
        padding: [DISTRICT_PADDING_PX, DISTRICT_PADDING_PX],
        maxZoom: PARCEL_MIN_ZOOM,
        animate: !prefersReducedMotion(),
      });
    };

    layer.on({
      click: activate,
      mouseover: () => {
        if (this.activeTerritoryLayer !== layer) layer.setStyle({ weight: 1.8, fillOpacity: this.parcelsActive ? 0 : 0.2 });
      },
      mouseout: () => {
        if (this.activeTerritoryLayer !== layer) layer.setStyle(this.#territoryStyle(layer));
      },
      add: () => this.#makeLayerKeyboardAccessible(
        layer,
        `${name}, katastrální území ${code}, ${parcelCount} parcel`,
        activate,
      ),
    });
  }

  #configureParcelFeature(feature, layer) {
    const label = feature.properties.label;
    layer.bindTooltip(this.#tooltipContent(`Parcela ${label}`), {
      direction: 'top',
      sticky: true,
      className: 'map-tooltip',
    });

    const activate = (keyboard = false) => {
      if (this.selectedParcelLayer !== null && this.selectedParcelLayer !== layer) {
        this.selectedParcelLayer.setStyle(PARCEL_STYLE);
      }
      this.selectedParcelLayer = layer;
      this.selectedParcelElement = layer.getElement();
      layer.setStyle(PARCEL_SELECTED_STYLE);
      layer.bringToFront();
      this.parcelSelectHandler?.({ id: feature.id, label, keyboard });
    };

    layer.on({
      click: () => activate(false),
      mouseover: () => {
        if (this.selectedParcelLayer !== layer) layer.setStyle({ weight: 2, fillOpacity: 0.34 });
      },
      mouseout: () => {
        if (this.selectedParcelLayer !== layer) layer.setStyle(PARCEL_STYLE);
      },
      add: () => this.#makeLayerKeyboardAccessible(layer, `Parcela ${label}`, () => activate(true)),
    });
  }

  #makeLayerKeyboardAccessible(layer, label, activate) {
    const element = layer.getElement();
    if (element === null) return;
    element.setAttribute('tabindex', '0');
    element.setAttribute('role', 'button');
    element.setAttribute('aria-label', label);
    element.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      event.preventDefault();
      activate();
    });
  }

  #territoryStyle(layer) {
    const base = layer === this.activeTerritoryLayer ? TERRITORY_ACTIVE_STYLE : TERRITORY_STYLE;
    return this.parcelsActive ? { ...base, fillOpacity: 0 } : base;
  }

  #refreshTerritoryStyles() {
    this.territoryLayer?.eachLayer((layer) => layer.setStyle(this.#territoryStyle(layer)));
  }

  #tooltipContent(titleText, detailText = '') {
    const wrapper = document.createElement('span');
    const title = document.createElement('strong');
    title.textContent = titleText;
    wrapper.append(title);
    if (detailText !== '') {
      const detail = document.createElement('span');
      detail.textContent = detailText;
      wrapper.append(detail);
    }
    return wrapper;
  }

  #showMapStatus(message, state, retry = null) {
    this.elements.status.hidden = false;
    this.elements.status.dataset.state = state;
    this.elements.statusText.textContent = message;
    this.elements.retry.hidden = retry === null;
    this.retryHandler = retry;
  }

  #hideMapStatus() {
    this.elements.status.hidden = true;
    this.elements.status.dataset.state = 'ready';
    this.elements.retry.hidden = true;
    this.retryHandler = null;
  }

  #mapErrorMessage(message, error) {
    return typeof error?.requestId === 'string' && error.requestId !== ''
      ? `${message} Kód požadavku: ${error.requestId}.`
      : message;
  }

  #bindPanelControls() {
    this.elements.retry.addEventListener('click', () => this.retryHandler?.());
    this.elements.detailRetry.addEventListener('click', () => this.detailRetryHandler?.());
    this.elements.detailClose.addEventListener('click', () => this.detailCloseHandler?.());
  }

  #addDistrictResetControl() {
    const mapView = this;
    const DistrictResetControl = L.Control.extend({
      options: { position: 'topright' },
      onAdd() {
        const container = L.DomUtil.create('div', 'leaflet-bar district-reset-control');
        const button = L.DomUtil.create('button', 'district-reset-button', container);
        button.type = 'button';
        button.textContent = 'Celý okres';
        button.setAttribute('aria-label', 'Zobrazit celý okres Jičín');
        L.DomEvent.disableClickPropagation(container);
        L.DomEvent.on(button, 'click', () => {
          mapView.detailCloseHandler?.();
          mapView.activeTerritoryLayer = null;
          mapView.#refreshTerritoryStyles();
          mapView.fitDistrict();
        });
        return container;
      },
    });
    new DistrictResetControl().addTo(this.map);
  }

  #recomputeMinimumZoom() {
    this.map.invalidateSize({ pan: false });
    const totalPadding = DISTRICT_PADDING_PX * 2;
    const overviewZoom = Math.max(
      0,
      Math.floor(this.map.getBoundsZoom(this.districtBounds, false, L.point(totalPadding, totalPadding))),
    );
    this.map.setMinZoom(overviewZoom);
  }

  #observeSize(mapElement) {
    if ('ResizeObserver' in window) {
      this.resizeObserver = new ResizeObserver(() => {
        this.map.invalidateSize({ pan: false });
        this.#recomputeMinimumZoom();
      });
      this.resizeObserver.observe(mapElement);
      return;
    }
    window.addEventListener('resize', () => this.#recomputeMinimumZoom());
  }

  #observeDetailSize() {
    const shell = this.elements.detail.closest('.app-shell');
    if (shell === null || !('ResizeObserver' in window)) return;
    this.detailResizeObserver = new ResizeObserver(() => {
      shell.style.setProperty('--parcel-detail-height', `${this.elements.detail.offsetHeight}px`);
    });
    this.detailResizeObserver.observe(this.elements.detail);
  }

  #recordParcelRender(started, featureCount) {
    const ended = now();
    try {
      performance.measure('viagem:parcel-layer-render', { start: started, end: ended });
    } catch {
      // Performance diagnostics must not affect rendering.
    }
    document.dispatchEvent(new CustomEvent('viagem:parcel-layer-rendered', {
      detail: { duration: ended - started, featureCount },
    }));
  }
}

function now() {
  return globalThis.performance?.now?.() ?? Date.now();
}

function prefersReducedMotion() {
  return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}
