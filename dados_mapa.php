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
    $cacheTtl = (strpos($url, '-aux.json') !== false) ? 600 : ((strpos($url, '-cs.json') !== false) ? 86400 : 45);
    if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < $cacheTtl) {
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

function tse2026Bytes(string $url): ?string
{
    $cacheDir = __DIR__ . '/data/cache_tse_2026_bu';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $cacheFile = $cacheDir . '/' . hash('sha256', $url) . '.bin';
    if (is_file($cacheFile) && time() - (int) filemtime($cacheFile) < 3600) return (string) file_get_contents($cacheFile);
    $body = false;
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 12]);
        $body = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        if ($status < 200 || $status >= 300) $body = false;
    } elseif (ini_get('allow_url_fopen')) $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 12]]));
    if (!is_string($body) || $body === '') return null;
    @file_put_contents($cacheFile, $body);
    return $body;
}

function tse2026SectionsByCandidate(string $municipalityCode, string $ibgeCode, int $officeCode, int $candidateNumber, int $offset, int $limit, int $localFilter = 0): array
{
    require_once __DIR__ . '/bu_asn.php';
    $pleitoPath = '3220'; $pleitoFile = '003220'; $municipalityCode = str_pad($municipalityCode, 5, '0', STR_PAD_LEFT);
    $places = [];
    $mapFile = __DIR__ . '/data/map_layers.json';
    if (is_file($mapFile)) {
        $mapData = json_decode((string) file_get_contents($mapFile), true);
        foreach (($mapData['cities'][$ibgeCode]['places'] ?? []) as $place) {
            $places[(string) ($place['zone'] ?? '') . ':' . (string) ($place['local'] ?? '')] = $place;
        }
    }
    $prefix = 'https://resultados.tse.jus.br/oficial/ele2026/arquivo-urna/';
    $configUrl = $prefix . $pleitoPath . '/config/pe/pe-p' . $pleitoFile . '-cs.json';
    $config = tse2026Json($configUrl);
    if (!$config) throw new RuntimeException('O TSE não forneceu a configuração das seções de Pernambuco.');
    $sections = [];
    foreach (($config['abr'] ?? []) as $uf) if (($uf['cd'] ?? '') === 'pe') foreach (($uf['mu'] ?? []) as $mu) {
        if ((string) ($mu['cd'] ?? '') !== $municipalityCode) continue;
        foreach (($mu['zon'] ?? []) as $zone) foreach (($zone['sec'] ?? []) as $section) $sections[] = ['zone' => (string) ($zone['cd'] ?? ''), 'section' => (string) ($section['ns'] ?? ''), 'data' => $section];
    }
    usort($sections, static function (array $a, array $b): int { return strnatcmp($a['zone'] . ':' . $a['section'], $b['zone'] . ':' . $b['section']); });
    $total = count($sections); $slice = array_slice($sections, $offset, $limit); $rows = [];
    foreach ($slice as $item) {
        $zone = str_pad($item['zone'], 4, '0', STR_PAD_LEFT); $section = str_pad($item['section'], 4, '0', STR_PAD_LEFT);
        if (!empty($item['data']['nsp'])) { if ($localFilter === 0) $rows[] = ['zone' => $zone, 'section' => $section, 'votes' => null, 'place_name' => 'Mesmo colégio da seção principal ' . str_pad((string) $item['data']['nsp'], 4, '0', STR_PAD_LEFT), 'aggregated_into' => (string) $item['data']['nsp']]; continue; }
        $auxName = 'p' . $pleitoFile . '-pe-m' . $municipalityCode . '-z' . $zone . '-s' . $section . '-aux.json';
        $auxUrl = $prefix . $pleitoPath . '/dados/pe/' . $municipalityCode . '/' . $zone . '/' . $section . '/' . $auxName;
        $aux = tse2026Json($auxUrl); $selected = null;
        foreach (($aux['hashes'] ?? []) as $hash) if (($hash['st'] ?? '') === 'Totalizado') $selected = $hash;
        if (!$selected) { $rows[] = ['zone' => $zone, 'section' => $section, 'votes' => null, 'pending' => true]; continue; }
        $buFile = null;
        foreach (($selected['arq'] ?? []) as $file) if (($file['tp'] ?? '') === 'bu' || preg_match('/-bu\.(dat|bu)$/i', (string) ($file['nm'] ?? ''))) { $buFile = (string) $file['nm']; break; }
        if ($buFile === null) { $rows[] = ['zone' => $zone, 'section' => $section, 'votes' => null, 'pending' => true]; continue; }
        $buUrl = $prefix . $pleitoPath . '/dados/pe/' . $municipalityCode . '/' . $zone . '/' . $section . '/' . rawurlencode((string) $selected['hash']) . '/' . rawurlencode($buFile);
        try {
            $raw = tse2026Bytes($buUrl); if ($raw === null) throw new RuntimeException('BU indisponível.');
            $identity = buDecodeSectionIdentity($raw);
            if ($localFilter > 0 && $identity['local'] !== $localFilter) continue;
            $decodedVotes = buDecodeVotes($raw); $votes = 0; $candidateVotes = [];
            foreach ($decodedVotes as $vote) {
                if ($vote['vote_type'] !== 'NOMINAL') continue;
                if ($candidateNumber === 0 && $localFilter > 0) {
                    $candidateVotes[] = ['office_code' => (int) $vote['office_code'], 'number' => (string) $vote['number'], 'votes' => (int) $vote['votes']];
                } elseif ((int) $vote['office_code'] === $officeCode && (int) $vote['number'] === $candidateNumber) $votes += (int) $vote['votes'];
            }
            $place = $places[$identity['zone'] . ':' . $identity['local']] ?? null;
            $row = ['zone' => $zone, 'section' => $section, 'local' => $identity['local'], 'place_name' => $place['name'] ?? ('Local de votação ' . $identity['local']), 'neighborhood' => $place['neighborhood'] ?? ''];
            if ($candidateNumber === 0 && $localFilter > 0) $row['candidate_votes'] = $candidateVotes;
            else $row['votes'] = $votes;
            $rows[] = $row;
        } catch (Throwable $error) { $rows[] = ['zone' => $zone, 'section' => $section, 'votes' => null, 'error' => true]; }
    }
    return ['sections' => $rows, 'total_sections' => $total, 'next_offset' => $offset + count($slice), 'done' => $offset + count($slice) >= $total];
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
                    if (!isset($candidates[$id])) $candidates[$id] = ['id' => $id, 'number' => (string) ($candidate['n'] ?? ''), 'name' => (string) (($candidate['nmu'] ?? '') !== '' ? $candidate['nmu'] : ($candidate['nm'] ?? '')), 'party' => (string) ($party['sg'] ?? ''), 'votes' => 0];
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
    $offices = ['governador' => ['0003', '006259'], 'senador' => ['0005', '006259'], 'federal' => ['0006', '006259'], 'estadual' => ['0007', '006259'], 'presidente' => ['0001', '006257']];
    $totals = ['year' => 2026, 'turn' => 1, 'source' => 'TSE', 'available' => true, 'sections' => 0, 'sectionsTotalized' => 0, 'offices' => []];
    foreach ($offices as $key => [$officeCode, $electionId]) {
        $result = tse2026File('pe', $code, $officeCode, $electionId);
        if (!$result) continue;
        // Nunca mostre um payload de outro ciclo eleitoral como se fosse 2026.
        if ((string) ($result['ele'] ?? '') !== '6259') continue;
        $totals['sections'] = max($totals['sections'], (int) ($result['s']['ts'] ?? 0));
        $totals['sectionsTotalized'] = max($totals['sectionsTotalized'], (int) ($result['s']['st'] ?? 0));
        $candidates = tse2026Candidates($result);
        usort($candidates, static fn(array $a, array $b): int => $b['votes'] <=> $a['votes']);
        $totals['offices'][$key] = $candidates;
    }
    return $totals;
}

function tse2026OfficeCandidatesForMunicipality(string $ibgeCode, string $office): array
{
    $config = tse2026Json('https://resultados.tse.jus.br/oficial/ele2026/6259/config/mun-e006259-cm.json');
    foreach (($config['abr'] ?? []) as $state) {
        if (($state['cd'] ?? '') !== 'pe') continue;
        foreach (($state['mu'] ?? []) as $municipality) {
            if (($municipality['cdi'] ?? '') !== $ibgeCode) continue;
            [$officeCode, $electionId] = ['estadual' => ['0007', '006259'], 'federal' => ['0006', '006259'], 'senador' => ['0005', '006259'], 'governador' => ['0003', '006259'], 'presidente' => ['0001', '006257']][$office];
            $result = tse2026File('pe', (string) $municipality['cd'], $officeCode, $electionId);
            if (!$result || (string) ($result['ele'] ?? '') !== ltrim($electionId, '0')) return [];
            $candidates = tse2026Candidates($result);
            usort($candidates, static fn(array $a, array $b): int => $b['votes'] <=> $a['votes']);
            return $candidates;
        }
    }
    return [];
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
        if ((string) ($result['ele'] ?? '') !== '6257') continue;
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
$consultationOffices = ['estadual' => ['0007', '006259'], 'federal' => ['0006', '006259'], 'senador' => ['0005', '006259'], 'governador' => ['0003', '006259'], 'presidente' => ['0001', '006257']];
if (isset($_GET['consulta'])) {
    $action = (string) $_GET['consulta'];
    $office = (string) ($_GET['cargo'] ?? '');
    if (!isset($consultationOffices[$office])) { http_response_code(400); echo json_encode(['error' => 'Cargo inválido.']); exit; }
    if ($action === 'colegio-secoes') {
        $cityName = (string) ($_GET['cidade'] ?? ''); $local = (int) ($_GET['local'] ?? 0); $ibge = $cityCodes[$cityName] ?? '';
        if ($ibge === '' || $local < 1) { http_response_code(400); echo json_encode(['error' => 'Município ou local de votação inválido.']); exit; }
        $municipalityCode = '';
        $config = tse2026Json('https://resultados.tse.jus.br/oficial/ele2026/6259/config/mun-e006259-cm.json');
        foreach (($config['abr'] ?? []) as $state) if (($state['cd'] ?? '') === 'pe') foreach (($state['mu'] ?? []) as $mu) if ((string) ($mu['cdi'] ?? '') === $ibge) $municipalityCode = (string) ($mu['cd'] ?? '');
        if ($municipalityCode === '') { http_response_code(404); echo json_encode(['error' => 'Código do município não encontrado no TSE.']); exit; }
        try {
            $result = tse2026SectionsByCandidate($municipalityCode, $ibge, 0, 0, max(0, (int) ($_GET['offset'] ?? 0)), min(20, max(1, (int) ($_GET['limit'] ?? 12))), $local);
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
        } catch (Throwable $error) { http_response_code(502); echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_UNICODE); exit; }
    }
    if ($action === 'secoes-candidato') {
        $cityName = (string) ($_GET['cidade'] ?? ''); $candidateNumber = (int) ($_GET['numero'] ?? 0);
        $ibge = $cityCodes[$cityName] ?? '';
        if ($ibge === '' || $candidateNumber < 1) { http_response_code(400); echo json_encode(['error' => 'Município ou candidato inválido.']); exit; }
        $municipalityCode = '';
        $config = tse2026Json('https://resultados.tse.jus.br/oficial/ele2026/6259/config/mun-e006259-cm.json');
        foreach (($config['abr'] ?? []) as $state) if (($state['cd'] ?? '') === 'pe') foreach (($state['mu'] ?? []) as $mu) if ((string) ($mu['cdi'] ?? '') === $ibge) $municipalityCode = (string) ($mu['cd'] ?? '');
        if ($municipalityCode === '') { http_response_code(404); echo json_encode(['error' => 'Código do município não encontrado no TSE.']); exit; }
        try {
            $result = tse2026SectionsByCandidate($municipalityCode, $ibge, (int) $consultationOffices[$office][0], $candidateNumber, max(0, (int) ($_GET['offset'] ?? 0)), min(20, max(1, (int) ($_GET['limit'] ?? 12))));
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
        } catch (Throwable $error) { http_response_code(502); echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_UNICODE); exit; }
    }
    $rows = [];
    $municipalities = [];
    $config = tse2026Json('https://resultados.tse.jus.br/oficial/ele2026/6259/config/mun-e006259-cm.json');
    foreach (($config['abr'] ?? []) as $state) if (($state['cd'] ?? '') === 'pe') foreach (($state['mu'] ?? []) as $municipality) $municipalities[] = ['name' => (string) $municipality['nm'], 'code' => (string) $municipality['cdi']];
    foreach ($municipalities as $municipality) {
        $cityName = $municipality['name']; $ibgeCode = $municipality['code'];
        foreach (tse2026OfficeCandidatesForMunicipality($ibgeCode, $office) as $candidate) {
            $rows[] = ['city' => $cityName, 'id' => $candidate['id'] ?? '', 'name' => $candidate['name'], 'number' => $candidate['number'] ?? '', 'party' => $candidate['party'] ?? '', 'votes' => (int) $candidate['votes']];
        }
    }
    if ($action === 'candidatos') {
        $party = (string) ($_GET['partido'] ?? ''); $unique = [];
        foreach ($rows as $row) if ($row['party'] === $party) $unique[$row['id']] = ['id' => $row['id'], 'name' => $row['name'], 'number' => $row['number']];
        usort($unique, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        echo json_encode(['candidates' => array_values($unique)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
    }
    if ($action === 'buscar') {
        $party = (string) ($_GET['partido'] ?? ''); $candidateId = (string) ($_GET['candidato'] ?? ''); $cityVotes = []; $candidateName = '';
        foreach ($rows as $row) if ($row['party'] === $party && $row['id'] === $candidateId) { $cityVotes[$row['city']] = $row['votes']; $candidateName = $row['name']; }
        $cityVotes = array_filter($cityVotes, static fn(int $votes): bool => $votes > 0); arsort($cityVotes);
        echo json_encode(['candidate' => $candidateName, 'cities' => $cityVotes, 'total' => array_sum($cityVotes)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
    }
    if ($action === 'partidos') {
        $parties = array_values(array_unique(array_filter(array_column($rows, 'party')))); sort($parties, SORT_NATURAL | SORT_FLAG_CASE);
        echo json_encode(['parties' => $parties], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
    }
    http_response_code(400); echo json_encode(['error' => 'Consulta inválida.']); exit;
}
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
            // Não associe resultados de 2022 aos locais; os dados eleitorais exibidos
            // devem vir exclusivamente da apuração de 2026.
            $place['election'] = null;
            $place['apurated'] = false;
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
