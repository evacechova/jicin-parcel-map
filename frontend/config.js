import scopeCsv from '../config/scopes/jicin.csv?raw';

import { parseScopeCsv } from './scope.js';

export const DISTRICT_BOUNDS = Object.freeze({
  west: 14.8,
  south: 50.15,
  east: 15.95,
  north: 50.85,
});

export const DISTRICT_BBOX = '14.80,50.15,15.95,50.85';
export const DISTRICT_PADDING_PX = 32;
export const NAVIGATION_BOUNDS_PADDING = 0.1;
export const PARCEL_MIN_ZOOM = 17;
export const VIEWPORT_DEBOUNCE_MS = 150;
export const BASEMAP_MAX_ZOOM = 19;

export const JICIN_TERRITORIES = parseScopeCsv(scopeCsv);
export const EXPECTED_TERRITORY_CODES = Object.freeze(
  JICIN_TERRITORIES.map(({ code }) => code),
);
