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
        mm = re.match(r'^Zastávka\s*(?:min|:)\s+(.+)$', c)   # 1. zast. nalepená na hlavičku „Zastávka min NÁZEV"
        if mm:
            c = mm.group(1)
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

# ── overrides (dev/snapshot-<rok>-overrides.json) ────────────────────────────
# Klíč = dobový surový název zastávky. KONVENCE: každý override nese OBOJÍ –
#   {"match": "<dnešní název>", "lat": …, "lon": …}
# „match" slouží jen k sešití geometrie (stitcher hledá zastávku v dnešní GTFS síti
# podle jména); „lat/lon" jsou pojistka polohy. Proč obojí: objížďka může zastávku
# dočasně VYHODIT Z FEEDU (stalo se u „Ulice 5. května"/„Průmyslová škola") – pak by
# build na samotném „match" spadl, nebo hůř: tiše se přemapovalo vedle. Se souřadnicemi
# build jede, poloha je přesná, a až se zastávka do feedu vrátí, geometrie se sešije sama.
# Zobrazený název je vždy dobový (emit bere raw), override mění jen polohu/geometrii.
# Dál: {"skip": true} = smetí z extrakce.
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
    if isinstance(raw, dict):        # {"raw": zobrazený název, "as": co resolvovat} – stejný název jinde v síti
        r = {"raw": raw["raw"]}; r.update(resolve(raw["as"])); return r
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
            'tarif', 'sms', 'ukončení', 'dopravce', 'informace', 'zpracov', 'bezbarier',
            'bezbariér', 'nízkopodlaž', 'neoznačen', 'znamení', 'jede ', 'jízdy', 'e-mail', 'tel:',
            'tel.', 'www', '@', 'počet', 'směr', 'seznam zast', 'platnost', 'obslu', 'nejede',
            'provoz', 'přestup', 'náhradní', 'zajiš', 'zajížd', 'spoje', 'spoj ', 'sloupec',
            'historick', 'pracovní', 'v zastávce', 'ostatní', 'do zast', 'minut',
            'jízdenk', 'lib25', 'lib36', 'lib na', 'na číslo', 'pošlete', 'zprávu', 've tvaru',
            'zdarma', 'výlukov', 'skeleton', 'projekt', 'statutár', 'platném', 'nařízením', '©',
            'odj', 'přj', 'jízdní řád', 'v pdf', 'pdf', 'zastávk', 'objíž',
            't0', 'tq', 'ex,', '6 o', 'p 6', 'émem')   # font smetí z rozbitých PDF
def _pdf_is_stop(l):
    if len(l) < 2 or len(l) > 34 or ':' in l or re.search(r'[$#%]', l): return False  # font smetí (D$%, X#,)
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
    l = re.sub(r'(?<=[A-Za-zÁ-Žá-ž])[12]$', '', l)           # zóna nalepená bez mezery („údolí1")
    return l.strip()
def _pdf_collect(lines, start):
    out = []
    for l in lines[start:]:
        if not l: continue
        ll = l.lower()
        if (l == "•" or re.fullmatch(r'\d{1,2}:\d{2}', l) or re.fullmatch(r'(0\d|1\d|2[0-3])', l)
                or ll.startswith('upozorn') or ll.startswith('poznám') or 'vysvětlivk' in ll):
            break                                        # • / čas / hodina / začátek poznámek = konec sledu
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

def _collapse_repeat(seq, key=lambda x: x):
    """Sled celý zopakovaný N× (víc­stránkový JŘ slepený za sebe) → jeden cyklus.
    Nedotkne se legitimního tam-a-zpět (to není celý-sled-krát-k)."""
    n = len(seq)
    for k in (5, 4, 3, 2):
        if n % k == 0:
            p = n // k
            if all(key(seq[i]) == key(seq[i % p]) for i in range(n)):
                return seq[:p]
    return seq

def extract_pdf_dirs(path):
    """Sledy zastávek po směrech. DPMLJ PDF 2021 mají 1 stranu = 1 směr (druhá
    strana = opačný směr). Vrací [ [stop,…], … ] – distinktní sledy (loop = 1)."""
    dirs = []
    for pg in fitz.open(path):
        s = _collapse_repeat(_pdf_page_stops(pg.get_text()))
        if len(s) >= 2 and s not in dirs:
            dirs.append(s)
    return dirs or [[]]

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

    # poloha stanic – k ověření, že výřez shape opravdu spojuje obě zastávky
    import math as _mm
    _d = lambda p, q: _mm.hypot((p[0]-q[0]) * 111000 * _mm.cos(_mm.radians(p[1])), (p[1]-q[1]) * 111000)
    st_pos = {}
    for sid, s in stations.items():
        try: st_pos[sid] = (float(s["stop_lon"]), float(s["stop_lat"]))
        except (KeyError, TypeError, ValueError): pass

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
            # U ČÁSTI SPOJŮ jsou shape_dist_traveled rozbité → výřez nesmyslný (Sokolská→
            # Dožínková: 77 m místo 1334 m, a začínal 1443 m od zastávky). A protože se dál
            # bere ten NEJKRATŠÍ, takový vadný výřez vždycky vyhrál. Zahodit ty, které
            # nedosahují k oběma zastávkám.
            pa, pb = st_pos.get(a), st_pos.get(b)
            if pa and pb and (_d(line[0], pa) > 120 or _d(line[-1], pb) > 120):
                continue                       # 120 m: bod shape bývá od zastávky i pár desítek m
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

    def direct(a, b):
        """Jen PŘÍMÝ úsek mezi dvěma zastávkami (skutečná trasa MHD), bez multi-hopu.
        Multi-hop Dijkstra po zastávkách dělá u historických linek nesmysly (objede to
        přes zastávky jiné linky) – od toho je uliční router.

        Bere DOPŘEDNÝ směr; opačný jen když dopředný neexistuje. Opačný směr NELZE brát
        jen proto, že má víc bodů: u JEDNOSMĚREK vede tam a zpět po JINÝCH ulicích
        (Šaldovo↔Sokolská: 266 m stranou; napříč sítí 131 takových úseků) a kreslila by
        se linka po špatné ulici. Hrubě zakreslený dopředný směr řeší uliční router."""
        if (a, b) in seg: return seg[(a, b)][1]
        if (b, a) in seg: return list(reversed(seg[(b, a)][1]))
        return None

    nrm = lambda x: " ".join(str(x or "").strip().lower().split())
    name2st = {}
    for sid, s in stations.items():
        name2st.setdefault(nrm(s["stop_name"]), sid)
    return (lambda name: name2st.get(nrm(name))), shortest, direct


def load_street_router():
    """Router po SKUTEČNÉ uliční geometrii: graf nad body všech GTFS shapes (ne jen skoky
    mezi zastávkami jako load_gtfs_stitcher). Umí spojit i dvojici zastávek, mezi kterými
    dnes žádná linka nejezdí – úseková sešívačka tam jinak udělá rovnou čáru, nebo (hůř)
    velkou okliku přes zastávky jiné linky (2022/17: 3116 m místo 610 m vzdušně).
    Vrací street((lat,lon),(lat,lon)) -> [[lon,lat],…] | None; nebo None když gtfs/ chybí."""
    import csv, heapq, collections, math as _m
    p = os.path.join(ROOT, "gtfs", "shapes.txt")
    if not os.path.exists(p):
        return None
    # POZOR na pořadí: uzly grafu jsou (lat, lon). Dřív se tu cos() počítal ze zeměpisné
    # DÉLKY místo ŠÍŘKY → východo-západní vzdálenosti nadhodnocené o ~58 % a router
    # preferoval trasy sever-jih (odtud ty severní okliky).
    d_m = lambda a, b: _m.hypot((a[1]-b[1]) * 111000 * _m.cos(_m.radians(a[0])), (a[0]-b[0]) * 111000)
    key = lambda la, lo: (round(la, 4), round(lo, 4))      # mřížka ~10 m → sdílené ulice se slijí
    shp = collections.defaultdict(list)
    for r in csv.DictReader(open(p, encoding="utf-8-sig", newline="")):
        shp[r["shape_id"]].append((int(r["shape_pt_sequence"]), float(r["shape_pt_lat"]), float(r["shape_pt_lon"])))
    G = collections.defaultdict(dict); POS = {}
    for pts in shp.values():
        pts.sort()
        for (_, la1, lo1), (_, la2, lo2) in zip(pts, pts[1:]):
            k1, k2 = key(la1, lo1), key(la2, lo2)
            POS[k1] = (la1, lo1); POS[k2] = (la2, lo2)
            if k1 == k2:
                continue
            w = d_m((la1, lo1), (la2, lo2))
            if w < G[k1].get(k2, 1e18):
                G[k1][k2] = w; G[k2][k1] = w               # ulice obousměrně
    if not G:
        return None
    nearest = lambda pt: min(POS, key=lambda k: d_m(POS[k], pt))

    def street(a, b):
        s, t = nearest(a), nearest(b)
        if s == t:
            return []
        dist, prev, pq = {s: 0.0}, {}, [(0.0, s)]
        while pq:
            d, u = heapq.heappop(pq)
            if u == t:
                break
            if d > dist.get(u, 1e18):
                continue
            for v, w in G[u].items():
                nd = d + w
                if nd < dist.get(v, 1e18):
                    dist[v] = nd; prev[v] = u; heapq.heappush(pq, (nd, v))
        if t not in dist:
            return None
        path = [t]
        while path[-1] != s:
            path.append(prev[path[-1]])
        path.reverse()
        return [[POS[k][1], POS[k][0]] for k in path]
    return street


def _polylen(g):
    """Délka polyline [(lon,lat), …] v metrech."""
    import math as _m
    if not g or len(g) < 2:
        return 0.0
    return sum(_m.hypot((a[0]-b[0]) * 111000 * _m.cos(_m.radians(a[1])), (a[1]-b[1]) * 111000)
               for a, b in zip(g, g[1:]))


def _path_dist(f, g):
    """Jak daleko leží trasa g od trasy f (max ze vzdáleností bodů g k nejbližšímu bodu f).
    Malé = tatáž ulice; velké = jiná ulice (typicky druhá polovina jednosměrného páru)."""
    import math as _m
    if not f or not g:
        return 1e9
    d = lambda p, q: _m.hypot((p[0]-q[0]) * 111000 * _m.cos(_m.radians(p[1])), (p[1]-q[1]) * 111000)
    return max(min(d(p, q) for q in f) for p in g)


def _max_dev(g, a, b):
    """Největší vzdálenost bodu trasy g od spojnice a–b (v metrech). Malé „vyboulení" = trasa
    kopíruje spojnici (reálná ulice); velké = router se vydal pryč od cíle (objíždí špatně)."""
    import math as _m
    if not g:
        return 0.0
    sc = _m.cos(_m.radians(a[1]))
    ax, ay = a[0] * sc, a[1]; bx, by = b[0] * sc, b[1]
    dx, dy = bx - ax, by - ay
    dd = dx * dx + dy * dy
    best = 0.0
    for p in g:
        px, py = p[0] * sc, p[1]
        t = 0.0 if dd == 0 else max(0.0, min(1.0, ((px - ax) * dx + (py - ay) * dy) / dd))
        best = max(best, _m.hypot(px - (ax + t * dx), py - (ay + t * dy)) * 111000)
    return best


# Úseky, kde je každá dostupná geometrie špatně → raději trasu v tom místě přerušit než
# kreslit nesmysl. Dvojice jsou (od, do) podle match názvů. Zatím prázdné: Šaldovo→Sokolská
# se po opravě metriky (viz load_street_router) sešije správně uličním routerem.
GEOM_OMIT = set()


def route_geometry(pts, stitch, street=None):
    """pts = [(match_name, lon, lat), …] → polyline. Priorita úseku mezi dvěma zastávkami:
      1) PŘÍMÝ úsek MHD (skutečná trasa, kterou dnes linka jezdí) – nejpřesnější,
      2) …ale je-li >1,3× delší než uliční nejkratší cesta, jde nejspíš o OBJÍŽĎKU → ulice,
      3) ULIČNÍ ROUTER, když přímý úsek neexistuje (dnes tudy nic nejezdí),
      4) rovná čára (nouzově).
    Multi-hop Dijkstra po zastávkách se ZÁMĚRNĚ nepoužívá – objížděl to přes zastávky jiných
    linek (2022/17: 3116 m místo 610 m vzdušně).
    Vrací (SEZNAM polyline, počet_rovných) – trasa se může přerušit, viz GEOM_OMIT."""
    if not pts:
        return [], 0
    polys = []
    poly = [[round(pts[0][1], 5), round(pts[0][2], 5)]]
    straights = 0
    resolve, _shortest, direct = stitch if stitch else (None, None, None)
    for i in range(len(pts) - 1):
        na, lo_a, la_a = pts[i]; nb, lo_b, la_b = pts[i + 1]
        if (na, nb) in GEOM_OMIT:                  # jen hrubá rovná čára po jednosměrce →
            if len(poly) > 1: polys.append(poly)   # nekreslit vůbec, trasu tu přerušit
            poly = [[round(lo_b, 5), round(la_b, 5)]]
            continue
        geom = None
        if stitch:
            sa, sb = resolve(na), resolve(nb)
            if sa and sb:
                geom = direct(sa, sb)
                if geom is not None and len(geom) <= 2:
                    geom = None                # 2 body = pouhá spojnice zastávek; GTFS shape tu
                                               # nenese žádný tvar (Šaldovo→Sokolská: 423 m na 2 body).
                                               # Ber jako „geometrii nemáme“ → nastoupí uliční router
                                               # (se svými pojistkami), ne rovná čára přes bloky.
        sgeom = street((la_a, lo_a), (la_b, lo_b)) if street else None
        if sgeom and len(sgeom) > 1:
            # router vede mezi nejbližšími uzly grafu – napoj trasu na SKUTEČNÉ zastávky,
            # a leží-li zastávka daleko od ulic pokrytých MHD, uliční trasa nedává smysl
            ga = _polylen([[lo_a, la_a], sgeom[0]])
            gb = _polylen([sgeom[-1], [lo_b, la_b]])
            crow = _polylen([[lo_a, la_a], [lo_b, la_b]])          # vzdušná vzdálenost
            bulge = _max_dev(sgeom, [lo_a, la_a], [lo_b, la_b])    # jak daleko se trasa vzdálí od spojnice
            if ga > 250 or gb > 250:
                sgeom = None                   # zastávka mimo uliční síť → radši rovná čára
            elif not geom and crow > 100 and (bulge > 0.6 * crow or _polylen(sgeom) > 1.9 * crow):
                sgeom = None                   # Přímý úsek MHD neexistuje (tu ulici dnes nikdo nejezdí)
                                               # a uliční trasa buď míří pryč od cíle (vyboulení), nebo
                                               # je nesmyslně dlouhá → router objíždí po jiných ulicích.
                                               # Rovná čára je poctivější aproximace.
                                               #  2022/15 Šaldovo–Poliklinika: šlo na sever (417 m vzdušně)
                                               #  2022/21 Poliklinika–Školní:  2886 m na 1395 m (2,1×)
            else:
                sgeom = [[lo_a, la_a]] + list(sgeom) + [[lo_b, la_b]]
        if geom and sgeom and _polylen(geom) > 1.3 * _polylen(sgeom):
            geom = sgeom                       # přímý úsek podezřele dlouhý → objížďka, ber ulice
        elif geom and sgeom and _polylen(geom) > 150:
            # GTFS má některé úseky zakreslené jen hrubě (Šaldovo→Sokolská: 2 body na 423 m
            # = rovná čára bez tvaru). Uliční router má tutéž ulici z jiných shapes s detailem.
            # Podmínka „není delší" zaručí, že jde opravdu o tutéž trasu, jen s víc body –
            # ne o objížďku po jiných ulicích.
            # POZOR: uliční graf je obousměrný a nezná jednosměrky – u jednosměrného páru
            # najde tu DRUHOU ulici (Šaldovo→Sokolská: 265 m stranou). Proto musí uliční
            # trasa ležet na TÉŽE ulici jako přímý úsek (_path_dist).
            dens = len(geom) / (_polylen(geom) / 100.0)          # bodů na 100 m
            if dens < 1.5 and _polylen(sgeom) <= 1.4 * _polylen(geom) \
                    and _path_dist(geom, sgeom) < 40:
                geom = sgeom
        if not geom:
            geom = sgeom
        if geom and len(geom) > 1:
            for lo, la in geom:
                p = [round(lo, 5), round(la, 5)]
                if poly[-1] != p: poly.append(p)
        else:
            p = [round(lo_b, 5), round(la_b, 5)]
            if poly[-1] != p: poly.append(p); straights += 1
    if len(poly) > 1:
        polys.append(poly)
    return polys, straights


# ── zápis datových souborů snapshotu (mapa-assets/data/<rok>/) ───────────────
def emit_snapshot_data(linky, rok, write_shapes=True, meta_extra=None):
    today = {r["short_name"]: r for r in json.load(open(os.path.join(DATA, "routes.json"), encoding="utf-8"))}
    def color_for(short, typ):
        base = short[1:] if short[:1] in ("X", "x") and short[1:] in today else short  # X11 zdědí barvu 11
        if base in today and today[base].get("color"):
            return today[base]["color"]
        if typ == "tram":
            return "#cc2900"
        return "hsl(%d, 65%%, 45%%)" % (sum(ord(c) for c in short) * 47 % 360)  # deterministické

    stops, order = {}, []          # dedup podle resolvovaného (match) názvu = fyzická zastávka
    def ref(rowdict, short):
        key = rowdict["match"]
        if key not in stops:
            name = re.sub(r'^x(?=[A-ZÁ-Ž])', '', rowdict["raw"]).strip()  # 2001 název (bez „x" na znamení)
            stops[key] = {"id": len(order) + 1, "name": name, "lat": rowdict["lat"], "lon": rowdict["lon"], "routes": []}
            order.append(key)
        if short not in stops[key]["routes"]:
            stops[key]["routes"].append(short)
        return stops[key]

    def dir_ids(rows, short):
        ids, seq, geompts = [], [], []
        for r in rows:
            if r["lat"] is None:
                continue
            st = ref(r, short)
            if ids and ids[-1] == st["id"]:          # slij sousední duplicitu (varianty zápisu téže zast.)
                continue
            ids.append(st["id"]); seq.append(st["name"]); geompts.append((r["match"], r["lon"], r["lat"]))
        return ids, seq, geompts

    stitch = load_gtfs_stitcher() if write_shapes else None
    street = load_street_router() if write_shapes else None
    if write_shapes:
        print("geometrie:", "přímé úseky MHD + uliční router" if (stitch and street)
              else ("sešití po síti GTFS" if stitch else "rovné spojnice (gtfs/ chybí)"))

    routes, feats, straight_tot = [], [], 0
    for short, info in linky.items():
        dirs = info.get("dirs") or [info.get("stops", [])]
        outdirs = [dir_ids(d, short) for d in dirs]           # [(ids, seq, geompts), …]
        outdirs = [o for o in outdirs if o[0]] or [([], [], [])]
        prim_ids, prim_seq, prim_geo = outdirs[0]
        col = color_for(short, info["type"])
        derived = (prim_seq[0] + " – " + prim_seq[-1]) if prim_seq else ""
        route = {"id": "r-" + short, "short_name": short, "long_name": info.get("long_name") or derived,
                 "type": info["type"], "color": col, "stops": prim_ids}
        # dva různé směry (jiná množina zastávek = jednosměrné/závleky) → přepínač směru
        if len(outdirs) >= 2 and set(outdirs[0][0]) != set(outdirs[1][0]):
            route["directions"] = [{"headsign": (o[1][-1] if o[1] else ""), "stops": o[0]} for o in outdirs]
        routes.append(route)
        if write_shapes:
            # asymetrická linka → vykresli oba směry (jednosměrné úseky, závleky);
            # symetrická → stačí jeden (druhý je jen opačné pořadí téže trasy).
            asym = len(outdirs) >= 2 and set(outdirs[0][0]) != set(outdirs[1][0])
            draw = outdirs if asym else outdirs[:1]
            lines_geo = []
            for _ids, _seq, _geo in draw:
                polys, straights = route_geometry(_geo, stitch, street)
                straight_tot += straights
                lines_geo.extend(p for p in polys if len(p) >= 2)
            if lines_geo:
                feats.append({"type": "Feature",
                              "properties": {"id": "r-" + short, "short_name": short, "color": col},
                              "geometry": {"type": "MultiLineString", "coordinates": lines_geo}})
    if write_shapes:
        print(f"rovných úseků celkem (mimo síť): {straight_tot}")

    out = os.path.join(DATA, str(rok)); os.makedirs(out, exist_ok=True)
    w = lambda fn, obj: json.dump(obj, open(os.path.join(out, fn), "w", encoding="utf-8"), ensure_ascii=False)
    w("stops.json", [stops[k] for k in order])
    w("routes.json", routes)
    if write_shapes:
        w("shapes.json", {"type": "FeatureCollection", "features": feats})
    meta = {"rok": int(rok), "counts": {"routes": len(routes), "stops": len(order)}}
    if meta_extra:
        meta.update(meta_extra)
    w("meta.json", meta)
    return len(order), len(routes)


# ── společný finalizér: resolve názvů, mezisoubor, overrides, zápis dat ───────
def finalize(rok, lines_raw, write_shapes=True, meta_extra=None):
    """lines_raw = {short: {"type","src","long_name"?, "stops":[raw,...] NEBO "dirs":[[raw,...],…]}}.
    „dirs" = sledy po směrech (PDF 2021: strana = směr); jinak jeden „stops"."""
    devdir = os.path.join(ROOT, "dev"); os.makedirs(devdir, exist_ok=True)
    ovpath = os.path.join(devdir, "snapshot-%s-overrides.json" % rok)
    overrides = json.load(open(ovpath, encoding="utf-8")) if os.path.exists(ovpath) else {}
    resolve, _ = make_resolver(overrides)

    linky = {}
    for short, info in lines_raw.items():
        raw_dirs = info.get("dirs") or [info.get("stops", [])]
        fmap = LINE_STOP_FIX.get((str(rok), short))         # per-linku: název ponech, resolvuj jinam
        if fmap:
            raw_dirs = [[({"raw": s, "as": fmap[s]} if (isinstance(s, str) and s in fmap) else s)
                         for s in d] for d in raw_dirs]
        res_dirs = []
        for d in raw_dirs:
            rows = [row(s, resolve) for s in d]
            rows = [r for r in rows if not r.get("skip")]  # vyřaď smetí označené skip
            rows = _collapse_repeat(rows, key=lambda r: r.get("match") or r.get("raw"))  # až po skipu (hlavičky mezi kopiemi)
            res_dirs.append(rows)
        linky[short] = {"type": info["type"], "src": info.get("src", ""),
                        "long_name": info.get("long_name"), "dirs": res_dirs}

    allrows = lambda: [s for v in linky.values() for d in v["dirs"] for s in d]
    json.dump({"rok": rok, "linky": linky},
              open(os.path.join(devdir, "snapshot-%s.json" % rok), "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    unmatched = sorted({s["raw"] for s in allrows() if s["match"] is None})
    added = 0
    for raw in unmatched:
        if raw not in overrides:
            overrides[raw] = {"match": None, "lat": None, "lon": None}; added += 1
    json.dump(dict(sorted(overrides.items())), open(ovpath, "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)

    from collections import Counter
    nmiss = sum(1 for s in allrows() if s["match"] is None)
    src = Counter(s["src"] for s in allrows())
    tot = len(allrows())
    print(f"[{rok}] linky: {len(linky)} | výskytů: {tot} | nespárováno: {nmiss} | unikátů k vyplnění: {len(unmatched)}")
    print("zdroje souřadnic:", dict(src))
    print(f"mezisoubor:  dev/snapshot-{rok}.json")
    print(f"k vyplnění:  dev/snapshot-{rok}-overrides.json ({added} nových stubů, celkem {len(overrides)})")
    if nmiss == 0:
        ns, nr = emit_snapshot_data(linky, rok, write_shapes=write_shapes, meta_extra=meta_extra)
        print(f"\n✓ data: mapa-assets/data/{rok}/ ({nr} linek, {ns} zastávek)")
    else:
        print(f"\n⚠ {nmiss} nespárováno – data NEzapsána (nejdřív dořeš overrides).")
    return nmiss


def build_2001(meta_extra=None, write_shapes=True):
    jr = os.path.join(ROOT, "jr", "2001")
    by_line = {}                                               # {linka: {"t": soubor, "z": soubor}}
    for f in os.listdir(jr):
        if not f.lower().endswith((".htm", ".html")) or f.startswith("Seznam"):
            continue
        m = re.match(r'(\d+)\s*([tz]?)', f)
        if not m:
            continue
        by_line.setdefault(m.group(1), {}).setdefault(m.group(2) or "t", f)
    lines_raw = {}
    lines_raw.update(tram_seed())                              # tramvaje 2,5,11,X3 první (nad busy)
    for ln in sorted(by_line, key=lambda x: (len(x), x)):
        files = by_line[ln]
        seqs = []
        for d in ("t", "z"):                                   # tam, pak zpět = dva směry
            if d in files:
                html = open(os.path.join(jr, files[d]), encoding="utf-8", errors="replace").read()
                s = extract_stops(html)
                if len(s) >= 2:
                    seqs.append(s)
        if not seqs:
            continue
        app = LINE_APPEND.get(("2001", ln))                   # prodloužení jen na některých spojích
        if app:
            if 0 in app and len(seqs) >= 1: seqs[0] = seqs[0] + app[0]
            if 1 in app and len(seqs) >= 2: seqs[1] = app[1] + seqs[1]
        lines_raw[ln] = {"type": "bus", "src": "jr/2001/" + (files.get("t") or files.get("z")),
                         "dirs": seqs}
    finalize("2001", lines_raw, write_shapes=write_shapes, meta_extra=meta_extra)


def build_1995(meta_extra=None, write_shapes=True):
    """Snapshot 1995 z ručně přepsaných skenů (dev/snapshot-1995-source.json – skeny nemají
    textovou vrstvu, přepsáno vizuálně). Formát: {"lines": {linka: {"type","dirs":[[stop,…],…]}}}."""
    src = json.load(open(os.path.join(ROOT, "dev", "snapshot-1995-source.json"), encoding="utf-8"))
    trams = set(src.get("trams", []))
    lines = src["lines"]
    order = sorted(lines, key=lambda x: (x not in trams, len(x), x))   # tramvaje první, pak busy
    lines_raw = {}
    for ln in order:
        info = lines[ln]
        lines_raw[ln] = {"type": info.get("type") or ("tram" if ln in trams else "bus"),
                         "src": "jr/1995 (sken)", "dirs": info["dirs"]}
    finalize("1995", lines_raw, write_shapes=write_shapes, meta_extra=meta_extra)


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


def today_line_segment(short, a_name, b_name):
    """Úsek dnešní linky mezi dvěma zastávkami (surové názvy z dnešních dat) – pro
    injektované výlukové linky, jimž nemáme JŘ (stejné zastávky jako dnešní linka)."""
    routes = json.load(open(os.path.join(DATA, "routes.json"), encoding="utf-8"))
    stops = json.load(open(os.path.join(DATA, "stops.json"), encoding="utf-8"))
    byid = {s["id"]: s["name"] for s in stops}
    r = next((x for x in routes if str(x.get("short_name")) == short), None)
    if not r:
        return []
    seq = [byid[i] for i in r["stops"] if i in byid]
    try:
        a = seq.index(a_name); b = seq.index(b_name)
    except ValueError:
        return []
    return seq[min(a, b):max(a, b) + 1]


# Injektované linky bez JŘ (výluky) po jednotlivých snapshotech. Zastávky = úsek
# dnešní linky; X-prefix je automaticky obarví jako výlukové.
def extra_lines(rok):
    rok = str(rok)
    if rok == "2022":                                       # X11 = výluka k 1.1.2022 (výhybna–Jablonec)
        seg = today_line_segment("11", "Vratislavice n.N. výhybna", "Jablonec n.N., Tyršovy sady")
        return {"X11": {"type": "tram", "src": "úsek dnešní 11 (výhybna–Jablonec)",
                        "long_name": "Vratislavice n.N. výhybna – Jablonec n.N., Tyršovy sady",
                        "dirs": [seg]}} if seg else {}
    if rok == "2011":                                       # výluka Fügnerova–výhybna: X5 i X11
        seg = today_line_segment("11", "Fügnerova", "Vratislavice n.N. výhybna")
        seg = [s for s in seg if s not in ("Sídliště Nové Vratislavice", "Pivovarská")]  # neobsluhovaly se
        if len(seg) < 2:
            return {}
        return {x: {"type": "bus", "src": "úsek dnešní 11 (Fügnerova–výhybna, bez Síd.N.Vr./Pivovarská)",
                    "long_name": "Fügnerova – Vratislavice n.N. výhybna (výluka)", "dirs": [seg]}
                for x in ("X5", "X11")}
    return {}


# Per-(rok, linka): zobrazený název zastávky ponech, ale resolvuj (souřadnice/dedup)
# na jinou zastávku téhož názvu v síti. 2011: linka 11 měla „Zelené Údolí", které
# fyzicky odpovídá jablonecké zastávce (v síti byly 2 stejnojmenné).
LINE_STOP_FIX = {
    ("2011", "11"): {"Zelené Údolí": "Jablonec n.N. Zelené Údolí"},
    ("2001", "12"): {"Polní": "Stračí"},   # „Polní" tehdy = dnešní Stračí (Polní neexistovala)
    ("1995", "12"): {"Polní": "Stračí"},
    ("1995", "1"): {"Pekárny": "Staré Pekárny"},   # tramvajové Pekárny (Hanychov) = dnešní Staré Pekárny
    ("1995", "2"): {"Pekárny": "Staré Pekárny"},
    # dobové „České mládeže" (Hanychov) = dnešní Malodoubská (tram 3 i bus 22)
    ("1995", "3"): {"Pekárny": "Staré Pekárny", "České mládeže": "Malodoubská"},
    ("1995", "22"): {"ČESKÉ MLÁDEŽE": "Malodoubská"},
}

# Prodloužení tras, co v daném roce jezdila jen na některých spojích (v JŘ jen v poznámkách);
# zastávky převzaté z dnešních linek. Klíč (rok, linka) → {index směru: [zastávky]}; směr 0
# se připojí na KONEC, směr 1 na ZAČÁTEK (opačný směr). 2001/24 do Radčic (tam Janův most,
# zpět U Lípy), 2001/26 do Stráže n.N.
LINE_APPEND = {
    ("2001", "24"): {0: ["Obzor", "Janův most", "Radčice rozcestí", "Jedlová", "Radčice"],
                     1: ["Radčice", "Jedlová", "Radčice rozcestí", "U Lípy", "U Radčického potoka"]},
    ("2001", "26"): {0: ["Na Vršku", "Stráž nad Nisou"],
                     1: ["Stráž nad Nisou", "Na Vršku"]},
}


def build_year_folder(rok, meta_extra=None, write_shapes=False):
    """Snapshot z ručně kurátorované složky jr/<rok>/ (1 PDF = 1 linka; jen provozní linky
    roku). Obě strany PDF = oba směry. write_shapes=False → geometrie se NEpřepisuje (řeší se
    zvlášť kvůli momentálním objížďkám ve feedu); True → přesešije nad staženým gtfs/."""
    folder = os.path.join(ROOT, "jr", str(rok))
    TRAMS = {"2", "3", "5", "11"}
    files = {}
    for f in sorted(os.listdir(folder)):
        if not f.lower().endswith(".pdf"):
            continue
        m = re.match(r'(\d+)', f)
        if m:
            files.setdefault(m.group(1), f)                 # 1 soubor na linku
    lines_raw = {}
    for ln in sorted(files, key=lambda x: (len(x), x)):
        dirs = extract_pdf_dirs(os.path.join(folder, files[ln]))
        if not any(dirs):
            print(f"  ⚠ {ln}: sled zastávek nevytažen – vynecháno"); continue
        lines_raw[ln] = {"type": "tram" if ln in TRAMS else "bus",
                         "src": "jr/%s/%s" % (rok, files[ln]), "dirs": dirs}
    lines_raw.update(extra_lines(rok))                      # injektované výlukové linky (X5/X11…)
    finalize(str(rok), lines_raw, write_shapes=write_shapes, meta_extra=meta_extra)


# rok (= název složky/URL) → meta_extra snapshotu. „date" = den stavu sítě; „cat_override"
# = přebití DB kategorie linky (historicky provozní linka, dnes v DB „mimo provoz").
SNAP_META = {
    "2022": {"date": "2022-01-01"},
    "2011": {"date": "2011-11-14", "cat_override": {"90": "nocni"}},   # 90 byla noční linka
    "2001": {"cat_override": {"301": "autobusy"}},                     # 301 dnes „mimo provoz" → tehdy bus
    "1995": {"date": "1995-01-01", "cat_override": {"1": "tramvaje"}},   # 1 tehdy běžná tramvaj (dnes v DB historická)
}

if __name__ == "__main__":
    rok = sys.argv[1] if len(sys.argv) > 1 else "2001"
    meta_extra = SNAP_META.get(rok)
    has_folder = re.fullmatch(r"\d{4}", rok) and rok not in ("2001", "1995") and \
        os.path.isdir(os.path.join(ROOT, "jr", rok)) and \
        any(f.lower().endswith(".pdf") for f in os.listdir(os.path.join(ROOT, "jr", rok)))
    if rok == "2001":
        build_2001(meta_extra=meta_extra)
    elif rok == "1995":                                    # skeny → ruční přepis (dev/snapshot-1995-source.json)
        build_1995(meta_extra=meta_extra)
    elif has_folder:
        build_year_folder(rok, meta_extra=meta_extra)
    elif re.fullmatch(r"\d{4}", rok):
        build_pdf_year(rok)
    else:
        sys.exit("Neznámý rok: %s (použij YYYY)" % rok)
