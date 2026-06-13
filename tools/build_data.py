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
import csv, json, os, collections, colorsys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
GTFS = os.path.join(ROOT, "gtfs")
OUT = os.path.join(ROOT, "mapa-assets", "data")
COORD_DP_TOLERANCE = 0.00004   # ~4 m, Douglas-Peucker simplification
COORD_DECIMALS = 5             # ~1.1 m precision


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


# ---------------------------------------------------------------- colors
TRAM_COLORS = {"2": "#e4002b", "3": "#0078c8", "5": "#009a44", "11": "#f2a900"}


def bus_color(i, n):
    h = (i / max(n, 1))
    r, g, b = colorsys.hls_to_rgb(h, 0.45, 0.62)
    return "#%02x%02x%02x" % (int(r * 255), int(g * 255), int(b * 255))


# ---------------------------------------------------------------- main
def main():
    os.makedirs(OUT, exist_ok=True)
    routes = read("routes.txt")
    trips = read("trips.txt")
    stops = read("stops.txt")
    stop_times = read("stop_times.txt")
    shapes_raw = read("shapes.txt")
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

    # ---- representative ordered stop list per route ------------------
    route_stop_list = {}
    for rid, rtrips in trips_by_route.items():
        best = None
        for t in rtrips:
            seq = seq_by_trip.get(t["trip_id"], [])
            if best is None or len(seq) > len(best[1]):
                best = (t, seq)
        ordered, seen = [], set()
        if best:
            for _, sid in best[1]:
                stn = station_of(sid)
                if stn not in seen:
                    seen.add(stn)
                    ordered.append(stn)
        route_stop_list[rid] = ordered

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
        routes_out.append({
            "id": rid,
            "short_name": r["route_short_name"],
            "long_name": r["route_long_name"],
            "type": "tram" if r["route_type"] == "0" else "bus",
            "color": color[rid],
            "stops": route_stop_list.get(rid, []),
        })
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
    stops_out.sort(key=lambda x: x["name"])
    with open(os.path.join(OUT, "stops.json"), "w", encoding="utf-8") as fh:
        json.dump(stops_out, fh, ensure_ascii=False, separators=(",", ":"))

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

    # ---- meta.json ----------------------------------------------------
    meta = {
        "agency": "Dopravní podnik měst Liberce a Jablonce nad Nisou, a.s.",
        "valid_from": feed.get("feed_start_date", ""),
        "valid_to": feed.get("feed_end_date", ""),
        "version": feed.get("feed_version", ""),
        "source": "http://www.dpmlj.cz/gtfs.zip",
        "counts": {"routes": len(routes_out), "stops": len(stops_out),
                   "shapes_features": len(features)},
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
    print(f"  meta.json      platnost {meta['valid_from']}-{meta['valid_to']}")


if __name__ == "__main__":
    main()
