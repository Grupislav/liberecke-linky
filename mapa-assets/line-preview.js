/* ====================================================================
   Doplňky detailu linky na hlavním webu (záložka Přehled), ze stejných
   GTFS dat jako interaktivní mapa:
     1) náhled trasy  – .line-map-preview[data-linka]  → celá síť + linka
     2) seznam zastávek – .line-stops[data-linka]      → dynamicky z GTFS
   Náhled respektuje aliasy mimo-provoz linek (161→16). Seznam zastávek
   alias NEpoužívá – u linek bez GTFS dat zůstane původní obsah z DB.
   Bez JS / bez dat zůstává funkční vše, co je v HTML.
   ==================================================================== */
(function () {
  "use strict";

  var previews = document.querySelectorAll(".line-map-preview[data-linka]");
  var stopLists = document.querySelectorAll(".line-stops[data-linka]");
  if (!previews.length && !stopLists.length) return;

  var BASE = (window.MAPA && window.MAPA.base) || "";
  var ALIASES = (window.MAPA && window.MAPA.aliases) || {};
  var SVG_NS = "http://www.w3.org/2000/svg";
  var W = 500, H = 333, PAD = 14;

  function getJSON(name) {
    return fetch(BASE + "/mapa-assets/data/" + name, { credentials: "same-origin" })
      .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); });
  }
  function alias(x) { return ALIASES[x] || x; }
  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  }

  // ── 1) náhledy trasy (shapes.json) ─────────────────────────────────
  if (previews.length) {
    getJSON("shapes.json").then(function (geo) {
      var feats = (geo.features || []).filter(function (f) {
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
          renderSvg(a, feats, sel, proj);
        }
      });
    }).catch(function () { /* fallback: textový odkaz zůstává */ });
  }

  // ── 2) seznam zastávek (routes.json + stops.json) ──────────────────
  if (stopLists.length) {
    Promise.all([getJSON("routes.json"), getJSON("stops.json")]).then(function (res) {
      var routeByShort = {}, stopById = {};
      res[0].forEach(function (r) { routeByShort[r.short_name] = r; });
      res[1].forEach(function (s) { stopById[s.id] = s; });

      Array.prototype.forEach.call(stopLists, function (el) {
        var r = routeByShort[el.getAttribute("data-linka")];   // bez aliasu
        if (!r || !r.stops || !r.stops.length) return;          // necháme obsah z DB
        var items = r.stops.map(function (sid) {
          var s = stopById[sid];
          return s ? "<li>" + esc(s.name) + "</li>" : "";
        }).join("");
        if (items) el.innerHTML = "<ol class='ls-list'>" + items + "</ol>";
      });
    }).catch(function () { /* fallback: obsah z DB zůstává */ });
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

  function polyline(ln, proj, color, width, opacity) {
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
    return pl;
  }

  function renderSvg(a, feats, sel, proj) {
    var svg = document.createElementNS(SVG_NS, "svg");
    svg.setAttribute("class", "lmp-svg");
    svg.setAttribute("viewBox", "0 0 " + W + " " + H);
    svg.setAttribute("preserveAspectRatio", "xMidYMid meet");
    svg.setAttribute("aria-hidden", "true");

    feats.forEach(function (f) {
      if (f.properties.short_name === sel) return;
      f.geometry.coordinates.forEach(function (ln) {
        var pl = polyline(ln, proj, "#c4ccd3", 1, 0.55);
        if (pl) svg.appendChild(pl);
      });
    });
    feats.filter(function (f) { return f.properties.short_name === sel; }).forEach(function (f) {
      var color = f.properties.color || "#0078c8";
      f.geometry.coordinates.forEach(function (ln) {
        var pl = polyline(ln, proj, color, 3.5, 1);
        if (pl) svg.appendChild(pl);
      });
    });

    a.insertBefore(svg, a.firstChild);
    a.classList.add("is-ready");
  }
})();
