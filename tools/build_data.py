#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Build static data for the "Liberecké linky – mapa" page from the GTFS feed.

Input : <repo>/gtfs/*.txt  (unzipped gtfs.zip from http://www.dpmlj.cz/gtfs.zip)
Output: <repo>/mapa-assets/data/{stops.json, routes.json, shapes.geojson, meta.json}

Re-run after every monthly GTFS update (from the repo root):
    curl -sL -o gtfs.zip http://www.dpmlj.cz/gtfs.zip
    unzip -o gtfs.zip -d gtfs
    python tools/build_data.py
Then review the regenerated mapa-assets/data/*.json and commit them.
(gtfs/ and gtfs.zip are gitignored – only the generated JSON is deployed.)
"""
import csv, json, os, collections, colorsys, io, sys

# UTF-8 stdout – jinak by výpis názvů linek se znaky mimo ASCII (např. „♿BUS")
# spadl na Windows konzoli (cp1250).
try:
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8")
except Exception:
    pass

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
GTFS = os.path.join(ROOT, "gtfs")
OUT = os.path.join(ROOT, "mapa-assets", "data")
COORD_DP_TOLERANCE = 0.00004   # ~4 m, Douglas-Peucker simplification
COORD_DECIMALS = 5             # ~1.1 m precision

# Natvrdo zadané souřadnice stanic, které obsluhuje jen linka „aktuálně mimo provoz"
# (z archivu former-lines.json) a mohou zmizet i z GTFS stops.txt, než linka zase pojede.
# Klíč = GTFS stop_id (parent station). 26611 = Areál Vesec (jezdí sem jen 41, ~1× za rok).
FORMER_STOP_FALLBACK = {
    "26611": {"name": "Areál Vesec", "lat": 50.7300661, "lon": 15.0700481},
}


def read(name):
    with open(os.path.join(GTFS, name), encoding="utf-8-sig", newline="") as fh:
        return list(csv.DictReader(fh))


# ---------------------------------------------------------------- geometry
def _perp_dist(p, a, b):
    (x, y), (x1, y1), (x2, y2) = p, a, b
    dx, dy = x2 - x1, y2 - y1
    if dx == 0 and dy == 0:
        return ((x - x1) ** 2 + (y - y1) ** 2) ** 0.5
    t = ((x - x1) * dx + (y - y1) * dy) / (dx * dx + dy * dy)
    t = max(0.0, min(1.0, t))
    px, py = x1 + t * dx, y1 + t * dy
    return ((x - px) ** 2 + (y - py) ** 2) ** 0.5


def simplify(pts, tol):
    """Iterative Douglas-Peucker (avoids recursion limits on long shapes)."""
    if len(pts) < 3:
        return pts[:]
    keep = [False] * len(pts)
    keep[0] = keep[-1] = True
    stack = [(0, len(pts) - 1)]
    while stack:
        s, e = stack.pop()
        dmax, idx = 0.0, -1
        for i in range(s + 1, e):
            d = _perp_dist(pts[i], pts[s], pts[e])
            if d > dmax:
                dmax, idx = d, i
        if dmax > tol and idx != -1:
            keep[idx] = True
            stack.append((s, idx))
            stack.append((idx, e))
    return [p for p, k in zip(pts, keep) if k]


def rnd(v):
    return round(float(v), COORD_DECIMALS)


def merge_variant(spine, var):
    """Sleje vzor trasy `var` do páteře `spine`: souvislý běh zastávek, které
    páteř nemá, vloží těsně před následující společnou zastávku (kotvu) – a na
    konci (cizí běh bez další kotvy) hned za poslední kotvu. Tím nechybí
    jednosměrné zastávky ani závleky a zůstávají na svém místě v trase.
    Zastávky už přítomné jen posunou kotvu (žádné duplikáty)."""
    spine = spine[:]
    anchor = -1      # index poslední ztotožněné zastávky v páteři
    pending = []     # cizí zastávky čekající na vložení před další kotvu
    for name in var:
        idx = -1
        for k in range(anchor + 1, len(spine)):   # nejprve hledej dál po trase
            if spine[k] == name:
                idx = k; break
        if idx == -1:
            for k in range(len(spine)):            # jinak kdekoliv (větve/okruhy)
                if spine[k] == name:
                    idx = k; break
        if idx != -1:
            if pending:
                spine[idx:idx] = pending           # cizí běh těsně před kotvu
                idx += len(pending); pending = []
            anchor = idx
        else:
            pending.append(name)
    if pending:
        spine[anchor + 1:anchor + 1] = pending     # koncový cizí běh za kotvu
    return spine


# ---------------------------------------------------------------- colors
TRAM_COLORS = {"2": "#e4002b", "3": "#0078c8", "5": "#009a44", "11": "#f2a900"}


def bus_color(i, n):
    h = (i / max(n, 1))
    r, g, b = colorsys.hls_to_rgb(h, 0.45, 0.62)
    return "#%02x%02x%02x" % (int(r * 255), int(g * 255), int(b * 255))


# ---------------------------------------------------------------- legacy shapes
def build_legacy_shapes(trips, stop_times, shapes_raw, station_of, stations):
    """Sešije trasy linek mimo provoz (mapa-assets/data/legacy-routes.json) po
    ulicích: mezi sousedními zastávkami najde nejkratší cestu v síti GTFS úseků
    a zřetězí jejich geometrii. Vrací {short_name: [[lon,lat], ...]}. Kde cesta
    není (zastávky mimo síť), použije rovnou čáru."""
    import heapq

    # geometrie tvarů s distancí podél trasy
    shp = collections.defaultdict(list)
    for row in shapes_raw:
        try:
            d = float(row.get("shape_dist_traveled") or 0)
        except ValueError:
            d = 0.0
        shp[row["shape_id"]].append(
            (int(row["shape_pt_sequence"]), float(row["shape_pt_lon"]), float(row["shape_pt_lat"]), d))
    for k in shp:
        shp[k].sort()

    st_by_trip = collections.defaultdict(list)
    for st in stop_times:
        try:
            d = float(st.get("shape_dist_traveled") or 0)
        except ValueError:
            d = 0.0
        st_by_trip[st["trip_id"]].append((int(st["stop_sequence"]), st["stop_id"], d))
    for k in st_by_trip:
        st_by_trip[k].sort()

    trip_shape = {t["trip_id"]: t.get("shape_id") for t in trips}

    seg = {}   # (A,B) -> (delka, [(lon,lat), ...])
    for tid, seq in st_by_trip.items():
        pts = shp.get(trip_shape.get(tid))
        if not pts or len(seq) < 2:
            continue
        for i in range(len(seq) - 1):
            a, b = station_of(seq[i][1]), station_of(seq[i + 1][1])
            d1, d2 = seq[i][2], seq[i + 1][2]
            if a == b or d2 <= d1:
                continue
            line = [(lo, la) for _, lo, la, d in pts if d1 - 1 <= d <= d2 + 1]
            if len(line) < 2:
                continue
            if (a, b) not in seg or (d2 - d1) < seg[(a, b)][0]:
                seg[(a, b)] = (d2 - d1, line)

    graph = collections.defaultdict(list)   # ulice jsou většinou obousměrné
    for (a, b), (length, line) in seg.items():
        graph[a].append((b, length, line))
        graph[b].append((a, length, list(reversed(line))))

    def shortest(a, b):
        if a == b:
            return []
        dist = {a: 0.0}; prev = {}; pq = [(0.0, a)]
        while pq:
            d, u = heapq.heappop(pq)
            if u == b:
                break
            if d > dist.get(u, 1e18):
                continue
            for v, w, line in graph.get(u, []):
                nd = d + w
                if nd < dist.get(v, 1e18):
                    dist[v] = nd; prev[v] = (u, line); heapq.heappush(pq, (nd, v))
        if b not in prev:
            return None
        chain = []; cur = b
        while cur != a:
            u, line = prev[cur]; chain.append(line); cur = u
        chain.reverse()
        out = []
        for line in chain:
            if out and line and out[-1] == line[0]:
                out.extend(line[1:])
            else:
                out.extend(line)
        return out

    name_to_station = {}
    for sid, s in stations.items():
        key = " ".join(s.get("stop_name", "").strip().lower().split())
        name_to_station.setdefault(key, sid)

    # zaniklé zastávky (se souřadnicemi) ze stops-history.json – aby legacy linky,
    # které jimi vedou (např. ♿BUS přes Koloseum/Sokolská most), nepřišly o úsek.
    vanished_coords = {}
    try:
        _h = json.load(open(os.path.join(OUT, "stops-history.json"), encoding="utf-8"))
        for _v in _h.get("vanished", []):
            if _v.get("lat") is not None and _v.get("lon") is not None:
                vanished_coords[" ".join(str(_v["name"]).strip().lower().split())] = (float(_v["lon"]), float(_v["lat"]))
    except Exception:
        pass

    def resolve(entry):
        if isinstance(entry, dict):
            key = " ".join(str(entry.get("name", "")).strip().lower().split())
            if key in name_to_station:
                return ("s", name_to_station[key])
            if entry.get("lat") is not None and entry.get("lon") is not None:
                return ("c", (float(entry["lon"]), float(entry["lat"])))
            return ("c", vanished_coords[key]) if key in vanished_coords else None
        key = " ".join(str(entry).strip().lower().split())
        if key in name_to_station:
            return ("s", name_to_station[key])
        return ("c", vanished_coords[key]) if key in vanished_coords else None

    def lonlat(r):
        if r[0] == "s":
            s = stations[r[1]]
            return (float(s["stop_lon"]), float(s["stop_lat"]))
        return r[1]

    try:
        legacy = json.load(open(os.path.join(OUT, "legacy-routes.json"), encoding="utf-8"))
    except Exception:
        return {}

    out = {}
    for lr in legacy:
        sn = lr.get("short_name")
        nodes = [r for r in (resolve(e) for e in lr.get("stops", [])) if r]
        if len(nodes) < 2:
            continue
        poly = [lonlat(nodes[0])]
        straights = 0
        for i in range(len(nodes) - 1):
            ra, rb = nodes[i], nodes[i + 1]
            geom = shortest(ra[1], rb[1]) if ra[0] == "s" and rb[0] == "s" else None
            if geom:
                for p in geom:
                    if poly[-1] != p:
                        poly.append(p)
            else:
                p = lonlat(rb)
                if poly[-1] != p:
                    poly.append(p)
                straights += 1
        out[sn] = [[round(lo, COORD_DECIMALS), round(la, COORD_DECIMALS)] for lo, la in poly]
        print(f"  legacy {sn:>4}: {len(out[sn]):>4} bodů" + (f"  ({straights} rovných úseků)" if straights else ""))
    return out


# ---------------------------------------------------------------- timetable
def parse_hms(s):
    if not s:
        return None
    try:
        h, m, sec = s.split(":")
        return int(h) * 3600 + int(m) * 60 + int(sec)
    except ValueError:
        return None


def build_timetable(trips, stop_times, route_by_id, station_of, stop_index, calendar):
    """Kompaktní jízdní řád pro klienta (odjezdy v zastávce + poloha vozidel dle
    JŘ – zobrazená přímo v zastávkách, bez mezilehlé interpolace).
      services = service_id → týdenní bitmaska (po–ne) a rozsah platnosti.
      trips    = linka, mód, cíl (headsign), service_id a sled zastávek
                 [stopIdx, sekunda] (čas odjezdu; u poslední příjezd).
    Časy můžou být ≥86400 (spoje po půlnoci) – klient to řeší přes službu
    předešlého dne. Stanice = index do stops.json (kvůli velikosti)."""
    services = {}
    days = ["monday", "tuesday", "wednesday", "thursday", "friday", "saturday", "sunday"]
    for c in calendar:
        services[c["service_id"]] = {
            "d": "".join("1" if c.get(d) == "1" else "0" for d in days),
            "f": c.get("start_date", ""),
            "t": c.get("end_date", ""),
        }

    rows = collections.defaultdict(list)
    for st in stop_times:
        t = parse_hms(st.get("departure_time") or st.get("arrival_time"))
        if t is None:
            continue
        rows[st["trip_id"]].append((int(st["stop_sequence"]), st["stop_id"], t))

    out_trips = []
    for tr in trips:
        seq = sorted(rows.get(tr["trip_id"], []))
        u = []
        for _, sid, t in seq:
            idx = stop_index.get(station_of(sid))
            if idx is None:
                continue
            if u and u[-1][0] == idx:      # stejná stanice po sobě – drž první čas
                continue
            u.append([idx, t])
        if len(u) < 2:
            continue
        r = route_by_id.get(tr["route_id"], {})
        out_trips.append({
            "r": r.get("route_short_name", ""),
            "m": "tram" if r.get("route_type") == "0" else "bus",
            "h": (tr.get("trip_headsign") or "").strip(),
            "s": tr["service_id"],
            "u": u,
        })
    return {"services": services, "trips": out_trips}


# ---------------------------------------------------------------- main
def main():
    os.makedirs(OUT, exist_ok=True)
    routes = read("routes.txt")
    trips = read("trips.txt")
    stops = read("stops.txt")
    stop_times = read("stop_times.txt")
    shapes_raw = read("shapes.txt")
    try:
        calendar = read("calendar.txt")
    except Exception:
        calendar = []
    try:
        feed = read("feed_info.txt")[0]
    except Exception:
        feed = {}

    # ---- stop indexes -------------------------------------------------
    stop_by_id = {s["stop_id"]: s for s in stops}

    def station_of(stop_id):
        """Resolve a platform/stop_id to its parent station id (or itself)."""
        s = stop_by_id.get(stop_id)
        if not s:
            return stop_id
        if s.get("parent_station"):
            return s["parent_station"]
        return stop_id

    # stations = the points we render (location_type == 1, plus any
    # referenced stop that has no parent and isn't a child platform)
    stations = {}
    for s in stops:
        if s["location_type"] == "1":
            stations[s["stop_id"]] = s
    for s in stops:
        if s["location_type"] != "1" and not s.get("parent_station"):
            stations.setdefault(s["stop_id"], s)

    # ---- route metadata ----------------------------------------------
    route_by_id = {r["route_id"]: r for r in routes}
    bus_routes = [r for r in routes if r["route_type"] == "3"]
    bus_routes_sorted = sorted(bus_routes, key=lambda r: r["route_id"])
    color = {}
    for r in routes:
        sn = r["route_short_name"]
        if r["route_type"] == "0":
            color[r["route_id"]] = TRAM_COLORS.get(sn, "#444444")
    for i, r in enumerate(bus_routes_sorted):
        color[r["route_id"]] = bus_color(i, len(bus_routes_sorted))

    # ---- trips grouped by route --------------------------------------
    trips_by_route = collections.defaultdict(list)
    trip_by_id = {}
    for t in trips:
        trips_by_route[t["route_id"]].append(t)
        trip_by_id[t["trip_id"]] = t

    seq_by_trip = collections.defaultdict(list)
    for st in stop_times:
        seq_by_trip[st["trip_id"]].append((int(st["stop_sequence"]), st["stop_id"]))
    for tid in seq_by_trip:
        seq_by_trip[tid].sort()

    # ---- which routes serve each station -----------------------------
    routes_at_station = collections.defaultdict(set)
    for tid, seq in seq_by_trip.items():
        t = trip_by_id.get(tid)
        if not t:
            continue
        sn = route_by_id[t["route_id"]]["route_short_name"]
        for _, sid in seq:
            routes_at_station[station_of(sid)].add(sn)

    def sn_key(x):
        try:
            return (0, int(x))
        except ValueError:
            return (1, x)

    # ---- ordered stop list(s) per route ------------------------------
    # Pro každý směr (direction_id) se vezme nejdelší spoj jako páteř a sleje
    # se s ostatními (SCS), takže nechybí jednosměrné zastávky ani závleky
    # (zastávky obsluhované jen v jednom směru / jen u části spojů). Okružní
    # linky se zachovají včetně návratu (nejdelší spoj má celý průjezd).
    # Když se oba směry liší množinou zastávek, vrátí se seznam po směrech;
    # u symetrických linek jeden seznam (`stops`).
    def stations_of_trip(tid):
        out = []
        for _, sid in seq_by_trip.get(tid, []):
            stn = station_of(sid)
            if not out or out[-1] != stn:   # jen po sobě jdoucí dedup
                out.append(stn)
        return out

    def dir_superset(tlist):
        pats = [(t, stations_of_trip(t["trip_id"])) for t in tlist]
        pats = [p for p in pats if p[1]]
        if not pats:
            return None
        pats.sort(key=lambda ts: len(ts[1]), reverse=True)   # páteř = nejdelší
        merged = pats[0][1][:]
        for _, s in pats[1:]:
            merged = merge_variant(merged, s)
        head = (pats[0][0].get("trip_headsign") or "").strip()
        if not head and merged:
            last = stations.get(merged[-1])
            head = last["stop_name"] if last else ""
        return merged, head

    route_stop_list = {}
    route_dirs = {}
    for rid, rtrips in trips_by_route.items():
        by_dir = collections.defaultdict(list)
        for t in rtrips:
            by_dir[t.get("direction_id", "")].append(t)
        ds = []
        for d, tl in by_dir.items():
            sup = dir_superset(tl)
            if sup:
                ds.append((len(tl), sup[0], sup[1]))   # (počet spojů, sled, headsign)
        ds.sort(key=lambda x: x[0], reverse=True)
        if not ds:
            route_stop_list[rid] = []
            continue
        if len(ds) >= 2 and ds[0][1] != ds[1][1]:
            # dva směry s odlišným sledem zastávek → dva seznamy + sjednocení pro
            # highlight na mapě. Liší se i pouhým obrácením pořadí (typicky tramvaje,
            # kde oba směry sdílí rodičovské stanice), aby měly přepínač směru taky.
            route_dirs[rid] = [
                {"headsign": ds[0][2], "stops": ds[0][1]},
                {"headsign": ds[1][2], "stops": ds[1][1]},
            ]
            union, seen = ds[0][1][:], set(ds[0][1])
            for s in ds[1][1]:
                if s not in seen:
                    seen.add(s); union.append(s)
            route_stop_list[rid] = union
        else:
            # jednosměrné (okružní) / oba směry shodné → jeden seznam (nejúplnější směr)
            route_stop_list[rid] = max(ds, key=lambda x: len(x[1]))[1]

    # ---- shapes -------------------------------------------------------
    pts_by_shape = collections.defaultdict(list)
    for row in shapes_raw:
        pts_by_shape[row["shape_id"]].append(
            (int(row["shape_pt_sequence"]), float(row["shape_pt_lon"]), float(row["shape_pt_lat"]))
        )
    for sid in pts_by_shape:
        pts_by_shape[sid].sort()

    shapes_by_route = collections.defaultdict(set)
    for t in trips:
        if t["shape_id"]:
            shapes_by_route[t["route_id"]].add(t["shape_id"])

    # ---- routes.json --------------------------------------------------
    routes_out = []
    for r in sorted(routes, key=lambda r: sn_key(r["route_short_name"])):
        rid = r["id"] if "id" in r else r["route_id"]
        ro = {
            "id": rid,
            "short_name": r["route_short_name"],
            "long_name": r["route_long_name"],
            "type": "tram" if r["route_type"] == "0" else "bus",
            "color": color[rid],
            "stops": route_stop_list.get(rid, []),
        }
        if route_dirs.get(rid):
            ro["directions"] = route_dirs[rid]
        routes_out.append(ro)
    with open(os.path.join(OUT, "routes.json"), "w", encoding="utf-8") as fh:
        json.dump(routes_out, fh, ensure_ascii=False, separators=(",", ":"))

    # ---- stops.json ---------------------------------------------------
    stops_out = []
    for sid, s in stations.items():
        served = sorted(routes_at_station.get(sid, []), key=sn_key)
        if not served:
            continue
        stops_out.append({
            "id": sid,
            "code": s.get("stop_code", ""),
            "name": s["stop_name"],
            "lat": rnd(s["stop_lat"]),
            "lon": rnd(s["stop_lon"]),
            "zone": s.get("zone_id", ""),
            "wheelchair": s.get("wheelchair_boarding", ""),
            "routes": served,
        })

    # Stanice, které dnes obsluhuje jen linka „aktuálně mimo provoz" z archivu
    # (former-lines.json) – v aktuálním feedu je nikdo nejezdí, takže by z stops.json
    # vypadly a akt linka (odkazuje na ně přes ID) by je nedohledala (mj. Areál Vesec u
    # linky 41, která vyjede jen 1× za rok). Přidáme je zpět: souřadnice z GTFS stops.txt,
    # a když stanice zmizí i odtud, z natvrdo zadaných FORMER_STOP_FALLBACK.
    former_routes = {}
    _fp = os.path.join(OUT, "former-lines.json")
    if os.path.exists(_fp):
        try:
            for _short, _rec in json.load(open(_fp, encoding="utf-8")).items():
                _sids = set(str(i) for i in (_rec.get("stops") or []))
                for _d in (_rec.get("directions") or []):
                    _sids.update(str(i) for i in (_d.get("stops") or []))
                for _i in _sids:
                    former_routes.setdefault(_i, []).append(_short)
        except (ValueError, OSError):
            pass
    _have = {x["id"] for x in stops_out}
    for sid in sorted(set(former_routes) - _have):
        st = stations.get(sid)
        fb = FORMER_STOP_FALLBACK.get(sid)
        rt = sorted(former_routes[sid], key=sn_key)
        if st:
            stops_out.append({"id": sid, "code": st.get("stop_code", ""), "name": st["stop_name"],
                              "lat": rnd(st["stop_lat"]), "lon": rnd(st["stop_lon"]),
                              "zone": st.get("zone_id", ""), "wheelchair": st.get("wheelchair_boarding", ""),
                              "routes": rt})
        elif fb:
            stops_out.append({"id": sid, "code": "", "name": fb["name"],
                              "lat": rnd(fb["lat"]), "lon": rnd(fb["lon"]),
                              "zone": "", "wheelchair": "", "routes": rt})

    stops_out.sort(key=lambda x: x["name"])
    with open(os.path.join(OUT, "stops.json"), "w", encoding="utf-8") as fh:
        json.dump(stops_out, fh, ensure_ascii=False, separators=(",", ":"))

    # ---- timetable.json (odjezdy v zastávce + poloha vozidel dle JŘ) ---
    stop_index = {s["id"]: i for i, s in enumerate(stops_out)}   # id stanice -> index ve stops.json
    timetable = build_timetable(trips, stop_times, route_by_id, station_of, stop_index, calendar)
    with open(os.path.join(OUT, "timetable.json"), "w", encoding="utf-8") as fh:
        json.dump(timetable, fh, ensure_ascii=False, separators=(",", ":"))

    # ---- shapes.geojson (one MultiLineString feature per route) -------
    features = []
    raw_pts = simp_pts = 0
    for r in routes_out:
        rid = r["id"]
        lines = []
        seen_geo = set()
        for shid in sorted(shapes_by_route.get(rid, [])):
            pts = [(lon, lat) for _, lon, lat in pts_by_shape.get(shid, [])]
            if len(pts) < 2:
                continue
            raw_pts += len(pts)
            pts = simplify(pts, COORD_DP_TOLERANCE)
            simp_pts += len(pts)
            coords = [[rnd(lon), rnd(lat)] for lon, lat in pts]
            key = (coords[0][0], coords[0][1], coords[-1][0], coords[-1][1], len(coords))
            if key in seen_geo:
                continue
            seen_geo.add(key)
            lines.append(coords)
        if not lines:
            continue
        features.append({
            "type": "Feature",
            "properties": {
                "id": rid,
                "short_name": r["short_name"],
                "type": r["type"],
                "color": r["color"],
            },
            "geometry": {"type": "MultiLineString", "coordinates": lines},
        })
    with open(os.path.join(OUT, "shapes.json"), "w", encoding="utf-8") as fh:
        json.dump({"type": "FeatureCollection", "features": features},
                  fh, ensure_ascii=False, separators=(",", ":"))

    # ---- former-lines.json (archiv posledního reálného tvaru KAŽDÉ linky) ----
    # Sezónní linky (školní, komerční 41, historická 1/4…) se do GTFS vracejí jen
    # část roku. Aby se daly i mimo sezónu vykreslit skutečnou trasou (ne aproximací),
    # ukládáme tvar každé linky, když je zrovna ve feedu. Slučuje se – nikdy nemažeme,
    # takže archiv drží poslední známou verzi i pro linky, co z feedu vypadly. Klient
    # z něj kreslí linky „aktuálně mimo provoz" (jsou v archivu, ale ne v routes.json).
    former_path = os.path.join(OUT, "former-lines.json")
    former = {}
    if os.path.exists(former_path):
        try:
            former = json.load(open(former_path, encoding="utf-8"))
        except (ValueError, OSError):
            former = {}
    geom_by_rid = {f["properties"]["id"]: f["geometry"] for f in features}
    last_seen = feed.get("feed_end_date", "")
    for r in routes_out:
        rec = {"type": r["type"], "long_name": r["long_name"], "stops": r["stops"],
               "geometry": geom_by_rid.get(r["id"], {"type": "MultiLineString", "coordinates": []}),
               "last_seen": last_seen}
        if r.get("directions"):
            rec["directions"] = r["directions"]
        former[r["short_name"]] = rec
    with open(former_path, "w", encoding="utf-8") as fh:
        json.dump(former, fh, ensure_ascii=False, separators=(",", ":"))

    # ---- legacy-shapes.json (trasy linek mimo provoz sešité po ulicích) ----
    print("sešívám trasy linek mimo provoz:")
    legacy_shapes = build_legacy_shapes(trips, stop_times, shapes_raw, station_of, stations)
    with open(os.path.join(OUT, "legacy-shapes.json"), "w", encoding="utf-8") as fh:
        json.dump(legacy_shapes, fh, ensure_ascii=False, separators=(",", ":"))

    # ---- meta.json ----------------------------------------------------
    meta = {
        "agency": "Dopravní podnik měst Liberce a Jablonce nad Nisou, a.s.",
        "valid_from": feed.get("feed_start_date", ""),
        "valid_to": feed.get("feed_end_date", ""),
        "version": feed.get("feed_version", ""),
        "source": "http://www.dpmlj.cz/gtfs.zip",
        "counts": {"routes": len(routes_out), "stops": len(stops_out),
                   "shapes_features": len(features), "trips": len(timetable["trips"])},
    }
    with open(os.path.join(OUT, "meta.json"), "w", encoding="utf-8") as fh:
        json.dump(meta, fh, ensure_ascii=False, indent=2)

    def kb(path):
        return round(os.path.getsize(os.path.join(OUT, path)) / 1024)

    print("OK ->", OUT)
    print(f"  routes.json    {kb('routes.json'):>5} kB  ({len(routes_out)} linek)")
    print(f"  stops.json     {kb('stops.json'):>5} kB  ({len(stops_out)} stanic)")
    print(f"  shapes.json    {kb('shapes.json'):>5} kB  ({len(features)} tras, "
          f"body {raw_pts}->{simp_pts})")
    tt = json.load(open(os.path.join(OUT, "timetable.json"), encoding="utf-8"))
    print(f"  timetable.json {kb('timetable.json'):>5} kB  ({len(tt['trips'])} spojů, "
          f"{len(tt['services'])} služeb)")
    print(f"  meta.json      platnost {meta['valid_from']}-{meta['valid_to']}")


if __name__ == "__main__":
    main()
