<?php
// ────────────────────────────────────────────────────────────────────────
// INIT
// ────────────────────────────────────────────────────────────────────────
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/scripts/variableCheck.php"; // nastaví $l, $lang, $jazyky
require_once __DIR__ . "/scripts/fce.php";

$__appBase = isset($appBasePath) ? rtrim((string)$appBasePath, '/') : '';
$faviconHref = $__appBase === '' ? '/favicon.png' : $__appBase . '/favicon.png';

// malý helper na udržení parametrů v URL
function keep_params(array $extra = []): string {
    $params = $_GET;
    foreach ($extra as $k => $v) $params[$k] = $v;
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($l, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <?php if (!empty($googleAnalyticsMeasurementId ?? '')): $gaId = htmlspecialchars((string)$googleAnalyticsMeasurementId, ENT_QUOTES, 'UTF-8'); ?>
  <script async src="https://www.googletagmanager.com/gtag/js?id=<?= $gaId ?>"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', '<?= $gaId ?>');
  </script>
  <?php endif; ?>

  <meta charset="UTF-8">
  <title><?= htmlspecialchars($lang['titulekstranky'], ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="description" content="<?= htmlspecialchars($lang['popisstranky'], ENT_QUOTES, 'UTF-8') ?>">
  <meta name="author" content="Tomáš Krupička (https://tomaskrupicka.cz)">
  <link rel="icon" href="<?= htmlspecialchars($faviconHref, ENT_QUOTES, 'UTF-8') ?>" type="image/png">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <!-- Schema.org -->
  <script type="application/ld+json"><?php
    $host = $_SERVER['HTTP_HOST'] ?? 'tomaskrupicka.cz';
    $req  = $_SERVER['REQUEST_URI'] ?? '';
    if ($req !== '') {
      $canonical = 'https://' . $host . preg_replace('/\?.*/', '', $req);
    } else {
      $__b = isset($appBasePath) ? rtrim((string)$appBasePath, '/') : '';
      $canonical = 'https://' . $host . ($__b === '' ? '/' : $__b . '/');
    }
      $esc = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); };
  ?>{"@context":"https://schema.org","@type":"WebPage","name":"<?= $esc($lang['titulekstranky'] ?? 'Liberecké linky') ?>","description":"<?= $esc($lang['popisstranky'] ?? '') ?>","url":"<?= $esc($canonical) ?>","inLanguage":"<?= $l === 'en' ? 'en' : 'cs' ?>"}</script>
  <!-- CSS + ikony -->
  <link rel="stylesheet" href="css/css.css" type="text/css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">

  <!-- jQuery z CDN (před tvůj bundle) -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"
      integrity="sha256-eKhayi8LEQwp4NKxN+CfCh+3qOVUtJn3QNZ0TciWLP4="
      crossorigin="anonymous"></script>

  <!-- tvůj bundle (může zůstat, pokud ho potřebuješ) -->
  <script src="scripts/js/jquery.tools.ui.timer.colorbox.tmep.highcharts.js"></script>
    
  <style>
    /* drobná kosmetika pro jazykové menu vpravo */
    #menu nav ul { margin-right: 8px; }
    nav ul .lang-switch a { padding: 27px 16px; }
  </style>

  <script>
   var loadingImage = '<p><img src="./images/loading.gif" alt="Loading…"></p>';

   function loadTab(tab) {
    const el = document.getElementById(tab);
    if (!el || el.innerHTML.trim() !== '') return; // už načteno
    el.innerHTML = loadingImage;

    const url = "scripts/tabs/" + tab + ".php<?= keep_params(['ja' => $l]) ?>";

    if (window.jQuery && $.get) {
      $.get(url, function (data) { el.innerHTML = data; });
    } else {
      fetch(url, {credentials:'same-origin'})
        .then(r => r.text())
        .then(html => { el.innerHTML = html; })
        .catch(() => { el.innerHTML = '<p>Chyba při načítání záložky.</p>'; });
    }
   }
  </script>
</head>
<body>

<?php
// ────────────────────────────────────────────────────────────────────────
// HLAVIČKA S NADPISEM A PŘEPÍNAČEM JAZYKŮ
// ────────────────────────────────────────────────────────────────────────
?>
<div class="roztahovak-modry">
  <div class="hlavicka container">
    <div id="nadpis"><h1><?= $lang['hlavninadpis'] ?></h1></div>

    <div id="menu">
      <nav>
        <ul>
          <li class="lang-switch">
            <a href="#" aria-label="<?= $l === 'cz' ? 'Změnit jazyk' : 'Change language' ?>" hreflang="<?= $l === 'cz' ? 'cs' : $l ?>"><?= strtoupper($l) ?></a>
            <ul class="jazyk">
              <?php
              // nabídneme jen dostupné jazyky odlišné od aktuálního
              $hreflangMap = ['cz' => 'cs', 'en' => 'en', 'de' => 'de', 'fr' => 'fr'];
              foreach ($jazyky as $code => $label) {
                  if ($code === $l) continue;
                  $href = keep_params(['ja' => $code]);
                  $hreflang = $hreflangMap[$code] ?? $code;
                  echo "<li><a href=\"{$href}\" hreflang=\"{$hreflang}\">" . strtoupper($code) . "</a></li>";
              }
              ?>
            </ul>
          </li>
        </ul>
      </nav>
    </div>
  </div>
</div>

<?php
// ────────────────────────────────────────────────────────────────────────
// VRŠEK – DLAŽDICE S LINKAMI
// ────────────────────────────────────────────────────────────────────────
?>
<div class="roztahovak-vrsek">
  <div id="tri" class="row">
    <div class="container">
      <div class="col-md-12">
        <div class="celkove">
          <?php require_once __DIR__ . "/scripts/vypislinek.php"; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
// ────────────────────────────────────────────────────────────────────────
/** TABS – MENU + PANELY
 *  - Přehled se načte rovnou (server-side include)
 *  - Ostatní panely se načítají líně (AJAX) přes loadTab()
// ────────────────────────────────────────────────────────────────────────
*/
?>
<div id="hlavni" class="container">
  <div id="oblastzalozek">
    <ul class="tabs">
      <li><a href="#prehled"><?= $lang['prehled'] ?></a></li>
      <li><a href="#historie"><?= $lang['historie'] ?></a></li>
      <li><a href="#pohledridice"><?= $lang['pohledridice'] ?></a></li>
      <li><a href="#mistopis"><?= $lang['mistopis'] ?></a></li>
      <li><a href="#fotogalerie"><?= $lang['fotogalerie'] ?></a></li>
    </ul>

    <div class="panely">
      <div id="prehled">
        <?php require __DIR__ . "/scripts/tabs/prehled.php"; ?>
      </div>
      <div id="historie"></div>
      <div id="pohledridice"></div>
      <div id="mistopis"></div>
      <div id="fotogalerie"></div>
    </div>
  </div>
</div>

<?php
// ────────────────────────────────────────────────────────────────────────
// PATIČKA
// ────────────────────────────────────────────────────────────────────────
?>
<div class="roztahovak-modry">
  <div class="paticka container">
    <p><?= $lang['paticka'] ?></p>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────────── -->
<!-- JS: Přepínání tabs + lazy-load obsahu + udržení hashe na dlaždicích -->
<!-- ─────────────────────────────────────────────────────────────────── -->
<script>
(function () {
  const tabs = document.querySelectorAll('ul.tabs a');
  const panels = document.querySelectorAll('.panely > div');
  const lazyTabs = new Set(['historie','pohledridice','mistopis','fotogalerie']);

  function showTab(id) {
    // schovej vše
    panels.forEach(p => p.style.display = 'none');
    tabs.forEach(a => a.classList.remove('current'));

    const a = document.querySelector(`ul.tabs a[href="#${id}"]`);
    const panel = document.getElementById(id);
    if (!a || !panel) return;

    a.classList.add('current');
    panel.style.display = 'block';

    // líné načtení obsahu
    if (lazyTabs.has(id) && panel.innerHTML.trim() === '') {
      if (typeof loadTab === 'function') loadTab(id);
    }
  }

  tabs.forEach(a => {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      const id = this.getAttribute('href').slice(1);
      history.replaceState(null, '', '#' + id);
      showTab(id);
      // po přepnutí tabu hned aktualizujeme odkazy na linky, aby zachovaly aktuální hash
      updateLinkHashes();
    });
  });

  function initFromHash() {
    const id = (location.hash || '#prehled').slice(1);
    showTab(id);
  }

  // ── Udrž hash na dlaždicích (odkazy s ?linka=...) podle aktuálního tabu
  function updateLinkHashes() {
    const currentHash = window.location.hash || '#prehled';
    document.querySelectorAll('a[href*="?linka="]').forEach(function (a) {
      const href = a.getAttribute('href');
      if (!href) return;
      // odstraň starý fragment a nastav aktuální
      const hashIndex = href.indexOf('#');
      const base = hashIndex >= 0 ? href.substring(0, hashIndex) : href;
      a.setAttribute('href', base + currentHash);
    });
  }

  window.addEventListener('hashchange', function () {
    initFromHash();
    updateLinkHashes();
  });

  // inicializace po načtení
  initFromHash();
  updateLinkHashes();
})();
</script>

</body>
</html>
