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

# ── seed tramvají z dnešních dat ─────────────────────────────────────────────
def tram_seed(resolve):
    routes = json.load(open(os.path.join(DATA, "routes.json"), encoding="utf-8"))
    stops = json.load(open(os.path.join(DATA, "stops.json"), encoding="utf-8"))
    byid = {s["id"]: s for s in stops}
    trams = {r["short_name"]: r for r in routes if r.get("type") == "tram"}
    def names_of(short):
        r = trams.get(short)
        return [byid[i]["name"] for i in (r["stops"] if r else []) if i in byid]
    out = {}
    for ln in ("2", "5", "11"):
        out[ln] = {"type": "tram", "src": "seed-dnešní-" + ln,
                   "stops": [row(n, resolve) for n in names_of(ln)]}
    # X3 = dnešní 3, úsek Kubelíkova–Horní Hanychov
    three = names_of("3")
    try:
        a = three.index("Kubelíkova"); b = three.index("Horní Hanychov")
        seg = three[min(a, b):max(a, b) + 1]
    except ValueError:
        seg = three
    out["X3"] = {"type": "tram", "src": "seed-dnešní-3 (úsek Kubelíkova–Horní Hanychov, zkontrolovat)",
                 "stops": [row(n, resolve) for n in seg]}
    return out

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
    w("meta.json", {"rok": rok, "counts": {"routes": len(routes), "stops": len(order)}})
    return len(order), len(routes)


# ── hlavní ───────────────────────────────────────────────────────────────────
def build_2001():
    devdir = os.path.join(ROOT, "dev"); os.makedirs(devdir, exist_ok=True)
    ovpath = os.path.join(devdir, "snapshot-2001-overrides.json")
    overrides = json.load(open(ovpath, encoding="utf-8")) if os.path.exists(ovpath) else {}
    resolve, _ = make_resolver(overrides)
    jr = os.path.join(ROOT, "jr", "2001")
    # bus linky: vyber soubor směru 'tam' (t) nebo bez t/z
    by_line = {}
    for f in os.listdir(jr):
        if not f.lower().endswith((".htm", ".html")) or f.startswith("Seznam"):
            continue
        m = re.match(r'(\d+)\s*([tz]?)', f)
        if not m:
            continue
        ln, d = m.group(1), m.group(2)
        if d == "z":
            continue
        by_line.setdefault(ln, f)            # t nebo bez směru

    linky = {}
    for ln in sorted(by_line, key=lambda x: (len(x), x)):
        html = open(os.path.join(jr, by_line[ln]), encoding="utf-8").read()
        stops = extract_stops(html)
        linky[ln] = {"type": "bus", "src": "jr/2001/" + by_line[ln],
                     "stops": [row(s, resolve) for s in stops]}

    linky.update(tram_seed(resolve))

    out = {"rok": 2001, "linky": linky}
    path = os.path.join(devdir, "snapshot-2001.json")
    json.dump(out, open(path, "w", encoding="utf-8"), ensure_ascii=False, indent=1)

    # doplň stub do overrides souboru pro nespárované unikátní názvy (bez přepsání vyplněných)
    unmatched = sorted({s["raw"] for v in linky.values() for s in v["stops"] if s["match"] is None})
    added = 0
    for raw in unmatched:
        if raw not in overrides:
            overrides[raw] = {"match": None, "lat": None, "lon": None}; added += 1
    json.dump(dict(sorted(overrides.items())), open(ovpath, "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    # statistika
    tot = sum(len(v["stops"]) for v in linky.values())
    nmiss = sum(1 for v in linky.values() for s in v["stops"] if s["match"] is None)
    from collections import Counter
    src = Counter(s["src"] for v in linky.values() for s in v["stops"])
    print(f"linky: {len(linky)} | výskytů: {tot} | nespárováno: {nmiss} | unikátů k vyplnění: {len(unmatched)}")
    print("zdroje souřadnic:", dict(src))
    print(f"mezisoubor:  dev/snapshot-2001.json (generovaný)")
    print(f"k vyplnění:  dev/snapshot-2001-overrides.json ({added} nových stubů, celkem {len(overrides)})")

    if nmiss == 0:
        ns, nr = emit_snapshot_data(linky, 2001)
        print(f"\n✓ data snapshotu zapsána: mapa-assets/data/2001/ ({nr} linek, {ns} zastávek)")
    else:
        print(f"\n⚠ {nmiss} nespárováno – data snapshotu NEzapsána (nejdřív dořešit overrides).")

if __name__ == "__main__":
    build_2001()
