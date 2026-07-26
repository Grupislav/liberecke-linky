<?php
// jistota, že máme jazyk a helpery
if (!isset($lang) || !isset($l)) {
    require_once __DIR__ . "/../config.php";
    require_once __DIR__ . "/variableCheck.php";
}
require_once __DIR__ . "/fce.php";

/** Vykreslí jednu dlaždici linky. */
function renderTile(string $label, string $class, string $l): string {
    $href = url_with_params(['linka' => $label, 'ja' => $l]) . '#prehled';
    $href = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
    $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $classEsc = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<a href="{$href}">
  <div class="barvaramecku {$classEsc}">
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

// Stav linky se odvozuje z GTFS, ne jen z DB kategorie:
//  • aktuálně provozovaná  = je v aktuálním feedu (routes.json)
//  • aktuálně mimo provoz  = teď ve feedu není, ale běžně jezdí (má dobovou kategorii,
//                            nebo je v archivu former-lines.json = viděli jsme ji v GTFS)
//  • trvale mimo provoz    = zrušená (DB „mimoprovoz") a není v archivu
$dataDir = __DIR__ . '/../mapa-assets/data/';
$liveShorts = $archShorts = [];
foreach (json_decode((string)@file_get_contents($dataDir . 'routes.json'), true) ?: [] as $r) {
    if (isset($r['short_name'])) $liveShorts[(string)$r['short_name']] = true;
}
foreach ((array)json_decode((string)@file_get_contents($dataDir . 'former-lines.json'), true) as $s => $_) {
    $archShorts[(string)$s] = true;
}
$catOverride = ['41' => 'nakupni'];   // skutečná kategorie linek, co ji v DB nemají (viz mapa.php)
$forceTrvale = ['46' => true];        // linky natvrdo „trvale mimo provoz" (bez ohledu na DB kategorii)

$provozni = $aktMimo = $trvaleMimo = [];
foreach ($allRows as $row) {
    $linka = (string)$row['linka'];
    $kod = $catOverride[$linka] ?? (string)$row['class'];
    $isLive = isset($liveShorts[$linka]);
    $isArch = isset($archShorts[$linka]);
    if ($isLive) {
        $row['class'] = $kod;
        $provozni[] = $row;
    } elseif (isset($forceTrvale[$linka]) || ($kod === 'mimoprovoz' && !$isArch)) {
        $row['class'] = 'mimoprovoz';               // trvale → šedá dlaždice
        $trvaleMimo[] = $row;
    } elseif ($isArch) {
        $row['class'] = $kod;                        // akt. mimo provoz – máme uložený tvar
        $aktMimo[] = $row;
    } else {
        // sezónní linka bez uloženého tvaru (zatím nebyla v GTFS) – ponech jako dřív
        $row['class'] = $kod;
        $provozni[] = $row;
    }
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
            echo renderTile((string)$row['linka'], (string)$row['class'], $l);
        }
        echo '</div>';
    }
};

$section($lang['provoznilinky'], [$provozni]);
if ($aktMimo) {
    echo '<br>';
    $section($lang['linky_akt_mimo'] ?? 'Aktuálně mimo provoz', [$aktMimo]);
}
echo '<br>';
$section($lang['linky_trvale_mimo'] ?? $lang['neprovoznilinky'], [$trvaleLetters, $trvaleNumbers]);
