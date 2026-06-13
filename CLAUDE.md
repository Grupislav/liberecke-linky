# CLAUDE.md — Liberecké linky

Orientační poznámky pro práci v tomto repu. Detailní uživatelská dokumentace je v `README.md`.

## Co to je
Web **„Liberecké linky"** — přehled MHD linek v Liberci a Jablonci n. N.
- **Hlavní web** (`index.php`): PHP (mysqli, prepared statements), jQuery, server-rendered taby (přehled / historie / pohled řidiče / místopis / fotogalerie). Obsah z **MySQL** (tabulky `texty`, `typy_linek`). Dvojjazyčně **cz/en**.
- **Mapa sítě** (`mapa.php`): nově přidaná **statická** Leaflet mapa nad **GTFS** daty DPMLJ — **bez databáze**, jen předgenerované JSON.
- Živě: `https://tomaskrupicka.cz/blog/liberecke-linky/`, mapa na `…/mapa`.

## Mapa — kde co je
- `mapa.php` — stránka (přebírá hlavičku/patičku a `css/css.css`, Leaflet z CDN, i18n přes `$lang`). Čistá URL `/mapa` přes rewrite v `.htaccess`.
- `mapa-assets/mapa.js` — klientská logika. Na začátku je blok **`TILE`** = podkladová mapa (teď OSM; pro produkci přepnout na keyed providera, jinde se nic nemění). `var LIBEREC`, šířky/opacity čar.
- `mapa-assets/mapa.css` — layout (mapa + boční panel), responsivita.
- `mapa-assets/data/*.json` — **generovaná data, commitují se a deployují**: `stops.json` (224 stanic), `routes.json` (51 linek), `shapes.json` (geometrie tras, GeoJSON), `meta.json` (platnost feedu).
- `mapa-assets/data/legacy-routes.json` — **ručně udržovaný** (NE z build_data.py): linky trvale mimo provoz, které nemají GTFS data. Formát: `[{ "short_name":"50", "long_name":"…", "type":"bus|tram", "stops":["Název zastávky" | {"name":"…","lat":…,"lon":…}, …] }]`. `stops` slouží **jen k vykreslení trasy** (waypointy) — seznam zastávek v přehledu se bere z DB. Zastávka mimo GTFS jde zadat objektem se souřadnicemi. Na mapě se kreslí **čárkovaně** a defaultně skrytě (jen filtr „Mimo provoz" / hover / focus).
- `mapa-assets/data/legacy-shapes.json` — **generovaný** build_data.py: geometrie legacy tras sešitá po ulicích (nejkratší cesta v síti GTFS úseků mezi zastávkami z `legacy-routes.json`; rovná čára jen u zastávek mimo síť). Klient (`mapa.js`, `line-preview.js`) ho použije pro vykreslení; bez něj spadne na rovnou spojnici zastávek.
- `mapa-assets/line-preview.js` — klient pro hlavní web (záložka Přehled): náhled trasy (SVG nad `shapes.json`, celá síť + zvýrazněná linka) a dynamický seznam zastávek z dat (jen aktuální linky; legacy z DB). Respektuje aliasy i legacy linky.
- `tools/build_data.py` — generátor dat z GTFS (Python, bez závislostí). **Čte** `legacy-routes.json`, **generuje** `legacy-shapes.json`; `legacy-routes.json` sám nepřepisuje. Po editaci `legacy-routes.json` přegeneruj kvůli `legacy-shapes.json`.
- Aliasy/legacy/barvy: `line_map_aliases()` (mimo-provoz → dnešní linka, 161→16), `fetch_line_kods()` + `line_category_colors()` + `line_category_priority()` (barvy a pořadí vrstev linek dle kategorie z DB), `line_route_longname()` (název trasy z GTFS/legacy pro nadpis přehledu) — vše ve `scripts/fce.php`.

## Regenerace dat z GTFS (cca měsíčně)
Z kořene repa:
```bash
curl -sL -o gtfs.zip http://www.dpmlj.cz/gtfs.zip
unzip -o gtfs.zip -d gtfs
python tools/build_data.py     # přepíše mapa-assets/data/*.json
```
`gtfs/` a `gtfs.zip` jsou v `.gitignore` (do repa patří jen vygenerované JSON). Po regeneraci zkontroluj `git diff` a commitni JSON.

Datový model: stanice = GTFS `location_type=1` (nástupiště se agregují k rodiči). Barvy linek se generují (GTFS je nemá): tramvaje pevně, busy z HSL palety. Geometrie zjednodušena Douglas-Peuckerem (~4 m).

## Konvence a úskalí
- **Auto-deploy:** push do `main` spustí GitHub Action `deploy-ftp.yml` → nahraje na FTP produkce. **Necommitovat/nepushovat bez výslovného pokynu uživatele.**
- **Cesty k assetům:** přes `$appBasePath` z `config.php` (prázdné = kořen domény; **produkce `/liberecke-linky`** — musí odpovídat reálnému umístění, jinak se rozbijí assety mapy i odkazy). V JS je k dispozici `window.MAPA.base`.
- `config.php` a `jr/` jsou gitignored (nepatří do commitů).
- **Lokální běh:** `php -S localhost:8080` z kořene. Vestavěný server **neumí `.htaccess`** → mapu lokálně otevírej jako `/mapa.php` (čistá `/mapa` jede až na produkčním Apache).
- **Na tomto stroji není nainstalované PHP** (ani XAMPP) — `mapa.php` nelze lokálně lintnout/spustit; ověřuj ručně, finální test u uživatele.
- i18n: texty v `scripts/language/cz.php` + `en.php` (klíče `mapa_*`). Jazyk přes `?ja=cz|en`.

## Stav a další kroky
- **Hotovo:** MVP mapy (zastávky, trasy linek reálnou geometrií, filtr tram/bus, hledání, info panel zastávka↔linky). Ověřeno: JS syntax, validita JSON, křížové reference.
- **Otevřené možnosti (dle priorit uživatele):** přepnutí podkladu na keyed providera před produkcí; legenda/obarvení; zobrazit jen aktuálně provozované linky; volitelně jízdní řády z GTFS (`stop_times`); později vyhledávání spojení přes routing API (`pttsoftware.eu`, vyžaduje backend).
- **Licence dat:** stránka DPMLJ neuvádí explicitní licenci — před veřejným publikováním potvrdit u DPMLJ. V mapě je atribuce „Data: DPMLJ a.s." + „© OpenStreetMap".
