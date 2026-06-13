<?php
// INIT
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../variableCheck.php"; // kvůli $lang
require_once __DIR__ . "/../fce.php";

/* INTRO, když není linka zadaná */
if (!isset($_GET['linka']) || trim((string)$_GET['linka']) === '') {
    echo $lang['prehled_intro'];
    return;
}

// 1) Vstup: čísla (1–9999) nebo jedno písmeno A–Z
//    Máš A–F a čísla (včetně 500, 600). Když to nesedí, ukážeme hlášku.
$linkaRaw = isset($_GET['linka']) ? trim((string)$_GET['linka']) : '';

// dovolíme A–Z (1 znak) nebo čísla 1–4 číslice
if (!preg_match('/^(?:[A-Za-z]|[0-9]{1,4})$/', $linkaRaw)) {
    echo "<p>{$lang['zalozkanedostupna']}</p>";
    return;
}

// normalizace písmen na velká (A–Z)
$linka = ctype_alpha($linkaRaw) ? strtoupper($linkaRaw) : $linkaRaw;

// 2) DB připojení
$conn = mysqli_connect($dbServer, $dbUzivatel, $dbHeslo, $dbDb);
if (!$conn) {
    echo '<p>' . htmlspecialchars($lang['err_db'], ENT_QUOTES, 'UTF-8') . '</p>';
    return;
}
mysqli_set_charset($conn, "utf8");

// 3) Prepared statement
$sql = "SELECT trasa, zastavky, funkce, mapa FROM texty WHERE linka = ?";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    mysqli_close($conn);
    echo '<p>' . htmlspecialchars($lang['err_db_prepare'], ENT_QUOTES, 'UTF-8') . '</p>';
    return;
}
mysqli_stmt_bind_param($stmt, "s", $linka);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result || mysqli_num_rows($result) === 0) {
    echo "<p>{$lang['zalozkanedostupna']}</p>";
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    return;
}

$t = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);
mysqli_close($conn);

// 4) Výstup
$trasa = htmlspecialchars($t['trasa'] ?? '', ENT_QUOTES, 'UTF-8');

// Náhled trasy → proklik do interaktivní mapy sítě (/mapa?linka=X).
// Samotný obrázek dokresluje mapa-assets/line-preview.js z GTFS dat;
// bez JS zůstává funkční textový odkaz. Sloupec `mapa` v DB se už nepoužívá.
$appBase    = isset($appBasePath) ? rtrim((string)$appBasePath, '/') : '';
$mapaHref   = htmlspecialchars(($appBase === '' ? '' : $appBase) . '/mapa?linka=' . urlencode($linka) . '&ja=' . urlencode($l), ENT_QUOTES, 'UTF-8');
$linkaEsc   = htmlspecialchars($linka, ENT_QUOTES, 'UTF-8');
$nahledAlt  = htmlspecialchars(sprintf($lang['mapa_nahled_alt'] ?? 'Náhled trasy linky %s', $linka), ENT_QUOTES, 'UTF-8');
$zobrazTxt  = htmlspecialchars($lang['mapa_zobraz_v_siti'] ?? 'Zobrazit v mapě sítě', ENT_QUOTES, 'UTF-8');

// Pozn.: 'funkce' a 'zastavky' u tebe typicky obsahují formátovaný HTML obsah z DB,
// takže je necháme bez escaping (věříme vlastnímu obsahu). Kdybys chtěl sanitizovat,
// řekni a přidáme whitelist tagů.
$funkceHtml   = $t['funkce']   ?? '';
$zastavkyHtml = $t['zastavky'] ?? '';

echo "<span class='font25'>{$trasa}</span><br>
      <br><span class='font22 zelena'>" . mb_strtoupper($lang['funkce'], 'UTF-8') . "</span><br>

      <div style='text-align:left'>{$funkceHtml}</div>

      <div class='row'>    
        <div class='col-md-6 dvasloupce'>
          <br><span class='font22 zelena'>" . mb_strtoupper($lang['seznamzastavek'], 'UTF-8') . "</span><br>
          <div class='line-stops' data-linka='{$linkaEsc}' style='text-align:left'>{$zastavkyHtml}</div>
        </div>

        <div class='col-md-6 dvasloupce'>
          <br><span class='font22 zelena'>" . mb_strtoupper($lang['mapa'], 'UTF-8') . "</span><br>
          <a class='line-map-preview' href='{$mapaHref}' data-linka='{$linkaEsc}' aria-label='{$nahledAlt}'>
            <span class='lmp-label'>{$zobrazTxt} &rarr;</span>
          </a>
        </div>
      </div>";
