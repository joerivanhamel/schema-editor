#!/usr/bin/env bash
# Re-import check through the admin form (the path a site owner uses): a template imported over an existing one of
# the same name must replace its content and change the front-end output. Run after docker/e2e.sh.
set -euo pipefail
export MSYS_NO_PATHCONV=1
COMPOSE="docker compose -f docker/docker-compose.yml"
URL="http://localhost:8089"
TMP="docker/e2e/out"
mkdir -p "$TMP"
JAR="$TMP/jar.txt"; rm -f "$JAR"

wp() { $COMPOSE run --rm -T cli "$@" 2>/dev/null; }

# A changed copy of the service template: the description now reads a literal marker.
python - <<'EOF'
import json
docs=json.load(open('docker/e2e/templates.json',encoding='utf-8'))
svc=[d for d in docs if d['name']=='E2E 02 Service page'][0]
svc['nodes'][0]['description']="{{ 'REIMPORT-MARKER' }}"
json.dump([svc],open('docker/e2e/out/reimport.json','w',encoding='utf-8'))
EOF

echo "== log in (test site credentials)"
curl -s -c "$JAR" -b "$JAR" -o "$TMP/discard.html" "$URL/wp-login.php"
curl -s -c "$JAR" -b "$JAR" -o "$TMP/discard.html" --data-urlencode "log=admin" --data-urlencode "pwd=admin" --data-urlencode "testcookie=1" "$URL/wp-login.php"

echo "== import through Settings & Import"
page=$(curl -s -b "$JAR" "$URL/wp-admin/edit.php?post_type=schema_template&page=schema-editor-settings")
nonce=$(printf '%s' "$page" | python -c "import re,sys; h=sys.stdin.read(); i=h.find('value=\"schema_editor_import\"'); seg=h[max(0,i-600):i+200]; m=re.findall(r'name=\"_wpnonce\" value=\"([0-9a-f]+)\"', seg); print(m[-1] if m else '')")
echo "   nonce: ${nonce:-MISSING}"
loc=$(curl -s -b "$JAR" -o "$TMP/discard.html" -w "%{redirect_url}" -F "action=schema_editor_import" -F "_wpnonce=$nonce" -F "schema_editor_file=@$TMP/reimport.json;type=application/json" "$URL/wp-admin/admin-post.php")
python -c "import sys,urllib.parse; q=urllib.parse.urlparse(sys.argv[1]).query; print('   notice:', urllib.parse.parse_qs(q).get('se_message',['(none)'])[0])" "$loc"

echo "== stored content"
id=$(wp post list --post_type=schema_template --title="E2E 02 Service page" --field=ID | head -1)
wp post get "$id" --field=post_content | grep -o 'REIMPORT-MARKER' | head -1 | sed 's/^/   stored: /' || echo "   stored: marker NOT found"

echo "== front end"
curl -s "$URL/garden-design/?x=$RANDOM" | grep -o 'REIMPORT-MARKER' | head -1 | sed 's/^/   rendered: /' || echo "   rendered: marker NOT found"

echo "== restore the original template"
wp schema-editor import /plugin/docker/e2e/templates.json >/dev/null && echo "   restored"
