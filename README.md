# Schema Editor

A WordPress plugin by Joeri Vanhamel for writing JSON-LD schema templates that are populated from the database and
applied by display targets. While Rank Math's Schema module is on, the templates are merged into Rank Math's own
`@graph`, so a page never carries two graphs and Rank Math's Organization, WebSite, WebPage, breadcrumb and article
nodes stay as Rank Math builds them. With Rank Math's Schema module switched off (the rest of Rank Math keeps
running) or without Rank Math, the plugin prints the whole graph itself, and a template set can then carry every
node on the site (see "Carrying the whole graph").

Nothing is hardcoded. A template says *where a value comes from* (`{{post.title}}`, `{{acf.hero_image|image}}`,
`{{option.legal_name}}`, `{{rankmath.description}}`) and the plugin reads it at request time. A property whose
source is empty is left out, so a missing field never produces an empty or wrong value, and a node missing one of
its `@required` properties is left out whole.

Requires WordPress 6.4+ and PHP 8.1+. Works with or without Rank Math and Advanced Custom Fields.

## Why not Rank Math's own schema settings

Rank Math Free sets one schema type per post type and one per post, with fixed shapes. Site-wide templates with
display conditions are a Rank Math PRO feature. This plugin gives the same "one template per page type" model to
any site, with three things PRO does not do: editing or removing nodes other code added (`merge` and `remove`
operations), repeating a node per related post (`@repeat`, for example one Person per team member), and the omit-
when-empty rule that keeps the output valid without anyone checking it by hand.

## Carrying the whole graph

To have every node managed by templates, switch Rank Math's Schema (Structured Data) module off; titles, meta,
sitemaps and Local SEO settings stay. The output setting "Automatic" notices and prints the plugin's own graph.
Templates that build what Rank Math used to provide are marked `"output": "standalone"`, so they can be imported
while the module is still on without changing anything live. `templates/examples/standalone-base.json` is a
starting set (business, place and website; the web page typed by request, with its primary image; breadcrumbs;
posts), reading Rank Math's settings and WordPress.

A safe cutover: import the set with the module on; as an administrator, open any page with
`?schema_editor_preview=standalone` to see the graph the plugin will print and compare it with the live one;
then switch the module off, purge caches and check again. Theme code that edits Rank Math's graph goes silent
with the module, and theme code that prints its own JSON-LD keeps printing, so move both into templates.

## Install

Download `schema-editor.zip` from the [latest release](https://github.com/joerivanhamel/schema-editor/releases/latest)
and upload it under Plugins → Add New → Upload Plugin, then activate. Or copy the `schema-editor` folder into
`wp-content/plugins/`. No build step; the plugin ships its own autoloader. Composer is only needed for the unit
tests.

To try the examples, import `templates/examples/` (Schema Editor → Settings & Import, or
`wp schema-editor import wp-content/plugins/schema-editor/templates/examples/`) and adapt the field names to your
theme.

## Writing a template

Schema Editor → Add Template. The document is JSON:

```json
{
  "name": "Service page",
  "enabled": true,
  "priority": 10,
  "targets": [ { "type": "page_template", "value": "page-consulting.php" } ],
  "exclude": [],
  "nodes": [
    {
      "@op": "add",
      "@key": "Service",
      "@required": [ "name" ],
      "@type": "Service",
      "@id": "{{post.permalink}}#service",
      "name": "{{post.title}}",
      "description": "{{ acf.hero_subtitle || rankmath.description }}",
      "provider": { "@id": "{{site.url}}#organization" },
      "image": "{{ acf.hero_image|image:2048x2048 || post.featured_image }}"
    },
    { "@op": "merge", "@match": "#webpage", "mainEntity": { "@id": "{{post.permalink}}#service" } }
  ]
}
```

### Targets

A template applies when any target matches and no exclude matches. Types:

| type | value | matches |
|---|---|---|
| `all` | | every front-end request |
| `singular` | | any single post, page or custom post |
| `post_type` | post type | single posts of that type |
| `page_template` | file name, e.g. `page-about.php`, or `default` | pages using that template |
| `post` | post ID | that post |
| `slug` | post slug | that post |
| `term` | `taxonomy:slug` | single posts carrying that term |
| `front_page` | | the front page |
| `posts_page` | | the blog listing |
| `post_type_archive` | post type | that archive |
| `taxonomy` | taxonomy | its term archives |
| `search`, `404` | | those pages |
| `author` | optional: user nicename or ID | author archives (author.php), one author or all |

Templates run in `priority` order (lower first); a later template may edit or replace what an earlier one added.

### Operations

| `@op` | needs | effect |
|---|---|---|
| `add` (default) | `@type` | adds the node under `@key` (default: the type). An existing node with the same key is replaced, which is how a template overrides a node from the theme or another template. |
| `merge` | `@match` | merges the rendered properties into every matching node (existing properties are overwritten, `@type` never). |
| `remove` | `@match`, optional `properties` | removes the listed properties from matching nodes, or the whole node when no list is given. |

`@match` selectors: `#organization` (an `@id` ending in that fragment), `@type:WebPage`, `@key:Service`, or a full
`@id`.

Other directives: `@required` (property names the node must end up with), `@if` (an expression; the node is only
rendered when it is non-empty), `@repeat` (WP_Query arguments; the node is rendered once per result, that result
being `post` and the page `parent`), and `@each` inside a value (an array-valued path; one object per entry, the
entry being `item`).

### Placeholders

`{{ path | filter:arg | filter || other.path || 'literal' }}`. Alternatives (`||`) are tried left to right and the
first non-empty one wins. Strings are cleaned to plain text (tags stripped, entities decoded, whitespace collapsed)
unless `|raw` is present. A string that is exactly one placeholder keeps the value's type, so `{{terms.feature}}`
yields a JSON array. A string mixing text and placeholders is omitted when any placeholder is empty.

| path | value |
|---|---|
| `post.id`, `post.title`, `post.permalink`, `post.slug`, `post.type` | the post |
| `post.excerpt`, `post.content` | plain text |
| `post.date`, `post.modified` | ISO 8601 |
| `post.featured_image`, `post.featured_image.large`, `post.featured_image_id` | the featured image |
| `post.author.name`, `post.author.url`, `post.author.id`, `post.author.description`, `post.author.avatar`, `post.author.acf.FIELD` | the post's WordPress author (ACF user fields through `acf.`) |
| `author.*` | same keys; the queried author on an author archive, otherwise the post's author |
| `parent.*` | the page being rendered, inside `@repeat` |
| `item.*` | the current `@each` entry |
| `acf.FIELD`, `acf.FIELD.sub`, `acf.FIELD.url` | an ACF field; `.url` on an attachment gives its URL |
| `acf.*_hero_image` | the first non-empty field whose name matches the glob |
| `option.FIELD` | an ACF options field (`get_field(…, 'option')`), globs allowed |
| `meta.KEY` | raw post meta |
| `rankmath.title`, `rankmath.description` | the post's Rank Math title and description |
| `rankmath.titles.KEY`, `rankmath.general.KEY`, `rankmath.sitemap.KEY` | Rank Math settings |
| `site.url` (with a trailing `/`), `site.home_url` (without), `site.name`, `site.description`, `site.language` | the site |
| `page.url`, `page.title`, `page.description` | the page's canonical URL, SEO title and SEO description (Rank Math's own head values when Rank Math is active), on any request including archives and search |
| `page.breadcrumbs` | `[{name, url, position}]`, the theme's visible trail when Settings names its function, else built from page titles, the posts page, post type archives and terms |
| `request.is_front_page`, `is_home`, `is_singular`, `is_page`, `is_archive`, `is_collection`, `is_search`, `is_author`, `is_404`, `is_paged`, `post_type`, `page_template`, `slug`, `post_id` | the request being rendered, for `@if` |
| `var.NAME` | a template variable (`"vars": { "NAME": "expression" }`) |
| `loop.index`, `loop.index0` | the position inside `@each` or `@repeat` |
| `terms.TAXONOMY`, `terms.TAXONOMY.slugs`, `terms.TAXONOMY.first`, `terms.all` | the post's terms (`all`: every public taxonomy of its type) |
| `settings.NAME` | a WordPress option |

Filters: `image[:size]` (attachment ID, ACF image array or URL → URL; size is a name or `1024x576`), `permalink`,
`year`, `int`, `float`, `bool`, `lower`, `upper`, `ucfirst`, `first_word`, `slug`, `words:N`, `chars:N`, `join` (comma
and space) or `join:'SEP'` (quote an argument that carries spaces),
`first`, `count`, `date:FORMAT`, `default:'x'`, `youtube_id`, `youtube_embed`, `youtube_watch` (each accepts a bare
ID or any YouTube URL), `iso_duration` ("1:24" → PT1M24S), `raw`, and since 0.2.0 `not`, `trim`, `before:','`,
`after:','`, `split:'x'`, `lines`, `last`, `key:a.b`, `pluck:field`, `unique`, `time`, `opening_hours`,
`map_url`, `image_width`, `image_height`, `image_alt`, `image_caption`, `attachment_id`, `post:KEY`, `meta:KEY`,
`acf:FIELD`, `template_page` and `find_post:TYPE` (see the skill's `reference.md` for each).

`@if` takes one expression or a list that must all hold, understands `a == b` and `a != b`, and works on nested
objects. `@each` takes an array expression or a query object, works on a node as well as inside a value, and
`@single: true` prints a list of one as that one object.

`acf.*_hero_image` style globs are matched against the field names the field groups define (not only saved
values), so a field still holding its ACF default value resolves too. The Preview box lists every ACF field on
the chosen post with a glimpse of its value.

### Preview

Every template has a Preview box: give it a post ID or URL and it renders the document in the editor (saved or not)
plus every other saved template that applies, and prints the resulting nodes. Rank Math's own nodes only exist on
the live request, so check the final graph with Google's Rich Results Test or `view-source` once the template is
saved.

## Settings & Import

Schema Editor → Settings & Import: output mode (automatic: inside Rank Math's graph while its Schema module is on,
else the plugin's own; Rank Math only; standalone; off), with the mode in force shown beneath; the theme's
breadcrumb trail function for `page.breadcrumbs`; always print Rank
Math's Organization, WebSite and WebPage nodes on single posts and pages (on by default: Rank Math leaves them out
when a post's schema type is "off", and a template then has nothing to merge into); remove Rank Math's sitelinks
search box; a footer HTML comment naming the templates that ran; delete templates on uninstall. Export
downloads every template as one JSON file; Import reads one document or an array and creates or updates by name.

## WP-CLI

```
wp schema-editor list
wp schema-editor import path/to/file-or-directory/
wp schema-editor export --file=templates.json
wp schema-editor render 6            # or a URL: the graph the plugin prints on its own for that post
```

## Development

```
composer install
composer lint
composer test
```

Without PHP on the machine, the same through Docker:

```
docker run --rm -v "$PWD:/app" -w /app composer:2 install
docker run --rm -v "$PWD:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

A throwaway WordPress with Rank Math for end-to-end checks is in `docker/docker-compose.yml` (instructions at the
top of the file). The rendering rules (`Renderer`, `Expression`), the graph operations (`GraphMerger`) and target
matching (`Targets`) are pure PHP with no WordPress dependency and are covered by the unit tests; the WordPress
resolver, admin screens and output hooks are exercised in the Docker site.

Layout:

```
schema-editor.php        bootstrap and autoloader
src/Plugin.php           composition root
src/Template/            Template (document), Repository (storage), Targets, RequestContext
src/Render/              Expression, Renderer, Context, resolvers (WordPress and array)
src/Output/              Engine, GraphMerger, RankMathBridge, StandaloneOutput
src/Admin/               post type, editor meta boxes, preview endpoint, settings/import/export
src/Cli/Command.php      WP-CLI
templates/examples/      example templates to import and adapt
tests/                   PHPUnit
```

## Google's guidelines

Templates can express anything Schema.org allows; whether Google accepts it is a separate question. The examples
follow Google's structured-data guidelines: `organization-hygiene.json`, for instance, removes self-serving review
markup and an unpublished price range from the Organization node. When a site wants to deviate from the
guidelines, keep the deviation in its own template, disabled by default, with the reasoning in its name, so turning
it on is a deliberate choice.

## Licence

GPL-2.0-or-later.
