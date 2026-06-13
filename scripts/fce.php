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
 * Mapování linka (short_name) -> barva dlaždice z DB. Stejný dotaz, jaký
 * obarvuje dlaždice (vypislinek.php), jen vytažený do sdílené funkce.
 * Při nedostupné DB vrací [] – mapa pak spadne zpět na barvy z GTFS.
 */
function fetch_line_tile_colors($conn): array {
    $colors = line_category_colors();
    $out = [];
    $res = mysqli_query(
        $conn,
        "SELECT t.linka, tl.kod FROM texty t INNER JOIN typy_linek tl ON tl.id = t.typ_linky_id"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $kod = (string)($row['kod'] ?? '');
            if (isset($colors[$kod])) {
                $out[(string)$row['linka']] = $colors[$kod];
            }
        }
        mysqli_free_result($res);
    }
    return $out;
}
