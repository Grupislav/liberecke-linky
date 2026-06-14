<?php
/** Čeština / Czech **/

// meta + header
$lang['titulekstranky'] = "Liberecké linky — interaktivní mapa MHD, přehledy, historie, videozáznamy";
$lang['popisstranky']   = "Liberecké linky — interaktivní mapa MHD, přehledy, historie, videozáznamy";
$lang['hlavninadpis']   = "Liberecké linky";
$lang['paticka']        = "Spolupracovali: <a style='color:white' target='_blank' href='http://www.boveraclub.com/'>Boveraclub (historické záznamy)</a>, <a style='color:white' target='_blank' href='https://www.youtube.com/channel/UCDnTRePslg2t4wC5MNPKOjQ/'>Liberecká podniková (videozáznamy, korektury)</a>, Tomáš Krupička st. (doplnění a korektura místopisných údajů) a další.<br><a style='color:white' target='_blank' href='https://tomaskrupicka.cz/dopravak/kazda-linka-ma-sve-kouzlo/'>Smysl projektu</a> · <a style='color:white' target='_blank' rel='noopener' href='https://github.com/Grupislav/Liberecke-linky'>Zdrojový kód (GitHub)</a> · <a style='color:white' target='_blank' href='https://tomaskrupicka.cz/'>Blog</a>.";

// tabs
$lang['prehled']       = "Přehled";
$lang['prehled_nav']   = "Přehled linek";
$lang['historie']      = "Historie";
$lang['pohledridice']  = "Videozáznamy";
$lang['mistopis']      = "Místopis";
$lang['fotogalerie']   = "Fotogalerie";

// nadpisy v obsahu
$lang['funkce']           = "Funkce linky";
$lang['seznamzastavek']   = "Seznam zastávek";
$lang['mapa']             = "Mapa";
$lang['provoznilinky']    = "Aktuálně provozované linky";
$lang['neprovoznilinky']  = "Linky trvale mimo provoz";

// nové: hlášky a úvody
$lang['zalozkanedostupna']  = "Záložka bude teprve doplněna.";
$lang['historiecekana']     = "Historie linky čeká na své zpracování.";
$lang['videocakana']        = "Video linky čeká na své zpracování.";
$lang['mistopiscekana']     = "Místopisný článek čeká na své zpracování.";
$lang['fotogaleriecekana']  = "Fotogalerie čeká na své zpracování nebo nejsou známy fotografie této linky. Pokud nějaké máte, <a href='mailto:info@tomaskrupicka.cz'>kontaktujte mě prosím</a>.";
// Šablonové texty z DB sloupce fotogalerie (lokalizace přes strtr ve fotogalerie.php).
$lang['fotogalerie_klik']   = "Pro zobrazení galerie klikněte zde";
$lang['fotogalerie_nejsou'] = "Bohužel nejsou známy fotografie této linky. Pokud nějaké máte a můžete je sdílet <a href='mailto:info@tomaskrupicka.cz'>kontaktujte mě prosím</a>.";
$lang['fotogalerie_alt']    = "Fotografie linky";

// chyby DB / AJAX
$lang['err_db']            = 'Došlo k chybě při připojení k databázi. Zkuste to prosím později.';
$lang['err_db_prepare']   = 'Došlo k chybě při přípravě dotazu. Zkuste to prosím později.';
$lang['err_ajax_tabload']  = 'Obsah záložky se nepodařilo načíst. Zkuste obnovit stránku.';
$lang['mapa_nedostupna']   = 'Mapový podklad není k dispozici.';
$lang['mapa_iframe_title'] = 'Mapa linky %s';

// stránka Mapa sítě (mapa.php / mapa.js)
$lang['mapa_linky']          = 'Linky';
$lang['mapa_nav']            = 'Živá mapa';
$lang['mapa_titulek']        = 'Živá mapa MHD Liberec | Liberecké linky';
$lang['mapa_popis']          = 'Interaktivní mapa linek a zastávek MHD v Liberci a Jablonci nad Nisou nad otevřenými daty (GTFS).';
$lang['mapa_pozn_poloha']    = 'Polohy vozidel jsou orientační, podle jízdního řádu – veřejná GPS data nejsou k dispozici.';
$lang['mapa_hledat']         = 'Hledat linku nebo zastávku…';
$lang['mapa_zastavky']       = 'Zastávky';
$lang['mapa_hledat_linku']   = 'Hledat linku…';
$lang['mapa_hledat_zastavku'] = 'Hledat zastávku…';
$lang['mapa_tram']           = 'Tramvaje';
$lang['mapa_bus']            = 'Autobusy';
$lang['mapa_vse']            = 'Provozní';
$lang['mapa_filtr_mimo']     = 'Mimo provoz';
$lang['mapa_filtr_historicke'] = 'Historické';
$lang['mapa_nic']            = 'Nic';
$lang['mapa_zastavka']       = 'Zastávka';
$lang['mapa_zona']           = 'Zóna';
$lang['mapa_bezbarierova']   = 'Bezbariérová';
$lang['mapa_linky_zde']      = 'Linky v zastávce';
$lang['mapa_zastavky_linky'] = 'Zastávky linky';
$lang['mapa_zpet']           = 'Zpět';
$lang['mapa_zobraz_linku']   = 'Zobrazit jen tuto linku';
$lang['mapa_ano']            = 'ano';
$lang['mapa_ne']             = 'ne';
$lang['mapa_neznamo']        = 'neznámo';

// integrace: nadpis, náhled v záložce, prolinky mapa↔detail
$lang['mapa_sit']            = 'MAPA SÍTĚ';
$lang['mapa_zobraz_v_siti']  = 'Zobrazit v interaktivní mapě';
$lang['mapa_detail_linky']   = 'Detail a historie linky';
$lang['mapa_nahled_alt']     = 'Náhled trasy linky %s';
$lang['mapa_mimo_provoz_pozn'] = 'Trasa je přibližná.';
$lang['mapa_mimo_nadpis']      = 'Linka %s (trvale mimo provoz)';
$lang['mapa_hist_nadpis']      = 'Historická linka %s';
$lang['mapa_zanikla']          = 'zaniklá zastávka';
$lang['mapa_smer']             = 'Směr %s';
$lang['mapa_vozidla']          = 'Vozidla';
$lang['mapa_barevne_linky']    = 'Barevné linky';
$lang['mapa_odjezdy']          = 'Odjezdy';
$lang['mapa_odjezdy_zadne']    = 'Odsud teď nic nejede.';
$lang['mapa_jr_spoje']         = 'Jízdní řád spoje';

// legenda kategorií linek na mapě (popisky ke kategoriím v typy_linek)
$lang['mapa_legenda']        = 'Kategorie linek';
$lang['mapa_kat_tramvaje']   = 'Tramvaje';
$lang['mapa_kat_autobusy']   = 'Autobusy';
$lang['mapa_kat_nocni']      = 'Noční';
$lang['mapa_kat_pracovni']   = 'Pracovní';
$lang['mapa_kat_skolni']     = 'Školní';
$lang['mapa_kat_nakupni']    = 'Komerční';
$lang['mapa_kat_historicke'] = 'Historické';

// singulární názvy kategorií pro nadpis linky v přehledu ("Kategorie číslo: trasa")
$lang['mapa_katsg_tramvaje']   = 'Tramvaj';
$lang['mapa_katsg_autobusy']   = 'Autobus';
$lang['mapa_katsg_nocni']      = 'Noční linka';
$lang['mapa_katsg_pracovni']   = 'Pracovní linka';
$lang['mapa_katsg_skolni']     = 'Školní linka';
$lang['mapa_katsg_nakupni']    = 'Komerční linka';
$lang['mapa_katsg_historicke'] = 'Historická linka';
$lang['mapa_katsg_mimoprovoz'] = 'Linka mimo provoz';

// (volitelné) úvodní texty, když není vybraná linka
$lang['prehled_intro']      = "Na této záložce najdete základní informace o dané lince. Pokračujte výběrem linky v horním menu.";
$lang['historie_intro']     = "Na této záložce najdete historii vývoje dané linky. Pokračujte výběrem linky v horním menu.";
$lang['pohledridice_intro'] = "Na této záložce najdete video linky zachycené z kabiny řidiče a krátký komentář. Pokračujte výběrem linky v horním menu.";
$lang['mistopis_intro']     = "Na této záložce najdete místopisné články o místech, kterými daná linka projíždí. Pokračujte výběrem linky v horním menu.";
$lang['fotogalerie_intro']  = "Na této záložce najdete odkaz na fotogalerii vážící se k dané lince. Pokračujte výběrem linky v horním menu.";
