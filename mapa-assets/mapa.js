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
  var ZPRIO = (window.MAPA && window.MAPA.tilePriority) || {};
  var ALIASES = (window.MAPA && window.MAPA.aliases) || {};
  var DATA = BASE + "/mapa-assets/data/";

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
  var DIM_COLOR = "#c4ccd3";   // ostatní linky při zvýraznění/filtru – světle šedá

  // ── stav ─────────────────────────────────────────────────────────
  var map, baseLayer, routeLayer, stopLayer;
  var routes = [], stops = [];
  var routeById = {}, routeByShort = {}, stopById = {}, stopByName = {};
  var routeLine = {};            // route id -> L.LayerGroup (polylines)
  var stopMarker = {};           // stop id  -> L.CircleMarker
  var focusedRouteId = null;
  var filter = "all";            // all | tram | bus
  var query = "";
  var mode = "lines";            // lines | stops

  var elMap = document.getElementById("mapa");
  var elRoutes = document.getElementById("ms-routes");
  var elStops = document.getElementById("ms-stops");
  var elToolbar = document.querySelector("#ms-browse .ms-toolbar");
  var elDetail = document.getElementById("ms-detail");
  var elBrowse = document.getElementById("ms-browse");
  var elSearch = document.getElementById("ms-search-input");
  var elMeta = document.getElementById("ms-meta");

  // ── načtení dat ──────────────────────────────────────────────────
  function getJSON(name) {
    return fetch(DATA + name, { credentials: "same-origin" }).then(function (r) {
      if (!r.ok) throw new Error(name + " " + r.status);
      return r.json();
    });
  }

  Promise.all([
    getJSON("routes.json"),
    getJSON("stops.json"),
    getJSON("shapes.json"),
    getJSON("meta.json").catch(function () { return null; }),
    getJSON("legacy-routes.json").catch(function () { return []; })
  ]).then(function (res) {
    routes = res[0]; stops = res[1];
    init(res[2], res[3], res[4]);
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
  function init(shapes, meta, legacy) {
    routes.forEach(function (r) { routeById[r.id] = r; routeByShort[r.short_name] = r; });
    stops.forEach(function (s) { stopById[s.id] = s; stopByName[norm(s.name)] = s; });

    map = L.map(elMap, { preferCanvas: true, zoomControl: true })
            .setView(LIBEREC, 12);
    baseLayer = L.tileLayer(TILE.url, TILE.options).addTo(map);

    routeLayer = L.layerGroup().addTo(map);
    stopLayer = L.layerGroup().addTo(map);

    drawRoutes(shapes);
    drawStops();
    addLegacyRoutes(legacy);   // linky mimo provoz z legacy-routes.json (přibližná trasa po zastávkách)
    applyZOrder();             // pořadí vrstev dle kategorie (tramvaje navrchu … mimo provoz dole)
    buildRouteList();
    buildStopList();
    bindUI();
    applyDeepLink();

    if (meta && meta.valid_from && elMeta) {
      elMeta.textContent = fmtDate(meta.valid_from) + " – " + fmtDate(meta.valid_to);
    }
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

  // ── linky mimo provoz (legacy-routes.json) ───────────────────────
  // Trasa se sestaví spojnicí zastávek (dle názvů → stops.json), kreslí se
  // čárkovaně jako „přibližná". Nemají vlastní GTFS geometrii.
  function addLegacyRoutes(legacy) {
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
      var color = lr.type === "tram" ? "#cc2900" : "#007db3";
      var r = {
        id: rid, short_name: lr.short_name, long_name: lr.long_name || "",
        type: lr.type === "tram" ? "tram" : "bus", color: color,
        stops: ids, stopNames: names, legacy: true
      };
      routes.push(r);
      routeById[rid] = r;
      if (!routeByShort[lr.short_name]) routeByShort[lr.short_name] = r;

      var grp = L.layerGroup();
      L.polyline(latlngs, {
        color: color, weight: W_BASE, opacity: OP_BASE,
        dashArray: "6 7", lineJoin: "round", lineCap: "round"
      }).on("click", function () { focusRoute(rid); }).addTo(grp);
      routeLine[rid] = grp;
      grp.addTo(routeLayer);
    });
  }

  // pořadí vrstev podle kategorie (nižší priorita = výš); řeší překryvy barev
  function routePriority(r) {
    if (r.legacy) return 6;
    var p = ZPRIO[r.short_name];
    if (p != null) return p;
    return r.type === "tram" ? 1 : 2;
  }
  function applyZOrder() {
    // od nejnižší priority (dole) po nejvyšší (tramvaje navrchu) – bringToFront postupně
    routes.slice().sort(function (a, b) { return routePriority(b) - routePriority(a); })
      .forEach(function (r) {
        var grp = routeLine[r.id];
        if (grp) grp.eachLayer(function (ln) { if (ln.bringToFront) ln.bringToFront(); });
      });
  }

  // ── trasy ────────────────────────────────────────────────────────
  function drawRoutes(geojson) {
    (geojson.features || []).forEach(function (f) {
      var p = f.properties, rid = p.id;
      var grp = L.layerGroup();
      f.geometry.coordinates.forEach(function (line) {
        var latlngs = line.map(function (c) { return [c[1], c[0]]; });
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
      var m = L.circleMarker([s.lat, s.lon], stopStyle(false));
      m.on("click", function () { focusStop(s.id); });
      m.bindTooltip(s.name, { direction: "top" });
      stopMarker[s.id] = m;
      m.addTo(stopLayer);
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
      var okType = filter === "all" || li.dataset.type === filter;
      var okQ = !query || li.dataset.search.indexOf(query) !== -1;
      li.classList.toggle("is-hidden", !(okType && okQ));
    });
  }

  // ── seznam zastávek v panelu (režim „Zastávky") ──────────────────
  function buildStopList() {
    if (!elStops) return;
    elStops.innerHTML = "";
    stops.forEach(function (s) {
      var li = document.createElement("li");
      li.className = "ms-stop-item";
      li.dataset.search = (s.name || "").toLowerCase();

      var nm = document.createElement("span");
      nm.className = "ms-line-name";
      nm.textContent = s.name;
      li.appendChild(nm);

      var cnt = (s.routes && s.routes.length) || 0;
      if (cnt) {
        var c = document.createElement("span");
        c.className = "ms-stop-count";
        c.textContent = cnt + "×";
        li.appendChild(c);
      }
      li.addEventListener("click", function () { focusStop(s.id); });
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

  // přepnutí panelu mezi režimy Linky / Zastávky
  function setMode(m) {
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
      var visibleByType = filter === "all" || r.type === filter;
      var focused = focusedRouteId === r.id;
      var dim = focusedRouteId ? !focused : !visibleByType;
      var style = {
        color: dim ? DIM_COLOR : routeColor(r),
        weight: focused ? W_FOCUS : (dim ? W_DIM : W_BASE),
        opacity: focused ? 1 : (dim ? OP_DIM : OP_BASE)
      };
      grp.eachLayer(function (ln) {
        ln.setStyle(style);
        if (focused && ln.bringToFront) ln.bringToFront();
      });
    });
  }

  // hover v seznamu → dočasné zvýraznění trasy (bez přiblížení); přiblíží až klik
  function hoverRoute(rid, on) {
    var r = routeById[rid];
    var grp = routeLine[rid];
    if (!grp || !r) return;
    if (on) {
      grp.eachLayer(function (ln) {
        ln.setStyle({ color: routeColor(r), weight: W_FOCUS, opacity: 1 });
        if (ln.bringToFront) ln.bringToFront();
      });
    } else {
      refreshRouteStyles();
      if (focusedRouteId && routeLine[focusedRouteId]) {
        routeLine[focusedRouteId].eachLayer(function (ln) {
          if (ln.bringToFront) ln.bringToFront();
        });
      }
    }
  }

  // ── FOCUS: linka ─────────────────────────────────────────────────
  function focusRoute(rid) {
    var r = routeById[rid];
    if (!r) return;
    focusedRouteId = rid;
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
    var stopsHtml, stopCount;
    if (r.legacy) {
      // legacy: vykreslíme názvy (mohou zahrnovat i zastávky mimo síť bez markeru)
      var lnames = r.stopNames || [];
      stopCount = lnames.length;
      stopsHtml = lnames.map(function (n) {
        return '<li style="border-left-color:' + col + '">' + esc(n) + "</li>";
      }).join("");
    } else {
      stopCount = (r.stops || []).length;
      stopsHtml = (r.stops || []).map(function (sid) {
        var s = stopById[sid];
        if (!s) return "";
        return '<li data-stop="' + s.id + '" style="border-left-color:' + col + '">' +
               esc(s.name) + "</li>";
      }).join("");
    }

    var detailUrl = BASE + "/?linka=" + encodeURIComponent(r.short_name) +
                    (JA ? "&ja=" + encodeURIComponent(JA) : "") + "#prehled";

    elDetail.innerHTML =
      backBtn() +
      '<h2><span class="ms-badge" style="background:' + col + '">' + esc(r.short_name) + "</span> " +
      (r.type === "tram" ? T.tram || "Tram" : T.bus || "Bus") + "</h2>" +
      '<p class="ms-sub">' + esc(r.long_name) + "</p>" +
      (r.legacy ? '<p class="ms-legacy-note">' + (T.legacyNote || "Trasa je přibližná – linka je mimo provoz.") + "</p>" : "") +
      '<a class="ms-detaillink" href="' + esc(detailUrl) + '">' +
        (T.detailLink || "Detail a historie linky") + " &rarr;</a>" +
      "<h3>" + (T.lineStops || "Zastávky linky") + " (" + stopCount + ")</h3>" +
      '<ul class="ms-stoplist">' + stopsHtml + "</ul>";

    bindDetail();
    showDetail(true);
  }

  // ── FOCUS: zastávka ──────────────────────────────────────────────
  function focusStop(sid) {
    var s = stopById[sid];
    if (!s) return;
    focusedRouteId = null;
    refreshRouteStyles();
    highlightStops([sid]);

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

    elDetail.innerHTML =
      backBtn() +
      "<h2>" + esc(s.name) + "</h2>" +
      '<p class="ms-sub">' + meta.join(" · ") + "</p>" +
      "<h3>" + (T.linesHere || "Linky v zastávce") + "</h3>" +
      '<div class="ms-linechips">' + chips + "</div>";

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

  // ── přepínání panelu detail / browse ─────────────────────────────
  function showDetail(on) {
    elDetail.hidden = !on;
    elBrowse.hidden = on;
  }

  function resetView() {
    focusedRouteId = null;
    showDetail(false);
    refreshRouteStyles();
    applyZOrder();
    highlightStops([]);
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
      el.addEventListener("click", function () { focusStop(el.getAttribute("data-stop")); });
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
        if (!focusedRouteId) refreshRouteStyles();
      });
    });

    Array.prototype.forEach.call(document.querySelectorAll(".ms-mode"), function (btn) {
      btn.addEventListener("click", function () { setMode(btn.dataset.mode); });
    });

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
