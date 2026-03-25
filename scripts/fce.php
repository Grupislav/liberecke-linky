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
 * formatData() vrací datum a čas
 * @param $datum
 * @return string
 */

function formatData($datum)
{

    if(substr($datum, 8, 1) == 0)
    {
        $den = substr($datum, 9, 1);
    }
    else
    {
        $den = substr($datum, 8, 2);
    }
    if(substr($datum, 5, 1) == 0)
    {
        $mesic = substr($datum, 6, 1);
    }
    else
    {
        $mesic = substr($datum, 5, 2);
    }

    return $den . "." . $mesic . "." . substr($datum, 0, 4) . " " . substr($datum, 11, 2) . ":" . substr($datum, 14, 2);
}

/**
 * jeVikend() - podle date urci typ dne
 * @param date $datum
 * @return int
 */

function jeVikend($datum)
{
    $denVTydnu = date("N", mktime(0, 0, 0, substr($datum, 5, 2), substr($datum, 8, 2), substr($datum, 0, 4)));
    if($denVTydnu == 6 OR $denVTydnu == 7)
    {
        return 1;
    }
    else
    {
        return 0;
    }
}

/**
 * @param $jazyky
 * @param $vybranyJazyk
 * @return string
 */

function menuJazyky($jazyky, $vybranyJazyk)
{
    $menu = "<li><a href='#'>" . strtoupper($vybranyJazyk) . "</a>";
    $menu .= "<ul class='jazyk'>";

    foreach($jazyky as $jazyk)
    {

        if($jazyk != $vybranyJazyk)
        {
            $menu .= "<li><a href='https://tomaskrupicka.cz/meteostanice-liberec-pilinkov/?ja={$jazyk}&amp;je={$_GET['je']}'>" . strtoupper($jazyk) . "</a></li>";
        }

    }

    $menu .= "</ul></li>";

    return $menu;
}

/**
 * @param $jednotky
 * @param $vybranaJednotka
 * @return string
 */

function menuJednotky($jednotky, $vybranaJednotka)
{
    $menu = "<li><a href='#' title='{$jednotky[$vybranaJednotka]}'>{$jednotky[$vybranaJednotka]}</a>";
    $menu .= "<ul class='teplota'>";

    foreach($jednotky as $index => $jednotka)
    {

        if($index != $vybranaJednotka)
        {
            $menu .= "<li><a href='https://tomaskrupicka.cz/meteostanice-liberec-pilinkov/?je={$index}&amp;ja={$_GET['ja']}' title='{$jednotka}'>{$jednotka}</a></li>";
        }

    }

    $menu .= "</ul></li>";

    return $menu;
}

function curl_get_file_contents($URL)
{
        $c = curl_init();
        curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($c, CURLOPT_URL, $URL);
        $contents = curl_exec($c);
        curl_close($c);

        if ($contents) return $contents;
            else return FALSE;
 }
 
function get_geolocation($url) 
{
        $cURL = curl_init();

        curl_setopt($cURL, CURLOPT_URL, $url);
        curl_setopt($cURL, CURLOPT_HTTPGET, true);
        curl_setopt($cURL, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($cURL, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Accept: application/json'
        ));
        return curl_exec($cURL);
}