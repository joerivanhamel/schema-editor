---
name: schema-editor-templates
description: Write, review and fix JSON-LD schema templates for Joeri Vanhamel's Schema Editor WordPress plugin. Use when Joeri asks for schema markup, a schema template, structured data or rich-results work on any WordPress site that runs Schema Editor (with or without Rank Math), when he wants a new template for a page type, when a template isn't populating, or when he asks whether a site's schema follows Google's guidelines.
---

# Schema Editor templates

Schema Editor stores JSON-LD templates as WordPress posts. At request time it picks the templates whose targets
match the page, fills their placeholders from the database, and either merges the resulting nodes into Rank
Math's `@graph` (while Rank Math's Schema module is on) or prints the whole graph itself (Rank Math absent, or
its Schema module switched off, with the rest of Rank Math still running). A template is a JSON document;
writing one well is the whole job. `reference.md` beside this file is the complete language reference;
`examples/` holds finished templates, including `standalone-base.json`, the base set for a site whose graph
the plugin carries alone.

## The three rules that never bend

1. **Nothing hardcoded.** Every value is a placeholder that names a database source (`{{post.title}}`,
   `{{acf.hero_image|image}}`, `{{option.legal_name}}`, `{{rankmath.description}}`). Typing a fact into a
   template is wrong even when the fact is constant: it belongs in Site Settings, Rank Math or a field, and the
   template reads it. If the source does not exist, ask for the field rather than inventing the value.
2. **Only what the user can see.** A property is justified when a visitor landing on that page for its purpose
   could see that information there and would have use for it. Meta descriptions, hidden fields and hard-coded
   theme fallback copy are not visible; if the only source is invisible, say so and let Joeri decide.
3. **Google's guidelines by default, deviations opt-in and documented.** No self-serving `Review` or
   `aggregateRating` on a LocalBusiness or Organization; no ratings copied from another platform; no `FAQPage`
   chasing; no `HowTo`; a `VideoObject` only where the video is actually embedded on the page; `headline` equals
   the visible title; no `foundingDate`, `priceRange` or hours unless they are on the site and true. When Joeri
   wants a deviation anyway, ship it as a separate template with `"enabled": false` and the reasoning in its name
   or a README, so switching it on is a deliberate act.

## Workflow

1. **Understand the page type.** What is the page's mainEntity (a Service, a CreativeWork, a Person, an
   Article)? What does the visitor see: title, standfirst, image, film, location, date, author, terms? Read the
   rendered page, not the theme's intentions.
2. **Discover the real sources.** Field names are the usual point of failure. Use, in order: the template
   editor's Preview box (paste any post URL; it lists every ACF field on that post with a glimpse of its value),
   `wp schema-editor render <post>` on a shell, the ACF field-group definitions, or the site's REST API for
   taxonomy names (`/wp-json/wp/v2/taxonomies`). Note that a value shown on the page may be theme fallback copy
   that is not in the database at all; then no placeholder can reach it.
3. **Decide the targets.** Prefer `page_template` for brand and service pages, `post_type` for custom types and
   posts, `front_page` / `posts_page` / `post_type_archive` for the listings. Use `exclude` rather than a long
   target list when one page of a type must be left out.
4. **Write the nodes.** One `add` per entity the page is about, with `@id` = `{{post.permalink}}#fragment`,
   `@required` on the properties Google requires, and `merge` operations to point Rank Math's `WebPage` at it
   (`mainEntity`, `about`, `video`). Keep `@key` equal to the type so a template replaces a theme's node of the
   same key rather than duplicating it. Use `@if` for nodes that only exist when a field is filled, `@repeat`
   for one node per related post, `@each` for lists built from a repeater.
5. **Prune.** Every property that survives must pass rules 1 and 2. Remove anything decorative.
6. **Verify.** Preview against two or three real posts of the type, including one with empty optional fields,
   and read the resulting JSON. Then, on the live or staging page, check the page source for one graph, no
   duplicate `@id`s, every `{@id}` reference resolving, and no leftover node from the theme with the same
   meaning. Run Google's Rich Results Test on the page (URL mode, or code mode with the page's JSON-LD when the
   host blocks Googlebot) and the Schema Markup Validator. Report errors, warnings and what each warning means.
7. **Hand over.** Explain each template in one table row: applies to, what it adds, which fields it reads and
   which of those still need confirming. Export the set as JSON so it can be re-imported by name.

## When the plugin carries the whole graph

With Rank Math's Schema module off (or no Rank Math), nothing provides the Organization, Place, WebSite,
WebPage, primary ImageObject, BreadcrumbList or BlogPosting unless templates do. Page-type templates that
`merge` into `#webpage` or `@type:BlogPosting` then need a base layer that runs first.

1. **Inventory before building.** Save every URL's current JSON-LD (the sitemaps plus archives, search and 404)
   as the baseline, and list where each node comes from: Rank Math, theme filters on `rank_math/json_ld` (these
   go silent with the module), theme scripts outside Rank Math (these keep printing and will duplicate), and
   existing templates. Read the theme's schema code; it holds the field names and rules to reproduce.
2. **Build the base set** from `examples/standalone-base.json`, all marked `"output": "standalone"` and
   priorities 1 to 4: business, place and website; web page (type by request: SearchResultsPage, ProfilePage,
   CollectionPage, AboutPage and ContactPage from Rank Math's Local SEO settings, else WebPage) with the primary
   image; breadcrumbs from `page.breadcrumbs` (set the theme's trail function in Settings so the markup matches
   the visible trail); articles. Read every value from Rank Math's settings (they stay editable with the module
   off), Site Settings fields and WordPress. Add the site's own theme-side rules on top (extra Organization
   properties, byline authors), never as typed values.
3. **Prove it locally** on a fixture that mirrors the site's data model (post types, field groups, the theme's
   trail function), with the module off, comparing property sets page type by page type against the baseline.
4. **Cut over without a gap.** Upload the plugin and import the set while the module is still on (standalone
   templates stay inert, the live output does not change). Compare every URL's live graph with
   `?schema_editor_preview=standalone` as an administrator. Only then switch Rank Math's Schema module off
   (Automatic output switches itself), purge caches, re-check, and have the theme's own schema scripts removed.

## Conventions Joeri expects

- Priority 1 to 4 for a standalone base set, 5 for site-wide hygiene templates (removing properties, fixing the
  WebSite node), 10 for page-type templates, 15 to 20 for later overrides and opt-in deviations. Later
  priorities can edit or replace what earlier ones added.
- Name templates `<site abbreviation> <number> <what>` so the list reads in order and the export is stable.
- People: names as the site shows them (first names if the site uses first names), `jobTitle` from the record,
  `worksFor` the Organization, no `sameAs` unless the profile is linked on the page. Give every person one stable
  `@id` and use it everywhere that person appears (author, founder, team page).
- Films: `VideoObject` with `name`, `thumbnailUrl`, `uploadDate`, `embedUrl`, `url`, and `duration` and
  `description` whenever a field exists; `youtube_embed` / `youtube_watch` take a bare ID or any YouTube URL;
  `iso_duration` turns "1:24" into `PT1M24S`. Link it from the page (`WebPage.video` or `BlogPosting.video`).
- Case studies and portfolio work: `CreativeWork` (not Article) as `WebPage.mainEntity`, with `creator` and
  `publisher` the Organization, a visible credit as `contributor`, `locationCreated` as a Place, `dateCreated`
  as the year (`|year`), `keywords` from `{{terms.all|join}}`.
- Services: `Service` with `name` and `serviceType` = the page title, `provider` the Organization, `areaServed`
  from the site's own list via `@each`, `description` from visible copy, `image` from the hero.
- Never emit a node with an empty or placeholder-looking value. The renderer omits empties for you; `@required`
  drops the node when a must-have is missing. Use that instead of defaults.

## When something does not populate

Check in this order: the debug footer comment (Settings & Import → print an HTML comment) says whether the
template ran at all; if it ran, the Preview box shows which properties resolved; a glob like `acf.*_hero_image`
matches the first non-empty field whose name fits, so a wrong suffix silently yields nothing; a value visible on
the page but absent in Preview's field list is theme fallback copy, not data. Caches (LiteSpeed, a CDN) serve
old graphs: fetch with a random query string to see the current output.
