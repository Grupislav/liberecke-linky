<?php

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
