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

  // přepínač směru výpisu zastávek (funguje i nad serverem vykresleným seznamem)
  document.addEventListener("click", function (e) {
    var btn = e.target.closest && e.target.closest(".ls-dirbtn");
    if (!btn) return;
    var wrap = btn.closest(".ls-dirs"); if (!wrap) return;
    var dir = btn.getAttribute("data-dir");
    Array.prototype.forEach.call(wrap.querySelectorAll(".ls-dirbtn"), function (b) { b.classList.toggle("is-on", b === btn); });
    Array.prototype.forEach.call(wrap.querySelectorAll(".ls-dir"), function (d) {
      d.classList.toggle("is-hidden", d.getAttribute("data-dir") !== dir);
    });
  });

  var previews = document.querySelectorAll(".line-map-preview[data-linka]");
  var stopLists = document.querySelectorAll(".line-stops[data-linka]");
  if (!previews.length && !stopLists.length) return;

  var BASE = (window.MAPA && window.MAPA.base) || "";
  var JA = (window.MAPA && window.MAPA.ja) || "";
  var ALIASES = (window.MAPA && window.MAPA.aliases) || {};
  var TILE_COLORS = (window.MAPA && window.MAPA.tileColors) || {};   // barvy linek dle dlaždic (shoda s velkou mapou)
  var TILE_STATE = (window.MAPA && window.MAPA.tileStates) || {};    // stav linky (trvale → čárkovaně)
  var DIRLABEL = (window.MAPA && window.MAPA.dir) || "Směr %s";       // šablona popisku směru
  var SVG_NS = "http://www.w3.org/2000/svg";
  var W = 500, H = 333, PAD = 14;

  var VER = (window.MAPA && window.MAPA.v) ? ("?v=" + window.MAPA.v) : "";
  function getJSON(name, fallback) {
    return fetch(BASE + "/mapa-assets/data/" + name + VER, { credentials: "same-origin" })
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
    (previews.length || stopLists.length) ? getJSON("routes.json", null) : Promise.resolve(null),
    getJSON("stops.json", null),
    getJSON("legacy-routes.json", []),
    previews.length ? getJSON("legacy-shapes.json", {}) : Promise.resolve({}),
    stopLists.length ? getJSON("stops-history.json", {}) : Promise.resolve({}),
    previews.length ? getJSON("former-lines.json", {}) : Promise.resolve({})
  ]).then(function (res) {
    var shapesGeo = res[0], routes = res[1], stops = res[2], legacy = res[3] || [],
        legacyShapes = res[4] || {}, history = res[5] || {}, former = res[6] || {};

    var stopById = {}, stopByName = {};
    (stops || []).forEach(function (s) { stopById[s.id] = s; stopByName[norm(s.name)] = s; });
    var legacyByShort = {};
    legacy.forEach(function (lr) { if (lr && lr.short_name) legacyByShort[lr.short_name] = lr; });

    // historie názvů – kvůli klikatelnosti starých/variantních/zaniklých názvů (bez „→"/kurzívy)
    var renamed = {}, aliases = {};
    Object.keys(history.renamed || {}).forEach(function (k) { renamed[norm(k)] = history.renamed[k]; });
    Object.keys(history.aliases || {}).forEach(function (k) { aliases[norm(k)] = history.aliases[k]; });
    (history.vanished || []).forEach(function (v) {
      if (v && v.name && !stopByName[norm(v.name)]) stopByName[norm(v.name)] = { name: v.name };
    });
    function resolveTarget(raw) {
      var clean = cleanStopName(raw), k = norm(clean);
      if (stopByName[k]) return stopByName[k].name;
      if (renamed[k] && stopByName[norm(renamed[k])]) return renamed[k];
      if (aliases[k] && stopByName[norm(aliases[k])]) return aliases[k];
      return null;
    }

    if (previews.length) renderPreviews(shapesGeo, stopByName, legacyByShort, legacyShapes, routes, stopById, resolveTarget, former);
    if (stopLists.length) renderStopLists(routes, stopById, resolveTarget);
  });

  // ── náhledy trasy ──────────────────────────────────────────────────
  function renderPreviews(shapesGeo, stopByName, legacyByShort, legacyShapes, routes, stopById, resolveTarget, former) {
    var feats = ((shapesGeo && shapesGeo.features) || []).filter(function (f) {
      return f.geometry && f.geometry.coordinates && f.geometry.coordinates.length;
    });
    if (!feats.length) return;

    var routeByShort = {};
    (routes || []).forEach(function (r) { routeByShort[r.short_name] = r; });
    // zastávky provozní linky (z routes.json → souřadnice z stops.json), bez duplicit
    function shapeStops(sel) {
      var route = routeByShort[sel], seen = {}, out = [];
      (route && route.stops || []).forEach(function (id) {
        if (seen[id]) return; seen[id] = 1;
        var s = stopById && stopById[id];
        if (s) out.push({ c: [s.lon, s.lat], name: s.name });
      });
      return out;
    }
    // zastávky linky mimo provoz (názvy/souřadnice z legacy-routes.json)
    function legacyStops(lr) {
      return (lr.stops || []).map(function (e) {
        var name = (e && typeof e === "object") ? e.name : e;
        var st = stopByName[norm(name)];
        if (st && st.lon != null) return { c: [st.lon, st.lat], name: st.name };
        // přejmenované / variantní názvy → dnešní zastávka (kvuli spárování s hoverem v seznamu)
        var tgt = resolveTarget && resolveTarget(name);
        if (tgt) { var ts = stopByName[norm(tgt)]; if (ts && ts.lon != null) return { c: [ts.lon, ts.lat], name: ts.name }; }
        if (e && typeof e === "object" && e.lat != null && e.lon != null) return { c: [e.lon, e.lat], name: name };
        return null;
      }).filter(Boolean);
    }
    // zastávky linky „aktuálně mimo provoz" (archiv former-lines.json → ID do stops.json)
    function formerStops(fr) {
      var seen = {}, out = [];
      (fr.stops || []).forEach(function (id) {
        if (seen[id]) return; seen[id] = 1;
        var s = stopById && stopById[id];
        if (s) out.push({ c: [s.lon, s.lat], name: s.name });
      });
      return out;
    }

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
        renderSvg(a, feats, proj, { kind: "shape", sel: sel, stops: shapeStops(sel) });
        return;
      }
      // linka „aktuálně mimo provoz" (není v živém GTFS, ale máme její poslední reálný
      // tvar v archivu) → kreslíme z archivu, stejně jako velká mapa. Má přednost před
      // legacy (přibližnou) trasou, aby náhled a mapa seděly.
      var fr = former[sel] || former[a.getAttribute("data-linka")];
      if (fr && fr.geometry && fr.geometry.coordinates && fr.geometry.coordinates.length) {
        var fcol = TILE_COLORS[sel] || (fr.type === "tram" ? "#b09a9a" : "#9aa4b0");
        // trvale mimo provoz čárkovaně i s reálným tvarem z archivu (81); akt plnou
        var fdash = TILE_STATE[sel] === "trvale" ? "7 6" : null;
        renderSvg(a, feats, proj, { kind: "former", lines: fr.geometry.coordinates, color: fcol, dash: fdash, stops: formerStops(fr) });
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
          renderSvg(a, feats, proj, { kind: "legacy", coords: coords, color: color, stops: legacyStops(lr) });
        }
      }
    });

    // hover nad zastávkou v seznamu → zvýrazni kolečko v náhledu a ukaž název
    Array.prototype.forEach.call(previews, function (a) {
      if (a.querySelector(".lmp-svg")) wirePreviewHover(a);
    });
  }

  function wirePreviewHover(a) {
    var row = a.closest ? a.closest(".row") : null;
    var list = row ? row.querySelector(".line-stops") : null;
    var svg = a.querySelector(".lmp-svg");
    if (!list || !svg) return;
    var byName = {};
    Array.prototype.forEach.call(svg.querySelectorAll(".lmp-stop"), function (c) {
      byName[c.getAttribute("data-sn")] = c;
    });
    list.addEventListener("mouseover", function (e) { hoverPreviewStop(svg, byName, e.target, true); });
    list.addEventListener("mouseout", function (e) { hoverPreviewStop(svg, byName, e.target, false); });
  }
  function clearPreviewHover(svg) {
    Array.prototype.forEach.call(svg.querySelectorAll(".lmp-stop-hi"), function (c) { c.classList.remove("lmp-stop-hi"); });
    var old = svg.querySelector('text[data-lbl="1"]');
    if (old) old.parentNode.removeChild(old);
  }
  function hoverPreviewStop(svg, byName, target, on) {
    var li = target.closest ? target.closest("li") : null;
    if (!li) return;
    clearPreviewHover(svg);
    if (!on) return;
    // párovací klíč: dnešní (GTFS) název z data-sn, jinak fallback na text položky
    var key = li.getAttribute("data-sn") || norm(li.textContent);
    var c = byName[key];
    if (!c) return;
    c.classList.add("lmp-stop-hi");
    var x = +c.getAttribute("cx"), y = +c.getAttribute("cy");
    var t = document.createElementNS(SVG_NS, "text");
    t.setAttribute("class", "lmp-stoplabel");
    t.setAttribute("data-lbl", "1");
    t.setAttribute("y", (y + 3.5).toFixed(1));
    if (x > W * 0.66) { t.setAttribute("x", (x - 6).toFixed(1)); t.setAttribute("text-anchor", "end"); }
    else { t.setAttribute("x", (x + 6).toFixed(1)); }
    // popisek ukaž tak, jak je v seznamu (může se lišit od GTFS názvu – přejmenované)
    var label = (li.textContent || "").trim();
    var title = c.querySelector("title");
    t.textContent = label || (title ? title.textContent : "");
    svg.appendChild(t);
  }

  // ── seznam zastávek ────────────────────────────────────────────────
  function renderStopLists(routes, stopById, resolveTarget) {
    var routeByShort = {};
    (routes || []).forEach(function (r) { routeByShort[r.short_name] = r; });

    // klikací jsou názvy, které se podaří spárovat (i přes historii); zobrazí se tak, jak jsou v DB
    function renderList(items) {
      return "<ul class='ls-list'>" + items.map(function (raw) {
        var target = resolveTarget(raw);
        if (target) {
          var href = BASE + "/mapa?zastavka=" + encodeURIComponent(target) + (JA ? "&ja=" + encodeURIComponent(JA) : "");
          // data-sn = dnešní (GTFS) název → spáruje hover v náhledu i u přejmenovaných/variantních názvů
          return "<li data-sn=\"" + esc(norm(target)) + "\"><a class='ls-stop' href=\"" + esc(href) + "\">" + esc(raw) + "</a></li>";
        }
        return "<li>" + esc(raw) + "</li>";
      }).join("") + "</ul>";
    }

    Array.prototype.forEach.call(stopLists, function (el) {
      if (el.querySelector(".ls-list")) return;   // provozní linka už vykreslená serverem
      var short = el.getAttribute("data-linka");
      if (routeByShort[short]) {
        // aktuální linka: seznam z GTFS (po směrech, liší-li se)
        var r = routeByShort[short];
        var namesOf = function (ids) {
          return (ids || []).map(function (sid) { return stopById[sid] && stopById[sid].name; }).filter(Boolean);
        };
        if (r.directions && r.directions.length >= 2) {
          var btns = r.directions.map(function (d, di) {
            return "<button type='button' class='ls-dirbtn" + (di === 0 ? " is-on" : "") +
                   "' data-dir='" + di + "'>&rarr; " + esc(d.headsign || "") + "</button>";
          }).join("");
          var blocks = r.directions.map(function (d, di) {
            return "<div class='ls-dir" + (di === 0 ? "" : " is-hidden") + "' data-dir='" + di + "'>" +
                   renderList(namesOf(d.stops)) + "</div>";
          }).join("");
          el.innerHTML = "<div class='ls-dirs'><div class='ls-dirswitch'>" + btns + "</div>" + blocks + "</div>";
        } else {
          var names = namesOf(r.stops);
          if (names.length) el.innerHTML = renderList(names);
        }
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

    var lineColor;
    if (hi.kind === "shape") {
      feats.filter(function (f) { return f.properties.short_name === hi.sel; }).forEach(function (f) {
        lineColor = TILE_COLORS[f.properties.short_name] || f.properties.color || "#0078c8";
        f.geometry.coordinates.forEach(function (ln) {
          var pl = polyline(ln, proj, lineColor, 3.5, 1);
          if (pl) svg.appendChild(pl);
        });
      });
    } else if (hi.kind === "former") { // reálný tvar z archivu; plná (akt) / čárkovaná (trvale)
      lineColor = hi.color;
      (hi.lines || []).forEach(function (ln) {
        var pl = polyline(ln, proj, hi.color, 3.5, 1, hi.dash);
        if (pl) svg.appendChild(pl);
      });
    } else { // legacy – čárkovaná spojnice zastávek
      lineColor = hi.color;
      var pl = polyline(hi.coords, proj, hi.color, 3.5, 1, "7 6");
      if (pl) svg.appendChild(pl);
    }

    // zastávky linky – kroužky s názvem na hover (nativní <title>)
    (hi.stops || []).forEach(function (st) {
      var c = document.createElementNS(SVG_NS, "circle");
      c.setAttribute("cx", proj.x(st.c).toFixed(1));
      c.setAttribute("cy", proj.y(st.c).toFixed(1));
      c.setAttribute("r", "2.6");
      c.setAttribute("class", "lmp-stop");
      c.setAttribute("fill", "#fff");
      c.setAttribute("stroke", lineColor || "#0078c8");
      c.setAttribute("stroke-width", "1.4");
      c.setAttribute("data-sn", norm(st.name));    // pro spárování s hoverem v seznamu zastávek
      var t = document.createElementNS(SVG_NS, "title");
      t.textContent = st.name;
      c.appendChild(t);
      svg.appendChild(c);
    });

    a.insertBefore(svg, a.firstChild);
    a.classList.add("is-ready");
  }
})();
