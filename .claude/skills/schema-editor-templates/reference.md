# Schema Editor template language

## Document

```json
{
  "name": "Service pages",
  "enabled": true,
  "priority": 10,
  "output": "any",
  "targets": [ { "type": "page_template", "value": "page-consulting.php" } ],
  "exclude": [ { "type": "slug", "value": "services" } ],
  "vars": { "image": "post.featured_image_id || rankmath.titles.open_graph_image_id" },
  "nodes": [ ... ]
}
```

A template applies when any target matches and no exclude matches. Lower priority runs first.

- `output` (optional): `any` (default), `standalone` (only when the plugin prints the whole graph itself: Rank
  Math absent, or its Schema module off), `rankmath` (only while templates edit Rank Math's graph). Templates
  that build what Rank Math would otherwise provide (Organization, WebSite, WebPage, BreadcrumbList,
  BlogPosting) are `standalone`, so they can be imported while Rank Math's module is still on.
- `vars` (optional): named expressions evaluated once per render, in order (a var may use earlier ones), read as
  `var.NAME`. Use them for a fallback chain needed in several places.

## Targets

| type | value | matches |
|---|---|---|
| `all` | | every front-end request |
| `singular` | | any single post, page or custom post |
| `post_type` | post type | single posts of that type |
| `page_template` | file name (`page-about.php`) or `default` | pages using that template |
| `post` | post ID | one post |
| `slug` | post slug | one post |
| `term` | `taxonomy:slug` | single posts carrying that term |
| `front_page` | | the front page |
| `posts_page` | | the blog listing |
| `post_type_archive` | post type | that archive |
| `taxonomy` | taxonomy | its term archives |
| `author` | optional user nicename or ID | author archives |
| `search`, `404` | | those pages |

## Node operations

| `@op` | needs | effect |
|---|---|---|
| `add` (default) | `@type` | adds the node under `@key` (default: the type). Same key = replace. |
| `merge` | `@match` | merges rendered properties into matching nodes (`@type` never changes) |
| `remove` | `@match`, optional `properties` | removes listed properties, or the whole node |

`@match`: `#organization` (an `@id` ending in that fragment), `@type:WebPage`, `@key:Service`, or a full `@id`.

Directives:

- `@required`: property names the node must end up with, else it is dropped.
- `@if`: an expression (render only when non-empty) or a list of expressions that must all hold. Comparisons
  work: `"post.id == rankmath.titles.local_seo_about_page"`, `"post.author.name | lower != site.name | lower"`.
  `@if` also works on a nested object (a `logo`, an `about` reference, one entry of an `author` list).
- `@repeat`: WP_Query args; one node per result, the result is `post`, the page is `parent`.
- `@each`: an array-valued expression (each entry is `item`) or a query object in the `@repeat` shape (each
  result is `post`, the page is `parent`). Inside a value it builds a list of objects; on a node it renders the
  node once per entry (combine with `@repeat` for "every reel of every case study"). `loop.index` is the 1-based
  position (ListItem.position), `loop.index0` the 0-based one.
- `@single: true` beside `@each` in a value prints a list of exactly one entry as that one object.

## Rendering rules

- A property whose value resolves to nothing is omitted; a string mixing text and placeholders is omitted when
  any placeholder is empty; an object left with only `@type` is omitted; an empty list is omitted.
- A string that is exactly one placeholder keeps the value's type (arrays stay arrays, `|int` gives a number).
- Strings are cleaned to plain text (tags stripped, entities decoded, whitespace collapsed) unless `|raw`.
- A list entry that renders to a list (a placeholder yielding a list, an `@each` object) is spliced into the
  surrounding list, so `"sameAs": ["{{ a }}", "{{ b | lines }}"]` is one flat list.
- Duplicate `@type` entries collapse: `["{{ rankmath.titles.local_business_type || 'Organization' }}",
  "Organization"]` is plain `Organization` when the setting says so.
- Rank Math re-encodes `&` as `&amp;` when it prints; that is Rank Math, not the template. The standalone
  printer does not.

## Placeholders

`{{ path | filter:arg | filter || other.path || 'literal' }}`. Alternatives left to right, first non-empty wins.

| path | value |
|---|---|
| `post.id`, `post.title`, `post.permalink`, `post.slug`, `post.type` | the post |
| `post.excerpt`, `post.content` | plain text |
| `post.date`, `post.modified` | ISO 8601 |
| `post.featured_image`, `post.featured_image.large`, `post.featured_image_id` | featured image |
| `post.author.name|url|id|description|avatar|acf.FIELD` | the post's author |
| `author.*` | the queried author on an author archive, else the post's author |
| `parent.*` | the page being rendered, inside `@repeat` |
| `item.*` | the current `@each` entry |
| `acf.FIELD`, `acf.FIELD.sub`, `acf.FIELD.url` | ACF field; `.url` on an attachment gives its URL |
| `acf.*_hero_image` | first non-empty field whose name matches the glob (matched against field definitions) |
| `option.FIELD` | ACF options field; globs allowed |
| `meta.KEY` | raw post meta |
| `rankmath.title`, `rankmath.description` | the post's Rank Math title and description |
| `rankmath.titles.KEY`, `rankmath.general.KEY`, `rankmath.sitemap.KEY` | Rank Math settings |
| `site.url` (ends in `/`), `site.home_url` (no trailing `/`), `site.name`, `site.description`, `site.language` | the site |
| `page.url`, `page.title`, `page.description` | the page's canonical URL, SEO title and SEO description (Rank Math's head values when Rank Math is active, WordPress's otherwise); on archives and search too |
| `page.breadcrumbs` | `[{name, url, position}]`: the theme's visible trail when Settings names its function, else built from WordPress titles |
| `request.is_front_page`, `is_home`, `is_singular`, `is_page`, `is_archive`, `is_collection`, `is_search`, `is_author`, `is_404`, `is_paged`, `post_type`, `page_template`, `slug`, `post_id` | what is being rendered (false conditions are empty) |
| `var.NAME` | a template variable |
| `loop.index`, `loop.index0` | position inside `@each` / `@repeat` |
| `terms.TAXONOMY`, `.slugs`, `.first`, `terms.all` | the post's terms |
| `settings.NAME` | a WordPress option |

## Filters

`image[:size]` (ID, ACF image array or URL → URL; size name or `1024x576`), `permalink`, `year`, `int`, `float`,
`bool`, `lower`, `upper`, `ucfirst`, `first_word`, `slug`, `words:N`, `chars:N`, `join` or `join:'SEP'`,
`first`, `count`, `date:FORMAT`, `default:'x'`, `youtube_id`, `youtube_embed`, `youtube_watch`, `iso_duration`,
`raw`. Quote an argument that carries spaces.

Since 0.2.0:

| filter | does |
|---|---|
| `not` | true when the value is empty, else nothing (for `@if`) |
| `trim`, `before:','`, `after:','` | text before / after the first separator (`after` is empty when there is none) |
| `split:' and '`, `lines` | text to a list (on a separator, on line breaks) |
| `last`, `key:a.b`, `pluck:field`, `unique` | list and array helpers (`pluck` takes one field from every row) |
| `time` | `8:00` to `08:00` |
| `opening_hours` | Rank Math's opening-hours rows grouped into `{days, opens, closes}` per time span |
| `map_url` | stored coordinates `lat,long` to a Google Maps link (Rank Math's `Place.hasMap` form) |
| `image_width`, `image_height`, `image_alt`, `image_caption`, `attachment_id` | facts about an attachment (ID, ACF array or uploads URL) |
| `post:KEY`, `meta:KEY`, `acf:FIELD` | read from another post, given its ID (`post:title`, `acf:team_role`) |
| `template_page` | a page-template file name to the ID of the page using it (`'page-team.php' \| template_page \| permalink`) |
| `find_post:TYPE` | a name to the ID of the one published post of that type titled so, or whose first word it is |

## Settings that matter

- Output "Automatic": templates edit Rank Math's graph while Rank Math's Schema module is on, and the plugin
  prints the whole graph when the module is off or Rank Math is absent. The settings page says which is in force.
- "Breadcrumb trail function": the theme function returning the visible trail, so `page.breadcrumbs` matches
  what visitors see (Google's rule for breadcrumb markup). Empty: built from WordPress.

- "Always print Rank Math's Organization, WebSite and WebPage nodes on single posts and pages": on, or a merge
  into `#webpage` finds nothing on pages whose Rank Math schema type is off.
- "Remove the sitelinks search box": on when search is noindex.
- "Print an HTML comment naming the templates that ran": on while testing, off for launch.

## Tools

- Admin: Schema Editor → template → Preview (post URL → nodes plus every ACF field with a glimpse of its value).
- WP-CLI: `wp schema-editor list | import <file-or-dir> | export --file=x.json | render <post-id-or-url>`.
- Import updates templates by name; export gives the whole set as one JSON array.
- Front end: an administrator adding `?schema_editor_preview=standalone` to any page address gets the graph the
  plugin would print on its own, as JSON, even while Rank Math's module is on. The editor Preview and
  `wp schema-editor render` show that standalone graph too.

## Google requirements worth remembering

- VideoObject: `name`, `thumbnailUrl`, `uploadDate` required; `description`, `duration`, `embedUrl` or
  `contentUrl` recommended; the video must be embedded on the page.
- Article/BlogPosting: `headline` (visible title), `image`, `datePublished`, `author` with `name` (and `url`).
- BreadcrumbList: `position` and `name` per item.
- LocalBusiness/Organization: `name`, `address`, `url`; `logo` 112px+; no self-serving reviews or ratings.
- Person: `name`; keep one `@id` per person across the site.
