"""Build the installable plugin zip with forward-slash entry names (PowerShell's Compress-Archive writes
backslashes, which unpack as literal file names on a Linux host). Run from the repository root:

    python docker/build-zip.py
"""
import os
import re
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
INCLUDE_FILES = ["schema-editor.php", "uninstall.php", "README.md", "CHANGELOG.md", "composer.json"]
INCLUDE_DIRS = ["src", "assets", "templates"]

with open(os.path.join(ROOT, "schema-editor.php"), encoding="utf-8") as fh:
    version = re.search(r"Version:\s*([0-9.]+)", fh.read()).group(1)

out = os.path.join(ROOT, "dist", f"schema-editor-{version}.zip")
os.makedirs(os.path.dirname(out), exist_ok=True)
if os.path.exists(out):
    os.remove(out)

count = 0
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for f in INCLUDE_FILES:
        z.write(os.path.join(ROOT, f), "schema-editor/" + f)
        count += 1
    for d in INCLUDE_DIRS:
        for root, _dirs, files in os.walk(os.path.join(ROOT, d)):
            for f in sorted(files):
                full = os.path.join(root, f)
                rel = os.path.relpath(full, ROOT).replace(os.sep, "/")
                z.write(full, "schema-editor/" + rel)
                count += 1

with zipfile.ZipFile(out) as z:
    names = z.namelist()
    bad = [n for n in names if "\\" in n]
    main = "schema-editor/schema-editor.php" in names

print(f"{out}: {count} files, {os.path.getsize(out):,} bytes")
print("backslash entries:", len(bad), "| main file present:", main)
sys.exit(0 if main and not bad else 1)
