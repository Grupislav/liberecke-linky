<?php
// ============================================================================
// gtfs-proxy.php — streamuje GTFS feed DPMLJ z produkčního (českého) serveru.
// GitHub Actions runnery na www.dpmlj.cz nedosáhnou (server blokuje cizí/
// datacentrové IP → spojení reset/timeout), proto update-data.yml stahuje feed
// přes tenhle skript běžící na serveru, který na feed dosáhne.
// Volitelná ochrana tokenem: nastav $gtfsProxyToken v config.php a volej s ?key=…
// ============================================================================
if (is_file(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
if (!empty($gtfsProxyToken)) {
    if (!hash_equals((string)$gtfsProxyToken, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Forbidden');
    }
}

// Cache-buster v URL + no-cache hlavičky: bot přes tuhle proxy dostával zaseklou
// (stale) kopii feedu – cache v cestě z produkčního serveru na DPMLJ vracela starý
// gtfs.zip, i když DPMLJ už servíruje nový. Query param změní klíč cache, hlavičky
// vynutí revalidaci.
$SRC  = 'http://www.dpmlj.cz/gtfs.zip?_=' . time();
$data = false; $code = 0; $err = '';

if (function_exists('curl_init')) {                     // preferuj php-curl
    $ch = curl_init($SRC);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_FRESH_CONNECT  => true,
        CURLOPT_HTTPHEADER     => ['Cache-Control: no-cache', 'Pragma: no-cache'],
    ]);
    $data = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
} elseif (ini_get('allow_url_fopen')) {                  // fallback bez php-curl
    $ctx = stream_context_create(['http' => ['timeout' => 180, 'follow_location' => 1,
        'header' => "Cache-Control: no-cache\r\nPragma: no-cache\r\n"]]);
    $data = @file_get_contents($SRC, false, $ctx);
    $code = $data !== false ? 200 : 0;
    if ($data === false) $err = 'file_get_contents selhal';
} else {
    $err = 'php-curl není a allow_url_fopen je vypnuté';
}

// sanity: feed má ~2 MB; cokoli malého = chyba/HTML chybová stránka
if ($data === false || $code !== 200 || strlen($data) < 100000) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    exit("GTFS fetch failed: HTTP $code, délka " . (is_string($data) ? strlen($data) : '-') . " | $err");
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="gtfs.zip"');
header('Content-Length: ' . strlen($data));
echo $data;
