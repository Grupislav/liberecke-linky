# Liberecké linky

Přehled **autobusových linek v Liberci a okolí**: dlaždice s linkami, záložky s přehledem jízdního řádu, historií, pohledem řidiče, místopisem a fotogalerií. Data jsou v **MySQL**; rozhraní je vícejazyčné (čeština / angličtina).

**Živá verze:** [tomaskrupicka.cz/blog/liberecke-linky/](https://tomaskrupicka.cz/blog/liberecke-linky/)

## Funkce

- výběr linky z přehledu dlaždic (řádky z tabulky `texty`, typ barvy z `typy_linek` přes `typ_linky_id`)  
- záložky: přehled, historie, pohled řidiče, místopis, fotogalerie — obsah se vkládá **na serveru** do stránky (vhodné pro SEO); `loadTab()` zůstává jen jako záloha  
- přepínání jazyka přes parametr URL (`ja`)  
- volitelně **Google Analytics 4** (ID v `config.php`)  
- strukturovaná data (Schema.org) v šabloně stránky  

## Technologie

PHP (mysqli, prepared statements), HTML/CSS, JavaScript (jQuery, vlastní bundle skriptů).

## Pro vývojáře

V repozitáři je šablona **`config.example.php`**; vlastní **`config.php`** je v `.gitignore` a nepatří do commitů.

### Požadavky

- PHP s rozšířením **mysqli**  
- MySQL / MariaDB s tabulkami projektu (min. `texty`, `typy_linek` a vyplněné `typ_linky_id` u dlaždic)

### Lokální běh

1. Zkopíruj `config.example.php` → `config.php`, doplň přístup k databázi (můžeš použít **lokální** MariaDB/MySQL se zkopírovaným dumpnutým schématem, nebo **vzdálený** hosting, pokud z tvé sítě povoluje připojení).  
2. Nastav `$appBasePath`: u vestavěného PHP serveru v kořeni projektu obvykle **`''`** (prázdný řetězec); u nasazení v podsložce na webu cesta typu `/blog/liberecke-linky`.  
3. Volitelně `$googleAnalyticsMeasurementId` – nech prázdné, pokud GA nechceš.  
4. V kořeni projektu spusť: `php -S localhost:8080`  
5. Otevři v prohlížeči **http://localhost:8080/** — měla by se načíst úvodní stránka s dlaždicemi a záložkami (pokud DB odpovídá produkci).

Bez funkční databáze uvidíš u dlaždic nebo v záložkách chybové hlášky z jazykových souborů (`err_db` atd.), ne kompletní obsah.

### Nasazení a CI (správce)

GitHub Action [`.github/workflows/deploy-ftp.yml`](.github/workflows/deploy-ftp.yml) po pushi na **`main`** nahraje soubory na FTP. V repozitáři nastav secrets `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_SERVER_DIR`. **`config.php` se přes workflow neposílá** – na produkci musí zůstat tvůj soubor s produkčními údaji.

Složka **`jr/`** (jízdní řády PDF/HTML) je v `.gitignore` a **nepatří do repozitáře**; na hosting ji musíš udržovat zvlášť (FTP, správce souborů), jinak odkazy na jízdní řády na webu nenajdou soubory.

---

Projekt a blog: **Tomáš Krupička** · [tomaskrupicka.cz](https://tomaskrupicka.cz). V patičce stránky jsou uvedeni další spolupracovníci (Boveraclub, Liberecká podniková aj.).
