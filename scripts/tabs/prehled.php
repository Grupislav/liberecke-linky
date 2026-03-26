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
$mapaSrc = htmlspecialchars($t['mapa'] ?? '', ENT_QUOTES, 'UTF-8');

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
          <div style='text-align:left'>{$zastavkyHtml}</div>
        </div>

        <div class='col-md-6 dvasloupce'>
          <br><span class='font22 zelena'>" . mb_strtoupper($lang['mapa'], 'UTF-8') . "</span><br>
          " . ($mapaSrc !== ''
                ? "<iframe style='border:none' src='{$mapaSrc}' width='500' height='333' loading='lazy' referrerpolicy='no-referrer-when-downgrade' title=\"" . htmlspecialchars(sprintf($lang['mapa_iframe_title'], $linka), ENT_QUOTES, 'UTF-8') . "\"></iframe>"
                : '<div class="sedaBunka">' . htmlspecialchars($lang['mapa_nedostupna'], ENT_QUOTES, 'UTF-8') . '</div>') . "
        </div>
      </div>";
