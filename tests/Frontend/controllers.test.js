import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ApiRequestError } from '../../frontend/api.js';
import { ParcelDetailController } from '../../frontend/parcel-detail-controller.js';
import { ParcelViewportController } from '../../frontend/parcel-viewport-controller.js';
import { TerritoryBootstrap } from '../../frontend/territory-bootstrap.js';
import { DebouncedViewportCoordinator } from '../../frontend/viewport-coordinator.js';

describe('TerritoryBootstrap', () => {
  it('keeps bootstrap independent and ignores a superseded response', async () => {
    const first = deferred();
    const second = deferred();
    const signals = [];
    const loadTerritories = vi.fn(({ signal }) => {
      signals.push(signal);
      return signals.length === 1 ? first.promise : second.promise;
    });
    const view = {
      showTerritoryLoading: vi.fn(),
      publishTerritories: vi.fn(),
      showTerritoryError: vi.fn(),
    };
    const onReady = vi.fn();
    const bootstrap = new TerritoryBootstrap({ loadTerritories, view, onReady });

    const firstLoad = bootstrap.load();
    const secondLoad = bootstrap.load();
    expect(signals[0].aborted).toBe(true);
    first.resolve({ generation: 1 });
    second.resolve({ generation: 2 });

    await expect(firstLoad).resolves.toBe(false);
    await expect(secondLoad).resolves.toBe(true);
    expect(view.publishTerritories).toHaveBeenCalledOnce();
    expect(view.publishTerritories).toHaveBeenCalledWith({ generation: 2 });
    expect(onReady).toHaveBeenCalledOnce();
  });
});

describe('ParcelViewportController', () => {
  let viewport;
  let view;

  beforeEach(() => {
    viewport = { bbox: [15.3, 50.4, 15.31, 50.41], zoom: 16 };
    view = {
      showTerritoryOnly: vi.fn(),
      showParcelLoading: vi.fn(),
      publishParcels: vi.fn(),
      showParcelError: vi.fn(),
    };
  });

  it('does not request parcels below zoom 17', async () => {
    const loadParcels = vi.fn();
    const controller = new ParcelViewportController({ loadParcels, readViewport: () => viewport, view });

    await controller.enable();
    expect(loadParcels).not.toHaveBeenCalled();
    expect(view.showTerritoryOnly).toHaveBeenCalledWith('zoom');
  });

  it('publishes only the latest completed viewport response', async () => {
    viewport = { ...viewport, zoom: 17 };
    const first = deferred();
    const second = deferred();
    const signals = [];
    const loadParcels = vi.fn(({ signal }) => {
      signals.push(signal);
      return signals.length === 1 ? first.promise : second.promise;
    });
    const controller = new ParcelViewportController({ loadParcels, readViewport: () => viewport, view });

    const firstLoad = controller.enable();
    const secondLoad = controller.refresh();
    expect(signals[0].aborted).toBe(true);
    first.resolve({ generation: 1 });
    second.resolve({ generation: 2 });

    await expect(firstLoad).resolves.toBe(false);
    await expect(secondLoad).resolves.toBe(true);
    expect(view.publishParcels).toHaveBeenCalledOnce();
    expect(view.publishParcels).toHaveBeenCalledWith({ generation: 2 });
  });

  it('turns too_dense into a silent KÚ fallback', async () => {
    viewport = { ...viewport, zoom: 17 };
    const loadParcels = vi.fn().mockRejectedValue(new ApiRequestError('ignored', { code: 'too_dense', status: 409 }));
    const controller = new ParcelViewportController({ loadParcels, readViewport: () => viewport, view });

    await expect(controller.enable()).resolves.toBe(true);
    expect(view.showTerritoryOnly).toHaveBeenCalledWith('too_dense');
    expect(view.showParcelError).not.toHaveBeenCalled();
  });

  it('retains data and exposes retry for a service failure', async () => {
    viewport = { ...viewport, zoom: 17 };
    const error = new ApiRequestError('ignored', { code: 'dataset_unavailable', status: 503 });
    const loadParcels = vi.fn().mockRejectedValue(error);
    const controller = new ParcelViewportController({ loadParcels, readViewport: () => viewport, view });

    await expect(controller.enable()).resolves.toBe(false);
    expect(view.showParcelError).toHaveBeenCalledWith(error, expect.any(Function));
  });
});

describe('ParcelDetailController', () => {
  it('keeps immediate selection, ignores stale detail and handles 404 as current-data change', async () => {
    const first = deferred();
    const missing = new ApiRequestError('ignored', { code: 'parcel_not_found', status: 404 });
    const signals = [];
    const loadDetail = vi.fn(({ signal }) => {
      signals.push(signal);
      return signals.length === 1 ? first.promise : Promise.reject(missing);
    });
    const view = {
      showDetailLoading: vi.fn(),
      publishDetail: vi.fn(),
      showParcelGone: vi.fn(),
      showDetailError: vi.fn(),
      closeDetail: vi.fn(),
    };
    const controller = new ParcelDetailController({ loadDetail, view });

    const firstLoad = controller.select({ id: 'CP.1', label: '1', keyboard: false });
    const secondLoad = controller.select({ id: 'CP.2', label: '2', keyboard: false });
    expect(signals[0].aborted).toBe(true);
    first.resolve({ inspire_id: 'CP.1' });

    await expect(firstLoad).resolves.toBe(false);
    await expect(secondLoad).resolves.toBe(true);
    expect(view.publishDetail).not.toHaveBeenCalled();
    expect(view.showParcelGone).toHaveBeenCalledOnce();
  });
});

describe('DebouncedViewportCoordinator', () => {
  it('coalesces moveend events and supports an immediate post-bootstrap flush', () => {
    vi.useFakeTimers();
    const onSettled = vi.fn();
    const coordinator = new DebouncedViewportCoordinator({ onSettled, delay: 150 });

    coordinator.schedule();
    coordinator.schedule();
    vi.advanceTimersByTime(149);
    expect(onSettled).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1);
    expect(onSettled).toHaveBeenCalledOnce();

    coordinator.schedule();
    coordinator.flush();
    expect(onSettled).toHaveBeenCalledTimes(2);
    vi.useRealTimers();
  });
});

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return { promise, resolve, reject };
}
