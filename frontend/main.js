import './style.css';

import {
  loadCadastralTerritories,
  loadParcelDetail,
  loadParcels,
} from './api.js';
import { MapView } from './map.js';
import { ParcelDetailController } from './parcel-detail-controller.js';
import { ParcelViewportController } from './parcel-viewport-controller.js';
import { TerritoryBootstrap } from './territory-bootstrap.js';
import { DebouncedViewportCoordinator } from './viewport-coordinator.js';

const mapView = new MapView({
  map: document.querySelector('#map'),
  status: document.querySelector('#map-status'),
  statusText: document.querySelector('#map-status-text'),
  retry: document.querySelector('#map-retry'),
  guidance: document.querySelector('#map-guidance'),
  basemapStatus: document.querySelector('#basemap-status'),
  detail: document.querySelector('#parcel-detail'),
  detailTitle: document.querySelector('#parcel-detail-title'),
  detailClose: document.querySelector('#parcel-detail-close'),
  detailStatus: document.querySelector('#parcel-detail-status'),
  detailFields: document.querySelector('#parcel-detail-fields'),
  detailRetry: document.querySelector('#parcel-detail-retry'),
});

const detailController = new ParcelDetailController({
  loadDetail: loadParcelDetail,
  view: mapView,
});
mapView.setParcelSelectHandler((selection) => detailController.select(selection));
mapView.setDetailCloseHandler(() => detailController.close());
mapView.setClearSelectionHandler(() => detailController.close());

const parcelController = new ParcelViewportController({
  loadParcels,
  readViewport: () => mapView.readViewport(),
  view: mapView,
});
const viewportCoordinator = new DebouncedViewportCoordinator({
  onSettled: () => parcelController.refresh(),
});
mapView.onMoveEnd(() => viewportCoordinator.schedule());

const territoryBootstrap = new TerritoryBootstrap({
  loadTerritories: loadCadastralTerritories,
  view: mapView,
  onReady: () => parcelController.enable(),
});

window.addEventListener('beforeunload', () => {
  viewportCoordinator.dispose();
  parcelController.dispose();
  territoryBootstrap.dispose();
  detailController.close();
});

void territoryBootstrap.load();
