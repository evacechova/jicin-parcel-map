import { PARCEL_MIN_ZOOM } from './config.js';

export class ParcelViewportController {
  #controller = null;
  #generation = 0;
  #enabled = false;

  constructor({ loadParcels, readViewport, view }) {
    this.loadParcels = loadParcels;
    this.readViewport = readViewport;
    this.view = view;
  }

  enable() {
    this.#enabled = true;
    return this.refresh();
  }

  async refresh() {
    this.#controller?.abort();
    const generation = ++this.#generation;
    if (!this.#enabled) return false;

    const viewport = this.readViewport();
    if (viewport.zoom < PARCEL_MIN_ZOOM) {
      this.view.showTerritoryOnly('zoom');
      return true;
    }

    const controller = new AbortController();
    this.#controller = controller;
    this.view.showParcelLoading();

    try {
      const collection = await this.loadParcels({
        bbox: viewport.bbox,
        zoom: viewport.zoom,
        signal: controller.signal,
      });
      if (controller.signal.aborted || generation !== this.#generation) return false;

      this.view.publishParcels(collection);
      return true;
    } catch (error) {
      if (isAbort(error) || controller.signal.aborted || generation !== this.#generation) {
        return false;
      }

      if (error?.code === 'too_dense') {
        this.view.showTerritoryOnly('too_dense');
        return true;
      }
      if (error?.code === 'zoom_too_low' || error?.code === 'bbox_too_large') {
        this.view.showTerritoryOnly('policy');
        return true;
      }

      this.view.showParcelError(error, () => this.refresh());
      return false;
    }
  }

  dispose() {
    this.#enabled = false;
    ++this.#generation;
    this.#controller?.abort();
  }
}

function isAbort(error) {
  return error?.name === 'AbortError';
}
