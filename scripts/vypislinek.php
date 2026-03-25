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

/** Pøidá rozsah do pole bez duplicit. */
function addRange(array &$arr, int $from, int $to, string $class): void {
    for ($i = $from; $i <= $to; $i++) $arr[(string)$i] = $class;
}

/* ================== PROVOZNÍ LINKY (vzestupnì) ================== */
$operational = []; // label(string) => class

// historické
$operational['1'] = 'historicke';
$operational['4'] = 'historicke';

// tramvaje
$operational['2']  = 'tramvaje';
$operational['3']  = 'tramvaje';
$operational['5']  = 'tramvaje';
$operational['11'] = 'tramvaje';

// autobusy denní 12–30 (obsahuje i 38–40)
addRange($operational, 12, 30, 'autobusy');

// pracovní 31–35 a 37
addRange($operational, 31, 35, 'pracovni');
$operational['37'] = 'pracovni';

// školní 36
$operational['36'] = 'skolni';

//38-40
addRange($operational, 38, 40, 'autobusy');
//51–60
addRange($operational, 51, 60, 'skolni');

// noèní/ranní
addRange($operational, 91, 94, 'nocni');
addRange($operational, 97, 99, 'nocni');

// nákupní
$operational['500'] = 'nakupni';
$operational['600'] = 'nakupni';

// seøadit klíèe èíselnì
uksort($operational, fn($a, $b) => intval($a) <=> intval($b));

// výstup
echo "<div class='hlavninadpis'><span class='font22 zelena'>"
   . mb_strtoupper($lang['provoznilinky'], 'UTF-8')
   . "</span></div><div>";

foreach ($operational as $label => $class) {
    echo renderTile($label, $class, $l);
}
echo "</div>";

/* ================== MIMO PROVOZ ================== */
echo "<div class='hlavninadpis'><br><span class='font22 zelena'>"
   . mb_strtoupper($lang['neprovoznilinky'], 'UTF-8')
   . "</span></div>";

// písmena A–F (poøád jako zvláštní skupina)
$letters = range('A','F');
echo '<div>';
foreach ($letters as $L) echo renderTile($L, 'mimoprovoz', $l);
echo '</div>';

// neprovozovaná èísla (vzestupnì)
$nonOperationalNumbers = ['6','7','8','41','44','50','71','81','90','161','201','301'];
usort($nonOperationalNumbers, fn($a,$b) => intval($a) <=> intval($b));

echo '<div>';
foreach ($nonOperationalNumbers as $n) echo renderTile($n, 'mimoprovoz', $l);
echo '</div>';
