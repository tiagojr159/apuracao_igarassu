<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function tse2026Json(string $url): ?array
{
    $cacheDir = __DIR__ . '/data/cache_tse_2026';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $cacheFile = $cacheDir . '/' . hash('sha256', $url) . '.json';
    if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < 45) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }
    $body = false;
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($status < 200 || $status >= 300) $body = false;
    } elseif (ini_get('allow_url_fopen')) {
        $context = stream_context_create(['http' => ['timeout' => 8, 'header' => "Accept: application/json\r\n"]]);
        $body = @file_get_contents($url, false, $context);
    }
    if (!is_string($body) || $body === '') return null;
    $data = json_decode($body, true);
    if (!is_array($data)) return null;
    @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $data;
}

function tse2026Candidates(array $data): array
{
    $candidates = [];
    foreach (($data['carg'] ?? []) as $cargo) {
        foreach (($cargo['agr'] ?? []) as $group) {
            foreach (($group['par'] ?? []) as $party) {
                foreach (($party['cand'] ?? []) as $candidate) {
                    $id = (string) ($candidate['sqcand'] ?? $candidate['n'] ?? $candidate['nm'] ?? '');
                    if ($id === '') continue;
                    $votes = (int) preg_replace('/\D/', '', (string) ($candidate['vap'] ?? '0'));
                    if (!isset($candidates[$id])) $candidates[$id] = ['name' => (string) ($candidate['nm'] ?? ''), 'votes' => 0];
                    $candidates[$id]['votes'] += $votes;
                }
            }
        }
    }
    return array_values($candidates);
}

function tse2026File(string $scope, string $municipality, string $office, string $election): ?array
{
    $name = $scope . ($municipality !== '' ? $municipality : '') . '-c' . $office . '-e' . $election . '-u.json';
    $url = 'https://resultados.tse.jus.br/oficial/ele2026/' . (int) $election . '/dados/' . $scope . '/' . $name;
    return tse2026Json($url);
}

function tse2026TotalsForMunicipality(string $ibgeCode): array
{
    $config = tse2026Json('https://resultados.tse.jus.br/oficial/ele2026/6259/config/mun-e006259-cm.json');
    $municipality = null;
    foreach (($config['abr'] ?? []) as $state) {
        if (($state['cd'] ?? '') !== 'pe') continue;
        foreach (($state['mu'] ?? []) as $item) {
            if (($item['cdi'] ?? '') === $ibgeCode) { $municipality = $item; break 2; }
        }
    }
    if (!$municipality) return ['year' => 2026, 'turn' => 1, 'source' => 'TSE', 'available' => false, 'sections' => 0, 'sectionsTotalized' => 0, 'offices' => []];
    $code = (string) $municipality['cd'];
    $offices = ['governador' => ['0003', '006259', 4], 'senador' => ['0005', '006259', 4], 'federal' => ['0006', '006259', 10], 'estadual' => ['0007', '006259', 10], 'presidente' => ['0001', '006257', 4]];
    $totals = ['year' => 2026, 'turn' => 1, 'source' => 'TSE', 'available' => true, 'sections' => 0, 'sectionsTotalized' => 0, 'offices' => []];
    foreach ($offices as $key => [$officeCode, $electionId, $limit]) {
        $result = tse2026File('pe', $code, $officeCode, $electionId);
        if (!$result) continue;
        $totals['sections'] = max($totals['sections'], (int) ($result['s']['ts'] ?? 0));
        $totals['sectionsTotalized'] = max($totals['sectionsTotalized'], (int) ($result['s']['st'] ?? 0));
        $candidates = tse2026Candidates($result);
        usort($candidates, static fn(array $a, array $b): int => $b['votes'] <=> $a['votes']);
        $totals['offices'][$key] = array_slice($candidates, 0, $limit);
    }
    return $totals;
}

function tse2026JapanTotals(): array
{
    $config = tse2026Json('https://resultados.tse.jus.br/oficial/ele2026/6257/config/mun-e006257-cm.json');
    $locations = [];
    foreach (($config['abr'] ?? []) as $scope) {
        if (($scope['cd'] ?? '') !== 'zz') continue;
        foreach (($scope['mu'] ?? []) as $item) {
            if (in_array(mb_strtoupper((string) ($item['nm'] ?? ''), 'UTF-8'), ['HAMAMATSU', 'NAGÓIA', 'TÓQUIO'], true)) $locations[] = $item;
        }
    }
    $totals = ['year' => 2026, 'turn' => 1, 'source' => 'TSE', 'available' => count($locations) > 0, 'sections' => 0, 'sectionsTotalized' => 0, 'locations' => array_column($locations, 'nm'), 'offices' => ['presidente' => []]];
    $candidates = [];
    foreach ($locations as $location) {
        $result = tse2026File('zz', (string) $location['cd'], '0001', '006257');
        if (!$result) continue;
        $totals['sections'] += (int) ($result['s']['ts'] ?? 0);
        $totals['sectionsTotalized'] += (int) ($result['s']['st'] ?? 0);
        foreach (tse2026Candidates($result) as $candidate) {
            $key = $candidate['name'];
            if (!isset($candidates[$key])) $candidates[$key] = $candidate;
            else $candidates[$key]['votes'] += $candidate['votes'];
        }
    }
    $totals['offices']['presidente'] = array_values($candidates);
    usort($totals['offices']['presidente'], static fn(array $a, array $b): int => $b['votes'] <=> $a['votes']);
    $totals['offices']['presidente'] = array_slice($totals['offices']['presidente'], 0, 4);
    return $totals;
}

$cityCodes = [
    'Igarassu' => '2606804',
    'Itapissuma' => '2607752',
    'Abreu e Lima' => '2600054',
    'Paulista' => '2610707',
    'Olinda' => '2609600',
    'Recife' => '2611606',
    'Jaboatão dos Guararapes' => '2607901',
    'Camaragibe' => '2603454',
    'Santa Cruz do Capibaribe' => '2612505',
    'Toritama' => '2615409',
    'Cabo de Santo Agostinho' => '2602902',
    'Ipojuca' => '2607208',
    'Caruaru' => '2604106',
    'Goiana' => '2606200',
    'São Lourenço da Mata' => '2613701',
];
$city = isset($_GET['cidade']) ? (string) $_GET['cidade'] : 'Igarassu';
$file = __DIR__ . '/data/map_layers.json';

if (!is_file($file) || !is_readable($file)) {
    http_response_code(503);
    echo json_encode(['error' => 'A base cartográfica local não está disponível.']);
    exit;
}

try {
    $base = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (isset($_GET['eleicao2026'])) {
        $liveCity = isset($_GET['cidade']) ? (string) $_GET['cidade'] : 'Igarassu';
        $liveTotals = $liveCity === 'Japão' ? tse2026JapanTotals() : (isset($cityCodes[$liveCity]) ? tse2026TotalsForMunicipality($cityCodes[$liveCity]) : null);
        if ($liveTotals === null) { http_response_code(404); echo json_encode(['error' => 'Município não encontrado.']); exit; }
        echo json_encode(['city' => $liveCity, 'electionTotals' => $liveTotals], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
    if ($city === 'Japão') {
        $result = [
            'city' => $city,
            'level' => 'international',
            'boundary' => ['type' => 'FeatureCollection', 'features' => []],
            'neighborhoods' => ['type' => 'FeatureCollection', 'features' => []],
            'pollingPlaces' => [],
            'electionTotals' => tse2026JapanTotals(),
            'election' => ['year' => 2026, 'turn' => 1, 'source' => 'TSE'],
            'metadata' => $base['metadata'],
        ];
    } elseif ($city === 'Pernambuco') {
        $result = [
            'city' => $city,
            'level' => 'state',
            'boundary' => $base['state']['boundary'],
            'municipalBoundaries' => $base['state']['municipalities'],
            'neighborhoods' => ['type' => 'FeatureCollection', 'features' => []],
            'pollingPlaces' => [],
            'metadata' => $base['metadata'],
        ];
    } elseif (isset($cityCodes[$city], $base['cities'][$cityCodes[$city]])) {
        $cityData = $base['cities'][$cityCodes[$city]];
        $pollingPlaces = array_map(static function (array $place): array {
            $place['election'] = null;
            return $place;
        }, $cityData['places']);
        $result = [
            'city' => $city,
            'level' => 'municipality',
            'boundary' => $cityData['boundary'],
            'neighborhoods' => $cityData['neighborhoods'],
            'pollingPlaces' => $pollingPlaces,
            'electionTotals' => tse2026TotalsForMunicipality($cityCodes[$city]),
            'election' => ['year' => 2026, 'turn' => 1, 'source' => 'TSE'],
            'metadata' => $base['metadata'],
        ];
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Município não encontrado na lista.']);
        exit;
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'Não foi possível preparar os dados do mapa.']);
}
