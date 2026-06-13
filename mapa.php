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
    'searchLines' => $lang['mapa_hledat_linku']   ?? 'Hledat linku…',
    'searchStops' => $lang['mapa_hledat_zastavku']?? 'Hledat zastávku…',
    'lines'       => $lang['mapa_linky']          ?? 'Linky',
    'tram'        => $lang['mapa_tram']           ?? 'Tramvaje',
    'bus'         => $lang['mapa_bus']            ?? 'Autobusy',
    'legacy'      => $lang['mapa_filtr_mimo']      ?? 'Mimo provoz',
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
    'legacyNote'  => $lang['mapa_mimo_provoz_pozn'] ?? 'Trasa je přibližná – linka je mimo provoz.',
    'legacyTitle' => $lang['mapa_mimo_nadpis']     ?? 'Linka %s (trvale mimo provoz)',
];

// Kategorie linek z DB (stejný dotaz jako dlaždice, sdílený přes fce.php).
// Z kódu kategorie odvodíme barvu (shodnou s dlaždicemi) i pořadí vrstev.
// Při nedostupné DB zůstanou pole prázdná → mapa spadne zpět na GTFS.
$catColors = line_category_colors();
$catPrio   = line_category_priority();
$tileColors = [];
$tilePriority = [];
$tileCats = [];   // linka -> název kategorie (singular) pro nadpis detailu na mapě
foreach (fetch_line_kods_db($dbServer ?? null, $dbUzivatel ?? null, $dbHeslo ?? null, $dbDb ?? null) as $short => $kod) {
    if (isset($catColors[$kod])) $tileColors[$short] = $catColors[$kod];
    if (isset($catPrio[$kod]))   $tilePriority[$short] = $catPrio[$kod];
    $lbl = $lang['mapa_katsg_' . $kod] ?? '';
    if ($lbl !== '') $tileCats[$short] = $lbl;
}

// seznam zastávek linek mimo provoz (z DB) – pro detail na mapě
$legacyStops = fetch_legacy_stop_lists_db($dbServer ?? null, $dbUzivatel ?? null, $dbHeslo ?? null, $dbDb ?? null);

// aliasy linek trvale mimo provoz -> existující trasa (161->16, 301->30, …)
$lineAliases = line_map_aliases();

// Legenda kategorií = kategorie, jejichž barvu má aspoň jedna linka reálně
// zobrazená na mapě (průnik DB ∩ routes.json). Bez dalšího dotazu.
$legend = [];
$mapShorts = [];
$routesJsonRaw = @file_get_contents(__DIR__ . '/mapa-assets/data/routes.json');
if ($routesJsonRaw) {
    foreach (json_decode($routesJsonRaw, true) ?: [] as $rj) {
        if (isset($rj['short_name'])) $mapShorts[(string)$rj['short_name']] = true;
    }
}
$presentHex = [];
foreach ($tileColors as $short => $hex) {
    if (isset($mapShorts[$short])) $presentHex[$hex] = true;
}
foreach ($catColors as $kod => $hex) {
    if ($kod === 'mimoprovoz') continue;
    if (isset($presentHex[$hex])) {
        $legend[] = ['color' => $hex, 'label' => $lang['mapa_kat_' . $kod] ?? $kod];
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
    <div id="nadpis">
      <h1><a class="nadpis-home" href="<?= $esc($asset('') . keep_params(['ja' => $l]) . '#prehled') ?>"><?= $esc($lang['hlavninadpis']) ?></a></h1>
      <span class="nadpis-sep">|</span>
      <span class="nadpis-switch">
        <a href="<?= $esc($asset('') . keep_params(['ja' => $l])) ?>"><?= $esc($lang['prehled']) ?></a>
        <a class="current" href="<?= $esc($asset('mapa') . keep_params(['ja' => $l])) ?>"><?= $esc($lang['mapa_nav'] ?? 'Interaktivní mapa') ?></a>
      </span>
    </div>
    <div id="menu">
      <nav>
        <ul>
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
    <div class="ms-modes" role="tablist">
      <button type="button" data-mode="lines" class="ms-mode is-on"><?= $esc($lang['mapa_linky'] ?? 'Linky') ?></button>
      <button type="button" data-mode="stops" class="ms-mode"><?= $esc($lang['mapa_zastavky'] ?? 'Zastávky') ?></button>
    </div>

    <div class="ms-search">
      <input type="search" id="ms-search-input" placeholder="<?= $esc($jsLang['searchLines']) ?>" autocomplete="off">
    </div>

    <div id="ms-detail" hidden></div>

    <div id="ms-browse">
      <div class="ms-toolbar">
        <button type="button" data-filter="all" class="ms-chip is-on"><?= $esc($jsLang['all']) ?></button>
        <button type="button" data-filter="tram" class="ms-chip"><?= $esc($jsLang['tram']) ?></button>
        <button type="button" data-filter="bus" class="ms-chip"><?= $esc($jsLang['bus']) ?></button>
        <button type="button" data-filter="legacy" class="ms-chip"><?= $esc($jsLang['legacy']) ?></button>
      </div>
      <ul id="ms-routes" class="ms-routes"></ul>
      <ul id="ms-stops" class="ms-routes" hidden></ul>
    </div>

    <div class="ms-foot">
      <span id="ms-meta"></span>
      <span class="ms-src">Data: <a href="https://www.dpmlj.cz/opendata" target="_blank" rel="noopener">DPMLJ a.s.</a></span>
    </div>
  </aside>

  <div id="mapa" role="application" aria-label="Mapa"></div>
</div>

<?php // ── LEGENDA KATEGORIÍ LINEK (pod mapou, před patičkou) ─────────────── ?>
<?php if (!empty($legend)): ?>
<div class="mapa-legenda" aria-label="<?= $esc($lang['mapa_legenda'] ?? 'Kategorie linek') ?>">
  <span class="ml-title"><?= $esc($lang['mapa_legenda'] ?? 'Kategorie linek') ?>:</span>
  <?php foreach ($legend as $item): ?>
  <span class="ml-item"><span class="ml-swatch" style="background:<?= $esc($item['color']) ?>"></span><?= $esc($item['label']) ?></span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php // ── PATIČKA ───────────────────────────────────────────────────────── ?>
<div class="roztahovak-modry paticka-wrap">
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
    tileCats: <?= json_encode($tileCats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT) ?>,
    tilePriority: <?= json_encode($tilePriority, JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT) ?>,
    legacyStops: <?= json_encode($legacyStops, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT) ?>,
    aliases: <?= json_encode($lineAliases, JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT) ?>,
    lang: <?= json_encode($jsLang, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
  };
</script>
<script src="<?= $esc($asset('mapa-assets/mapa.js')) ?>" defer></script>
</body>
</html>
