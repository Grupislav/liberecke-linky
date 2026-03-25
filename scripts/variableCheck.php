<?php
// Výchozí jazyk z config.php ($l); parametr ?ja= v URL ho může přepsat.
if (!isset($_GET['ja'])) { $_GET['ja'] = $l; }

$jazyky = [
  "cz" => "cz",
  "en" => "en",
];

// jazyk
if (isset($_GET['ja']) && isset($jazyky[$_GET['ja']])) {
  $l = $jazyky[$_GET['ja']];
} else {
  $_GET['ja'] = $l;
}

require_once __DIR__ . "/language/" . $l . ".php";