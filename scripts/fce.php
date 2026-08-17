<?php

/**
 * Seznam zastávek provozní (GTFS) linky jako HTML <ul class="ls-list"> s odkazy
 * na mapu – aby se v přehledu vykreslil rovnou na serveru (bez probliknutí DB→GTFS).
 * Vrátí '' pro linku, která v GTFS není (legacy → použije se obsah z DB).
 */
function gtfs_stop_list_html(string $linka, string $appBase, string $ja, string $dirTpl = 'Směr %s'): string {
    static $routes = null, $stops = null;
    if ($routes === null) {
        $rr = @file_get_contents(__DIR__ . '/../mapa-assets/data/routes.json');
        $routes = $rr ? (json_decode($rr, true) ?: []) : [];
        $ss = @file_get_contents(__DIR__ . '/../mapa-assets/data/stops.json');
        $stops = [];
        foreach ($ss ? (json_decode($ss, true) ?: []) : [] as $st) {
            $stops[(string)$st['id']] = (string)$st['name'];
        }
    }
    $route = null;
    foreach ($routes as $rt) {
        if ((string)($rt['short_name'] ?? '') === $linka) { $route = $rt; break; }
    }
    if (!$route) return '';
    $base = $appBase === '' ? '' : $appBase;

    $stopsRef = $stops;
    $renderUl = function (array $ids) use ($stopsRef, $base, $ja): string {
        $items = '';
        foreach ($ids as $sid) {
            $name = $stopsRef[(string)$sid] ?? null;
            if ($name === null) continue;
            $href = $base . '/mapa?zastavka=' . rawurlencode($name) . '&ja=' . rawurlencode($ja);
            $items .= "<li><a class='ls-stop' href='" . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . "'>"
                    . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "</a></li>";
        }
        return $items === '' ? '' : "<ul class='ls-list'>{$items}</ul>";
    };

    // odlišné směry (jednosměrné zastávky / závleky) → přepínač směru + jeden seznam
    if (!empty($route['directions']) && count($route['directions']) >= 2) {
        $btns = ''; $blocks = ''; $i = 0;
        foreach ($route['directions'] as $d) {
            $ul = $renderUl($d['stops'] ?? []);
            if ($ul === '') continue;
            $head = htmlspecialchars((string)($d['headsign'] ?? ''), ENT_QUOTES, 'UTF-8');
            $btns .= "<button type='button' class='ls-dirbtn" . ($i === 0 ? ' is-on' : '')
                   . "' data-dir='{$i}'>&rarr; {$head}</button>";
            $blocks .= "<div class='ls-dir" . ($i === 0 ? '' : ' is-hidden') . "' data-dir='{$i}'>{$ul}</div>";
            $i++;
        }
        if ($i >= 2) return "<div class='ls-dirs'><div class='ls-dirswitch'>{$btns}</div>{$blocks}</div>";
        if ($blocks !== '') return $blocks;   // jen jeden směr s daty → bez přepínače
    }
    return $renderUl($route['stops'] ?? []);
}

/** Verze assetu pro cache-busting: '?v=<mtime souboru v repu>' (kvůli 7denní cache JS/CSS v .htaccess). */
function av(string $rel): string {
    $mt = @filemtime(__DIR__ . '/../' . ltrim($rel, '/'));
    return $mt ? '?v=' . $mt : '';
}

/** Vrátí URL aktuální stránky s přepsanými query parametry (ostatní zachová). */
function url_with_params(array $override): string {
    $uri   = $_SERVER['REQUEST_URI'] ?? '/';
    $parts = parse_url($uri);
    $path  = $parts['path'] ?? '/';
    parse_str($parts['query'] ?? '', $q);
    $q = array_merge($q, $override);
    foreach ($q as $k => $v) { if ($v === null || $v === '') unset($q[$k]); }
    $query = http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    return $path . ($query ? ('?' . $query) : '');
}

/**
 * Barva kategorie linky (typy_linek.kod) -> hex. Jediný zdroj pravdy pro
 * obarvení linek; MUSÍ odpovídat třídám .barvaramecku v css/css.css
 * (.autobusy, .tramvaje, …), aby dlaždice a mapa měly stejné barvy.
 */
function line_category_colors(): array {
    return [
        'vylukova'   => '#e8590c',   // výlukové (linky s číslem začínajícím na X)
        'tramvaje'   => '#cc2900',
        'autobusy'   => '#007db3',
        'nocni'      => '#000000',
        'pracovni'   => '#003399',
        'skolni'     => '#86592d',
        'nakupni'    => '#cc9900',
        'historicke' => '#991f00',
        'mimoprovoz' => '#b3b3cc',
    ];
}

/**
 * Linky trvale mimo provoz, které nemají v GTFS vlastní data, ale jejich
 * trasa zhruba odpovídá jiné (aktuální) lince. Klíč = neexistující linka,
 * hodnota = linka v GTFS, jejíž trasa/náhled se použije. Rozšiřuj dle potřeby.
 */
function line_map_aliases(): array {
    // 161/301 jsou nyní plnohodnotné legacy linky (legacy-routes.json), ne aliasy.
    // Sem patří jen linky, které chceš ukázat jako jinou (existující) linku 1:1.
    return [];
}

/**
 * Pořadí vykreslení linek na mapě podle kategorie (nižší = výš/navrchu).
 * Tramvaje navrchu, pak autobusy/pracovní, školní, komerční, dole noční a
 * historické/mimo provoz.
 */
function line_category_priority(): array {
    return [
        'vylukova'   => 0,   // výlukové úplně navrchu
        'tramvaje'   => 1,
        'autobusy'   => 2,
        'pracovni'   => 3,   // pracovní pod autobusy
        'skolni'     => 4,
        'nakupni'    => 5,
        'nocni'      => 6,
        'historicke' => 7,
        'mimoprovoz' => 7,
    ];
}

/**
 * Stav a barva linky – JEDNO místo pro mapu i přehled (aby seděly).
 * Vstup: číslo linky, kategorie z DB, typ (tram/bus) a příznaky z GTFS/archivu.
 * Výstup: ['state','kod','color'] kde
 *   state = 'operational' (je v aktuálním GTFS) | 'akt' (teď ne, ale sezónní – vrátí se)
 *           | 'trvale' (zrušená / DB „mimoprovoz" bez archivu, nebo natvrdo forceTrvale)
 *   kod   = efektivní kategorie pro barvu/popisek (provozní linka s DB „mimoprovoz/
 *           historicke" dostane kategorii dle typu – např. sezónní tramvaj 4)
 *   color = provozní → barva kategorie; mimo provoz → šedá s nádechem typu
 * catOverride: linky, jejichž skutečnou kategorii DB nemá (41 = komerční).
 * forceTrvale: linky natvrdo „trvale mimo provoz" (46 = úsek zrušené trasy).
 */
function line_overrides(): array {
    // 41 = komerční festivalová linka. 81 = starý název 41; zůstává trvale mimo provoz,
    // jen bere trasu z archivu (klon 41 bez Mařanovy). 46 = natvrdo trvale.
    return ['catOverride' => ['41' => 'nakupni'], 'forceTrvale' => ['46', '81']];
}

function line_display(string $short, string $dbKod, string $type, bool $isLive, bool $isArch): array {
    $ov  = line_overrides();
    $kod = $ov['catOverride'][$short] ?? $dbKod;
    // trvale = zrušená: DB „mimoprovoz" bez archivu, nebo natvrdo forceTrvale. Historické
    // a ostatní kategorie mimo aktuální GTFS jsou „akt" (sezónní, vrátí se – např. linka 1).
    if ($isLive) {
        $state = 'operational';
    } elseif (in_array($short, $ov['forceTrvale'], true) || ($kod === 'mimoprovoz' && !$isArch)) {
        $state = 'trvale';
    } else {
        $state = 'akt';
    }
    // provozní linka s nereálnou DB kategorií („mimoprovoz"/prázdná) → dle typu vozidla
    if ($state === 'operational' && ($kod === 'mimoprovoz' || $kod === '')) {
        $kod = $type === 'tram' ? 'tramvaje' : 'autobusy';
    }
    $cc = line_category_colors();
    if ($state === 'trvale') {
        $color = $type === 'tram' ? '#b09a9a' : '#9aa4b0';   // trvale mimo provoz → šedá dle vozidla
    } else {
        // provozní i akt. mimo provoz → barva kategorie (typu linky); akt se pozná popiskem
        $color = $cc[$kod] ?? ($type === 'tram' ? $cc['tramvaje'] : $cc['autobusy']);
    }
    return ['state' => $state, 'kod' => $kod, 'color' => $color];
}

/**
 * Přítomnost linek ve zdrojích (pro line_display): které jsou v GTFS (live), v archivu
 * (arch = viděné dřív v GTFS), v legacy, a jejich typ. Čte z mapa-assets/data/.
 */
function line_sources(string $dataDir): array {
    $live = $arch = $type = $legacy = [];
    foreach (json_decode((string)@file_get_contents($dataDir . 'routes.json'), true) ?: [] as $r) {
        if (!isset($r['short_name'])) continue;
        $s = (string)$r['short_name']; $live[$s] = true; $type[$s] = $r['type'] ?? 'bus';
    }
    foreach ((array)json_decode((string)@file_get_contents($dataDir . 'former-lines.json'), true) as $s => $fr) {
        $s = (string)$s; $arch[$s] = true;
        if (!isset($type[$s])) $type[$s] = (is_array($fr) ? ($fr['type'] ?? 'bus') : 'bus');
    }
    foreach (json_decode((string)@file_get_contents($dataDir . 'legacy-routes.json'), true) ?: [] as $r) {
        if (!isset($r['short_name'])) continue;
        $s = (string)$r['short_name']; $legacy[$s] = true;
        if (!isset($type[$s])) $type[$s] = $r['type'] ?? 'bus';
    }
    return ['live' => $live, 'arch' => $arch, 'type' => $type, 'legacy' => $legacy];
}

/**
 * Barvy linek (short → hex) přes line_display – provozní dle kategorie, mimo provoz šedě.
 * Jednotné pro velkou mapu i náhled v přehledu (jinak náhled bral jen DB kategorii bez
 * override, takže 41 vycházela šedě místo komerční). $lineKods = DB kategorie (fetch_line_kods).
 */
function line_display_map(string $dataDir, array $lineKods): array {
    $src = line_sources($dataDir);
    $universe = $lineKods;
    foreach (['live', 'arch'] as $g) {
        foreach ($src[$g] as $s => $_) { if (!isset($universe[$s])) $universe[$s] = ''; }
    }
    $out = [];
    foreach ($universe as $short => $dbKod) {
        $short = (string)$short;
        $type = $src['type'][$short] ?? ((string)$dbKod === 'tramvaje' ? 'tram' : 'bus');
        $d = line_display($short, (string)$dbKod, $type, isset($src['live'][$short]), isset($src['arch'][$short]));
        $out[$short] = ['color' => $d['color'], 'state' => $d['state']];
    }
    return $out;
}

/**
 * Mapování linka (short_name) -> kód kategorie z DB. Stejný dotaz, jaký
 * obarvuje dlaždice (vypislinek.php), jen vytažený do sdílené funkce.
 * Z kódu se pak odvodí barva (line_category_colors) i pořadí (line_category_priority).
 * Při nedostupné DB vrací [].
 */
function fetch_line_kods($conn): array {
    $out = [];
    $res = mysqli_query(
        $conn,
        "SELECT t.linka, tl.kod FROM texty t INNER JOIN typy_linek tl ON tl.id = t.typ_linky_id"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $out[(string)$row['linka']] = (string)($row['kod'] ?? '');
        }
        mysqli_free_result($res);
    }
    return $out;
}

/** Otevře spojení dle DB configu a vrátí linka->kód kategorie ([] při chybě). */
function fetch_line_kods_db($server, $user, $pass, $db): array {
    if ($server === null || $user === null || $db === null) return [];
    $conn = @mysqli_connect($server, $user, $pass, $db);
    if (!$conn) return [];
    mysqli_set_charset($conn, 'utf8');
    $kods = fetch_line_kods($conn);
    mysqli_close($conn);
    return $kods;
}

/**
 * Je daná linka kompletně přeložená do EN? (všechny _en sloupce vyplněné).
 * Slouží k rozhodnutí o noindex a hreflang u anglických stránek.
 * Graceful: při chybě/chybějících sloupcích (ještě neproběhla migrace) vrací false.
 */
function line_has_en_db($server, $user, $pass, $db, string $linka): bool {
    if ($server === null || $user === null || $db === null) return false;
    $conn = @mysqli_connect($server, $user, $pass, $db);
    if (!$conn) return false;
    mysqli_set_charset($conn, 'utf8');
    $ok = false;
    $sql = "SELECT 1 FROM texty WHERE linka = ?
              AND TRIM(COALESCE(funkce_en, ''))       <> ''
              AND TRIM(COALESCE(historie_en, ''))     <> ''
              AND TRIM(COALESCE(pohledridice_en, '')) <> ''
              AND TRIM(COALESCE(mistopis_en, ''))     <> '' LIMIT 1";
    if ($stmt = @mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "s", $linka);
        if (@mysqli_stmt_execute($stmt)) {
            mysqli_stmt_store_result($stmt);
            $ok = mysqli_stmt_num_rows($stmt) > 0;
        }
        mysqli_stmt_close($stmt);
    }
    mysqli_close($conn);
    return $ok;
}

/**
 * Seznam zastávek linek mimo provoz z DB (sloupec `zastavky`, HTML <li>).
 * Vrací linka -> [názvy zastávek]. Pro detail na mapě, kde DB není po ruce.
 */
function fetch_legacy_stop_lists_db($server, $user, $pass, $db): array {
    if ($server === null || $user === null || $db === null) return [];
    $conn = @mysqli_connect($server, $user, $pass, $db);
    if (!$conn) return [];
    mysqli_set_charset($conn, 'utf8');
    $out = [];
    $res = mysqli_query(
        $conn,
        "SELECT t.linka, t.zastavky FROM texty t
         INNER JOIN typy_linek tl ON tl.id = t.typ_linky_id
         WHERE tl.kod IN ('mimoprovoz', 'historicke')"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $names = [];
            if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', (string)($row['zastavky'] ?? ''), $mm)) {
                foreach ($mm[1] as $raw) {
                    $name = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if ($name !== '') $names[] = $name;
                }
            }
            if ($names) $out[(string)$row['linka']] = $names;
        }
        mysqli_free_result($res);
    }
    mysqli_close($conn);
    return $out;
}

/** linka->barva dlaždice (kategorie z DB → hex). [] při nedostupné DB. */
function fetch_line_tile_colors_db($server, $user, $pass, $db): array {
    $colors = line_category_colors();
    $out = [];
    foreach (fetch_line_kods_db($server, $user, $pass, $db) as $short => $kod) {
        if (isset($colors[$kod])) $out[$short] = $colors[$kod];
    }
    return $out;
}

/**
 * Vrátí dlouhý název trasy aktuální linky z GTFS (routes.json) – pro dynamický
 * nadpis "Kategorie číslo: trasa". Linky mimo provoz tu schválně nejsou:
 * pro ně se použije ručně udržovaný nadpis ze sloupce `trasa` v DB.
 */
function line_route_longname(string $linka): ?string {
    $raw = @file_get_contents(__DIR__ . '/../mapa-assets/data/routes.json');
    if (!$raw) return null;
    foreach (json_decode($raw, true) ?: [] as $r) {
        if (isset($r['short_name']) && (string)$r['short_name'] === $linka) {
            $ln = trim((string)($r['long_name'] ?? ''));
            if ($ln !== '') return $ln;
        }
    }
    return null;
}
