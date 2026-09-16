import { chromium } from '@playwright/test';

const baseUrl = process.env.FRONTEND_BENCHMARK_URL ?? 'http://127.0.0.1:5173/';
const iterations = Number.parseInt(process.env.FRONTEND_BENCHMARK_ITERATIONS ?? '5', 10);
if (!Number.isSafeInteger(iterations) || iterations < 1 || iterations > 20) {
  throw new Error('FRONTEND_BENCHMARK_ITERATIONS must be an integer from 1 to 20.');
}

const browser = await chromium.launch({ channel: 'chrome', headless: true });
try {
  const scenarios = [
    { name: 'desktop', viewport: { width: 1440, height: 900 } },
    { name: 'mobile', viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true },
  ];
  const report = {
    fixture: { territories: 240, parcels: 1500, parcelVertices: 17 },
    browser: await browser.version(),
    iterations,
    scenarios: {},
    fallback: null,
  };

  for (const scenario of scenarios) {
    process.stderr.write(`benchmark ${scenario.name}: warm-up + ${iterations} measured runs\n`);
    const context = await browser.newContext({
      viewport: scenario.viewport,
      isMobile: scenario.isMobile ?? false,
      hasTouch: scenario.hasTouch ?? false,
      reducedMotion: 'reduce',
    });
    const results = [];
    for (let iteration = 0; iteration <= iterations; ++iteration) {
      process.stderr.write(`  run ${iteration + 1}/${iterations + 1}\n`);
      const result = await measureOnce(context, scenario);
      if (iteration > 0) results.push(result);
    }
    report.scenarios[scenario.name] = summarise(results);
    await context.close();
  }

  const fallbackContext = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  report.fallback = await verifyTooDenseFallback(fallbackContext);
  await fallbackContext.close();
  process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
} finally {
  await browser.close();
}

async function measureOnce(context, scenario) {
  const page = await context.newPage();
  page.setDefaultTimeout(15_000);
  await preparePage(page);
  const pageErrors = [];
  const apiResponses = [];
  page.on('pageerror', (error) => pageErrors.push(error));
  page.on('response', (response) => {
    if (response.url().includes('/api/v1/')) {
      apiResponses.push({ status: response.status(), url: response.url() });
    }
  });
  await page.goto(baseUrl, { waitUntil: 'domcontentloaded' });
  const jicin = page.locator('[aria-label^="Jičín, katastrální území"]');
  await jicin.waitFor({ state: 'attached' });
  if (pageErrors.length > 0) throw pageErrors[0];
  await jicin.click({ force: true });
  await waitForMapMovement(page);
  await page.evaluate(() => {
    performance.clearMeasures();
    performance.clearResourceTimings();
    window.__viagemLongTasks = [];
  });
  await zoomToParcelLevel(page);
  try {
    await page.waitForFunction(() => {
      const guidance = document.querySelector('#map-guidance')?.textContent ?? '';
      const status = document.querySelector('#map-status');
      return guidance.startsWith('Zobrazeno')
        || guidance.includes('zůstávají hranice KÚ')
        || (!status?.hidden && status?.dataset.state === 'error');
    });
  } catch (error) {
    const timeoutState = await page.evaluate(() => ({
      guidance: document.querySelector('#map-guidance')?.textContent ?? '',
      status: document.querySelector('#map-status-text')?.textContent ?? '',
      statusState: document.querySelector('#map-status')?.dataset.state ?? '',
      statusHidden: document.querySelector('#map-status')?.hidden ?? false,
      zoom: document.querySelector('#map')?.dataset.zoom ?? '',
    }));
    throw new Error(`Parcel viewport timed out: ${JSON.stringify({ timeoutState, apiResponses, pageErrors: pageErrors.map(String) })}`, { cause: error });
  }
  const uiState = await page.evaluate(() => ({
    guidance: document.querySelector('#map-guidance')?.textContent ?? '',
    status: document.querySelector('#map-status-text')?.textContent ?? '',
    statusHidden: document.querySelector('#map-status')?.hidden ?? false,
  }));
  if (!uiState.guidance.startsWith('Zobrazeno')) {
    throw new Error(`Parcel viewport did not render: ${JSON.stringify({ uiState, apiResponses })}`);
  }
  const parcelFeatures = page.locator('[aria-label^="Parcela "]');
  const renderedFeatures = await parcelFeatures.count();
  if (renderedFeatures < 1 || renderedFeatures > 1500) {
    throw new Error(`Expected a non-empty capped parcel layer, got ${renderedFeatures} features.`);
  }

  const visibleParcelIndex = await page.evaluate(() => {
    return [...document.querySelectorAll('[aria-label^="Parcela "]')].findIndex((element) => {
      const rectangle = element.getBoundingClientRect();
      return rectangle.right > 0
        && rectangle.bottom > 0
        && rectangle.left < window.innerWidth
        && rectangle.top < window.innerHeight;
    });
  });
  if (visibleParcelIndex === -1) throw new Error('No rendered parcel is inside the interactive viewport.');
  await parcelFeatures.nth(visibleParcelIndex).click({ force: true });
  await page.locator('#parcel-detail-fields').waitFor({ state: 'visible' });
  const detailBox = await page.locator('#parcel-detail').boundingBox();
  const protectedControls = await Promise.all([
    page.locator('.leaflet-control-attribution').boundingBox(),
    page.locator('.leaflet-control-scale').boundingBox(),
    page.locator('.leaflet-control-zoom').boundingBox(),
    page.locator('.district-reset-control').boundingBox(),
  ]);
  if (detailBox !== null && protectedControls.some((controlBox) => rectanglesOverlap(detailBox, controlBox))) {
    throw new Error('The parcel detail overlaps a Leaflet control or attribution.');
  }
  const detailText = await page.locator('#parcel-detail').innerText();
  const normalisedDetailText = detailText.toLocaleLowerCase('cs-CZ');
  if (!normalisedDetailText.includes('výměra') || !normalisedDetailText.includes('katastrální území')) {
    throw new Error(`Parcel detail did not render the documented fields: ${JSON.stringify(detailText)}`);
  }
  await page.locator('#parcel-detail-close').click();
  await page.locator('#parcel-detail').waitFor({ state: 'hidden' });

  const metrics = await page.evaluate(() => {
    const lastDuration = (name) => {
      const entries = performance.getEntriesByName(name);
      return entries.length === 0 ? null : entries.at(-1).duration;
    };
    const parcelResource = performance.getEntriesByType('resource')
      .filter((entry) => entry.name.includes('/api/v1/parcels?'))
      .at(-1);
    const longTasks = window.__viagemLongTasks ?? [];
    return {
      requestMs: lastDuration('viagem:parcels:request'),
      jsonParseMs: lastDuration('viagem:parcels:json-parse'),
      renderMs: lastDuration('viagem:parcel-layer-render'),
      resourceMs: parcelResource?.duration ?? null,
      transferBytes: parcelResource?.transferSize ?? null,
      decodedBytes: parcelResource?.decodedBodySize ?? null,
      longTaskCount: longTasks.length,
      longTaskTotalMs: longTasks.reduce((sum, duration) => sum + duration, 0),
      longTaskMaxMs: longTasks.length === 0 ? 0 : Math.max(...longTasks),
      heapBytes: performance.memory?.usedJSHeapSize ?? null,
    };
  });
  await page.close();

  return {
    ...metrics,
    renderedFeatures,
    detailWidth: detailBox?.width ?? null,
    detailHeight: detailBox?.height ?? null,
    viewportWidth: scenario.viewport.width,
    viewportHeight: scenario.viewport.height,
  };
}

async function verifyTooDenseFallback(context) {
  const page = await context.newPage();
  page.setDefaultTimeout(15_000);
  await preparePage(page);
  await page.route('**/api/v1/parcels?**', async (route) => {
    await route.fulfill({
      status: 409,
      contentType: 'application/json',
      body: JSON.stringify({
        error: {
          code: 'too_dense',
          message: 'Viewport exceeds the parcel representation limit.',
          requestId: 'frontend-benchmark-too-dense',
        },
      }),
    });
  });
  await page.goto(baseUrl, { waitUntil: 'domcontentloaded' });
  const jicin = page.locator('[aria-label^="Jičín, katastrální území"]');
  await jicin.waitFor({ state: 'attached' });
  await jicin.click({ force: true });
  await waitForMapMovement(page);
  await zoomToParcelLevel(page);
  await page.locator('#map-guidance').filter({ hasText: /zůstávají hranice KÚ/u }).waitFor({ state: 'visible' });
  const result = {
    territoryFeatures: await page.locator('[aria-label*="katastrální území"]').count(),
    parcelFeatures: await page.locator('[aria-label^="Parcela "]').count(),
    retryErrorVisible: await page.locator('#map-status:not([hidden])').count() > 0,
  };
  await page.close();
  return result;
}

async function preparePage(page) {
  await page.addInitScript(() => {
    window.__viagemLongTasks = [];
    if ('PerformanceObserver' in window) {
      try {
        new PerformanceObserver((list) => {
          window.__viagemLongTasks.push(...list.getEntries().map((entry) => entry.duration));
        }).observe({ type: 'longtask', buffered: true });
      } catch {
        // Long Task API availability is reported by the resulting empty array.
      }
    }
  });
  await page.route('https://tile.openstreetmap.org/**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'image/png',
      body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64'),
    });
  });
}

async function waitForMapMovement(page) {
  await page.waitForFunction(() => document.querySelector('#map')?.dataset.zoom !== undefined);
  await page.waitForTimeout(250);
}

async function zoomToParcelLevel(page) {
  for (let attempt = 0; attempt < 10; ++attempt) {
    const zoom = Number(await page.locator('#map').getAttribute('data-zoom'));
    if (zoom >= 17) return;
    await page.locator('.leaflet-control-zoom-in').click();
    await page.waitForFunction((previousZoom) => {
      return Number(document.querySelector('#map')?.dataset.zoom) > previousZoom;
    }, zoom);
  }
  throw new Error('The map did not reach parcel zoom 17.');
}

function summarise(results) {
  const metricNames = [
    'requestMs',
    'jsonParseMs',
    'renderMs',
    'resourceMs',
    'transferBytes',
    'decodedBytes',
    'longTaskCount',
    'longTaskTotalMs',
    'longTaskMaxMs',
    'heapBytes',
    'detailWidth',
    'detailHeight',
  ];
  const summary = {
    renderedFeatures: {
      minimum: Math.min(...results.map((result) => result.renderedFeatures)),
      maximum: Math.max(...results.map((result) => result.renderedFeatures)),
    },
  };
  for (const metric of metricNames) {
    const values = results.map((result) => result[metric]).filter(Number.isFinite);
    summary[metric] = values.length === 0 ? null : {
      median: percentile(values, 0.5),
      p95: percentile(values, 0.95),
    };
  }
  return summary;
}

function percentile(values, ratio) {
  const sorted = [...values].sort((left, right) => left - right);
  return sorted[Math.max(0, Math.ceil(sorted.length * ratio) - 1)];
}

function rectanglesOverlap(left, right) {
  if (left === null || right === null) return false;
  return left.x < right.x + right.width
    && left.x + left.width > right.x
    && left.y < right.y + right.height
    && left.y + left.height > right.y;
}
