<?php
// INIT
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../variableCheck.php"; // kvůli $lang
require_once __DIR__ . "/../fce.php";

if (!isset($_GET['linka']) || trim((string)$_GET['linka']) === '') {
    echo $lang['mistopis_intro'];
    return;
}

// 1) Vstup: A–Z (1 znak) nebo čísla (1–4 číslice)
$linkaRaw = $_GET['linka'] ?? '';
$linkaRaw = trim((string)$linkaRaw);

if (!preg_match('/^(?:[A-Za-z]|[0-9]{1,4})$/', $linkaRaw)) {
    echo "<p>" . ($lang['zalozkanedostupna']) . "</p>";
    return;
}
$linka = ctype_alpha($linkaRaw) ? strtoupper($linkaRaw) : $linkaRaw;

// 2) DB
$conn = mysqli_connect($dbServer, $dbUzivatel, $dbHeslo, $dbDb);
if (!$conn) {
    echo "<p>Nejaký problém s DB.</p>";
    return;
}
mysqli_set_charset($conn, "utf8");

// 3) Prepared statement
$sql  = "SELECT mistopis FROM texty WHERE linka = ?";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    mysqli_close($conn);
    echo "<p>Dotaz nelze připravit.</p>";
    return;
}
mysqli_stmt_bind_param($stmt, "s", $linka);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result || mysqli_num_rows($result) === 0) {
    echo "<p>" . ($lang['mistopiscekana']) . "</p>";
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    return;
}

$row = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);
mysqli_close($conn);

// 4) Výstup
$mistopisHtml = $row['mistopis'] ?? '';

if ($mistopisHtml === '' || trim(strip_tags($mistopisHtml)) === '') {
    echo "<p>" . ($lang['mistopiscekana']) . "</p>";
} else {
    // Obsah z DB je HTML → necháme beze escapingu (předpokládáme vlastní obsah).
    echo "<div style='text-align:left'>{$mistopisHtml}</div>";
}
