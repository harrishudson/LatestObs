<?php
date_default_timezone_set('Australia/Sydney');
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

$CACHE_DIR = __DIR__ . '/cache';
if (!file_exists($CACHE_DIR)) mkdir($CACHE_DIR, 0777, true);
$CACHE_TTL = 180; // 3 minutes
$FTP_BASE = 'ftp://ftp.bom.gov.au/anon/gen/fwo/';
$STATE_MAP = [
    'NSW' => 'IDN60910',
    'VIC' => 'IDV60910',
    'QLD' => 'IDQ60910',
    'WA'  => 'IDW60910',
    'SA'  => 'IDS60910',
    'TAS' => 'IDT60910',
    'NT'  => 'IDD60910'
];

// --- PARAMETERS ---
// State parameter: uppercase, valid values only
$stateParam = strtoupper($_GET['state'] ?? 'ALL');
if ($stateParam !== 'ALL' && $stateParam !== 'ACT' && !isset($STATE_MAP[$stateParam]) && !in_array($stateParam, $STATE_MAP)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid state parameter']);
    exit;
}

// WMO ID parameter: only digits allowed
$wmoFilter = isset($_GET['wmo_id']) ? preg_replace('/[^0-9]/', '', $_GET['wmo_id']) : null;

// Determine which states to fetch
$statesToFetch = [];
if ($stateParam === 'ALL') {
    $statesToFetch = array_keys($STATE_MAP);
} elseif ($stateParam === 'ACT') {
    $statesToFetch = ['NSW'];
} elseif (isset($STATE_MAP[$stateParam])) {
    $statesToFetch = [$stateParam];
} elseif (in_array($stateParam, $STATE_MAP)) {
    $statesToFetch = [array_search($stateParam, $STATE_MAP)];
}

// --- HELPER FUNCTIONS ---
function extractJsonFromTgz($tgzFile, $stateAbbrev, $lastFetched) {
    $data = [];

    // Use a unique temp file for the .tar to avoid concurrency issues
    $tempTar = tempnam(sys_get_temp_dir(), 'bom_') . '.tar';

    try {
        $phar = new PharData($tgzFile);
        $phar->decompress(); // creates .tar in same dir
        $originalTar = str_replace('.tgz', '.tar', $tgzFile);
        if (!file_exists($originalTar)) return $data;
        rename($originalTar, $tempTar);

        $tar = new PharData($tempTar);
        $iterator = new RecursiveIteratorIterator($tar);

        foreach ($iterator as $file) {
            $name = $file->getFilename();
            if (substr($name, -5) === '.json') {
                $content = file_get_contents($file->getPathname());
                $json = json_decode($content, true);
                if (!$json || empty($json['observations']['data'])) continue;

                $header = $json['observations']['header'][0] ?? [];
                foreach ($json['observations']['data'] as $obs) {
                    if (($obs['sort_order'] ?? 1) == 0) {
                        $data[] = [
                            'state_abbrev' => $stateAbbrev,
                            'state' => $header['state'] ?? '',
                            'copyright' => $json['observations']['notice'][0]['copyright'] ?? '',
                            'id' => $header['ID'] ?? '',
                            'name' => $header['name'] ?? '',
                            'wmo_id' => $header['wmo_id'] ?? '',
                            'aifstime_utc' => $obs['aifstime_utc'] ?? '',
                            'aifstime_local' => $obs['aifstime_local'] ?? '',
                            'lat' => (float)($obs['lat'] ?? 0),
                            'lon' => (float)($obs['lon'] ?? 0),
                            'gust_kmh' => $obs['gust_kmh'] ?? null,
                            'wind_kmh' => $obs['wind_spd_kmh'] ?? null,
                            'air_temp' => $obs['air_temp'] ?? null,
                            'apparent_t' => $obs['apparent_t'] ?? null,
                            'rain_since_9am' => $obs['rain_trace'] ?? null,
                            'last_fetched' => date('c', $lastFetched)
                        ];
                        break;
                    }
                }
            }
        }
    } catch (Exception $e) {
        // ignore failures
    }

    if (file_exists($tempTar)) @unlink($tempTar);
    return $data;
}

function getRemoteFileMtime($ftpUrl) {
    $parts = parse_url($ftpUrl);
    $conn = ftp_connect($parts['host'], 21, 10);
    if (!$conn) return false;
    if (!ftp_login($conn, 'anonymous', '')) { ftp_close($conn); return false; }
    $mtime = ftp_mdtm($conn, ltrim($parts['path'], '/'));
    ftp_close($conn);
    return ($mtime !== -1) ? $mtime : false;
}

function downloadWithCache($ftpUrl, $localTgz, $lockFile, $cacheTtl) {
    $now = time();
    $localExists = file_exists($localTgz);
    $localAge = $localExists ? $now - filemtime($localTgz) : PHP_INT_MAX;
    $indexFile = $localTgz . '.index';

    if ($localAge < $cacheTtl) return $localTgz;

    $fpLock = fopen($lockFile, 'c');
    if (!$fpLock) return false;
    if (!flock($fpLock, LOCK_EX)) return false;

    // Double-check inside lock
    $localExists = file_exists($localTgz);
    $localAge = $localExists ? $now - filemtime($localTgz) : PHP_INT_MAX;
    if ($localAge < $cacheTtl) { flock($fpLock, LOCK_UN); fclose($fpLock); return $localTgz; }

    $remoteMtime = getRemoteFileMtime($ftpUrl);
    $lastSeen = file_exists($indexFile) ? (int)file_get_contents($indexFile) : 0;

    if ($remoteMtime !== false && $remoteMtime > $lastSeen) {
        $data = @file_get_contents($ftpUrl);
        if ($data !== false) file_put_contents($localTgz, $data);
        file_put_contents($indexFile, $remoteMtime);
    } elseif ($remoteMtime === false && $localExists) {
        touch($localTgz); // reset TTL even if FTP fails
    } else {
        if ($localExists) touch($localTgz); // reset TTL without downloading
    }

    flock($fpLock, LOCK_UN);
    fclose($fpLock);

    return file_exists($localTgz) ? $localTgz : false;
}

// --- MAIN ---
$allData = [];
foreach ($statesToFetch as $state) {
    $productId = $STATE_MAP[$state];
    $ftpUrl = $FTP_BASE . $productId . '.tgz';
    $localTgz = "$CACHE_DIR/{$productId}.tgz";
    $lockFile = "$CACHE_DIR/{$productId}.lock";

    $tgzFile = downloadWithCache($ftpUrl, $localTgz, $lockFile, $CACHE_TTL);
    if (!$tgzFile) continue;

    $lastFetched = filemtime($tgzFile);
    $stateData = extractJsonFromTgz($tgzFile, $state, $lastFetched);
    $allData = array_merge($allData, $stateData);
}

if ($wmoFilter) {
    $allData = array_values(array_filter($allData, fn($d) => $d['wmo_id'] == $wmoFilter));
}

$geojson = [
    'type' => 'FeatureCollection',
    'features' => array_map(fn($obs) => [
        'type' => 'Feature',
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [$obs['lon'], $obs['lat']]
        ],
        'properties' => $obs
    ], $allData)
];

echo json_encode($geojson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>

