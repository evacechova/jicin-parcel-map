import {
  DISTRICT_BBOX,
  EXPECTED_TERRITORY_CODES,
  PARCEL_MIN_ZOOM,
} from './config.js';

const PARCEL_FEATURE_LIMIT = 2000;
const PARCEL_ID_PATTERN = /^[A-Za-z0-9._-]+$/u;

export class ApiRequestError extends Error {
  constructor(message, { status = 0, code = 'network_error', requestId = null } = {}) {
    super(message);
    this.name = 'ApiRequestError';
    this.status = status;
    this.code = code;
    this.requestId = requestId;
  }
}

export async function loadCadastralTerritories({ signal, fetchImplementation = fetch } = {}) {
  const payload = await fetchJson(
    `/api/v1/cadastral-territories?bbox=${DISTRICT_BBOX}`,
    { signal, fetchImplementation, metricName: 'territories' },
  );

  return validateTerritoryCollection(payload);
}

export async function loadParcels({ bbox, zoom, signal, fetchImplementation = fetch }) {
  if (!Array.isArray(bbox) || bbox.length !== 4 || !Number.isInteger(zoom)) {
    throw new TypeError('A parcel request requires a BBOX and integer zoom.');
  }
  if (zoom < PARCEL_MIN_ZOOM) {
    throw new TypeError('A parcel request must not be sent below the configured LOD threshold.');
  }

  const bboxValue = bbox.map(formatCoordinate).join(',');
  const payload = await fetchJson(
    `/api/v1/parcels?bbox=${bboxValue}&zoom=${zoom}`,
    { signal, fetchImplementation, metricName: 'parcels' },
  );

  return validateParcelCollection(payload);
}

export async function loadParcelDetail({ inspireId, signal, fetchImplementation = fetch }) {
  if (typeof inspireId !== 'string' || !PARCEL_ID_PATTERN.test(inspireId) || inspireId.length > 64) {
    throw new TypeError('A valid INSPIRE parcel identifier is required.');
  }

  const payload = await fetchJson(
    `/api/v1/parcels/${encodeURIComponent(inspireId)}`,
    { signal, fetchImplementation, metricName: 'parcel-detail' },
  );

  return validateParcelDetail(payload);
}

export function validateTerritoryCollection(payload, expectedCodes = EXPECTED_TERRITORY_CODES) {
  if (payload?.type !== 'FeatureCollection' || !Array.isArray(payload.features)) {
    throw invalidResponse();
  }

  const expected = new Set(expectedCodes);
  if (expected.size !== expectedCodes.length || payload.features.length !== expected.size) {
    throw invalidResponse('Server vrátil neúplnou vrstvu katastrálních území.');
  }

  const received = new Set();
  for (const feature of payload.features) {
    const code = feature?.properties?.ku_code;
    if (
      feature?.type !== 'Feature'
      || typeof feature.id !== 'string'
      || feature.id !== code
      || typeof code !== 'string'
      || !expected.has(code)
      || received.has(code)
      || typeof feature.properties.name !== 'string'
      || feature.properties.name.trim() === ''
      || !Number.isSafeInteger(feature.properties.parcel_count)
      || feature.properties.parcel_count < 0
      || !isMultiPolygon(feature.geometry)
    ) {
      throw invalidResponse('Server vrátil neúplnou vrstvu katastrálních území.');
    }
    received.add(code);
  }

  return payload;
}

export function validateParcelCollection(payload) {
  if (
    payload?.type !== 'FeatureCollection'
    || !Array.isArray(payload.features)
    || payload.features.length > PARCEL_FEATURE_LIMIT
  ) {
    throw invalidResponse();
  }

  const identifiers = new Set();
  for (const feature of payload.features) {
    if (
      feature?.type !== 'Feature'
      || typeof feature.id !== 'string'
      || feature.id.length > 64
      || !PARCEL_ID_PATTERN.test(feature.id)
      || identifiers.has(feature.id)
      || Object.keys(feature.properties ?? {}).length !== 1
      || typeof feature.properties?.label !== 'string'
      || !isMultiPolygon(feature.geometry)
    ) {
      throw invalidResponse();
    }
    identifiers.add(feature.id);
  }

  return payload;
}

export function validateParcelDetail(payload) {
  const detail = payload?.data;
  if (
    detail === null
    || typeof detail !== 'object'
    || typeof detail.inspire_id !== 'string'
    || !PARCEL_ID_PATTERN.test(detail.inspire_id)
    || typeof detail.label !== 'string'
    || typeof detail.area_m2 !== 'number'
    || !Number.isFinite(detail.area_m2)
    || detail.area_m2 < 0
    || typeof detail.national_cadastral_reference !== 'string'
    || typeof detail.cadastral_territory?.ku_code !== 'string'
    || !/^[0-9]{6}$/u.test(detail.cadastral_territory.ku_code)
    || typeof detail.cadastral_territory?.name !== 'string'
    || detail.cadastral_territory.name.trim() === ''
  ) {
    throw invalidResponse();
  }

  return detail;
}

async function fetchJson(url, { signal, fetchImplementation, metricName }) {
  const requestStarted = now();
  let response;
  try {
    response = await fetchImplementation(url, {
      headers: { Accept: 'application/json' },
      signal,
    });
  } catch (error) {
    if (error?.name === 'AbortError') throw error;
    throw new ApiRequestError('Datový požadavek se nepodařilo dokončit.');
  }

  const parseStarted = now();
  let body;
  try {
    body = await response.json();
  } catch {
    throw new ApiRequestError('Server vrátil nečitelnou odpověď.', {
      status: response.status,
      code: 'invalid_response',
      requestId: response.headers?.get?.('X-Request-Id') ?? null,
    });
  } finally {
    recordMeasure(`viagem:${metricName}:json-parse`, parseStarted);
    recordMeasure(`viagem:${metricName}:request`, requestStarted);
  }

  if (!response.ok) {
    throw new ApiRequestError('Datový požadavek se nepodařilo dokončit.', {
      status: response.status,
      code: typeof body?.error?.code === 'string' ? body.error.code : 'http_error',
      requestId: typeof body?.error?.requestId === 'string'
        ? body.error.requestId
        : response.headers?.get?.('X-Request-Id') ?? null,
    });
  }

  return body;
}

function isMultiPolygon(geometry) {
  return geometry?.type === 'MultiPolygon'
    && Array.isArray(geometry.coordinates)
    && geometry.coordinates.length > 0;
}

function formatCoordinate(value) {
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    throw new TypeError('Viewport coordinates must be finite numbers.');
  }

  return value.toFixed(7).replace(/(?:\.0+|(?<decimal>\.[0-9]*?)0+)$/u, '$<decimal>');
}

function invalidResponse(message = 'Server vrátil neplatná mapová data.') {
  return new ApiRequestError(message, { code: 'invalid_response' });
}

function now() {
  return globalThis.performance?.now?.() ?? Date.now();
}

function recordMeasure(name, started) {
  try {
    globalThis.performance?.measure?.(name, { start: started, end: now() });
  } catch {
    // Performance measurements are diagnostics and must not break the map.
  }
}
