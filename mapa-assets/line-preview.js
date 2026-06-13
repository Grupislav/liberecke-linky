/* ====================================================================
   Náhled trasy linky na hlavním webu (záložka Přehled)
   Vykreslí celou síť MHD světle šedě a vybranou linku zvýrazní její
   barvou – vynikne tak poloha linky v rámci sítě. Inline SVG ze stejného
   mapa-assets/data/shapes.json jako interaktivní mapa.
   Klik vede na /mapa?linka=X. Bez JS / bez dat zůstává funkční odkaz.
   ==================================================================== */
(function () {
  "use strict";

  var nodes = document.querySelectorAll(".line-map-preview[data-linka]");
  if (!nodes.length) return;

  var BASE = (window.MAPA && window.MAPA.base) || "";
  var SVG_NS = "http://www.w3.org/2000/svg";
  var W = 500, H = 333, PAD = 14;

  fetch(BASE + "/mapa-assets/data/shapes.json", { credentials: "same-origin" })
    .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
    .then(function (geo) {
      var feats = (geo.features || []).filter(function (f) {
        return f.geometry && f.geometry.coordinates && f.geometry.coordinates.length;
      });
      if (!feats.length) return;

      // globální bbox přes celou síť + projekce (jednotná pro všechny náhledy)
      var bb = bbox(feats);
      var kx = Math.cos(((bb.minY + bb.maxY) / 2) * Math.PI / 180);
      var spanX = Math.max((bb.maxX - bb.minX) * kx, 1e-6);
      var spanY = Math.max(bb.maxY - bb.minY, 1e-6);
      var s = Math.min((W - 2 * PAD) / spanX, (H - 2 * PAD) / spanY);
      var offX = (W - spanX * s) / 2;
      var offY = (H - spanY * s) / 2;
      var proj = {
        x: function (c) { return offX + (c[0] - bb.minX) * kx * s; },
        y: function (c) { return offY + (bb.maxY - c[1]) * s; }
      };

      Array.prototype.forEach.call(nodes, function (a) {
        var sel = a.getAttribute("data-linka");
        // vykresli jen když vybranou linku v datech opravdu máme
        if (feats.some(function (f) { return f.properties.short_name === sel; })) {
          renderSvg(a, feats, sel, proj);
        }
      });
    })
    .catch(function () { /* ponecháme textový fallback + odkaz */ });

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

    // 1) celá síť světle šedě
    feats.forEach(function (f) {
      if (f.properties.short_name === sel) return;
      f.geometry.coordinates.forEach(function (ln) {
        var pl = polyline(ln, proj, "#c4ccd3", 1, 0.55);
        if (pl) svg.appendChild(pl);
      });
    });
    // 2) vybraná linka navrch její barvou
    var picked = feats.filter(function (f) { return f.properties.short_name === sel; });
    picked.forEach(function (f) {
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
