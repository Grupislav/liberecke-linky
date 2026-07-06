/* ====================================================================
   Mapa sítě MHD Liberec / Jablonec n. N. – statická Leaflet mapa
   Data: mapa-assets/data/*.json (generuje tools/build_data.py z GTFS)
   ==================================================================== */
(function () {
  "use strict";

  var BASE = (window.MAPA && window.MAPA.base) || "";
  var JA = (window.MAPA && window.MAPA.ja) || "";
  var T = (window.MAPA && window.MAPA.lang) || {};
  var TILE_COLORS = (window.MAPA && window.MAPA.tileColors) || {};
  var TILE_CATS = (window.MAPA && window.MAPA.tileCats) || {};   // linka -> název kategorie (z DB)
  var LEGACY_STOPS = (window.MAPA && window.MAPA.legacyStops) || {};  // linka mimo provoz -> [názvy z DB]
  var ZPRIO = (window.MAPA && window.MAPA.tilePriority) || {};
  var ALIASES = (window.MAPA && window.MAPA.aliases) || {};
  var SNAP = (window.MAPA && window.MAPA.snapshot) || false;   // historický snapshot (bez JŘ)
  var DATA = (window.MAPA && window.MAPA.dataDir) || (BASE + "/mapa-assets/data/");

  // barva linky podle dlaždic (z DB); fallback na barvu z GTFS
  function rcolor(shortName, fallback) {
    return (shortName && TILE_COLORS[shortName]) || fallback;
  }
  // barva pro objekt linky – legacy linky drží svou typovou barvu (ne šedou mimoprovoz dlaždici)
  function routeColor(r) { return r.legacy ? r.color : rcolor(r.short_name, r.color); }

  // ── PODKLADOVÁ MAPA ──────────────────────────────────────────────
  // Pro přechod na keyed providera (MapTiler/Stadia/Carto/Mapy.cz)
  // stačí přepsat tento blok – nic jiného v kódu se nemění.
  var TILE = {
    url: "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
    options: {
      maxZoom: 19,
      attribution:
        '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> | ' +
        'data <a href="https://www.dpmlj.cz/opendata" target="_blank" rel="noopener">DPMLJ a.s.</a>'
    }
  };

  var LIBEREC = [50.7705, 15.058];

  // styly čar
  var W_DIM = 1.8, W_BASE = 3, W_FOCUS = 6;
  var OP_DIM = 0.5, OP_BASE = 0.85;
  var DIM_COLOR = "#9aa7b0";   // výchozí trasování / ostatní linky – šedá (tmavší, ať obsluhované oblasti vyniknou)

  // ── stav ─────────────────────────────────────────────────────────
  var map, baseLayer, routeLayer, stopLayer;
  var routes = [], stops = [];
  var routeById = {}, routeByShort = {}, stopById = {}, stopByName = {};
  var STOP_RENAMED = {}, STOP_ALIASES = {}, vanishedStops = [];  // historie zastávek
  var routeLine = {};            // route id -> L.LayerGroup (polylines)
  var stopMarker = {};           // stop id  -> L.CircleMarker
  var focusedRouteId = null;
  var hoveredRouteId = null;     // linka pod kurzorem v seznamu (dočasné zvýraznění)
  var focusedStopId = null;      // vybraná zastávka (špendlík zůstává)
  var stopPin = null;            // výrazné označení polohy zastávky na mapě
  var filter = "all";            // all | tram | bus | legacy (mimo provoz)
  var query = "";
  var mode = "lines";            // lines | stops

  // ── jízdní řád (odjezdy v zastávce + poloha vozidel dle JŘ) ──────
  var TT = null;                 // timetable.json
  var depByStop = null;          // index: stopIdx -> [{ti, sec}] (odjezdy)
  var stopIndexById = {};        // id stanice -> index ve `stops` (= index v timetable)
  var vehLayer = null;           // vrstva vozidel
  var vehOn = true;              // přepínač zobrazení vozidel
  var colorLines = false;        // přepínač: obarvit provozní linky (jinak šedá síť)
  var vehTimer = null;
  var hoverTrip = null;          // spoj pod kurzorem (dočasné zvýraznění trasy)
  var focusedTrip = null;        // rozkliknutý spoj (jízdní řád v sidebaru)
  var focusedTripRouteId = null; // linka rozkliknutého spoje (její běžná čára se skryje)
  var tripOverlay = null;        // vrstva: projetá (čárkovaně) + zbývající část trasy
  var routeGeom = {};            // route id -> [[ [lat,lng], … ], …] (pro rozdělení trasy)

  var elMap = document.getElementById("mapa");
  var elRoutes = document.getElementById("ms-routes");
  var elStops = document.getElementById("ms-stops");
  var elToolbar = document.querySelector("#ms-browse .ms-toolbar");
  var elDetail = document.getElementById("ms-detail");
  var elBrowse = document.getElementById("ms-browse");
  var elSearch = document.getElementById("ms-search-input");
  var elMeta = document.getElementById("ms-meta");

  // ── načtení dat ──────────────────────────────────────────────────
  var VER = (window.MAPA && window.MAPA.v) ? ("?v=" + window.MAPA.v) : "";
  function getJSON(name) {
    return fetch(DATA + name + VER, { credentials: "same-origin" }).then(function (r) {
      if (!r.ok) throw new Error(name + " " + r.status);
      return r.json();
    });
  }

  Promise.all([
    getJSON("routes.json"),
    getJSON("stops.json"),
    getJSON("shapes.json"),
    getJSON("meta.json").catch(function () { return null; }),
    getJSON("legacy-routes.json").catch(function () { return []; }),
    getJSON("legacy-shapes.json").catch(function () { return {}; }),
    getJSON("stops-history.json").catch(function () { return {}; })
  ]).then(function (res) {
    routes = res[0]; stops = res[1];
    init(res[2], res[3], res[4], res[5], res[6]);
  }).catch(function (e) {
    showMsg(T.noData || "Data nejsou k dispozici.");
    if (window.console) console.error(e);
  });

  function showMsg(text) {
    var d = document.createElement("div");
    d.className = "ms-msg";
    d.textContent = text;
    elMap.appendChild(d);
  }

  // ── inicializace ─────────────────────────────────────────────────
  function init(shapes, meta, legacy, legacyShapes, history) {
    if (SNAP) colorLines = true;   // snapshot = rovnou barevná síť (žádné „provozní vs. ostatní")
    routes.forEach(function (r) { routeById[r.id] = r; routeByShort[r.short_name] = r; });
    stops.forEach(function (s, i) { stopById[s.id] = s; stopByName[norm(s.name)] = s; stopIndexById[s.id] = i; });
    processHistory(history);

    map = L.map(elMap, { preferCanvas: true, zoomControl: true })
            .setView(LIBEREC, 12);
    // zoom doprava nahoru, ať se nepere s plovoucím tlačítkem „zobrazit panel" vlevo
    if (map.zoomControl) map.zoomControl.setPosition("topright");
    baseLayer = L.tileLayer(TILE.url, TILE.options).addTo(map);

    routeLayer = L.layerGroup().addTo(map);
    stopLayer = L.layerGroup().addTo(map);

    drawRoutes(shapes);
    drawStops();
    addLegacyRoutes(legacy, legacyShapes);   // linky mimo provoz (geometrie z legacy-shapes.json)
    applyZOrder();             // pořadí vrstev dle kategorie (tramvaje navrchu … mimo provoz dole)
    refreshRouteStyles();      // výchozí stav (šedá síť) hned po načtení, ne až při první akci
    buildRouteList();
    buildStopList();
    bindUI();
    applyDeepLink();
    if (SNAP) addMapToggles();   // snapshot: jen přepínač „Barevné linky" (bez JŘ/vozidel)
    else loadTimetable();        // živá mapa: jízdní řád (vozidla + odjezdy)

    if (meta && meta.valid_from && elMeta) {
      elMeta.textContent = fmtDate(meta.valid_from) + " – " + fmtDate(meta.valid_to);
    }
  }

  // ── jízdní řád: načtení a vozidla ────────────────────────────────
  function loadTimetable() {
    getJSON("timetable.json").then(function (tt) {
      TT = tt;
      buildDepIndex();
      vehLayer = L.layerGroup();
      if (vehOn) vehLayer.addTo(map);
      addMapToggles();
      refreshVehicles();
      vehTimer = window.setInterval(tick, 20000);   // pravidelná aktualizace
      // pokud je už otevřený detail zastávky (např. deep-link), doplň odjezdy
      var wrap = document.getElementById("ms-dep-wrap");
      if (wrap && focusedStopId != null && stopById[focusedStopId])
        wrap.innerHTML = departuresHtml(stopById[focusedStopId]);
    }).catch(function () { /* JŘ nedostupný – mapa funguje i bez vozidel */ });
  }

  // jeden „tik": přepočet vozidel + případně odjezdů v otevřeném detailu
  function tick() {
    refreshVehicles();
    var wrap = document.getElementById("ms-dep-wrap");
    if (wrap && focusedStopId != null) {
      var s = stopById[focusedStopId];
      if (s && !s.historical) wrap.innerHTML = departuresHtml(s);
    }
    if (focusedTrip) {                          // posuň polohu/trasu i jízdní řád spoje
      var st = tripState(focusedTrip);
      if (st) { showTripView(focusedTrip); renderTripDetail(focusedTrip, st); }
    }
  }

  // aktuální čas v Praze: sekundy od půlnoci, den v týdnu (po=0), YYYYMMDD
  function pragueNow() {
    var parts = new Intl.DateTimeFormat("en-GB", {
      timeZone: "Europe/Prague", hourCycle: "h23", weekday: "short",
      year: "numeric", month: "2-digit", day: "2-digit",
      hour: "2-digit", minute: "2-digit", second: "2-digit"
    }).formatToParts(new Date());
    var o = {};
    parts.forEach(function (p) { o[p.type] = p.value; });
    var hh = parseInt(o.hour, 10) % 24;
    var sec = hh * 3600 + parseInt(o.minute, 10) * 60 + parseInt(o.second, 10);
    var wmap = { Mon: 0, Tue: 1, Wed: 2, Thu: 3, Fri: 4, Sat: 5, Sun: 6 };
    return { sec: sec, wd: wmap[o.weekday], ymd: o.year + o.month + o.day };
  }

  function prevDay(ymd, wd) {
    var dt = new Date(+ymd.slice(0, 4), +ymd.slice(4, 6) - 1, +ymd.slice(6, 8));
    dt.setDate(dt.getDate() - 1);
    var p = function (n) { return (n < 10 ? "0" : "") + n; };
    return { ymd: "" + dt.getFullYear() + p(dt.getMonth() + 1) + p(dt.getDate()), wd: (wd + 6) % 7 };
  }
  function nextDay(ymd, wd) {
    var dt = new Date(+ymd.slice(0, 4), +ymd.slice(4, 6) - 1, +ymd.slice(6, 8));
    dt.setDate(dt.getDate() + 1);
    var p = function (n) { return (n < 10 ? "0" : "") + n; };
    return { ymd: "" + dt.getFullYear() + p(dt.getMonth() + 1) + p(dt.getDate()), wd: (wd + 1) % 7 };
  }

  // service_id aktivní v daný den (bitmaska po–ne + rozsah platnosti)
  function activeServices(ymd, wd) {
    var set = {};
    if (!TT) return set;
    Object.keys(TT.services).forEach(function (id) {
      var s = TT.services[id];
      if (s.d.charAt(wd) === "1" && s.f <= ymd && ymd <= s.t) set[id] = true;
    });
    return set;
  }

  function buildDepIndex() {
    depByStop = {};
    if (!TT) return;
    TT.trips.forEach(function (tr, ti) {
      var u = tr.u;
      for (var k = 0; k < u.length - 1; k++) {     // poslední zastávka = příjezd, ne odjezd
        var idx = u[k][0];
        (depByStop[idx] || (depByStop[idx] = [])).push({ ti: ti, sec: u[k][1] });
      }
    });
  }

  function vehiclePassesFilter(tr) {
    if (filter === "legacy" || filter === "historicke") return false;  // legacy nemají JŘ
    if (filter === "tram") return tr.m === "tram";
    if (filter === "bus") return tr.m === "bus";
    return true;
  }

  // azimut (0 = sever, po směru hodin) z bodu a do b – pro otočení šipky
  function bearingDeg(a, b) {
    var dLat = b[0] - a[0];
    var dLon = (b[1] - a[1]) * Math.cos(a[0] * Math.PI / 180);
    return Math.atan2(dLon, dLat) * 180 / Math.PI;
  }

  function refreshVehicles() {
    if (!vehLayer) return;
    vehLayer.clearLayers();
    if (!vehOn || !TT) return;
    var now = pragueNow();
    var today = activeServices(now.ymd, now.wd);
    var pv = prevDay(now.ymd, now.wd);
    var yest = activeServices(pv.ymd, pv.wd);
    var list = [];
    TT.trips.forEach(function (tr) {
      if (!vehiclePassesFilter(tr)) return;
      if (today[tr.s]) collectVehicle(tr, now.sec, list);
      if (yest[tr.s]) collectVehicle(tr, now.sec + 86400, list);   // spoje po půlnoci (služba včerejška)
    });
    // víc vozidel v jedné zastávce → rozprostři je do vějíře, ať se nepřekrývají
    var byStop = {};
    list.forEach(function (v) { (byStop[v.si] || (byStop[v.si] = [])).push(v); });
    Object.keys(byStop).forEach(function (k) {
      var g = byStop[k];
      g.forEach(function (v, i) { drawVehicle(v, g.length, i); });
    });
  }

  function inWindow(tr, t) {
    var u = tr.u;
    return t >= u[0][1] - 120 && t <= u[u.length - 1][1] + 120;   // −2 min před / +2 min po
  }

  // index zastávky, kde vozidlo podle JŘ právě je (bez mezilehlé polohy)
  function positionAt(tr, t) {
    var u = tr.u, n = u.length, first = u[0][1], last = u[n - 1][1], i, terminus = false, nextIdx = -1;
    if (t <= first) { i = 0; nextIdx = u[1][0]; }              // výchozí (před odjezdem)
    else if (t >= last) { i = n - 1; terminus = true; }        // konečná (po příjezdu) – bez šipky
    else { i = 0; while (i < n - 1 && u[i + 1][1] <= t) i++; if (i < n - 1) nextIdx = u[i + 1][0]; else terminus = true; }
    return { i: i, terminus: terminus, nextIdx: nextIdx };
  }

  // stav spoje teď (který referenční čas platí + poloha); null = nejede
  function tripState(tr) {
    var now = pragueNow();
    var today = activeServices(now.ymd, now.wd);
    var pv = prevDay(now.ymd, now.wd), yest = activeServices(pv.ymd, pv.wd);
    var t = null;
    if (today[tr.s] && inWindow(tr, now.sec)) t = now.sec;
    else if (yest[tr.s] && inWindow(tr, now.sec + 86400)) t = now.sec + 86400;  // po půlnoci
    if (t == null) return null;
    var p = positionAt(tr, t); p.t = t; return p;
  }

  // zjisti polohu vozidla (zastávku, kde podle JŘ právě je) a ulož ji k vykreslení
  function collectVehicle(tr, t, list) {
    if (!inWindow(tr, t)) return;
    var u = tr.u, p = positionAt(tr, t), si = u[p.i][0], s = stops[si];
    if (!s) return;
    var bearing = null;
    if (!p.terminus && p.nextIdx >= 0) {
      var ns = stops[p.nextIdx];
      if (ns) bearing = bearingDeg([s.lat, s.lon], [ns.lat, ns.lon]);
    }
    list.push({ tr: tr, s: s, si: si, bearing: bearing });
  }

  // překryv ve stejné zastávce: 1 vozidlo na střed, víc do kružnice kolem ní
  function fanLatLng(s, n, i) {
    if (n <= 1) return [s.lat, s.lon];
    var R = 0.00012 + 0.00004 * Math.min(n - 1, 6);    // poloměr roste s počtem (~13–40 m)
    var a = (i / n) * 2 * Math.PI;
    return [s.lat + R * Math.cos(a), s.lon + R * Math.sin(a) / Math.cos(s.lat * Math.PI / 180)];
  }

  function drawVehicle(v, n, i) {
    var tr = v.tr, s = v.s, rr = routeByShort[tr.r];
    var color = rr ? routeColor(rr) : (tr.m === "tram" ? "#cc2900" : "#007db3");   // barva dle kategorie linky
    var icon = L.divIcon({
      className: "ms-veh", html: vehicleSvg(color, v.bearing),
      iconSize: [36, 36], iconAnchor: [18, 18]
    });
    var m = L.marker(fanLatLng(s, n, i), { icon: icon, keyboard: false, riseOnHover: true });
    // badge „linka → CÍL" jako reálný ukazatel; pod ním aktuální (poslední) zastávka
    m.bindTooltip("<span class='ms-veh-dest' style='background:" + color + "'>" + esc(tr.r) + " &rarr; " +
                  esc(tr.h) + "</span><br><span class='ms-veh-stop'>" + esc(s.name) + "</span>", { direction: "top" });
    m.on("mouseover", function () { hoverTrip = tr; showTripView(tr); });
    m.on("mouseout", function () { hoverTrip = null; if (focusedTrip) showTripView(focusedTrip); else hideTripView(); });
    m.on("click", function () { focusTrip(tr); });
    m.addTo(vehLayer);
  }

  function vehicleSvg(color, bearing) {
    var rot = (bearing == null) ? "" : ' style="transform:rotate(' + bearing.toFixed(0) + 'deg)"';
    var arrow = (bearing == null) ? ""    // konečná → bez šipky; jinak šipka k další zastávce
      : '<path d="M18 0 L27 15 L9 15 Z" fill="' + color + '"/>';
    return '<div class="ms-veh-i"' + rot + '><svg viewBox="0 0 36 36" width="36" height="36">' +
           arrow + '<circle cx="18" cy="18" r="8.5" fill="' + color + '" stroke="#fff" stroke-width="2"/>' +
           "</svg></div>";
  }

  function addMapToggles() {
    var Ctl = L.Control.extend({
      options: { position: "topright" },
      onAdd: function () {
        var div = L.DomUtil.create("div", "leaflet-bar ms-toggles");
        div.innerHTML =
          (SNAP ? "" :   // ve snapshotu nejsou vozidla (žádný JŘ)
            '<label><input type="checkbox" data-t="veh"' + (vehOn ? " checked" : "") + "> " +
            esc(T.vehicles || "Vozidla") + "</label>") +
          '<label><input type="checkbox" data-t="col"' + (colorLines ? " checked" : "") + "> " +
          esc(T.colorLines || "Barevné linky") + "</label>";
        L.DomEvent.disableClickPropagation(div);
        var veh = div.querySelector('[data-t="veh"]');
        if (veh) veh.addEventListener("change", function () {
          vehOn = veh.checked;
          if (vehOn) { if (!map.hasLayer(vehLayer)) vehLayer.addTo(map); refreshVehicles(); }
          else { vehLayer.clearLayers(); if (map.hasLayer(vehLayer)) map.removeLayer(vehLayer); }
        });
        var col = div.querySelector('[data-t="col"]');
        col.addEventListener("change", function () { colorLines = col.checked; refreshRouteStyles(); });
        return div;
      }
    });
    map.addControl(new Ctl());
  }

  // ── odjezdy ze zastávky (okno 24 h; přes půlnoc do zítřejší služby) ──
  function departuresFor(idx, now) {
    if (!TT || !depByStop || depByStop[idx] == null) return [];
    var pv = prevDay(now.ymd, now.wd), nx = nextDay(now.ymd, now.wd);
    var sets = {                                   // služby předešlého / dnešního / zítřejšího dne
      "-1": activeServices(pv.ymd, pv.wd),
      "0": activeServices(now.ymd, now.wd),
      "1": activeServices(nx.ymd, nx.wd)
    };
    var res = [];
    depByStop[idx].forEach(function (e) {
      var tr = TT.trips[e.ti];
      for (var o = -1; o <= 1; o++) {
        if (!sets[o][tr.s]) continue;
        var abs = e.sec + o * 86400;               // absolutní čas vůči dnešní půlnoci
        if (abs >= now.sec && abs <= now.sec + 86400) { res.push({ sec: abs, tr: tr }); break; }
      }
    });
    res.sort(function (a, b) { return a.sec - b.sec; });
    return res;
  }

  function fmtTime(sec) {
    sec = ((sec % 86400) + 86400) % 86400;
    var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60);
    return (h < 10 ? "0" : "") + h + ":" + (m < 10 ? "0" : "") + m;
  }

  function departuresHtml(s) {
    if (!TT || !s) return "";
    var idx = stopIndexById[s.id];
    if (idx == null) return "";
    var now = pragueNow();
    var all = departuresFor(idx, now);             // až 24 h dopředu, seřazené
    var head = "<h3>" + (T.departures || "Odjezdy") + "</h3>";
    if (!all.length)
      return head + '<p class="ms-dep-empty">' + (T.noDepartures || "Odsud teď nic nejede.") + "</p>";
    // počet do hodiny určí, kolik ukázat: min 5 (z 24 h), max 10; přes 10/h → až 20
    var hourCount = 0;
    while (hourCount < all.length && all[hourCount].sec <= now.sec + 3600) hourCount++;
    var n = hourCount > 10 ? Math.min(hourCount, 20) : Math.max(hourCount, 5);
    n = Math.min(n, all.length);
    var rows = all.slice(0, n).map(function (d) {
      var rr = routeByShort[d.tr.r];   // plná barevná paleta dle kategorie (ne jen tram/bus)
      var color = rr ? routeColor(rr) : (d.tr.m === "tram" ? "#cc2900" : "#007db3");
      return '<li class="ms-dep"><span class="ms-dep-time">' + fmtTime(d.sec) + "</span>" +
             '<span class="ms-badge" style="background:' + color + '">' + esc(d.tr.r) + "</span>" +
             '<span class="ms-dep-head">' + esc(d.tr.h) + "</span></li>";
    }).join("");
    return head + '<ul class="ms-deplist">' + rows + "</ul>";
  }

  // ── detail spoje: jízdní řád + projetá/zbývající část trasy na mapě ──
  // projekce bodu [lat,lng] na lomenou čáru → {seg, t, d2}
  function projPoint(line, pt) {
    var k = Math.cos(pt[0] * Math.PI / 180), px = pt[1] * k, py = pt[0];
    var best = { seg: 0, t: 0, d2: Infinity };
    for (var i = 0; i < line.length - 1; i++) {
      var ax = line[i][1] * k, ay = line[i][0], bx = line[i + 1][1] * k, by = line[i + 1][0];
      var dx = bx - ax, dy = by - ay, L = dx * dx + dy * dy;
      var t = L ? ((px - ax) * dx + (py - ay) * dy) / L : 0;
      t = t < 0 ? 0 : (t > 1 ? 1 : t);
      var cx = ax + t * dx, cy = ay + t * dy, d2 = (px - cx) * (px - cx) + (py - cy) * (py - cy);
      if (d2 < best.d2) best = { seg: i, t: t, d2: d2 };
    }
    return best;
  }
  function ptAt(line, p) {
    return [line[p.seg][0] + (line[p.seg + 1][0] - line[p.seg][0]) * p.t,
            line[p.seg][1] + (line[p.seg + 1][1] - line[p.seg][1]) * p.t];
  }
  function sliceBetween(line, a, b) {
    if (a.seg > b.seg || (a.seg === b.seg && a.t > b.t)) { var tmp = a; a = b; b = tmp; }
    var out = [ptAt(line, a)];
    for (var i = a.seg + 1; i <= b.seg; i++) out.push(line[i]);
    out.push(ptAt(line, b));
    return out;
  }
  // vyber tu z polylinií trasy, která nejlíp sedí na zadané body
  function pickLine(lines, pts) {
    var best = null, bestD = Infinity;
    (lines || []).forEach(function (ln) {
      if (ln.length < 2) return;
      var d = 0;
      pts.forEach(function (p) { d += projPoint(ln, p).d2; });
      if (d < bestD) { bestD = d; best = ln; }
    });
    return best;
  }

  function tripStopIds(tr) {
    return tr.u.map(function (x) { var s = stops[x[0]]; return s ? s.id : null; }).filter(Boolean);
  }

  // nakresli trasu spoje: projetou část čárkovaně, zbývající plně
  function drawTripOverlay(tr, st, rr) {
    var u = tr.u, color = routeColor(rr);
    var F = stops[u[0][0]], C = stops[u[st.i][0]], Z = stops[u[u.length - 1][0]];
    if (!F || !C || !Z) return;
    var fc = [F.lat, F.lon], cc = [C.lat, C.lon], zc = [Z.lat, Z.lon];
    var line = pickLine(routeGeom[rr.id], [fc, cc, zc]), traveled, remaining;
    if (line) {                       // rozřízni reálnou geometrii v aktuální poloze
      traveled = sliceBetween(line, projPoint(line, fc), projPoint(line, cc));
      remaining = sliceBetween(line, projPoint(line, cc), projPoint(line, zc));
    } else {                          // bez geometrie → spojnice zastávek
      traveled = []; remaining = [];
      for (var a = 0; a <= st.i; a++) { var s1 = stops[u[a][0]]; if (s1) traveled.push([s1.lat, s1.lon]); }
      for (var b = st.i; b < u.length; b++) { var s2 = stops[u[b][0]]; if (s2) remaining.push([s2.lat, s2.lon]); }
    }
    if (remaining.length >= 2)
      L.polyline(remaining, { color: color, weight: W_FOCUS, opacity: 1, lineCap: "round", lineJoin: "round" }).addTo(tripOverlay);
    if (traveled.length >= 2)
      L.polyline(traveled, { color: color, weight: W_FOCUS, opacity: 1, dashArray: "3 9", lineCap: "round", lineJoin: "round" }).addTo(tripOverlay);
  }

  // zvýraznění spoje (hover i klik): trasa + zastávky; vrací stav nebo null
  function showTripView(tr) {
    var rr = routeByShort[tr.r]; if (!rr) return null;
    var st = tripState(tr); if (!st) return null;
    focusedTripRouteId = rr.id;
    if (!tripOverlay) tripOverlay = L.layerGroup().addTo(map);
    tripOverlay.clearLayers();
    drawTripOverlay(tr, st, rr);
    highlightStops(tripStopIds(tr));
    refreshRouteStyles();
    return st;
  }
  // zruš dočasné zvýraznění (po odjetí kurzoru), zachovej případně vybranou zastávku
  function hideTripView() {
    focusedTripRouteId = null;
    if (tripOverlay) tripOverlay.clearLayers();
    highlightStops(focusedStopId != null ? [focusedStopId] : []);
    refreshRouteStyles();
  }
  // úplné zrušení spoje (přechod na linku/zastávku/reset)
  function clearTrip() {
    focusedTrip = null; hoverTrip = null; focusedTripRouteId = null;
    if (tripOverlay) tripOverlay.clearLayers();
  }

  function focusTrip(tr) {
    var rr = routeByShort[tr.r];
    var st = showTripView(tr);
    if (!st) { if (rr) focusRoute(rr.id); return; }   // spoj už nejede → aspoň linka
    focusedTrip = tr; focusedStopId = null; setStopPin(null);
    renderTripDetail(tr, st);
    var grp = rr ? routeLine[rr.id] : null;
    if (grp) {
      var b = L.latLngBounds([]);
      grp.eachLayer(function (ln) { b.extend(ln.getBounds()); });
      if (b.isValid()) map.fitBounds(b, { padding: [40, 40] });
    }
  }

  function renderTripDetail(tr, st) {
    var rr = routeByShort[tr.r];
    var col = rr ? routeColor(rr) : (tr.m === "tram" ? "#cc2900" : "#007db3");
    var rows = tr.u.map(function (x, k) {
      var s = stops[x[0]]; if (!s) return "";
      var cls = k < st.i ? "ms-tp-past" : (k === st.i ? "ms-tp-now" : "");
      return '<li class="ms-tp ' + cls + '" data-stop="' + s.id + '" style="border-left-color:' + col + '">' +
             '<span class="ms-tp-time">' + fmtTime(x[1]) + "</span>" +
             '<span class="ms-tp-name">' + esc(s.name) + "</span></li>";
    }).join("");
    var badge = rr
      ? '<span class="ms-badge ms-badge-link" style="background:' + col + '" data-route="' + rr.id + '">' + esc(tr.r) + "</span>"
      : '<span class="ms-badge" style="background:' + col + '">' + esc(tr.r) + "</span>";
    elDetail.innerHTML = backBtn() +
      "<h2>" + badge + " &rarr; " + esc(tr.h) + "</h2>" +
      '<p class="ms-sub">' + (T.tripSchedule || "Jízdní řád spoje") + "</p>" +
      '<ul class="ms-tplist">' + rows + "</ul>";
    bindDetail();
    showDetail(true);
  }

  // ?linka=2 / #linka=2 → rovnou zafokusuj danou linku (deep-link z hlavního webu)
  function applyDeepLink() {
    var href = window.location.href;
    var ms = /[?&#]zastavka=([^&#]+)/.exec(href);   // ?zastavka=Název → zafokusuj zastávku
    if (ms) {
      var st = stopByName[norm(decodeURIComponent(ms[1]))];
      if (st) { focusStop(st.id); return; }
    }
    var m = /[?&#]linka=([^&#]+)/.exec(href);
    if (!m) return;
    var label = decodeURIComponent(m[1]);
    label = ALIASES[label] || label;                 // 161 → 16, 301 → 30, …
    var r = routeByShort[label] || routeByShort[label.toUpperCase()];
    if (r) focusRoute(r.id);
  }

  function fmtDate(s) {
    if (!s || s.length !== 8) return s || "";
    return s.slice(6, 8) + "." + s.slice(4, 6) + "." + s.slice(0, 4);
  }

  // normalizace názvu zastávky pro párování legacy linek
  function norm(s) { return String(s == null ? "" : s).trim().toLowerCase().replace(/\s+/g, " "); }

  // ořež přípony názvů zastávek z DB (na znamení "(x)", šipky) pro párování na GTFS
  function cleanStopName(s) {
    return String(s == null ? "" : s).replace(/\s*\([^)]*\)\s*$/, "").replace(/\s*[↑↓→←⇑⇓]\s*$/, "").trim();
  }

  // historie zastávek: přejmenování, varianty zápisu, zaniklé (se souřadnicemi)
  function processHistory(h) {
    h = h || {};
    var rn = h.renamed || {}, al = h.aliases || {};
    Object.keys(rn).forEach(function (k) { STOP_RENAMED[norm(k)] = rn[k]; });
    Object.keys(al).forEach(function (k) { STOP_ALIASES[norm(k)] = al[k]; });
    (h.vanished || []).forEach(function (v) {
      if (!v || !v.name || v.lat == null || v.lon == null) return;
      var s = { id: "hist:" + v.name, name: v.name, lat: v.lat, lon: v.lon, routes: [], historical: true };
      stopById[s.id] = s;
      if (!stopByName[norm(v.name)]) stopByName[norm(v.name)] = s;
      vanishedStops.push(s);
    });
  }

  // najdi zastávku pro název z DB seznamu (dnešní názvy → přejmenování → varianty → zaniklé).
  // Historicky (kurzívou) se značí JEN zaniklé zastávky; přejmenované názvy stále
  // existujících zastávek jsou klikací, ale bez označení a bez „→ nový název".
  function resolveStopName(raw) {
    var clean = cleanStopName(raw);
    var k = norm(clean);
    if (stopByName[k]) return { id: stopByName[k].id, display: clean, historical: !!stopByName[k].historical };
    var nn = STOP_RENAMED[k];
    if (nn && stopByName[norm(nn)]) return { id: stopByName[norm(nn)].id, display: clean, historical: false };
    var av = STOP_ALIASES[k];
    if (av && stopByName[norm(av)]) return { id: stopByName[norm(av)].id, display: clean, historical: false };
    return null;
  }

  // ── linky mimo provoz (legacy-routes.json) ───────────────────────
  // Trasa se sestaví spojnicí zastávek (dle názvů → stops.json), kreslí se
  // čárkovaně jako „přibližná". Nemají vlastní GTFS geometrii.
  function addLegacyRoutes(legacy, legacyShapes) {
    legacyShapes = legacyShapes || {};
    (legacy || []).forEach(function (lr) {
      if (!lr || !lr.short_name) return;
      var ids = [], names = [], latlngs = [];
      (lr.stops || []).forEach(function (entry) {
        var name = (entry && typeof entry === "object") ? entry.name : entry;
        var s = stopByName[norm(name)];
        if (s) { ids.push(s.id); names.push(s.name); latlngs.push([s.lat, s.lon]); }
        else if (entry && typeof entry === "object" && entry.lat != null && entry.lon != null) {
          names.push(name); latlngs.push([entry.lat, entry.lon]);   // zastávka mimo GTFS (vlastní souřadnice)
        } else if (window.console) {
          console.warn("legacy " + lr.short_name + ": neznámá zastávka »" + name + "«");
        }
      });
      if (latlngs.length < 2) return;

      var rid = "legacy-" + lr.short_name;
      var category = lr.category === "historicke" ? "historicke" : "mimoprovoz";
      var color = category === "historicke" ? "#991f00" : (lr.type === "tram" ? "#cc2900" : "#007db3");
      var r = {
        id: rid, short_name: lr.short_name, long_name: lr.long_name || "",
        type: lr.type === "tram" ? "tram" : "bus", color: color, category: category,
        approximate: !!lr.approximate, stops: ids, stopNames: names, legacy: true
      };
      routes.push(r);
      routeById[rid] = r;
      if (!routeByShort[lr.short_name]) routeByShort[lr.short_name] = r;

      // geometrie: sešitá trasa po ulicích z legacy-shapes.json, jinak rovná spojnice zastávek
      var geom = legacyShapes[lr.short_name];
      var drawLatlngs = (geom && geom.length >= 2)
        ? geom.map(function (c) { return [c[1], c[0]]; })
        : latlngs;

      var grp = L.layerGroup();
      L.polyline(drawLatlngs, {
        color: color, weight: W_BASE, opacity: OP_BASE,
        dashArray: "6 7", lineJoin: "round", lineCap: "round"
      }).on("click", function () { focusRoute(rid); }).addTo(grp);
      routeLine[rid] = grp;
      // legacy linky se do mapy NEpřidávají automaticky – zobrazí je až
      // filtr „Mimo provoz", hover v seznamu, nebo focus (refreshRouteStyles).
    });
  }

  // pořadí vrstev podle kategorie (nižší priorita = výš); řeší překryvy barev
  // skupina filtru pro legacy linku: Mimo provoz ("legacy") vs Historické
  function legacyGroup(r) { return r.category === "historicke" ? "historicke" : "legacy"; }

  function routePriority(r) {
    if (r.legacy) return r.category === "historicke" ? 8 : 7;  // historické úplně dole
    var p = ZPRIO[r.short_name];
    if (p != null) return p;
    return r.type === "tram" ? 1 : 2;
  }
  function applyZOrder() {
    // od nejnižší priority (dole) po nejvyšší (tramvaje navrchu) – bringToFront postupně
    routes.slice().sort(function (a, b) { return routePriority(b) - routePriority(a); })
      .forEach(function (r) {
        var grp = routeLine[r.id];
        if (grp && routeLayer.hasLayer(grp)) grp.eachLayer(function (ln) { if (ln.bringToFront) ln.bringToFront(); });
      });
  }

  // ── trasy ────────────────────────────────────────────────────────
  function drawRoutes(geojson) {
    (geojson.features || []).forEach(function (f) {
      var p = f.properties, rid = p.id;
      var grp = L.layerGroup();
      routeGeom[rid] = [];
      f.geometry.coordinates.forEach(function (line) {
        var latlngs = line.map(function (c) { return [c[1], c[0]]; });
        routeGeom[rid].push(latlngs);     // pro rozdělení trasy na projetou/zbývající
        L.polyline(latlngs, {
          color: rcolor(p.short_name, p.color), weight: W_BASE, opacity: OP_BASE,
          lineJoin: "round", lineCap: "round"
        }).on("click", function () { focusRoute(rid); }).addTo(grp);
      });
      routeLine[rid] = grp;
      grp.addTo(routeLayer);
    });
  }

  // ── zastávky ─────────────────────────────────────────────────────
  function drawStops() {
    stops.forEach(function (s) {
      // neviditelný větší terč pro snazší klik/tap (hlavně mobil); přidán první → vespod
      var hit = L.circleMarker([s.lat, s.lon], { radius: 10, stroke: false, fill: true, fillOpacity: 0 });
      hit.on("click", function () { focusStop(s.id); });
      hit.bindTooltip(s.name, { direction: "top" });
      hit.addTo(stopLayer);

      var m = L.circleMarker([s.lat, s.lon], stopStyle(false));
      m.on("click", function () { focusStop(s.id); });
      m.bindTooltip(s.name, { direction: "top" });
      stopMarker[s.id] = m;
      m.addTo(stopLayer);   // viditelná tečka navrch (kvůli hoveru/tooltipu a stylu)
    });
  }

  function stopStyle(hi) {
    return {
      radius: hi ? 6 : 4,
      color: hi ? "#c0392b" : "#37474f",
      weight: hi ? 2 : 1,
      fillColor: "#fff",
      fillOpacity: 1
    };
  }

  // ── seznam linek v panelu ────────────────────────────────────────
  function buildRouteList() {
    elRoutes.innerHTML = "";
    routes.forEach(function (r) {
      var li = document.createElement("li");
      li.dataset.id = r.id;
      li.dataset.type = r.type;
      li.dataset.legacy = r.legacy ? "1" : "0";
      li.dataset.category = r.category || "";
      li.dataset.search = (r.short_name + " " + r.long_name).toLowerCase();
      if (r.legacy) li.classList.add("ms-legacy");

      var b = document.createElement("span");
      b.className = "ms-badge";
      b.style.background = routeColor(r);
      b.textContent = r.short_name;

      var nm = document.createElement("span");
      nm.className = "ms-line-name";
      nm.textContent = r.long_name;

      li.appendChild(b);
      li.appendChild(nm);
      li.addEventListener("click", function () { focusRoute(r.id); });
      li.addEventListener("mouseenter", function () { hoverRoute(r.id, true); });
      li.addEventListener("mouseleave", function () { hoverRoute(r.id, false); });
      elRoutes.appendChild(li);
    });
  }

  function applyListFilter() {
    var items = elRoutes.querySelectorAll("li");
    Array.prototype.forEach.call(items, function (li) {
      var isLeg = li.dataset.legacy === "1";
      var okType;
      if (filter === "legacy") okType = isLeg && li.dataset.category !== "historicke";
      else if (filter === "historicke") okType = isLeg && li.dataset.category === "historicke";
      else okType = !isLeg && (filter === "all" || li.dataset.type === filter);
      var okQ = !query || li.dataset.search.indexOf(query) !== -1;
      li.classList.toggle("is-hidden", !(okType && okQ));
    });
  }

  // ── seznam zastávek v panelu (režim „Zastávky") ──────────────────
  function buildStopList() {
    if (!elStops) return;
    elStops.innerHTML = "";
    var all = stops.concat(vanishedStops).slice().sort(function (a, b) {
      return String(a.name).localeCompare(String(b.name), "cs");
    });
    all.forEach(function (s) {
      var li = document.createElement("li");
      li.className = "ms-stop-item" + (s.historical ? " ms-stop-hist" : "");
      li.dataset.search = ((s.name || "") + " " + (s.code || "")).toLowerCase();

      var nm = document.createElement("span");
      nm.className = "ms-line-name";
      nm.textContent = s.name;
      li.appendChild(nm);

      if (s.code) {
        var c = document.createElement("span");
        c.className = "ms-stop-count";
        c.textContent = s.code;        // kód zastávky (GTFS stop_code)
        li.appendChild(c);
      }

      li.addEventListener("click", function () { focusStop(s.id); });
      li.addEventListener("mouseenter", function () { previewStop(s.id, true); });
      li.addEventListener("mouseleave", function () { previewStop(s.id, false); });
      elStops.appendChild(li);
    });
  }

  function applyStopFilter() {
    if (!elStops) return;
    Array.prototype.forEach.call(elStops.querySelectorAll("li"), function (li) {
      var ok = !query || li.dataset.search.indexOf(query) !== -1;
      li.classList.toggle("is-hidden", !ok);
    });
  }

  // skrytí / zobrazení bočního panelu (víc místa pro mapu, hlavně na mobilu)
  function setSidebar(collapsed) {
    var layout = document.querySelector(".mapa-layout");
    if (!layout) return;
    layout.classList.toggle("ms-collapsed", collapsed);
    var hideBtn = document.getElementById("ms-collapse");
    var showBtn = document.getElementById("ms-expand");
    if (hideBtn) hideBtn.setAttribute("aria-expanded", String(!collapsed));
    if (showBtn) showBtn.setAttribute("aria-expanded", String(!collapsed));
    if (map) map.invalidateSize();   // Leaflet musí přepočítat velikost kontejneru
  }

  // přepnutí panelu mezi režimy Linky / Zastávky
  function setMode(m) {
    resetView();          // klik na tab z detailu (zastávka/spoj/linka) → zpět na přehled
    mode = m;
    Array.prototype.forEach.call(document.querySelectorAll(".ms-mode"), function (b) {
      b.classList.toggle("is-on", b.dataset.mode === m);
    });
    var isLines = m === "lines";
    if (elRoutes) elRoutes.hidden = !isLines;
    if (elStops) elStops.hidden = isLines;
    if (elToolbar) elToolbar.hidden = !isLines;
    if (elSearch) elSearch.placeholder = isLines
      ? (T.searchLines || "Hledat linku…") : (T.searchStops || "Hledat zastávku…");
    if (isLines) applyListFilter(); else applyStopFilter();
  }

  // zvýraznění tras podle filtru/focus
  function refreshRouteStyles() {
    routes.forEach(function (r) {
      var grp = routeLine[r.id];
      if (!grp) return;
      var emph = focusedRouteId === r.id || hoveredRouteId === r.id;

      if (r.legacy) {
        // legacy se kreslí jen ve svém filtru (Mimo provoz / Historické) nebo když je zvýrazněná
        var show = filter === legacyGroup(r) || emph;
        var has = routeLayer.hasLayer(grp);
        if (show && !has) grp.addTo(routeLayer);
        else if (!show && has) routeLayer.removeLayer(grp);
        if (!show) return;
      }

      // barevnost trasy:
      //  • zvýrazněná (hover v seznamu/na vozidle, focus) = barevně, má přednost
      //  • něco jiného zvýrazněné → ostatní šedě
      //  • jinak se provozní linky obarví jen při zapnutém přepínači „Barevné
      //    linky" (a respektuje typový filtr); legacy se barví ve svém filtru.
      //    Výchozí stav = šedá síť, ať vyniknou vozidla.
      var someFocus = !!(focusedRouteId || hoveredRouteId || focusedTripRouteId);
      var dim;
      if (emph) dim = false;
      else if (someFocus) dim = true;
      else if (r.legacy) dim = filter !== legacyGroup(r);
      else dim = !(colorLines && (filter === "all" || filter === r.type));

      var style = {
        color: dim ? DIM_COLOR : routeColor(r),
        weight: emph ? W_FOCUS : (dim ? W_DIM : W_BASE),
        opacity: emph ? 1 : (dim ? OP_DIM : OP_BASE)
      };
      // rozkliknutý spoj: běžnou čáru linky skryjeme – kreslí ji overlay
      // (projetá část čárkovaně, zbývající plně)
      if (!r.legacy && focusedTripRouteId === r.id) style = { opacity: 0, weight: 0 };
      var front = emph || (r.legacy && filter === "legacy");
      grp.eachLayer(function (ln) {
        ln.setStyle(style);
        if (front && ln.bringToFront) ln.bringToFront();
      });
    });
  }

  // hover v seznamu → dočasné zvýraznění trasy (bez přiblížení); přiblíží až klik
  function hoverRoute(rid, on) {
    hoveredRouteId = on ? rid : null;
    refreshRouteStyles();
  }

  // ── FOCUS: linka ─────────────────────────────────────────────────
  function focusRoute(rid) {
    var r = routeById[rid];
    if (!r) return;
    clearTrip();
    focusedRouteId = rid;
    focusedStopId = null;
    setStopPin(null);
    refreshRouteStyles();
    highlightStops(r.stops || []);
    renderRouteDetail(r);

    var grp = routeLine[rid];
    if (grp) {
      var b = L.latLngBounds([]);
      grp.eachLayer(function (ln) { b.extend(ln.getBounds()); });
      if (b.isValid()) map.fitBounds(b, { padding: [40, 40] });
    }
  }

  function renderRouteDetail(r) {
    var col = routeColor(r);
    var stopsSection = "";
    if (r.legacy) {
      // legacy: seznam zastávek z DB (názvy v GTFS jsou klikací, ostatní ne)
      var lnames = LEGACY_STOPS[r.short_name] || [];
      if (lnames.length) {
        var lh = lnames.map(function (raw) {
          var rr = resolveStopName(raw);
          if (rr) {
            return '<li data-stop="' + rr.id + '" class="' + (rr.historical ? "ms-stop-hist" : "") +
                   '" style="border-left-color:' + col + '">' + esc(rr.display) + "</li>";
          }
          return '<li class="ms-stop-noclick" style="border-left-color:' + col + '">' + esc(raw) + "</li>";
        }).join("");
        stopsSection = "<h3>" + (T.lineStops || "Zastávky linky") + " (" + lnames.length + ")</h3>" +
                       '<ul class="ms-stoplist">' + lh + "</ul>";
      }
    } else {
      var renderUL = function (ids) {
        var html = (ids || []).map(function (sid) {
          var s = stopById[sid];
          if (!s) return "";
          return '<li data-stop="' + s.id + '" style="border-left-color:' + col + '">' +
                 esc(s.name) + "</li>";
        }).join("");
        return '<ul class="ms-stoplist">' + html + "</ul>";
      };
      if (r.directions && r.directions.length >= 2) {
        // odlišné směry (jednosměrné zastávky / závleky) → přepínač směru + jeden seznam
        var sw = r.directions.map(function (d, di) {
          return '<button type="button" class="ms-dirbtn' + (di === 0 ? " is-on" : "") +
                 '" data-dir="' + di + '">&rarr; ' + esc(d.headsign || "") + "</button>";
        }).join("");
        var blocks = r.directions.map(function (d, di) {
          return '<div class="ms-dir' + (di === 0 ? "" : " is-hidden") + '" data-dir="' + di + '">' +
                 renderUL(d.stops) + "</div>";
        }).join("");
        stopsSection = "<h3>" + (T.lineStops || "Zastávky linky") + "</h3>" +
                       '<div class="ms-dirs"><div class="ms-dirswitch">' + sw + "</div>" + blocks + "</div>";
      } else {
        stopsSection = "<h3>" + (T.lineStops || "Zastávky linky") + " (" + (r.stops || []).length + ")</h3>" +
                       renderUL(r.stops);
      }
    }

    var detailUrl = BASE + "/?linka=" + encodeURIComponent(r.short_name) +
                    (JA ? "&ja=" + encodeURIComponent(JA) : "") + "#prehled";

    // nadpis: legacy → "Linka XX (trvale mimo provoz)"; jinak "Kategorie [číslo v rámečku]"
    var headerHtml;
    if (r.legacy) {
      var tpl = r.category === "historicke"
        ? (T.historicTitle || "Historická linka %s")
        : (T.legacyTitle || "Linka %s (trvale mimo provoz)");
      headerHtml = "<h2>" + esc(tpl.replace("%s", r.short_name)) + "</h2>";
    } else {
      var cat = TILE_CATS[r.short_name] || (r.type === "tram" ? (T.tram || "Tram") : (T.bus || "Bus"));
      headerHtml = "<h2>" + esc(cat) +
        ' <span class="ms-badge" style="background:' + col + '">' + esc(r.short_name) + "</span></h2>";
    }

    elDetail.innerHTML =
      backBtn() +
      headerHtml +
      '<p class="ms-sub">' + esc(r.long_name) + "</p>" +
      (r.approximate ? '<p class="ms-legacy-note">' + (T.legacyNote || "Trasa je přibližná.") + "</p>" : "") +
      '<a class="ms-detaillink" href="' + esc(detailUrl) + '">' +
        (T.detailLink || "Detail a historie linky") + " &rarr;</a>" +
      stopsSection;

    bindDetail();
    showDetail(true);
  }

  // ── FOCUS: zastávka ──────────────────────────────────────────────
  function focusStop(sid) {
    var s = stopById[sid];
    if (!s) return;
    clearTrip();
    focusedRouteId = null;
    focusedStopId = sid;
    refreshRouteStyles();
    highlightStops([sid]);
    setStopPin(sid);

    var inner;
    if (s.historical) {
      inner = "<h2>" + esc(s.name) + "</h2>" +
              '<p class="ms-sub ms-stop-hist">' + (T.formerStop || "zaniklá zastávka") + "</p>";
    } else {
      var chips = (s.routes || []).map(function (sn) {
        var r = routeByShort[sn];
        var color = r ? routeColor(r) : "#666";
        var rid = r ? r.id : "";
        return '<span class="ms-badge" style="background:' + color + '" data-route="' + rid + '">' + esc(sn) + "</span>";
      }).join("");
      var meta = [];
      if (s.code) meta.push("#" + esc(s.code));
      if (s.zone) meta.push((T.zone || "Zóna") + " " + esc(s.zone));
      meta.push((T.wheelchair || "Bezbariérová") + ": " + wheel(s.wheelchair));
      inner = "<h2>" + esc(s.name) + "</h2>" +
              '<p class="ms-sub">' + meta.join(" · ") + "</p>" +
              "<h3>" + (T.linesHere || "Linky v zastávce") + "</h3>" +
              '<div class="ms-linechips">' + chips + "</div>" +
              '<div id="ms-dep-wrap">' + departuresHtml(s) + "</div>";
    }
    elDetail.innerHTML = backBtn() + inner;

    bindDetail();
    showDetail(true);
    map.setView([s.lat, s.lon], Math.max(map.getZoom(), 15));
  }

  function wheel(v) {
    if (v === "1") return T.yes || "ano";
    if (v === "2") return T.no || "ne";
    return T.unknown || "neznámo";
  }

  // ── highlight zastávek na mapě ───────────────────────────────────
  function highlightStops(ids) {
    var set = {};
    ids.forEach(function (i) { set[i] = true; });
    stops.forEach(function (s) {
      var m = stopMarker[s.id];
      if (m) m.setStyle(stopStyle(!!set[s.id]));
    });
  }

  // výrazné označení polohy zastávky (velké kolečko); sid=null značku schová
  function setStopPin(sid) {
    var s = sid && stopById[sid];
    if (!s) {
      if (stopPin && map.hasLayer(stopPin)) map.removeLayer(stopPin);
      return;
    }
    var ll = [s.lat, s.lon];
    if (!stopPin) {
      stopPin = L.circleMarker(ll, {
        radius: 13, color: "#c0392b", weight: 3.5,
        fillColor: "#c0392b", fillOpacity: 0.2, interactive: false
      }).addTo(map);
      stopPin.bindTooltip("", { direction: "top" });   // pro zaniklé zast. (nemají vlastní marker)
    } else {
      stopPin.setLatLng(ll);
      if (!map.hasLayer(stopPin)) stopPin.addTo(map);
    }
    stopPin.setTooltipContent(s.name);
    if (stopPin.bringToFront) stopPin.bringToFront();
  }

  // hover zastávky (v seznamu / detailu): dočasně ukáž špendlík; po odjetí
  // vrať na vybranou zastávku (nebo schovej)
  function previewStop(sid, on) {
    setStopPin(on ? sid : focusedStopId);
    var m = stopMarker[sid];               // ukaž i název zastávky (hover v seznamu)
    if (m) { if (on) m.openTooltip(); else m.closeTooltip(); }
    else if (stopPin) {                    // zaniklé zastávky nemají marker → název přes špendlík
      if (on) stopPin.openTooltip(); else stopPin.closeTooltip();
    }
  }

  // ── přepínání panelu detail / browse ─────────────────────────────
  function showDetail(on) {
    elDetail.hidden = !on;
    elBrowse.hidden = on;
  }

  function resetView() {
    clearTrip();
    focusedRouteId = null;
    focusedStopId = null;
    showDetail(false);
    refreshRouteStyles();
    applyZOrder();
    highlightStops([]);
    setStopPin(null);
  }

  function backBtn() {
    return '<button type="button" class="ms-back">&larr; ' + (T.back || "Zpět") + "</button>";
  }

  function bindDetail() {
    var back = elDetail.querySelector(".ms-back");
    if (back) back.addEventListener("click", resetView);
    Array.prototype.forEach.call(elDetail.querySelectorAll("[data-route]"), function (el) {
      el.addEventListener("click", function () {
        var id = el.getAttribute("data-route");
        if (id) focusRoute(id);
      });
    });
    Array.prototype.forEach.call(elDetail.querySelectorAll("[data-stop]"), function (el) {
      var sid = el.getAttribute("data-stop");
      el.addEventListener("click", function () { focusStop(sid); });
      el.addEventListener("mouseenter", function () { previewStop(sid, true); });
      el.addEventListener("mouseleave", function () { previewStop(sid, false); });
    });
    // přepínač směru výpisu zastávek (detail linky)
    Array.prototype.forEach.call(elDetail.querySelectorAll(".ms-dirbtn"), function (btn) {
      btn.addEventListener("click", function () {
        var wrap = btn.parentNode.parentNode, dir = btn.getAttribute("data-dir");
        Array.prototype.forEach.call(wrap.querySelectorAll(".ms-dirbtn"), function (b) { b.classList.toggle("is-on", b === btn); });
        Array.prototype.forEach.call(wrap.querySelectorAll(".ms-dir"), function (d) {
          d.classList.toggle("is-hidden", d.getAttribute("data-dir") !== dir);
        });
      });
    });
  }

  // ── UI události ──────────────────────────────────────────────────
  function bindUI() {
    Array.prototype.forEach.call(document.querySelectorAll(".ms-chip"), function (btn) {
      btn.addEventListener("click", function () {
        filter = btn.dataset.filter;
        Array.prototype.forEach.call(document.querySelectorAll(".ms-chip"), function (b) {
          b.classList.toggle("is-on", b === btn);
        });
        applyListFilter();
        refreshRouteStyles();
        refreshVehicles();          // vozidla respektují filtr tram/bus/provozní
      });
    });

    Array.prototype.forEach.call(document.querySelectorAll(".ms-mode"), function (btn) {
      btn.addEventListener("click", function () { setMode(btn.dataset.mode); });
    });

    var elCollapse = document.getElementById("ms-collapse");
    if (elCollapse) elCollapse.addEventListener("click", function () { setSidebar(true); });
    var elExpand = document.getElementById("ms-expand");
    if (elExpand) elExpand.addEventListener("click", function () { setSidebar(false); });

    if (elSearch) {
      elSearch.addEventListener("input", function () {
        query = elSearch.value.trim().toLowerCase();
        if (mode === "lines") applyListFilter(); else applyStopFilter();
      });
    }
  }

  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  }
})();
