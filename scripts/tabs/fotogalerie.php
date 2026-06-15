<?php
// INIT
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../variableCheck.php"; // kvůli $lang
require_once __DIR__ . "/../fce.php";

if (!isset($_GET['linka']) || trim((string)$_GET['linka']) === '') {
    echo $lang['fotogalerie_intro'];
    return;
}

// 1) Vstup: linka = písmena/číslice (+ znak ♿), max 6 znaků
$linkaRaw = $_GET['linka'] ?? '';
$linkaRaw = trim((string)$linkaRaw);

if (!preg_match('/^[\p{L}\p{N}\x{267F}]{1,6}$/u', $linkaRaw)) {
    echo "<p>" . ($lang['zalozkanedostupna']) . "</p>";
    return;
}
$linka = ctype_alpha($linkaRaw) ? strtoupper($linkaRaw) : $linkaRaw;

// 2) DB
$conn = mysqli_connect($dbServer, $dbUzivatel, $dbHeslo, $dbDb);
if (!$conn) {
    echo '<p>' . htmlspecialchars($lang['err_db'], ENT_QUOTES, 'UTF-8') . '</p>';
    return;
}
mysqli_set_charset($conn, "utf8");

// 3) Prepared statement
$col  = ($l === 'en') ? "COALESCE(NULLIF(fotogalerie_en, ''), fotogalerie)" : "fotogalerie";
$sql  = "SELECT $col AS fotogalerie FROM texty WHERE linka = ?";
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
    echo "<p>" . ($lang['fotogaleriecekana']) . "</p>";
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    return;
}

$row = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);
mysqli_close($conn);

// 4) Výstup
$galHtml = $row['fotogalerie'] ?? '';

// Lokalizace šablonových textů uložených natvrdo v DB (česky). Klíče jsou
// české zdrojové řetězce; v cz jsou hodnoty totožné (no-op), v en se přeloží.
$galHtml = strtr($galHtml, [
    'Pro zobrazení galerie klikněte zde' => $lang['fotogalerie_klik'],
    "Bohužel nejsou známy fotografie této linky. Pokud nějaké máte a můžete je sdílet <a href='mailto:info@tomaskrupicka.cz'>kontaktujte mě prosím</a>." => $lang['fotogalerie_nejsou'],
]);

if ($galHtml === '' || trim(strip_tags($galHtml)) === '') {
    echo "<p>" . ($lang['fotogaleriecekana']) . "</p>";
} else {
    // doplnit alt obrázkům bez alt atributu
    $altFallback = $lang['fotogalerie_alt'] . ' ' . htmlspecialchars($linka, ENT_QUOTES, 'UTF-8');
    $galHtml = preg_replace_callback('/<img(?=[^>]*)((?![^>]*\balt=)[^>]*)>/i', function ($m) use ($altFallback) {
        return '<img' . $m[1] . ' alt="' . $altFallback . '">';
    }, $galHtml);
    echo $galHtml;
}
