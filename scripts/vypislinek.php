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

$sqlProv = "SELECT t.linka, tl.kod AS class
    FROM texty t
    INNER JOIN typy_linek tl ON tl.id = t.typ_linky_id
    WHERE tl.kod <> 'mimoprovoz'";

$sqlMimo = "SELECT t.linka, tl.kod AS class
    FROM texty t
    INNER JOIN typy_linek tl ON tl.id = t.typ_linky_id
    WHERE tl.kod = 'mimoprovoz'";

$resProv = mysqli_query($conn, $sqlProv);
$resMimo = mysqli_query($conn, $sqlMimo);
if (!$resProv || !$resMimo) {
    mysqli_close($conn);
    echo '<p>' . htmlspecialchars($lang['err_db_prepare'], ENT_QUOTES, 'UTF-8') . '</p>';
    return;
}

$provozni = [];
while ($row = mysqli_fetch_assoc($resProv)) {
    $provozni[] = $row;
}
mysqli_free_result($resProv);

$mimo = [];
while ($row = mysqli_fetch_assoc($resMimo)) {
    $mimo[] = $row;
}
mysqli_free_result($resMimo);
mysqli_close($conn);

sortProvozniLinks($provozni);
[$mimoLetters, $mimoNumbers] = sortMimoProvozLinks($mimo);

echo "<div class='hlavninadpis'><span class='font22 zelena'>"
   . mb_strtoupper($lang['provoznilinky'], 'UTF-8')
   . "</span></div><div>";

foreach ($provozni as $row) {
    echo renderTile((string)$row['linka'], (string)$row['class'], $l);
}
echo "</div>";

echo "<div class='hlavninadpis'><br><span class='font22 zelena'>"
   . mb_strtoupper($lang['neprovoznilinky'], 'UTF-8')
   . "</span></div>";

echo '<div>';
foreach ($mimoLetters as $row) {
    echo renderTile((string)$row['linka'], (string)$row['class'], $l);
}
echo '</div>';

echo '<div>';
foreach ($mimoNumbers as $row) {
    echo renderTile((string)$row['linka'], (string)$row['class'], $l);
}
echo '</div>';
