#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
build_snapshot.py — generátor historických snapshotů sítě (zatím fáze MEZISOUBOR).

Pro daný rok vytáhne sled zastávek po linkách a dohledá souřadnice z dnešních dat,
a zapíše editovatelný mezisoubor (dev/snapshot-<rok>.json) k ruční korektuře.
Po korektuře navazuje fáze 2 (geometrie + mapa-assets/data/<rok>/*.json).

ZDROJE (vše už v repu):
  jr/2001/*.htm*                       – vyčištěné JŘ tabulky (sled zastávek)
  mapa-assets/data/stops.json          – dnešní zastávky (název → souřadnice)
  mapa-assets/data/routes.json         – dnešní linky (seed tramvají)
  mapa-assets/data/stops-history.json  – přejmenované / varianty / zaniklé

Tramvaje 2001 nemají JŘ → seed z dnešních (2,5,11); X3 = dnešní 3 ořezaná na
úsek Kubelíkova–Horní Hanychov. Odlišnosti dořeší ruční korektura.
"""
import os, re, json, io, sys

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8")
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, "mapa-assets", "data")

# ── extrakce sledu zastávek z 2001 HTML ──────────────────────────────────────
LABELS = ('zastávka', 'zastavka', 'platí od', 'platnost', 'pracovní', 'sobota',
          'neděle', 'svátky', 'dopravní podnik', 'směr', 'dopravce', 'poznámk',
          'www', 'tel', 'jízdní řád', 'seznam zastávek', 'provoz', 'končí', 'postižené')

# oprava překlepů ve zdrojových (HTML) názvech – mění zobrazený název i párování
RAW_FIX = {
    "Melamtrichova": "Melantrichova",
    "Douní mlýn": "Doubí mlýn",
    "Žižkova nám.": "Žižkovo nám.",
    "LETNÁ": "Pavlovice Letná",
}

def cell_clean(x):
    x = re.sub(r'<[^>]+>', '', x).replace('&nbsp;', ' ').replace('\xa0', ' ')
    return re.sub(r'\s+', ' ', x).strip()

def namelike(c):
    if not c or len(c) < 2 or len(c) > 34:           return False
    if ' - ' in c:                                   return False  # hlavička/poznámka
    if re.search(r'\d', c) and re.fullmatch(r'[\dA-Zr¤\s]+', c): return False  # časové sloupce
    if re.fullmatch(r'[\d.\s:F+\-]*', c):            return False
    if any(k in c.lower() for k in LABELS):          return False
    if not re.search(r'[A-Za-zÁ-Žá-ž]', c):          return False
    return True

def extract_stops(html):
    out = []
    for td in re.findall(r'<td[^>]*>(.*?)</td>', html, re.S | re.I):
        c = cell_clean(td)
        c = RAW_FIX.get(c, c)
        if namelike(c) and (not out or out[-1] != c):
            out.append(c)
    return out

# ── resolve názvu zastávky na dnešní souřadnice ──────────────────────────────
def norm(s):
    return re.sub(r'\s+', ' ', str(s or '').strip().lower())

def clean_name(raw):
    s = str(raw or '').strip()
    s = re.sub(r'^x(?=[A-ZÁ-Ž])', '', s)            # 'x' = na znamení
    s = re.sub(r'\s*\([^)]*\)\s*$', '', s)          # závorkové přípony
    return s.strip()

# bezpečné rozepsání běžných zkratek (delší/specifičtější dřív); jen jistá náhrada
EXPAND = [
    ("žel.zast.", "železniční zastávka"), ("žel.zas", "železniční zastávka"),
    ("žel.", "železniční"),
    ("Kr.Studánka", "Krásná Studánka"), ("Kr.Údolí", "Kryštofovo Údolí"),
    ("Kryš.Údolí", "Kryštofovo Údolí"),
    ("Pavl.", "Pavlovice "), ("Kun.", "Kunratická "), ("Dopr.", "Dopravní "),
    ("nám.", "náměstí"), ("sídl.", "sídliště"), ("sídliště.", "sídliště"),
]
def expand_abbr(s):
    out = s
    for a, b in EXPAND:
        out = out.replace(a, b)
    return re.sub(r'\s+', ' ', out).strip()

def make_resolver(overrides=None):
    overrides = overrides or {}
    stops = json.load(open(os.path.join(DATA, "stops.json"), encoding="utf-8"))
    by_name = {norm(s["name"]): s for s in stops}
    hist = json.load(open(os.path.join(DATA, "stops-history.json"), encoding="utf-8"))
    renamed = {norm(k): v for k, v in hist.get("renamed", {}).items()}
    aliases = {norm(k): v for k, v in hist.get("aliases", {}).items()}
    vanished = {norm(v["name"]): v for v in hist.get("vanished", []) if v.get("lat") is not None}

    def lookup(k):
        if k in by_name:
            s = by_name[k];  return s["name"], s["lat"], s["lon"], "gtfs"
        if k in renamed and norm(renamed[k]) in by_name:
            s = by_name[norm(renamed[k])]; return s["name"], s["lat"], s["lon"], "historie-renamed"
        if k in aliases and norm(aliases[k]) in by_name:
            s = by_name[norm(aliases[k])]; return s["name"], s["lat"], s["lon"], "historie-alias"
        if k in vanished:
            v = vanished[k];  return v["name"], v["lat"], v["lon"], "historie-vanished"
        return None

    def resolve(raw):
        o = overrides.get(raw)                       # ruční korektura přebíjí auto
        if o:
            if o.get("skip"):                        # extrakční smetí → vyřadit ze sledu
                return {"match": None, "lat": None, "lon": None, "src": "skip", "skip": True}
            if o.get("lat") is not None and o.get("lon") is not None:
                return {"match": o.get("match") or raw, "lat": o["lat"], "lon": o["lon"], "src": "ruční"}
            m = o.get("match")                        # vyplněn jen dnešní název → dohledej souřadnice
            if m and m != "null":
                hit = lookup(norm(m)) or (lookup(norm(expand_abbr(m))) if expand_abbr(m) != m else None)
                if hit:
                    return {"match": hit[0], "lat": hit[1], "lon": hit[2], "src": "ruční-match"}
        clean = clean_name(raw)
        hit = lookup(norm(clean))
        if hit:
            return {"match": hit[0], "lat": hit[1], "lon": hit[2], "src": hit[3]}
        exp = expand_abbr(clean)                     # zkus rozepsané zkratky
        if exp != clean:
            hit = lookup(norm(exp))
            if hit:
                return {"match": hit[0], "lat": hit[1], "lon": hit[2], "src": "odhad-" + hit[3]}
        return {"match": None, "lat": None, "lon": None, "src": "?"}
    return resolve, stops

def row(raw, resolve):
    r = {"raw": raw}; r.update(resolve(raw)); return r

# ── seed tramvají z dnešních dat (surové názvy, resolve proběhne ve finalize) ─
def tram_seed():
    routes = json.load(open(os.path.join(DATA, "routes.json"), encoding="utf-8"))
    stops = json.load(open(os.path.join(DATA, "stops.json"), encoding="utf-8"))
    byid = {s["id"]: s for s in stops}
    trams = {r["short_name"]: r for r in routes if r.get("type") == "tram"}
    def names_of(short):
        r = trams.get(short)
        return [byid[i]["name"] for i in (r["stops"] if r else []) if i in byid]
    out = {}
    for ln in ("2", "5", "11"):
        out[ln] = {"type": "tram", "src": "seed-dnešní-" + ln, "stops": names_of(ln)}
    three = names_of("3")                              # X3 = dnešní 3, úsek Kubelíkova–Horní Hanychov
    try:
        a = three.index("Kubelíkova"); b = three.index("Horní Hanychov")
        seg = three[min(a, b):max(a, b) + 1]
    except ValueError:
        seg = three
    out["X3"] = {"type": "tram", "src": "seed-dnešní-3 (úsek Kubelíkova–Horní Hanychov, zkontrolovat)", "stops": seg}
    return out


# ── extrakce sledu zastávek z PDF JŘ (formát DPMLJ, 2008–2011) ────────────────
import fitz  # PyMuPDF
_PDF_BAD = ('platí od', 'platí pro', 'platí pouze', 'linka čís', 'linka č', 'linku', 'na lince',
            'tarif', 'sms', 'zóna', 'ukončení', 'dopravce', 'informace', 'zpracov', 'bezbarier',
            'bezbariér', 'nízkopodlaž', 'neoznačen', 'znamení', 'jede ', 'jízdy', 'e-mail', 'tel:',
            'tel.', 'www', '@', 'počet', 'směr', 'seznam zast', 'platnost', 'obslu', 'nejede',
            'provoz', 'přestup', 'náhradní', 'zajiš', 'zajížd', 'spoje', 'spoj ', 'sloupec',
            'historick', 'pracovní', 'v zastávce', 'ostatní', 'do zast', 'minut',
            'jízdenk', 'lib25', 'lib36', 'lib na', 'na číslo', 'pošlete', 'zprávu', 've tvaru',
            'zdarma', 'výlukov', 'skeleton', 'projekt', 'statutár', 'platném', 'nařízením', '©',
            'odj', 'přj', 'jízdní řád', 'v pdf', 'pdf', 'zastávk', 'objíž',
            't0', 'tq', 'ex,', '6 o', 'p 6', 'émem')   # font smetí z rozbitých PDF
def _pdf_is_stop(l):
    if len(l) < 2 or len(l) > 34 or ':' in l: return False
    if l.count(' - ') >= 2: return False                  # hlavička trasy (A - B - C)
    if re.search(r'\d+\.\s*\d+\.', l): return False        # datum (od 26.10. do…)
    if re.search(r'\dg', l): return False                  # časový token s markerem (34gA, 02g…)
    ll = l.lower()
    if any(b in ll for b in _PDF_BAD): return False
    if re.fullmatch(r'[\d,\s.]+', l): return False
    if re.fullmatch(r'[axhAXH WEX$+*()\s.•]{1,5}', l): return False   # markery a/x/h, •
    return bool(re.search(r'[A-Za-zÁ-Žá-ž]', l))
def _pdf_clean(l):
    l = re.sub(r'^(?:[hax]+\s+|[hax]+(?=[A-ZÁ-Ž]))', '', l)  # prefix marker h/a/x (i kombinace hx, xa)
    l = re.sub(r'\s+[hax]$', '', l)                          # sufixový marker „ a"/„ x"/„ h"
    l = re.sub(r'(?:\s+\d|\.\d)$', '', l)                    # nalepená zóna „ 1" / „.1"
    return l.strip()
def _pdf_collect(lines, start):
    out = []
    for l in lines[start:]:
        if not l: continue
        if l == "•" or re.fullmatch(r'\d{1,2}:\d{2}', l) or re.fullmatch(r'(0\d|1\d|2[0-3])', l):
            break                                        # • / čas HH:MM / hodinový blok = konec sledu
        if _pdf_is_stop(l):
            nm = _pdf_clean(l)
            if nm: out.append(nm)
    return out
def _pdf_page_stops(txt):
    # zkus od shora (nový formát 2021 = sled nahoře) i od hlavičky „Zastávky" (starší
    # formát = sled až za časovou maticí); vrať delší (validnější) výsledek.
    lines = [l.strip() for l in txt.split("\n")]
    hi = next((k for k, l in enumerate(lines) if 'zastáv' in l.lower()), None)
    a = _pdf_collect(lines, 0)
    b = _pdf_collect(lines, hi + 1) if hi is not None else []
    return a if len(a) >= len(b) else b
def extract_pdf_stops(path):
    best = []
    for pg in fitz.open(path):
        s = _pdf_page_stops(pg.get_text())
        if len(s) > len(best): best = s
    return best

# ── geometrie: sešití po síti GTFS úseků (jako legacy), jinak rovná čára ──────
def load_gtfs_stitcher():
    """Vrátí (resolve_name, shortest) nad sítí GTFS úseků z gtfs/, nebo None když
    gtfs/ chybí. resolve_name(name)->station_id|None; shortest(a,b)->[(lon,lat)]|None."""
    import csv, heapq, collections
    gtfs = os.path.join(ROOT, "gtfs")
    need = ["stops.txt", "stop_times.txt", "trips.txt", "shapes.txt"]
    if not all(os.path.exists(os.path.join(gtfs, f)) for f in need):
        return None
    rd = lambda n: list(csv.DictReader(open(os.path.join(gtfs, n), encoding="utf-8-sig", newline="")))
    stops, stop_times, trips, shapes_raw = rd("stops.txt"), rd("stop_times.txt"), rd("trips.txt"), rd("shapes.txt")
    by_id = {s["stop_id"]: s for s in stops}
    station_of = lambda sid: (by_id.get(sid, {}).get("parent_station") or sid)
    stations = {s["stop_id"]: s for s in stops if s["location_type"] == "1"}
    for s in stops:
        if s["location_type"] != "1" and not s.get("parent_station"):
            stations.setdefault(s["stop_id"], s)

    shp = collections.defaultdict(list)
    for r in shapes_raw:
        try: d = float(r.get("shape_dist_traveled") or 0)
        except ValueError: d = 0.0
        shp[r["shape_id"]].append((int(r["shape_pt_sequence"]), float(r["shape_pt_lon"]), float(r["shape_pt_lat"]), d))
    for k in shp: shp[k].sort()
    trip_shape = {t["trip_id"]: t.get("shape_id") for t in trips}
    st_by_trip = collections.defaultdict(list)
    for st in stop_times:
        try: d = float(st.get("shape_dist_traveled") or 0)
        except ValueError: d = 0.0
        st_by_trip[st["trip_id"]].append((int(st["stop_sequence"]), st["stop_id"], d))
    for k in st_by_trip: st_by_trip[k].sort()

    seg = {}
    for tid, seq in st_by_trip.items():
        pts = shp.get(trip_shape.get(tid))
        if not pts or len(seq) < 2: continue
        for i in range(len(seq) - 1):
            a, b = station_of(seq[i][1]), station_of(seq[i + 1][1])
            d1, d2 = seq[i][2], seq[i + 1][2]
            if a == b or d2 <= d1: continue
            line = [(lo, la) for _, lo, la, d in pts if d1 - 1 <= d <= d2 + 1]
            if len(line) < 2: continue
            if (a, b) not in seg or (d2 - d1) < seg[(a, b)][0]:
                seg[(a, b)] = (d2 - d1, line)
    graph = collections.defaultdict(list)
    for (a, b), (length, line) in seg.items():
        graph[a].append((b, length, line)); graph[b].append((a, length, list(reversed(line))))

    def shortest(a, b):
        if a == b: return []
        dist, prev, pq = {a: 0.0}, {}, [(0.0, a)]
        while pq:
            d, u = heapq.heappop(pq)
            if u == b: break
            if d > dist.get(u, 1e18): continue
            for v, w, line in graph.get(u, []):
                nd = d + w
                if nd < dist.get(v, 1e18):
                    dist[v] = nd; prev[v] = (u, line); heapq.heappush(pq, (nd, v))
        if b not in prev: return None
        chain, cur = [], b
        while cur != a:
            u, line = prev[cur]; chain.append(line); cur = u
        chain.reverse()
        out = []
        for line in chain:
            out.extend(line[1:] if out and line and out[-1] == line[0] else line)
        return out

    nrm = lambda x: " ".join(str(x or "").strip().lower().split())
    name2st = {}
    for sid, s in stations.items():
        name2st.setdefault(nrm(s["stop_name"]), sid)
    return (lambda name: name2st.get(nrm(name))), shortest


def route_geometry(pts, stitch):
    """pts = [(match_name, lon, lat), …] → polyline; mezi GTFS zastávkami sešije
    po síti, jinak rovná čára. Vrací ([ [lon,lat],… ], počet_rovných_úseků)."""
    if not pts:
        return [], 0
    poly = [[round(pts[0][1], 5), round(pts[0][2], 5)]]
    straights = 0
    resolve, shortest = stitch if stitch else (None, None)
    for i in range(len(pts) - 1):
        na, _, _ = pts[i]; nb, lo_b, la_b = pts[i + 1]
        geom = None
        if stitch:
            sa, sb = resolve(na), resolve(nb)
            if sa and sb:
                geom = shortest(sa, sb)
        if geom:
            for lo, la in geom:
                p = [round(lo, 5), round(la, 5)]
                if poly[-1] != p: poly.append(p)
        else:
            p = [round(lo_b, 5), round(la_b, 5)]
            if poly[-1] != p: poly.append(p); straights += 1
    return poly, straights


# ── zápis datových souborů snapshotu (mapa-assets/data/<rok>/) ───────────────
def emit_snapshot_data(linky, rok):
    today = {r["short_name"]: r for r in json.load(open(os.path.join(DATA, "routes.json"), encoding="utf-8"))}
    def color_for(short, typ):
        if short in today and today[short].get("color"):
            return today[short]["color"]
        if typ == "tram":
            return "#cc2900"
        return "hsl(%d, 65%%, 45%%)" % (sum(ord(c) for c in short) * 47 % 360)  # deterministické

    stops, order = {}, []          # dedup podle resolvovaného (match) názvu = fyzická zastávka
    def ref(rowdict):
        key = rowdict["match"]
        if key not in stops:
            name = re.sub(r'^x(?=[A-ZÁ-Ž])', '', rowdict["raw"]).strip()  # 2001 název (bez „x" na znamení)
            stops[key] = {"id": len(order) + 1, "name": name, "lat": rowdict["lat"], "lon": rowdict["lon"], "routes": []}
            order.append(key)
        return stops[key]

    stitch = load_gtfs_stitcher()
    print("geometrie:", "sešití po síti GTFS" if stitch else "rovné spojnice (gtfs/ chybí)")

    routes, feats, straight_tot = [], [], 0
    for short, info in linky.items():
        ids, seq, geompts = [], [], []
        for r in info["stops"]:
            if r["lat"] is None:
                continue
            st = ref(r)
            if ids and ids[-1] == st["id"]:          # slij sousední duplicitu (varianty zápisu téže zast.)
                continue
            ids.append(st["id"]); seq.append(st["name"]); geompts.append((r["match"], r["lon"], r["lat"]))
            if short not in st["routes"]:
                st["routes"].append(short)
        col = color_for(short, info["type"])
        derived = (seq[0] + " – " + seq[-1]) if seq else ""
        routes.append({"id": "r-" + short, "short_name": short, "long_name": info.get("long_name") or derived,
                       "type": info["type"], "color": col, "stops": ids})
        coords, straights = route_geometry(geompts, stitch)
        straight_tot += straights
        if len(coords) >= 2:
            feats.append({"type": "Feature",
                          "properties": {"id": "r-" + short, "short_name": short, "color": col},
                          "geometry": {"type": "MultiLineString", "coordinates": [coords]}})
    print(f"rovných úseků celkem (mimo síť): {straight_tot}")

    out = os.path.join(DATA, str(rok)); os.makedirs(out, exist_ok=True)
    w = lambda fn, obj: json.dump(obj, open(os.path.join(out, fn), "w", encoding="utf-8"), ensure_ascii=False)
    w("stops.json", [stops[k] for k in order])
    w("routes.json", routes)
    w("shapes.json", {"type": "FeatureCollection", "features": feats})
    w("meta.json", {"rok": int(rok), "counts": {"routes": len(routes), "stops": len(order)}})
    return len(order), len(routes)


# ── společný finalizér: resolve názvů, mezisoubor, overrides, zápis dat ───────
def finalize(rok, lines_raw):
    """lines_raw = {short: {"type","src","long_name"?,"stops":[raw_name,...]}} (v pořadí vkládání)."""
    devdir = os.path.join(ROOT, "dev"); os.makedirs(devdir, exist_ok=True)
    ovpath = os.path.join(devdir, "snapshot-%s-overrides.json" % rok)
    overrides = json.load(open(ovpath, encoding="utf-8")) if os.path.exists(ovpath) else {}
    resolve, _ = make_resolver(overrides)

    linky = {}
    for short, info in lines_raw.items():
        rows = [row(s, resolve) for s in info["stops"]]
        rows = [r for r in rows if not r.get("skip")]      # vyřaď smetí označené skip
        linky[short] = {"type": info["type"], "src": info.get("src", ""),
                        "long_name": info.get("long_name"), "stops": rows}

    json.dump({"rok": rok, "linky": linky},
              open(os.path.join(devdir, "snapshot-%s.json" % rok), "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    unmatched = sorted({s["raw"] for v in linky.values() for s in v["stops"] if s["match"] is None})
    added = 0
    for raw in unmatched:
        if raw not in overrides:
            overrides[raw] = {"match": None, "lat": None, "lon": None}; added += 1
    json.dump(dict(sorted(overrides.items())), open(ovpath, "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    from collections import Counter
    nmiss = sum(1 for v in linky.values() for s in v["stops"] if s["match"] is None)
    src = Counter(s["src"] for v in linky.values() for s in v["stops"])
    tot = sum(len(v["stops"]) for v in linky.values())
    print(f"[{rok}] linky: {len(linky)} | výskytů: {tot} | nespárováno: {nmiss} | unikátů k vyplnění: {len(unmatched)}")
    print("zdroje souřadnic:", dict(src))
    print(f"mezisoubor:  dev/snapshot-{rok}.json")
    print(f"k vyplnění:  dev/snapshot-{rok}-overrides.json ({added} nových stubů, celkem {len(overrides)})")
    if nmiss == 0:
        ns, nr = emit_snapshot_data(linky, rok)
        print(f"\n✓ data: mapa-assets/data/{rok}/ ({nr} linek, {ns} zastávek)")
    else:
        print(f"\n⚠ {nmiss} nespárováno – data NEzapsána (nejdřív dořeš overrides).")
    return nmiss


def build_2001():
    jr = os.path.join(ROOT, "jr", "2001")
    by_line = {}
    for f in os.listdir(jr):
        if not f.lower().endswith((".htm", ".html")) or f.startswith("Seznam"):
            continue
        m = re.match(r'(\d+)\s*([tz]?)', f)
        if not m or m.group(2) == "z":
            continue
        by_line.setdefault(m.group(1), f)
    lines_raw = {}
    for ln in sorted(by_line, key=lambda x: (len(x), x)):       # busy seřazené (stejné pořadí jako dřív)
        lines_raw[ln] = {"type": "bus", "src": "jr/2001/" + by_line[ln],
                         "stops": extract_stops(open(os.path.join(jr, by_line[ln]), encoding="utf-8").read())}
    lines_raw.update(tram_seed())                               # pak tramvaje 2,5,11,X3
    finalize("2001", lines_raw)


def select_pdf_for_year(rok):
    """U každé linky vyber JŘ (PDF) s platností nejbližší ≤ konec roku (datum v názvu)."""
    import collections as _c
    by = _c.defaultdict(list)
    for f in os.listdir(os.path.join(ROOT, "jr")):
        if not f.lower().endswith(".pdf"):
            continue
        m = re.match(r'(\d{1,4})', f[:-4])
        if not m:
            continue
        dm = re.search(r'(\d{1,2})\.\s*(\d{1,2})\.\s*((?:19|20)\d{2})', f)
        if dm:
            d = (int(dm.group(3)), int(dm.group(2)), int(dm.group(1)))
        else:
            y = re.search(r'((?:19|20)\d{2})', f); d = (int(y.group(1)), 1, 1) if y else None
        if d:
            by[m.group(1)].append((d, f))
    ref = (rok, 12, 31)
    chosen = {}
    for ln, cs in by.items():
        le = sorted((c for c in cs if c[0] <= ref), reverse=True)   # nejnovější první
        if le:
            chosen[ln] = le                                          # [(date, file), …]
    return chosen


def build_pdf_year(rok):
    """Snapshot libovolného roku z PDF JŘ (2008+); 2001 jede z HTML přes build_2001.
    U každé linky bere nejnovější JŘ ≤ rok; když z něj nic nevyleze (rozbitý font),
    spadne na starší."""
    rok = int(rok)
    chosen = select_pdf_for_year(rok)
    TRAMS = {"2", "3", "5", "11"}
    lines_raw = {}
    for ln in sorted(chosen, key=lambda x: (len(x), x)):
        picked = None
        for d, f in chosen[ln]:
            stops = extract_pdf_stops(os.path.join(ROOT, "jr", f))
            if len(stops) >= 2:                    # rozbité PDF (font smetí) dají 0 → fallback na starší
                picked = (d, f, stops); break
        if not picked:
            print(f"  ⚠ {ln}: nepodařilo se vytáhnout zastávky – vynecháno"); continue
        d, f, stops = picked
        old = " ⚠STARÉ %d" % d[0] if d[0] < rok - 2 else ""
        lines_raw[ln] = {"type": "tram" if ln in TRAMS else "bus", "src": "jr/" + f + old, "stops": stops}
    finalize(str(rok), lines_raw)


if __name__ == "__main__":
    rok = sys.argv[1] if len(sys.argv) > 1 else "2001"
    if rok == "2001":
        build_2001()
    elif re.fullmatch(r"\d{4}", rok):
        build_pdf_year(rok)
    else:
        sys.exit("Neznámý rok: %s (použij YYYY)" % rok)
