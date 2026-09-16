import { describe, expect, it, vi } from 'vitest';

import {
  ApiRequestError,
  loadCadastralTerritories,
  loadParcelDetail,
  loadParcels,
  validateParcelCollection,
  validateTerritoryCollection,
} from '../../frontend/api.js';
import {
  DISTRICT_BBOX,
  EXPECTED_TERRITORY_CODES,
  JICIN_TERRITORIES,
} from '../../frontend/config.js';

describe('committed Jičín scope', () => {
  it('loads the same 240 sorted unique codes used by the importer', () => {
    expect(JICIN_TERRITORIES).toHaveLength(240);
    expect(new Set(EXPECTED_TERRITORY_CODES).size).toBe(240);
    expect(EXPECTED_TERRITORY_CODES).toEqual([...EXPECTED_TERRITORY_CODES].sort());
    expect(JICIN_TERRITORIES).toContainEqual({ code: '659541', name: 'Jičín' });
  });
});

describe('map API client', () => {
  it('uses fixed D for the complete KÚ bootstrap and validates the full code set', async () => {
    const payload = territoryCollection();
    const fetchImplementation = vi.fn().mockResolvedValue(jsonResponse(payload));
    const signal = new AbortController().signal;

    await expect(loadCadastralTerritories({ signal, fetchImplementation })).resolves.toBe(payload);
    expect(fetchImplementation).toHaveBeenCalledWith(
      `/api/v1/cadastral-territories?bbox=${DISTRICT_BBOX}`,
      { headers: { Accept: 'application/json' }, signal },
    );
  });

  it('rejects a partial or duplicate KÚ bootstrap', () => {
    const partial = territoryCollection();
    partial.features.pop();
    expect(() => validateTerritoryCollection(partial)).toThrow(ApiRequestError);

    const duplicate = territoryCollection();
    duplicate.features[1] = duplicate.features[0];
    expect(() => validateTerritoryCollection(duplicate)).toThrow(ApiRequestError);
  });

  it('serialises the Leaflet BBOX in longitude/latitude order and sends settled zoom', async () => {
    const payload = { type: 'FeatureCollection', features: [] };
    const fetchImplementation = vi.fn().mockResolvedValue(jsonResponse(payload));

    await loadParcels({
      bbox: [15.3, 50.4, 15.31, 50.41],
      zoom: 17,
      fetchImplementation,
    });

    expect(fetchImplementation.mock.calls[0][0]).toBe('/api/v1/parcels?bbox=15.3,50.4,15.31,50.41&zoom=17');
  });

  it('preserves the stable API code and request ID for too_dense', async () => {
    const fetchImplementation = vi.fn().mockResolvedValue(jsonResponse({
      error: { code: 'too_dense', message: 'ignored', requestId: 'request-123' },
    }, 409));

    await expect(loadParcels({
      bbox: [15.3, 50.4, 15.31, 50.41],
      zoom: 17,
      fetchImplementation,
    })).rejects.toMatchObject({ status: 409, code: 'too_dense', requestId: 'request-123' });
  });

  it('loads and validates the metadata-only parcel detail', async () => {
    const detail = {
      inspire_id: 'CP.123',
      label: 'st. 10',
      area_m2: 25.5,
      national_cadastral_reference: '659541-st. 10',
      cadastral_territory: { ku_code: '659541', name: 'Jičín' },
    };
    const fetchImplementation = vi.fn().mockResolvedValue(jsonResponse({ data: detail }));

    await expect(loadParcelDetail({ inspireId: 'CP.123', fetchImplementation })).resolves.toBe(detail);
    expect(fetchImplementation.mock.calls[0][0]).toBe('/api/v1/parcels/CP.123');
  });

  it('rejects incomplete, duplicate or oversized parcel collections', () => {
    const feature = parcelFeature('CP.1');
    expect(() => validateParcelCollection({ type: 'FeatureCollection', features: [feature, feature] })).toThrow(ApiRequestError);
    expect(() => validateParcelCollection({
      type: 'FeatureCollection',
      features: Array.from({ length: 2001 }, (_, index) => parcelFeature(`CP.${index}`)),
    })).toThrow(ApiRequestError);
  });
});

function territoryCollection() {
  return {
    type: 'FeatureCollection',
    features: JICIN_TERRITORIES.map(({ code, name }) => ({
      type: 'Feature',
      id: code,
      properties: { ku_code: code, name, parcel_count: 1 },
      geometry: { type: 'MultiPolygon', coordinates: [[[[15, 50], [16, 50], [16, 51], [15, 50]]]] },
    })),
  };
}

function parcelFeature(id) {
  return {
    type: 'Feature',
    id,
    properties: { label: id },
    geometry: { type: 'MultiPolygon', coordinates: [[[[15, 50], [16, 50], [16, 51], [15, 50]]]] },
  };
}

function jsonResponse(body, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    headers: { get: vi.fn().mockReturnValue(null) },
    json: vi.fn().mockResolvedValue(body),
  };
}
