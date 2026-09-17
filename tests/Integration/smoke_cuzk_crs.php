<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$fixtureJson = file_get_contents($root . '/tests/Fixtures/Crs/cuzk-jicin-control-points.json');
if ($fixtureJson === false) {
    throw new RuntimeException('Cannot read the ČÚZK CRS regression fixture.');
}
$fixture = json_decode($fixtureJson, true, flags: JSON_THROW_ON_ERROR);
$source = (string) ($fixture['source'] ?? '');
if ($source !== 'https://services.cuzk.gov.cz/wfs/inspire-CP-wfs.asp') {
    throw new RuntimeException('Unexpected ČÚZK WFS fixture source.');
}

$totalPoints = 0;
foreach ($fixture['parcels'] ?? [] as $parcel) {
    $inspireId = (string) ($parcel['inspire_id'] ?? '');
    $expectedLifespan = (string) ($parcel['begin_lifespan_version'] ?? '');
    $expectedPoints = $parcel['points'] ?? [];
    $live = [];

    foreach ([5514, 4326] as $srid) {
        $url = $source . '?' . http_build_query([
            'service' => 'WFS',
            'version' => '2.0.0',
            'request' => 'GetFeature',
            'typeNames' => 'cp:CadastralParcel',
            'srsName' => 'EPSG:' . $srid,
            'resourceID' => $inspireId,
        ], '', '&', PHP_QUERY_RFC3986);
        $xml = fetchWfs($url);
        if (!str_contains($xml, 'numberMatched="1"') || !str_contains($xml, 'numberReturned="1"')) {
            throw new RuntimeException(sprintf('ČÚZK WFS did not return exactly one %s feature in EPSG:%d.', $inspireId, $srid));
        }
        if (preg_match('/<cp:beginLifespanVersion>([^<]+)<\/cp:beginLifespanVersion>/', $xml, $lifespan) !== 1) {
            throw new RuntimeException(sprintf('ČÚZK WFS %s has no beginLifespanVersion.', $inspireId));
        }
        if ($lifespan[1] !== $expectedLifespan) {
            throw new RuntimeException(sprintf(
                'ČÚZK WFS %s changed beginLifespanVersion from %s to %s; review the offline fixture.',
                $inspireId,
                $expectedLifespan,
                $lifespan[1],
            ));
        }
        $live[$srid] = coordinatesFromWfs($xml, $inspireId, $srid);
    }

    if (count($live[5514]) !== count($expectedPoints) || count($live[4326]) !== count($expectedPoints)) {
        throw new RuntimeException(sprintf('ČÚZK WFS %s point count changed; review the offline fixture.', $inspireId));
    }
    foreach ($expectedPoints as $index => $expected) {
        $actual = [
            $live[5514][$index][0],
            $live[5514][$index][1],
            $live[4326][$index][0],
            $live[4326][$index][1],
        ];
        if ($actual !== array_map('floatval', $expected)) {
            throw new RuntimeException(sprintf(
                'ČÚZK WFS %s point %d differs from the offline fixture; review source version and geometry.',
                $inspireId,
                $index,
            ));
        }
        ++$totalPoints;
    }
}

if ($totalPoints !== 145) {
    throw new RuntimeException(sprintf('Expected 145 live ČÚZK WFS points, got %d.', $totalPoints));
}

printf(
    "Online ČÚZK WFS CRS smoke passed: %d parcels, %d paired EPSG:5514/EPSG:4326 points, unchanged lifespan versions.\n",
    count($fixture['parcels']),
    $totalPoints,
);

function fetchWfs(string $url): string
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Cannot initialize ČÚZK WFS request.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'jicin-parcel-map/crs-regression',
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if (!is_string($body) || curl_errno($curl) !== CURLE_OK || $status !== 200) {
        throw new RuntimeException(sprintf('ČÚZK WFS request failed with HTTP %d.', $status));
    }

    return $body;
}

/** @return list<array{float, float}> */
function coordinatesFromWfs(string $xml, string $inspireId, int $srid): array
{
    preg_match_all('/<gml:posList>([^<]+)<\/gml:posList>/', $xml, $matches);
    $points = [];
    foreach ($matches[1] as $positionList) {
        $values = preg_split('/\s+/', trim($positionList));
        if ($values === false || count($values) % 2 !== 0) {
            throw new RuntimeException(sprintf('Invalid ČÚZK WFS coordinates for %s in EPSG:%d.', $inspireId, $srid));
        }
        for ($index = 0; $index < count($values); $index += 2) {
            $points[] = [(float) $values[$index], (float) $values[$index + 1]];
        }
    }

    return $points;
}
