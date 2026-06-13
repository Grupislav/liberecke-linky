<?php
// ────────────────────────────────────────────────────────────────────────
// MAPA SÍTĚ MHD – statická Leaflet mapa nad GTFS daty (mapa-assets/data/*)
// ────────────────────────────────────────────────────────────────────────
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/scripts/variableCheck.php"; // nastaví $l, $lang, $jazyky
require_once __DIR__ . "/scripts/fce.php";

$__appBase  = isset($appBasePath) ? rtrim((string)$appBasePath, '/') : '';
$faviconHref = $__appBase === '' ? '/favicon.png' : $__appBase . '/favicon.png';
$asset = static function (string $p) use ($__appBase): string {
    return ($__appBase === '' ? '' : $__appBase) . '/' . ltrim($p, '/');
};

function keep_params(array $extra = []): string {
    $params = $_GET;
    foreach ($extra as $k => $v) $params[$k] = $v;
    return '?' . http_build_query($params);
}

$esc = static function ($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$__host = $_SERVER['HTTP_HOST'] ?? 'tomaskrupicka.cz';
$__req  = $_SERVER['REQUEST_URI'] ?? '';
$canonical = $__req !== ''
    ? 'https://' . $__host . preg_replace('/\?.*/', '', $__req)
    : 'https://' . $__host . ($__appBase === '' ? '/mapa' : $__appBase . '/mapa');

// strings předané do JS (i18n)
$jsLang = [
    'search'      => $lang['mapa_hledat']        ?? 'Hledat linku nebo zastávku…',
    'lines'       => $lang['mapa_linky']          ?? 'Linky',
    'tram'        => $lang['mapa_tram']           ?? 'Tramvaje',
    'bus'         => $lang['mapa_bus']            ?? 'Autobusy',
    'all'         => $lang['mapa_vse']            ?? 'Vše',
    'none'        => $lang['mapa_nic']            ?? 'Nic',
    'stop'        => $lang['mapa_zastavka']       ?? 'Zastávka',
    'zone'        => $lang['mapa_zona']           ?? 'Zóna',
    'wheelchair'  => $lang['mapa_bezbarierova']   ?? 'Bezbariérová',
    'linesHere'   => $lang['mapa_linky_zde']      ?? 'Linky v zastávce',
    'lineStops'   => $lang['mapa_zastavky_linky'] ?? 'Zastávky linky',
    'back'        => $lang['mapa_zpet']           ?? 'Zpět',
    'noData'      => $lang['mapa_nedostupna']     ?? 'Mapový podklad není k dispozici.',
    'showLine'    => $lang['mapa_zobraz_linku']   ?? 'Zobrazit jen tuto linku',
    'yes'         => $lang['mapa_ano']            ?? 'ano',
    'no'          => $lang['mapa_ne']             ?? 'ne',
    'unknown'     => $lang['mapa_neznamo']        ?? 'neznámo',
    'detailLink'  => $lang['mapa_detail_linky']   ?? 'Detail a historie linky',
];

// Barvy linek z DB (shodné s dlaždicemi na hlavním webu); stejný dotaz jako
// u dlaždic, jen sdílený přes fce.php. Při nedostupné DB zůstane [] a mapa
// spadne zpět na barvy generované z GTFS.
$tileColors = [];
if (isset($dbServer, $dbUzivatel, $dbHeslo, $dbDb)) {
    $conn = @mysqli_connect($dbServer, $dbUzivatel, $dbHeslo, $dbDb);
    if ($conn) {
        mysqli_set_charset($conn, 'utf8');
        $tileColors = fetch_line_tile_colors($conn);
        mysqli_close($conn);
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $esc($l) ?>">
<head>
  <?php if (!empty($googleAnalyticsMeasurementId ?? '')): $gaId = $esc($googleAnalyticsMeasurementId); ?>
  <script async src="https://www.googletagmanager.com/gtag/js?id=<?= $gaId ?>"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', '<?= $gaId ?>');
  </script>
  <?php endif; ?>

  <meta charset="UTF-8">
  <title><?= $esc($lang['mapa_titulek'] ?? 'Mapa linek MHD Liberec a Jablonec n. N. | Liberecké linky') ?></title>
  <meta name="description" content="<?= $esc($lang['mapa_popis'] ?? 'Interaktivní mapa linek a zastávek MHD v Liberci a Jablonci nad Nisou nad otevřenými daty (GTFS).') ?>">
  <meta name="author" content="Tomáš Krupička (https://tomaskrupicka.cz)">
  <link rel="icon" href="<?= $esc($faviconHref) ?>" type="image/png">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="canonical" href="<?= $esc($canonical) ?>">
  <script type="application/ld+json"><?= json_encode([
    "@context" => "https://schema.org", "@type" => "WebApplication",
    "name" => $lang['mapa_titulek'] ?? 'Mapa linek MHD',
    "applicationCategory" => "MapApplication",
    "operatingSystem" => "Web",
    "url" => $canonical,
    "inLanguage" => $l === 'en' ? 'en' : 'cs',
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

  <link rel="stylesheet" href="<?= $esc($asset('css/css.css')) ?>" type="text/css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
  <!-- Leaflet (mapová knihovna, BSD) + dlaždice OSM (atribuce v mapě) -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
  <link rel="stylesheet" href="<?= $esc($asset('mapa-assets/mapa.css')) ?>" type="text/css">
</head>
<body class="mapa-body">

<?php // ── HLAVIČKA + MENU (Linky / Mapa / jazyk) ───────────────────────── ?>
<div class="roztahovak-modry">
  <div class="hlavicka container">
    <div id="nadpis"><h1><?= $esc($lang['hlavninadpis']) ?></h1></div>
    <div id="menu">
      <nav>
        <ul>
          <li><a href="<?= $esc($asset('') . keep_params(['ja' => $l])) ?>"><?= $esc($lang['mapa_linky'] ?? 'Linky') ?></a></li>
          <li><a class="current" href="<?= $esc($asset('mapa') . keep_params(['ja' => $l])) ?>"><?= $esc($lang['mapa'] ?? 'Mapa') ?></a></li>
          <li class="lang-switch">
            <a href="#" aria-label="<?= $l === 'cz' ? 'Změnit jazyk' : 'Change language' ?>" hreflang="<?= $l === 'cz' ? 'cs' : $esc($l) ?>"><?= strtoupper($esc($l)) ?></a>
            <ul class="jazyk">
              <?php
              $hreflangMap = ['cz' => 'cs', 'en' => 'en'];
              foreach ($jazyky as $code => $label) {
                  if ($code === $l) continue;
                  $href = keep_params(['ja' => $code]);
                  $hreflang = $hreflangMap[$code] ?? $code;
                  echo '<li><a href="' . $esc($href) . '" hreflang="' . $esc($hreflang) . '">' . strtoupper($esc($code)) . '</a></li>';
              }
              ?>
            </ul>
          </li>
        </ul>
      </nav>
    </div>
  </div>
</div>

<?php // ── MAPA + BOČNÍ PANEL ────────────────────────────────────────────── ?>
<div class="mapa-layout">
  <aside id="mapa-sidebar" aria-label="<?= $esc($lang['mapa_linky'] ?? 'Linky') ?>">
    <div class="ms-search">
      <input type="search" id="ms-search-input" placeholder="<?= $esc($jsLang['search']) ?>" autocomplete="off">
    </div>

    <div id="ms-detail" hidden></div>

    <div id="ms-browse">
      <div class="ms-toolbar">
        <button type="button" data-filter="all" class="ms-chip is-on"><?= $esc($jsLang['all']) ?></button>
        <button type="button" data-filter="tram" class="ms-chip"><?= $esc($jsLang['tram']) ?></button>
        <button type="button" data-filter="bus" class="ms-chip"><?= $esc($jsLang['bus']) ?></button>
      </div>
      <ul id="ms-routes" class="ms-routes"></ul>
    </div>

    <div class="ms-foot">
      <span id="ms-meta"></span>
      <span class="ms-src">Data: <a href="https://www.dpmlj.cz/opendata" target="_blank" rel="noopener">DPMLJ a.s.</a></span>
    </div>
  </aside>

  <div id="mapa" role="application" aria-label="Mapa"></div>
</div>

<?php // ── PATIČKA ───────────────────────────────────────────────────────── ?>
<div class="roztahovak-modry">
  <div class="paticka container">
    <p><?= $lang['paticka'] ?></p>
  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
  window.MAPA = {
    base: <?= json_encode($__appBase, JSON_UNESCAPED_SLASHES) ?>,
    ja:   <?= json_encode($l, JSON_UNESCAPED_SLASHES) ?>,
    tileColors: <?= json_encode($tileColors, JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT) ?>,
    lang: <?= json_encode($jsLang, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
  };
</script>
<script src="<?= $esc($asset('mapa-assets/mapa.js')) ?>" defer></script>
</body>
</html>
