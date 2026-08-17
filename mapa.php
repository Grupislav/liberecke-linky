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
$__path = $__req !== ''
    ? preg_replace('/\?.*/', '', $__req)
    : ($__appBase === '' ? '/mapa' : $__appBase . '/mapa');
// Absolutní URL pro daný jazyk (cz = výchozí, bez ?ja). Pro canonical i hreflang.
$__seoUrl = static function (string $langCode) use ($__host, $__path): string {
    return 'https://' . $__host . $__path . ($langCode !== 'cz' ? '?ja=' . rawurlencode($langCode) : '');
};
$canonical = $__seoUrl($l);

// strings předané do JS (i18n)
$jsLang = [
    'search'      => $lang['mapa_hledat']        ?? 'Hledat linku nebo zastávku…',
    'searchLines' => $lang['mapa_hledat_linku']   ?? 'Hledat linku…',
    'searchStops' => $lang['mapa_hledat_zastavku']?? 'Hledat zastávku…',
    'lines'       => $lang['mapa_linky']          ?? 'Linky',
    'tram'        => $lang['mapa_tram']           ?? 'Tramvaje',
    'bus'         => $lang['mapa_bus']            ?? 'Autobusy',
    'legacy'      => $lang['mapa_filtr_mimo']      ?? 'Mimo provoz',
    'historic'    => $lang['mapa_filtr_historicke'] ?? 'Historické',
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
    'historicTitle' => $lang['mapa_hist_nadpis']   ?? 'Historická linka %s',
    'stateAkt'    => $lang['mapa_akt_mimo']        ?? 'aktuálně mimo provoz',
    'formerStop'  => $lang['mapa_zanikla']         ?? 'zaniklá zastávka',
    'dirLabel'    => $lang['mapa_smer']            ?? 'Směr %s',
    'vehicles'    => $lang['mapa_vozidla']         ?? 'Vozidla',
    'colorLines'  => $lang['mapa_barevne_linky']   ?? 'Barevné linky',
    'grpOper'     => $lang['provoznilinky']        ?? 'Provozované linky',
    'grpAkt'      => $lang['linky_akt_mimo']       ?? 'Aktuálně mimo provoz',
    'grpTrvale'   => $lang['linky_trvale_mimo']    ?? 'Trvale mimo provoz',
    'departures'  => $lang['mapa_odjezdy']         ?? 'Odjezdy',
    'noDepartures' => $lang['mapa_odjezdy_zadne']  ?? 'Odsud teď nic nejede.',
    'tripSchedule' => $lang['mapa_jr_spoje']       ?? 'Jízdní řád spoje',
];

// ── Historický snapshot sítě ────────────────────────────────────────────
// /mapa/<rok> → ?rok=YYYY (rewrite). Existuje-li mapa-assets/data/<rok>/, jede
// snapshot roku: data z data/<rok>/, bez JŘ (vozidla/odjezdy) a bez legacy linek;
// kategoriové barvy + legendu ale bere z DB stejně jako živá mapa.
$snapRok    = (isset($_GET['rok']) && preg_match('/^[0-9]{4}$/', (string)$_GET['rok'])) ? (string)$_GET['rok'] : '';
$isSnapshot = $snapRok !== '' && is_dir(__DIR__ . "/mapa-assets/data/$snapRok");
if ($snapRok !== '' && !$isSnapshot) { http_response_code(404); }
$dataSub = $isSnapshot ? "mapa-assets/data/$snapRok/" : "mapa-assets/data/";

// Popisek snapshotu: když meta.json udává konkrétní datum stavu sítě (např. složka
// 2022 = stav k 1. 1. 2022), zobraz datum; jinak jen rok. URL/složka je <rok>.
$snapLabel = $snapRok;
if ($isSnapshot) {
    $snapMeta = json_decode(@file_get_contents(__DIR__ . '/' . $dataSub . 'meta.json'), true) ?: [];
    if (!empty($snapMeta['date']) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $snapMeta['date'], $dm)) {
        $d = (int)$dm[3]; $mo = (int)$dm[2]; $y = $dm[1];
        if ($l === 'en') {
            $__mon = [1=>'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            $snapLabel = 'as of ' . $__mon[$mo] . ' ' . $d . ', ' . $y;
        } else {
            $snapLabel = 'k ' . $d . '. ' . $mo . '. ' . $y;
        }
    }
}

// Dostupné snapshoty pro rozbalovátko v menu: každá složka mapa-assets/data/<rok>/.
// Nový rok se objeví sám, jakmile se nasadí jeho data – nikde se neregistruje.
$snapRoky = [];
foreach ((array)@scandir(__DIR__ . '/mapa-assets/data') as $__d) {
    if (preg_match('/^[0-9]{4}$/', (string)$__d) && is_dir(__DIR__ . "/mapa-assets/data/$__d")) {
        $snapRoky[] = (string)$__d;
    }
}
rsort($snapRoky, SORT_STRING);          // nejnovější nahoře

// Odkazy v menu musí zahodit „rok" z aktuální URL – keep_params ho jinak přenese a
// „Živá mapa" i položky rozbalovátka by uvázly na roce, na kterém právě stojíme
// (rewrite přidává ?rok=<cíl> přes QSA, ale PHP by vzalo to poslední = původní rok).
$navHref = static function (string $path) use ($asset, $l): string {
    $p = $_GET; unset($p['rok']); $p['ja'] = $l;
    return $asset($path) . '?' . http_build_query($p);
};

// Kategoriové barvy/priorita/labely z DB (stejná paleta jako živá mapa) – platí
// i pro snapshot, aby měl stejné barvy a legendu. Klíčováno číslem linky, takže
// se použije na kteroukoli linku daného roku, co dnes existuje. Bez DB zůstanou
// pole prázdná → spadne se na barvy zapečené v routes.json.
$catColors = line_category_colors();
$catPrio   = line_category_priority();
$tileColors = $tilePriority = $tileCats = $tileStates = [];
$lineKods = fetch_line_kods_db($dbServer ?? null, $dbUzivatel ?? null, $dbHeslo ?? null, $dbDb ?? null);
$applyCat = static function (string $short, string $kod) use (&$tileColors, &$tilePriority, &$tileCats, $catColors, $catPrio, $lang) {
    if (isset($catColors[$kod])) $tileColors[$short] = $catColors[$kod];
    if (isset($catPrio[$kod]))   $tilePriority[$short] = $catPrio[$kod];
    $lbl = $lang['mapa_katsg_' . $kod] ?? '';
    if ($lbl !== '') $tileCats[$short] = $lbl;
};
foreach ($lineKods as $short => $kod) { $applyCat((string)$short, $kod); }
// Snapshot: kategoriové výjimky z meta.json (cat_override). Historicky provozní linka,
// která je dnes v DB „mimo provoz" (šedá), dostane svou dobovou kategorii – např.
// noční linka 90 v roce 2011. Přebíjí DB kategorii.
if ($isSnapshot && !empty($snapMeta['cat_override']) && is_array($snapMeta['cat_override'])) {
    foreach ($snapMeta['cat_override'] as $short => $kod) {
        $short = (string)$short;
        if (isset($catColors[$kod])) $tileColors[$short] = $catColors[$kod];
        if (isset($catPrio[$kod]))   $tilePriority[$short] = $catPrio[$kod];
        $lbl = $lang['mapa_katsg_' . $kod] ?? '';
        if ($lbl !== '') $tileCats[$short] = $lbl;
    }
}

// Linky mimo provoz (seznamy zastávek z DB) + jejich aliasy – jen živá mapa.
$legacyStops = $lineAliases = [];
if (!$isSnapshot) {
    $legacyStops = fetch_legacy_stop_lists_db($dbServer ?? null, $dbUzivatel ?? null, $dbHeslo ?? null, $dbDb ?? null);
    $lineAliases = line_map_aliases();
}

// Legenda kategorií = kategorie, jejichž barvu má aspoň jedna linka aktuálního
// pohledu (průnik DB ∩ routes.json živé mapy / snapshotu daného roku).
$legend = [];
$mapShorts = $mapTypes = [];
$routesJsonRaw = @file_get_contents(__DIR__ . '/' . $dataSub . 'routes.json');
if ($routesJsonRaw) {
    foreach (json_decode($routesJsonRaw, true) ?: [] as $rj) {
        if (isset($rj['short_name'])) {
            $mapShorts[(string)$rj['short_name']] = true;
            $mapTypes[(string)$rj['short_name']] = $rj['type'] ?? 'bus';
        }
    }
}
// Barvu, kategorii a stav řeší JEDNA sdílená funkce (line_display) společně s přehledem –
// ať mapa a přehled vždy odpovídají. Projdeme sjednocení všech známých linek (DB ∪ GTFS ∪
// archiv ∪ legacy): provozní dostanou barvu kategorie (sezónní tramvaj 4 s DB „mimoprovoz"
// → tramvajová), mimo provoz šedou dle typu (historické zůstávají červené). U snapshotu ne –
// tam kategorie řeší cat_override z meta.json.
if (!$isSnapshot) {
    $src = line_sources(__DIR__ . '/' . $dataSub);
    $universe = $lineKods;
    // legacy ZÁMĚRNĚ ne: barvu legacy linek (vč. červené u historických) řeší klientský
    // addLegacyRoutes dle legacy-routes.json; line_display bez DB kategorie je nezná.
    foreach (['live', 'arch'] as $g) {
        foreach ($src[$g] as $s => $_) { if (!isset($universe[$s])) $universe[$s] = ''; }
    }
    foreach ($universe as $short => $dbKod) {
        $short = (string)$short;
        $type = $src['type'][$short] ?? ((string)$dbKod === 'tramvaje' ? 'tram' : 'bus');
        $d = line_display($short, (string)$dbKod, $type, isset($src['live'][$short]), isset($src['arch'][$short]));
        $tileColors[$short] = $d['color'];
        $tileStates[$short] = $d['state'];
        if (isset($catPrio[$d['kod']])) $tilePriority[$short] = $catPrio[$d['kod']];
        $lbl = $lang['mapa_katsg_' . $d['kod']] ?? '';
        if ($lbl !== '') $tileCats[$short] = $lbl;
    }
}
// Výlukové linky: číslo začínající na „X" (X2, X3, …) → kategorie „vylukova"
// (vlastní barva/priorita). Řešeno automaticky z routes.json, ne z DB – výlukové
// linky se v GTFS objevují/mizí podle uzavírek.
foreach (array_keys($mapShorts) as $short) {
    $short = (string)$short;   // PHP normalizuje číselné klíče na int → přetypovat zpět
    if ($short !== '' && ($short[0] === 'X' || $short[0] === 'x')) {
        if (isset($catColors['vylukova'])) $tileColors[$short] = $catColors['vylukova'];
        if (isset($catPrio['vylukova']))   $tilePriority[$short] = $catPrio['vylukova'];
        $lbl = $lang['mapa_katsg_vylukova'] ?? '';
        if ($lbl !== '') $tileCats[$short] = $lbl;
    }
}
$presentHex = [];
foreach ($tileColors as $short => $hex) {
    if (isset($mapShorts[$short])) $presentHex[$hex] = true;
}
foreach ($catColors as $kod => $hex) {
    // mimo provoz i historické se v legendě neuvádí (kreslí se šedě dle typu, rozlišení
    // je jen v popisku/filtru, ne barvou)
    if ($kod === 'mimoprovoz' || $kod === 'historicke') continue;
    if (isset($presentHex[$hex])) {
        $legend[] = ['color' => $hex, 'label' => $lang['mapa_kat_' . $kod] ?? $kod];
    }
}
// deduplikace legendy
$seenLeg = [];
$legend = array_values(array_filter($legend, static function ($it) use (&$seenLeg) {
    $k = $it['color'] . '|' . $it['label'];
    if (isset($seenLeg[$k])) return false;
    $seenLeg[$k] = true;
    return true;
}));
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
  <?php
  $__title = $isSnapshot
      ? sprintf($lang['mapa_snap_titulek'] ?? 'Síť MHD Liberec %s | Liberecké linky', $snapLabel)
      : ($lang['mapa_titulek'] ?? 'Mapa linek MHD Liberec a Jablonec n. N. | Liberecké linky');
  ?>
  <title><?= $esc($__title) ?></title>
  <?php if ($isSnapshot): ?><meta name="robots" content="noindex"><?php endif; ?>
  <meta name="description" content="<?= $esc($lang['mapa_popis'] ?? 'Interaktivní mapa linek a zastávek MHD v Liberci a Jablonci nad Nisou nad otevřenými daty (GTFS).') ?>">
  <meta name="author" content="Tomáš Krupička (https://tomaskrupicka.cz)">
  <link rel="icon" href="<?= $esc($faviconHref) ?>" type="image/png">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="canonical" href="<?= $esc($canonical) ?>">
  <?php
  // hreflang alternativy – ať se jazykové verze indexují samostatně (cz = x-default).
  $__hreflangMap = ['cz' => 'cs', 'en' => 'en'];
  foreach ($jazyky as $__code => $__x) {
      $__hl = $__hreflangMap[$__code] ?? $__code;
      echo '  <link rel="alternate" hreflang="' . $esc($__hl) . '" href="' . $esc($__seoUrl($__code)) . "\">\n";
  }
  ?>
  <link rel="alternate" hreflang="x-default" href="<?= $esc($__seoUrl('cz')) ?>">
  <?php $__ogImg = 'https://' . $__host . ($__appBase === '' ? '' : $__appBase) . '/mapa-assets/og-mapa.jpg'; ?>
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Liberecké linky">
  <meta property="og:locale" content="<?= $l === 'en' ? 'en_US' : 'cs_CZ' ?>">
  <meta property="og:title" content="<?= $esc($lang['mapa_titulek'] ?? 'Živá mapa MHD Liberec') ?>">
  <meta property="og:description" content="<?= $esc($lang['mapa_popis'] ?? '') ?>">
  <meta property="og:url" content="<?= $esc($canonical) ?>">
  <meta property="og:image" content="<?= $esc($__ogImg) ?>">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta name="twitter:card" content="summary_large_image">
  <script type="application/ld+json"><?= json_encode([
    "@context" => "https://schema.org", "@type" => "WebApplication",
    "name" => $lang['mapa_titulek'] ?? 'Mapa linek MHD',
    "applicationCategory" => "MapApplication",
    "operatingSystem" => "Web",
    "url" => $canonical,
    "inLanguage" => $l === 'en' ? 'en' : 'cs',
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

  <link rel="stylesheet" href="<?= $esc($asset('css/css.css')) . av('css/css.css') ?>" type="text/css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
  <!-- Leaflet (mapová knihovna, BSD) + dlaždice OSM (atribuce v mapě) -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
  <link rel="stylesheet" href="<?= $esc($asset('mapa-assets/mapa.css')) . av('mapa-assets/mapa.css') ?>" type="text/css">
</head>
<body class="mapa-body">

<?php // ── HLAVIČKA + MENU (Linky / Mapa / jazyk) ───────────────────────── ?>
<div class="roztahovak-modry">
  <div class="hlavicka container">
    <div id="nadpis">
      <h1><a class="nadpis-home" href="<?= $esc($navHref('') . '#prehled') ?>"><?= $esc($lang['hlavninadpis']) ?></a></h1>
      <span class="nadpis-sep">|</span>
      <span class="nadpis-switch">
        <a href="<?= $esc($navHref('')) ?>"><?= $esc($lang['prehled_nav'] ?? $lang['prehled']) ?></a>
        <a<?= $isSnapshot ? '' : ' class="current"' ?> href="<?= $esc($navHref('mapa')) ?>"><?= $esc($lang['mapa_nav'] ?? 'Interaktivní mapa') ?></a>
        <?php if ($snapRoky): ?>
        <details class="nadpis-drop<?= $isSnapshot ? ' is-current' : '' ?>">
          <summary><?= $esc($isSnapshot ? $snapRok : ($lang['mapa_snap_nav'] ?? 'Historie')) ?></summary>
          <ul>
            <?php foreach ($snapRoky as $__r): ?>
            <li><a<?= $__r === $snapRok ? ' class="current"' : '' ?>
                  href="<?= $esc($navHref('mapa/' . $__r)) ?>"><?= $esc($__r) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </details>
        <?php endif; ?>
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
  <button type="button" id="ms-expand" class="ms-expand" aria-controls="mapa-sidebar" aria-expanded="true"
          aria-label="<?= $esc($lang['mapa_zobrazit_panel'] ?? 'Zobrazit panel') ?>">
    <span aria-hidden="true">&#9776;</span> <?= $esc($lang['mapa_panel'] ?? 'Panel') ?>
  </button>
  <?php if ($isSnapshot): ?>
  <div class="ms-snapbadge">
    <?= $esc(sprintf($lang['mapa_snap_badge'] ?? 'Historická síť %s', $snapLabel)) ?>
    · <a href="<?= $esc($asset('mapa') . ($l !== 'cz' ? '?ja=' . rawurlencode($l) : '')) ?>"><?= $esc($lang['mapa_snap_live'] ?? 'živá mapa') ?></a>
  </div>
  <?php endif; ?>
  <aside id="mapa-sidebar" aria-label="<?= $esc($lang['mapa_linky'] ?? 'Linky') ?>">
    <div class="ms-modes" role="tablist">
      <button type="button" data-mode="lines" class="ms-mode is-on"><?= $esc($lang['mapa_linky'] ?? 'Linky') ?></button>
      <button type="button" data-mode="stops" class="ms-mode"><?= $esc($lang['mapa_zastavky'] ?? 'Zastávky') ?></button>
      <button type="button" id="ms-collapse" class="ms-collapse" aria-controls="mapa-sidebar" aria-expanded="true"
              title="<?= $esc($lang['mapa_skryt_panel'] ?? 'Skrýt panel') ?>"
              aria-label="<?= $esc($lang['mapa_skryt_panel'] ?? 'Skrýt panel') ?>">&laquo;</button>
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
        <?php if (!$isSnapshot): // mimo provoz nemá v ročním snímku smysl ?>
        <button type="button" data-filter="legacy" class="ms-chip"><?= $esc($jsLang['legacy']) ?></button>
        <?php endif; ?>
      </div>
      <ul id="ms-routes" class="ms-routes"></ul>
      <ul id="ms-stops" class="ms-routes" hidden></ul>
    </div>

    <div class="ms-foot">
      <span id="ms-meta"></span>
      <span class="ms-src">Data: <a href="https://www.dpmlj.cz/opendata" target="_blank" rel="noopener">DPMLJ a.s.</a></span>
      <?php if (!$isSnapshot): ?><span class="ms-note"><?= $esc($lang['mapa_pozn_poloha'] ?? '') ?></span><?php endif; ?>
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
    dataDir: <?= json_encode(($__appBase === '' ? '' : $__appBase) . '/' . $dataSub, JSON_UNESCAPED_SLASHES) ?>,
    snapshot: <?= json_encode($isSnapshot) ?>,
    rok:  <?= json_encode($snapRok, JSON_UNESCAPED_SLASHES) ?>,
    <?php
    // Cache-buster: nejnovější čas ZE VŠECH datových souborů. Dřív se bral jen z meta.json –
    // jenže když se přegeneruje třeba jen shapes.json (a meta zůstane obsahově stejné), FTP
    // deploy meta nenahraje, čas se nezmění a prohlížeč servíruje starou geometrii z cache.
    $__v = 0;
    foreach (['meta.json', 'routes.json', 'stops.json', 'shapes.json', 'timetable.json',
              'legacy-routes.json', 'legacy-shapes.json', 'former-lines.json'] as $__f) {
        $__t = @filemtime(__DIR__ . '/' . $dataSub . $__f);
        if ($__t && $__t > $__v) $__v = $__t;
    }
    ?>
    v:    <?= json_encode($__v) ?>,
    ja:   <?= json_encode($l, JSON_UNESCAPED_SLASHES) ?>,
    tileColors: <?= json_encode($tileColors, JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT) ?>,
    tileCats: <?= json_encode($tileCats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT) ?>,
    tilePriority: <?= json_encode($tilePriority, JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT) ?>,
    tileStates: <?= json_encode($tileStates, JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT) ?>,
    legacyStops: <?= json_encode((object)$legacyStops, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    aliases: <?= json_encode($lineAliases, JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT) ?>,
    lang: <?= json_encode($jsLang, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
  };
</script>
<script src="<?= $esc($asset('mapa-assets/mapa.js')) . av('mapa-assets/mapa.js') ?>" defer></script>
</body>
</html>
