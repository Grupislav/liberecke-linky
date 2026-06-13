/* ====================================================================
   Doplňky detailu linky na hlavním webu (záložka Přehled), ze stejných
   GTFS dat jako interaktivní mapa:
     1) náhled trasy  – .line-map-preview[data-linka]  → celá síť + linka
     2) seznam zastávek – .line-stops[data-linka]      → dynamicky z dat
   Podporuje:
     • aliasy mimo-provoz linek (161→16) – pro náhled,
     • linky mimo provoz z legacy-routes.json – přibližná (čárkovaná) trasa
       po zastávkách a přesný seznam zastávek.
   Bez JS / bez dat zůstává funkční vše, co je v HTML (odkaz, obsah z DB).
   ==================================================================== */
(function () {
  "use strict";

  var previews = document.querySelectorAll(".line-map-preview[data-linka]");
  var stopLists = document.querySelectorAll(".line-stops[data-linka]");
  if (!previews.length && !stopLists.length) return;

  var BASE = (window.MAPA && window.MAPA.base) || "";
  var JA = (window.MAPA && window.MAPA.ja) || "";
  var ALIASES = (window.MAPA && window.MAPA.aliases) || {};
  var TILE_COLORS = (window.MAPA && window.MAPA.tileColors) || {};   // barvy linek dle dlaždic (shoda s velkou mapou)
  var SVG_NS = "http://www.w3.org/2000/svg";
  var W = 500, H = 333, PAD = 14;

  function getJSON(name, fallback) {
    return fetch(BASE + "/mapa-assets/data/" + name, { credentials: "same-origin" })
      .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
      .catch(function () { return fallback; });
  }
  function alias(x) { return ALIASES[x] || x; }
  function norm(s) { return String(s == null ? "" : s).trim().toLowerCase().replace(/\s+/g, " "); }
  function cleanStopName(s) {
    return String(s == null ? "" : s).replace(/\s*\([^)]*\)\s*$/, "").replace(/\s*[↑↓→←⇑⇓]\s*$/, "").trim();
  }
  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  }

  Promise.all([
    previews.length ? getJSON("shapes.json", null) : Promise.resolve(null),
    stopLists.length ? getJSON("routes.json", null) : Promise.resolve(null),
    getJSON("stops.json", null),
    getJSON("legacy-routes.json", []),
    previews.length ? getJSON("legacy-shapes.json", {}) : Promise.resolve({})
  ]).then(function (res) {
    var shapesGeo = res[0], routes = res[1], stops = res[2], legacy = res[3] || [], legacyShapes = res[4] || {};

    var stopById = {}, stopByName = {};
    (stops || []).forEach(function (s) { stopById[s.id] = s; stopByName[norm(s.name)] = s; });
    var legacyByShort = {};
    legacy.forEach(function (lr) { if (lr && lr.short_name) legacyByShort[lr.short_name] = lr; });

    if (previews.length) renderPreviews(shapesGeo, stopByName, legacyByShort, legacyShapes);
    if (stopLists.length) renderStopLists(routes, stopById, stopByName);
  });

  // ── náhledy trasy ──────────────────────────────────────────────────
  function renderPreviews(shapesGeo, stopByName, legacyByShort, legacyShapes) {
    var feats = ((shapesGeo && shapesGeo.features) || []).filter(function (f) {
      return f.geometry && f.geometry.coordinates && f.geometry.coordinates.length;
    });
    if (!feats.length) return;

    var bb = bbox(feats);
    var kx = Math.cos(((bb.minY + bb.maxY) / 2) * Math.PI / 180);
    var spanX = Math.max((bb.maxX - bb.minX) * kx, 1e-6);
    var spanY = Math.max(bb.maxY - bb.minY, 1e-6);
    var s = Math.min((W - 2 * PAD) / spanX, (H - 2 * PAD) / spanY);
    var offX = (W - spanX * s) / 2, offY = (H - spanY * s) / 2;
    var proj = {
      x: function (c) { return offX + (c[0] - bb.minX) * kx * s; },
      y: function (c) { return offY + (bb.maxY - c[1]) * s; }
    };

    Array.prototype.forEach.call(previews, function (a) {
      var sel = alias(a.getAttribute("data-linka"));
      if (feats.some(function (f) { return f.properties.short_name === sel; })) {
        renderSvg(a, feats, proj, { kind: "shape", sel: sel });
        return;
      }
      var lr = legacyByShort[sel] || legacyByShort[a.getAttribute("data-linka")];
      if (lr) {
        // sešitá trasa po ulicích (legacy-shapes.json), jinak spojnice zastávek
        var coords = legacyShapes[lr.short_name];
        if (!(coords && coords.length >= 2)) {
          coords = (lr.stops || []).map(function (e) {
            var name = (e && typeof e === "object") ? e.name : e;
            var st = stopByName[norm(name)];
            if (st) return [st.lon, st.lat];
            if (e && typeof e === "object" && e.lat != null && e.lon != null) return [e.lon, e.lat];
            return null;
          }).filter(Boolean);
        }
        if (coords && coords.length >= 2) {
          var color = lr.type === "tram" ? "#cc2900" : "#007db3";
          renderSvg(a, feats, proj, { kind: "legacy", coords: coords, color: color });
        }
      }
    });
  }

  // ── seznam zastávek ────────────────────────────────────────────────
  function renderStopLists(routes, stopById, stopByName) {
    var routeByShort = {};
    (routes || []).forEach(function (r) { routeByShort[r.short_name] = r; });

    // vykreslí seznam názvů; názvy s protějškem v GTFS jsou odkazem na mapu
    function renderList(items) {
      return "<ul class='ls-list'>" + items.map(function (raw) {
        var clean = cleanStopName(raw);
        if (stopByName[norm(clean)]) {
          var href = BASE + "/mapa?zastavka=" + encodeURIComponent(clean) + (JA ? "&ja=" + encodeURIComponent(JA) : "");
          return "<li><a class='ls-stop' href=\"" + esc(href) + "\">" + esc(raw) + "</a></li>";
        }
        return "<li>" + esc(raw) + "</li>";
      }).join("") + "</ul>";
    }

    Array.prototype.forEach.call(stopLists, function (el) {
      var short = el.getAttribute("data-linka");
      if (routeByShort[short]) {
        // aktuální linka: seznam z GTFS
        var names = (routeByShort[short].stops || []).map(function (sid) {
          return stopById[sid] && stopById[sid].name;
        }).filter(Boolean);
        if (names.length) el.innerHTML = renderList(names);
        return;
      }
      // linka mimo provoz: přestav seznam z DB obsahu (<li>), odkazy kde název v GTFS
      var lis = el.querySelectorAll("li");
      if (!lis.length) return;
      var raws = Array.prototype.map.call(lis, function (li) { return li.textContent.trim(); }).filter(Boolean);
      if (raws.length) el.innerHTML = renderList(raws);
    });
  }

  // ── pomocné ────────────────────────────────────────────────────────
  function bbox(feats) {
    var b = { minX: Infinity, minY: Infinity, maxX: -Infinity, maxY: -Infinity };
    feats.forEach(function (f) {
      f.geometry.coordinates.forEach(function (ln) {
        ln.forEach(function (c) {
          if (c[0] < b.minX) b.minX = c[0];
          if (c[0] > b.maxX) b.maxX = c[0];
          if (c[1] < b.minY) b.minY = c[1];
          if (c[1] > b.maxY) b.maxY = c[1];
        });
      });
    });
    return b;
  }

  function polyline(ln, proj, color, width, opacity, dash) {
    if (ln.length < 2) return null;
    var pts = ln.map(function (c) { return proj.x(c).toFixed(1) + "," + proj.y(c).toFixed(1); }).join(" ");
    var pl = document.createElementNS(SVG_NS, "polyline");
    pl.setAttribute("points", pts);
    pl.setAttribute("fill", "none");
    pl.setAttribute("stroke", color);
    pl.setAttribute("stroke-width", String(width));
    pl.setAttribute("stroke-linejoin", "round");
    pl.setAttribute("stroke-linecap", "round");
    if (opacity != null) pl.setAttribute("stroke-opacity", String(opacity));
    if (dash) pl.setAttribute("stroke-dasharray", dash);
    return pl;
  }

  function renderSvg(a, feats, proj, hi) {
    var svg = document.createElementNS(SVG_NS, "svg");
    svg.setAttribute("class", "lmp-svg");
    svg.setAttribute("viewBox", "0 0 " + W + " " + H);
    svg.setAttribute("preserveAspectRatio", "xMidYMid meet");
    svg.setAttribute("aria-hidden", "true");

    // celá síť světle šedě (u shape highlightu vynech vybranou – nakreslí se navrch)
    feats.forEach(function (f) {
      if (hi.kind === "shape" && f.properties.short_name === hi.sel) return;
      f.geometry.coordinates.forEach(function (ln) {
        var pl = polyline(ln, proj, "#c4ccd3", 1, 0.55);
        if (pl) svg.appendChild(pl);
      });
    });

    if (hi.kind === "shape") {
      feats.filter(function (f) { return f.properties.short_name === hi.sel; }).forEach(function (f) {
        var color = TILE_COLORS[f.properties.short_name] || f.properties.color || "#0078c8";
        f.geometry.coordinates.forEach(function (ln) {
          var pl = polyline(ln, proj, color, 3.5, 1);
          if (pl) svg.appendChild(pl);
        });
      });
    } else { // legacy – čárkovaná spojnice zastávek
      var pl = polyline(hi.coords, proj, hi.color, 3.5, 1, "7 6");
      if (pl) svg.appendChild(pl);
    }

    a.insertBefore(svg, a.firstChild);
    a.classList.add("is-ready");
  }
})();
