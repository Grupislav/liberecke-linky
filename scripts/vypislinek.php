<?php
// jistota, že máme jazyk a helpery
if (!isset($lang) || !isset($l)) {
    require_once __DIR__ . "/../config.php";
    require_once __DIR__ . "/variableCheck.php";
}
require_once __DIR__ . "/fce.php";

/** Vykreslí jednu dlaždici linky. Barva se předává jako hex (sdílená s mapou,
 * viz line_display), ne přes CSS třídu – ať přehled a mapa vždy odpovídají. */
function renderTile(string $label, string $color, string $l): string {
    $href = url_with_params(['linka' => $label, 'ja' => $l]) . '#prehled';
    $href = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
    // Zobrazený text: „♿BUS" přetéká dlaždici → jen piktogram (odkaz drží plný název).
    $display = $label === '♿BUS' ? '♿' : $label;
    $labelEsc = htmlspecialchars($display, ENT_QUOTES, 'UTF-8');
    $colorEsc = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<a href="{$href}">
  <div class="barvaramecku" style="background-color:{$colorEsc}">
    <span class="textlinek">{$labelEsc}</span>
  </div>
</a>
HTML;
}

/** Seřadí provozní linky číselně (stejně jako dřív uksort intval). */
function sortProvozniLinks(array &$rows): void {
    usort($rows, static function (array $a, array $b): int {
        return intval($a['linka']) <=> intval($b['linka']);
    });
}

/** Rozdělí mimo provoz na písmena a čísla a seřadí (A–F, pak čísla vzestupně). */
function sortMimoProvozLinks(array $rows): array {
    $letters = [];
    $numbers = [];
    foreach ($rows as $row) {
        $label = (string)($row['linka'] ?? '');
        if ($label !== '' && ctype_alpha($label)) {
            $letters[] = $row;
        } else {
            $numbers[] = $row;
        }
    }
    usort($letters, static function (array $a, array $b): int {
        return strcmp($a['linka'], $b['linka']);
    });
    usort($numbers, static function (array $a, array $b): int {
        return intval($a['linka']) <=> intval($b['linka']);
    });
    return [$letters, $numbers];
}

if (!isset($dbServer, $dbUzivatel, $dbHeslo, $dbDb)) {
    echo '<p>' . htmlspecialchars($lang['err_db'], ENT_QUOTES, 'UTF-8') . '</p>';
    return;
}

$conn = mysqli_connect($dbServer, $dbUzivatel, $dbHeslo, $dbDb);
if (!$conn) {
    echo '<p>' . htmlspecialchars($lang['err_db'], ENT_QUOTES, 'UTF-8') . '</p>';
    return;
}
mysqli_set_charset($conn, 'utf8');

$sqlAll = "SELECT t.linka, tl.kod AS class
    FROM texty t
    INNER JOIN typy_linek tl ON tl.id = t.typ_linky_id";

$resAll = mysqli_query($conn, $sqlAll);
if (!$resAll) {
    mysqli_close($conn);
    echo '<p>' . htmlspecialchars($lang['err_db_prepare'], ENT_QUOTES, 'UTF-8') . '</p>';
    return;
}
$allRows = [];
while ($row = mysqli_fetch_assoc($resAll)) {
    $allRows[] = $row;
}
mysqli_free_result($resAll);
mysqli_close($conn);

// Stav i barva linky z JEDNÉ sdílené funkce (line_display) – ať přehled a mapa vždy
// odpovídají. Provozní = v routes.json; akt. mimo provoz = teď ve feedu není, ale je to
// běžná/sezónní linka; trvale = zrušená (DB „mimoprovoz" bez archivu) nebo natvrdo.
$src = line_sources(__DIR__ . '/../mapa-assets/data/');

$provozni = $aktMimo = $trvaleMimo = [];
foreach ($allRows as $row) {
    $linka = (string)$row['linka'];
    $type  = $src['type'][$linka] ?? ((string)$row['class'] === 'tramvaje' ? 'tram' : 'bus');
    $d = line_display($linka, (string)$row['class'], $type, isset($src['live'][$linka]), isset($src['arch'][$linka]));
    $row['color'] = $d['tilecolor'];   // dlaždice: trvale mimo provoz šedě, jinak dle kategorie
    if ($d['state'] === 'operational')   $provozni[]   = $row;
    elseif ($d['state'] === 'trvale')    $trvaleMimo[] = $row;
    else                                 $aktMimo[]    = $row;
}

sortProvozniLinks($provozni);
sortProvozniLinks($aktMimo);
[$trvaleLetters, $trvaleNumbers] = sortMimoProvozLinks($trvaleMimo);

$section = static function (string $nadpis, array $groups) use ($l): void {
    echo "<div class='hlavninadpis'><span class='font22 zelena'>"
       . mb_strtoupper($nadpis, 'UTF-8') . "</span></div>";
    foreach ($groups as $rows) {
        if (!$rows) continue;
        echo '<div>';
        foreach ($rows as $row) {
            echo renderTile((string)$row['linka'], (string)$row['color'], $l);
        }
        echo '</div>';
    }
};

$section($lang['provoznilinky'], [$provozni]);
if ($aktMimo) {
    echo '<br>';
    $section($lang['linky_akt_mimo'] ?? 'Aktuálně mimo provoz', [$aktMimo]);
}
if ($trvaleLetters || $trvaleNumbers) {
    echo '<br>';
    $section($lang['linky_trvale_mimo'] ?? $lang['neprovoznilinky'], [$trvaleLetters, $trvaleNumbers]);
}
