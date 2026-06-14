<?php
/** English **/

// meta + header
$lang['titulekstranky'] = "Liberec Routes — interactive transit map, line overviews, history, videos";
$lang['popisstranky']   = "Liberec Routes — interactive transit map, line overviews, history, videos";
$lang['hlavninadpis']   = "Liberec Routes";
$lang['paticka']        = "Contributors: <a style='color:white' target='_blank' href='http://www.boveraclub.com/'>Boveraclub (historical records)</a>, <a style='color:white' target='_blank' href='https://www.youtube.com/channel/UCDnTRePslg2t4wC5MNPKOjQ/'>Liberecká podniková (videos, proofreading)</a>, Tomáš Krupička Sr. (local facts) and others.<br><a style='color:white' target='_blank' href='https://tomaskrupicka.cz/dopravak/kazda-linka-ma-sve-kouzlo/'>Project idea</a> · <a style='color:white' target='_blank' rel='noopener' href='https://github.com/Grupislav/Liberecke-linky'>Source code (GitHub)</a> · <a style='color:white' target='_blank' href='https://tomaskrupicka.cz/'>Blog</a>.";

// tabs
$lang['prehled']       = "Overview";
$lang['prehled_nav']   = "Line overview";
$lang['historie']      = "History";
$lang['pohledridice']  = "Videos";
$lang['mistopis']      = "Local geography";
$lang['fotogalerie']   = "Photo gallery";

// headings
$lang['funkce']           = "Route function";
$lang['seznamzastavek']   = "List of stops";
$lang['mapa']             = "Map";
$lang['provoznilinky']    = "Currently operated routes";
$lang['neprovoznilinky']  = "Routes permanently out of service";

// messages & placeholders
$lang['zalozkanedostupna'] = "This tab will be added later.";
$lang['historiecekana']    = "The route history is yet to be written.";
$lang['videocakana']       = "The route video is yet to be added.";
$lang['mistopiscekana']    = "The local geography article is yet to be added.";
$lang['fotogaleriecekana'] = "The photo gallery is not available yet. If you have photos of this route, please <a href='mailto:info@tomaskrupicka.cz'>contact me</a>.";

// DB / AJAX errors
$lang['err_db']           = 'Could not connect to the database. Please try again later.';
$lang['err_db_prepare']  = 'Could not prepare the query. Please try again later.';
$lang['err_ajax_tabload'] = 'Could not load this tab. Try refreshing the page.';
$lang['mapa_nedostupna']  = 'Map data is not available.';
$lang['mapa_iframe_title'] = 'Map for route %s';

// Network map page (mapa.php / mapa.js)
$lang['mapa_linky']          = 'Routes';
$lang['mapa_nav']            = 'Live map';
$lang['mapa_titulek']        = 'Live transit map – Liberec | Liberec Routes';
$lang['mapa_popis']          = 'Interactive map of public transit routes and stops in Liberec and Jablonec nad Nisou, based on open data (GTFS).';
$lang['mapa_pozn_poloha']    = 'Vehicle positions are approximate, based on the timetable – no public GPS data is available.';
$lang['mapa_hledat']         = 'Search a route or stop…';
$lang['mapa_zastavky']       = 'Stops';
$lang['mapa_hledat_linku']   = 'Search a route…';
$lang['mapa_hledat_zastavku'] = 'Search a stop…';
$lang['mapa_tram']           = 'Trams';
$lang['mapa_bus']            = 'Buses';
$lang['mapa_vse']            = 'In service';
$lang['mapa_filtr_mimo']     = 'Out of service';
$lang['mapa_filtr_historicke'] = 'Historical';
$lang['mapa_nic']            = 'None';
$lang['mapa_zastavka']       = 'Stop';
$lang['mapa_zona']           = 'Zone';
$lang['mapa_bezbarierova']   = 'Wheelchair access';
$lang['mapa_linky_zde']      = 'Routes at this stop';
$lang['mapa_zastavky_linky'] = 'Route stops';
$lang['mapa_zpet']           = 'Back';
$lang['mapa_zobraz_linku']   = 'Show only this route';
$lang['mapa_ano']            = 'yes';
$lang['mapa_ne']             = 'no';
$lang['mapa_neznamo']        = 'unknown';

// integration: header, in-tab preview, map↔detail cross-links
$lang['mapa_sit']            = 'NETWORK MAP';
$lang['mapa_zobraz_v_siti']  = 'Show in the interactive map';
$lang['mapa_detail_linky']   = 'Route detail & history';
$lang['mapa_nahled_alt']     = 'Route %s preview';
$lang['mapa_mimo_provoz_pozn'] = 'Route is approximate.';
$lang['mapa_mimo_nadpis']      = 'Line %s (permanently out of service)';
$lang['mapa_hist_nadpis']      = 'Historical line %s';
$lang['mapa_zanikla']          = 'former stop';
$lang['mapa_smer']             = 'To %s';
$lang['mapa_vozidla']          = 'Vehicles';
$lang['mapa_barevne_linky']    = 'Coloured lines';
$lang['mapa_odjezdy']          = 'Departures';
$lang['mapa_odjezdy_zadne']    = 'Nothing departs here right now.';
$lang['mapa_jr_spoje']         = 'Trip schedule';

// line-category legend on the map (labels for typy_linek categories)
$lang['mapa_legenda']        = 'Line categories';
$lang['mapa_kat_tramvaje']   = 'Trams';
$lang['mapa_kat_autobusy']   = 'Buses';
$lang['mapa_kat_nocni']      = 'Night';
$lang['mapa_kat_pracovni']   = 'Workday';
$lang['mapa_kat_skolni']     = 'School';
$lang['mapa_kat_nakupni']    = 'Commercial';
$lang['mapa_kat_historicke'] = 'Historical';

// singular category names for the route title in the Overview ("Category number: route")
$lang['mapa_katsg_tramvaje']   = 'Tram';
$lang['mapa_katsg_autobusy']   = 'Bus';
$lang['mapa_katsg_nocni']      = 'Night line';
$lang['mapa_katsg_pracovni']   = 'Workday line';
$lang['mapa_katsg_skolni']     = 'School line';
$lang['mapa_katsg_nakupni']    = 'Commercial line';
$lang['mapa_katsg_historicke'] = 'Historical line';
$lang['mapa_katsg_mimoprovoz'] = 'Out-of-service line';

// intros (no route selected)
$lang['prehled_intro']      = "This tab shows the basic information about the selected route. Please choose a route in the top menu.";
$lang['historie_intro']     = "This tab shows the historical development of the selected route. Please choose a route in the top menu.";
$lang['pohledridice_intro'] = "This tab shows a driver's view video of the selected route and a short commentary. Please choose a route in the top menu.";
$lang['mistopis_intro']     = "This tab contains local geography articles related to places along the selected route. Please choose a route in the top menu.";
$lang['fotogalerie_intro']  = "This tab contains a link to the photo gallery related to the selected route. Please choose a route in the top menu.";
