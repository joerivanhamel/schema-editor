"""Assertions over the pages docker/e2e.sh fetched. Run: python docker/e2e-assert.py"""
import json
import os
import re
import sys

OUT = os.path.join(os.path.dirname(__file__), "e2e", "out")
ok = True


def graph(name):
    html = open(os.path.join(OUT, name), encoding="utf-8", errors="ignore").read()
    scripts = re.findall(r'<script type="application/ld\+json"[^>]*>(.*?)</script>', html, re.S)
    comments = re.findall(r"<!-- Schema Editor: [^>]*-->", html)
    return [json.loads(s) for s in scripts], comments


def check(cond, msg):
    global ok
    print(("PASS " if cond else "FAIL ") + msg)
    ok = ok and bool(cond)


def by_type(nodes, t):
    return [n for n in nodes if n.get("@type") == t or (isinstance(n.get("@type"), list) and t in n["@type"])]


def by_id_suffix(nodes, suffix):
    return [n for n in nodes if str(n.get("@id", "")).endswith(suffix)]


g, c = graph("consulting.html")
check(len(g) == 1, "consulting: exactly one JSON-LD script (Rank Math prints the merged graph)")
nodes = g[0]["@graph"]
print("  types:", [n.get("@type") for n in nodes], "| comment:", c)
svc = by_type(nodes, "Service")
check(len(svc) == 1, "Service node present once")
check(svc and svc[0].get("description") == "Practical advice shaped around your business.", "description: meta.hero_subtitle beats rankmath.description")
check(svc and all(k not in svc[0] for k in ("areaServed", "award", "image")), "empty areaServed, award and image omitted")
wp = by_type(nodes, "WebPage")
check(wp and wp[0].get("mainEntity", {}).get("@id", "").endswith("#service"), "WebPage.mainEntity merged into Rank Math's WebPage")
org = by_id_suffix(nodes, "#organization")
print("  organization keys:", sorted(org[0].keys()) if org else None)
check(org and "address" in org[0], "Local SEO active: the Organization carries the address (so the next check is real)")
check(org and "priceRange" not in org[0], "priceRange removed from the Organization (Rank Math setting held £££)")
ws = by_type(nodes, "WebSite")
check(ws and "potentialAction" not in ws[0], "SearchAction removed by the setting")

g, c = graph("team.html")
nodes = g[0]["@graph"]
persons = by_type(nodes, "Person")
print("  team persons:", [(p.get("@id"), p.get("name"), p.get("jobTitle")) for p in persons])
check(len(persons) == 2, "two Person nodes (the one without a jobTitle is dropped by @required)")
check(any(p["@id"].endswith("/team/#alex") for p in persons) and any(p["@id"].endswith("/team/#jon") for p in persons), "Person @ids = parent permalink + first name")
wp = by_type(nodes, "WebPage")
check(wp and wp[0].get("about", {}).get("@id", "").endswith("#organization"), "WebPage.about merged")
check(not by_type(nodes, "VideoObject"), "team: film template skipped by @if")

g, c = graph("film.html")
nodes = g[0]["@graph"]
vid = by_type(nodes, "VideoObject")
art = [n for n in nodes if n.get("@type") in ("BlogPosting", "Article", "NewsArticle")]
check(len(vid) == 1, "VideoObject inside the graph")
check(vid and vid[0].get("duration") == "PT0M18S" and vid[0].get("embedUrl", "").endswith("/embed/RPX44L1DfQU") and vid[0].get("thumbnailUrl", "").endswith(".png"), "duration, embedUrl and thumbnailUrl resolved")
check(art and art[0].get("video", {}).get("@id", "").endswith("#video"), "BlogPosting.video merged through the @type selector")

g, c = graph("home.html")
nodes = g[0]["@graph"] if g else []
check(g and not by_type(nodes, "VideoObject"), "home: Rank Math graph present, no VideoObject")
print("  home comment:", c)

print("\nALL PASS" if ok else "\nSOME FAILED")
sys.exit(0 if ok else 1)
