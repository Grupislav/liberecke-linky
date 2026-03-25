<?php

// Zkopíruj jako config.php a doplň hodnoty. Soubor config.php se do Gitu necommituje.

// ── DATABASE ────────────────────────────────────────────────────────────
$dbServer   = 'db.example.com';
$dbUzivatel = 'db_user';
$dbHeslo    = 'db_password';
$dbDb       = 'db_name';

/**
 * Veřejná cesta k aplikaci (bez koncového lomítka).
 * Prázdné = kořen domény. Pro https://tomaskrupicka.cz/blog/liberecke-linky/ např.:
 */
$appBasePath = '/blog/liberecke-linky';

// Výchozí jazyk: cz | en
$l = 'cz';

/**
 * GA4 Measurement ID (např. G-XXXXXXXX). Prázdný řetězec = Google Analytics se nevloží.
 */
$googleAnalyticsMeasurementId = '';
