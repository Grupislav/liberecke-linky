/* ====================================================================
   Náhled trasy linky na hlavním webu (záložka Přehled)
   Dokresluje inline SVG z mapa-assets/data/shapes.json (stejná GTFS data
   jako interaktivní mapa) do <a class="line-map-preview" data-linka="X">.
   Klik vede na /mapa?linka=X. Bez JS / bez dat zůstává funkční odkaz.
   ==================================================================== */
(function () {
  "use strict";

  var nodes = document.querySelectorAll(".line-map-preview[data-linka]");
  if (!nodes.length) return;

  var BASE = (window.MAPA && window.MAPA.base) || "";
  var SVG_NS = "http://www.w3.org/2000/svg";

  fetch(BASE + "/mapa-assets/data/shapes.json", { credentials: "same-origin" })
    .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
    .then(function (geo) {
      var byShort = {};
      (geo.features || []).forEach(function (f) {
        var p = f.properties || {};
        if (p.short_name != null) byShort[String(p.short_name)] = f;
      });
      Array.prototype.forEach.call(nodes, function (a) {
        var f = byShort[a.getAttribute("data-linka")];
        if (f) renderSvg(a, f);
      });
    })
    .catch(function () { /* ponecháme textový fallback + odkaz */ });

  function renderSvg(a, feature) {
    var W = 500, H = 333, PAD = 16;
    var lines = (feature.geometry && feature.geometry.coordinates) || [];

    var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    lines.forEach(function (ln) {
      ln.forEach(function (c) {
        if (c[0] < minX) minX = c[0];
        if (c[0] > maxX) maxX = c[0];
        if (c[1] < minY) minY = c[1];
        if (c[1] > maxY) maxY = c[1];
      });
    });
    if (!isFinite(minX)) return;

    // korekce na zeměpisnou šířku (stupeň délky je kratší než stupeň šířky)
    var kx = Math.cos(((minY + maxY) / 2) * Math.PI / 180);
    var spanX = Math.max((maxX - minX) * kx, 1e-6);
    var spanY = Math.max(maxY - minY, 1e-6);
    var s = Math.min((W - 2 * PAD) / spanX, (H - 2 * PAD) / spanY);
    var offX = (W - spanX * s) / 2;
    var offY = (H - spanY * s) / 2;

    function px(c) { return offX + (c[0] - minX) * kx * s; }
    function py(c) { return offY + (maxY - c[1]) * s; } // lat → osa Y obráceně

    var color = (feature.properties && feature.properties.color) || "#0078c8";
    var svg = document.createElementNS(SVG_NS, "svg");
    svg.setAttribute("class", "lmp-svg");
    svg.setAttribute("viewBox", "0 0 " + W + " " + H);
    svg.setAttribute("preserveAspectRatio", "xMidYMid meet");
    svg.setAttribute("aria-hidden", "true");

    lines.forEach(function (ln) {
      if (ln.length < 2) return;
      var pts = ln.map(function (c) { return px(c).toFixed(1) + "," + py(c).toFixed(1); }).join(" ");
      var pl = document.createElementNS(SVG_NS, "polyline");
      pl.setAttribute("points", pts);
      pl.setAttribute("fill", "none");
      pl.setAttribute("stroke", color);
      pl.setAttribute("stroke-width", "3.5");
      pl.setAttribute("stroke-linejoin", "round");
      pl.setAttribute("stroke-linecap", "round");
      svg.appendChild(pl);
    });

    a.insertBefore(svg, a.firstChild);
    a.classList.add("is-ready");
  }
})();
