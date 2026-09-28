#!/usr/bin/env bash
# End-to-end check in a throwaway WordPress: Rank Math + Schema Editor, templates imported, JSON-LD inspected.
# Run from the repository root:  bash docker/e2e.sh        (add "down" to remove the containers and volumes)
set -euo pipefail
export MSYS_NO_PATHCONV=1
COMPOSE="docker compose -f docker/docker-compose.yml"
URL="http://localhost:8089"

if [ "${1:-}" = "down" ]; then
	$COMPOSE down -v
	exit 0
fi

wp() { $COMPOSE run --rm -T cli "$@"; }

echo "== up"
$COMPOSE up -d --quiet-pull
for i in $(seq 1 40); do
	if curl -s -o /dev/null "$URL/" ; then break; fi
	sleep 3
done

echo "== install WordPress"
if ! wp core is-installed >/dev/null 2>&1; then
	wp core install --url="$URL" --title="Schema Editor test" --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
fi
wp option update permalink_structure '/%postname%/' >/dev/null
wp rewrite flush --hard >/dev/null 2>&1 || true

echo "== plugins"
wp plugin is-installed seo-by-rank-math >/dev/null 2>&1 || wp plugin install seo-by-rank-math >/dev/null
wp plugin activate seo-by-rank-math schema-editor >/dev/null
# Rank Math only runs its front end once the setup wizard has marked the site configured; a CLI install never
# ran the wizard, so set the flag and the settings it would have written (knowledge graph, Local SEO, a price
# range for template 01 to remove).
wp option update rank_math_modules '["link-counter","sitemap","rich-snippet","local-seo","acf"]' --format=json >/dev/null
wp option update rank_math_is_configured 1 >/dev/null
wp option update rank_math_registration_skip 1 >/dev/null
wp option update rank-math-options-titles '{"knowledgegraph_type":"company","knowledgegraph_name":"Test Studio Ltd","website_name":"Schema Editor test","local_business_type":"HomeAndConstructionBusiness","price_range":"£££","url":"http://localhost:8089/","local_address":{"streetAddress":"1 Test Lane","addressLocality":"Hitchin","postalCode":"SG5 3XD","addressCountry":"GB"},"geo":"51.9670911,-0.2854595","title_separator":"|","pt_post_default_rich_snippet":"article","pt_post_default_article_type":"BlogPosting","pt_page_default_rich_snippet":"off"}' --format=json >/dev/null
wp option update rank-math-options-general '{"strip_category_base":"off","attachment_redirect_urls":"on"}' --format=json >/dev/null
wp option update schema_editor_settings '{"output_mode":"auto","force_global_entities":true,"disable_rankmath_search":true,"debug_comment":true,"delete_on_uninstall":false}' --format=json >/dev/null

echo "== content"
page_id=$(wp post list --post_type=page --name=garden-design --field=ID | head -1)
if [ -z "$page_id" ]; then
	page_id=$(wp post create --post_type=page --post_status=publish --post_title="Garden Design & Build" --post_name=garden-design --porcelain)
fi
wp post meta update "$page_id" hero_subtitle "Design-led gardens shaped around your home." >/dev/null
wp post meta update "$page_id" rank_math_description "Meta description that should lose to the subtitle." >/dev/null
team_id=$(wp post list --post_type=page --name=team --field=ID | head -1)
if [ -z "$team_id" ]; then
	team_id=$(wp post create --post_type=page --post_status=publish --post_title="Meet the Team" --post_name=team --porcelain)
fi
for person in "Alex Haerle|Lead Designer" "Jon Dundon|Aftercare Lead" "Nobody|"; do
	name="${person%%|*}"; role="${person##*|}"
	slug=$(echo "$name" | tr 'A-Z ' 'a-z-')
	pid=$(wp post list --post_type=post --name="$slug" --field=ID | head -1)
	if [ -z "$pid" ]; then
		pid=$(wp post create --post_type=post --post_status=publish --post_title="$name" --post_name="$slug" --post_content="Profile." --porcelain)
	fi
	if [ -n "$role" ]; then wp post meta update "$pid" team_role "$role" >/dev/null; fi
done
film_id=$(wp post list --post_type=post --name=calm-garden-reveal --field=ID | head -1)
if [ -z "$film_id" ]; then
	film_id=$(wp post create --post_type=post --post_status=publish --post_title="A Completed Garden, Seen in Motion" --post_name=calm-garden-reveal --post_content="A film." --porcelain)
fi
wp post meta update "$film_id" video_id RPX44L1DfQU >/dev/null
wp post meta update "$film_id" video_title "A Calm Garden Reveal" >/dev/null
wp post meta update "$film_id" video_duration "0:18" >/dev/null
# A featured image for the film (a generated PNG) so thumbnailUrl resolves.
if [ -z "$(wp post meta get "$film_id" _thumbnail_id 2>/dev/null)" ]; then
	att=$(wp media import /plugin/docker/e2e/still.png --post_id="$film_id" --featured_image --porcelain 2>/dev/null || true)
	echo "   featured image: ${att:-none}"
fi

echo "== import templates"
wp schema-editor import /plugin/docker/e2e/templates.json
wp schema-editor list

echo "== render (CLI)"
wp schema-editor render "$page_id" | head -40

echo "== fetch pages"
mkdir -p docker/e2e/out
curl -s "$URL/garden-design/" -o docker/e2e/out/garden-design.html
curl -s "$URL/team/" -o docker/e2e/out/team.html
curl -s "$URL/calm-garden-reveal/" -o docker/e2e/out/film.html
curl -s "$URL/" -o docker/e2e/out/home.html
echo "fetched $(wc -c < docker/e2e/out/garden-design.html) bytes for /garden-design/"
