# Liberecké linky

Přehled **autobusových linek v Liberci a okolí**: dlaždice s linkami, záložky s přehledem jízdního řádu, historií, pohledem řidiče, místopisem a fotogalerií. Data jsou v **MySQL**; rozhraní je vícejazyčné (čeština / angličtina).

**Živá verze:** [tomaskrupicka.cz/blog/liberecke-linky/](https://tomaskrupicka.cz/blog/liberecke-linky/)

## Funkce

- výběr linky z přehledu dlaždic  
- záložky: přehled, historie, pohled řidiče, místopis, fotogalerie (část obsahu se načítá přes AJAX)  
- přepínání jazyka přes parametr URL (`ja`)  
- volitelně **Google Analytics 4** (ID v `config.php`)  
- strukturovaná data (Schema.org) v šabloně stránky  

## Technologie

PHP (mysqli, prepared statements), HTML/CSS, JavaScript (jQuery, vlastní bundle skriptů).

## Pro vývojáře

V repozitáři je šablona **`config.example.php`**; vlastní **`config.php`** je v `.gitignore` a nepatří do commitů.

### Požadavky

- PHP s **mysqli**  
- MySQL / MariaDB se schématem linek (tabulky podle vašeho nasazení)

### Lokální běh

1. Zkopíruj `config.example.php` → `config.php`, doplň přístup k databázi.  
2. Nastav `$appBasePath` podle URL (např. `/blog/liberecke-linky` nebo `''` v kořeni webu).  
3. Volitelně `$googleAnalyticsMeasurementId` – nech prázdné, pokud GA nechceš.  
4. Spusť PHP server v kořeni projektu, např. `php -S localhost:8080`.

### Nasazení a CI (správce)

GitHub Action [`.github/workflows/deploy-ftp.yml`](.github/workflows/deploy-ftp.yml) po pushi na **`main`** nahraje soubory na FTP. V repozitáři nastav secrets `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_SERVER_DIR`. **`config.php` se přes workflow neposílá** – na produkci musí zůstat tvůj soubor s produkčními údaji.

Složka **`jr/`** (jízdní řády PDF/HTML) je v `.gitignore` a **nepatří do repozitáře**; na hosting ji musíš udržovat zvlášť (FTP, správce souborů), jinak odkazy na jízdní řády na webu nenajdou soubory.

---

Projekt a blog: **Tomáš Krupička** · [tomaskrupicka.cz](https://tomaskrupicka.cz). V patičce stránky jsou uvedeni další spolupracovníci (Boveraclub, Liberecká podniková aj.).
