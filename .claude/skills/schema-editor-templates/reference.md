# Schema Editor template language

## Document

```json
{
  "name": "RL 03 Service pages",
  "enabled": true,
  "priority": 10,
  "targets": [ { "type": "page_template", "value": "page-garden-design.php" } ],
  "exclude": [ { "type": "slug", "value": "services" } ],
  "nodes": [ ... ]
}
```

A template applies when any target matches and no exclude matches. Lower priority runs first.

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

Directives: `@required` (property names the node must end up with, else it is dropped), `@if` (expression;
render only when non-empty), `@repeat` (WP_Query args; one node per result, the result is `post`, the page is
`parent`), `@each` inside a value (array path; one object per entry, the entry is `item`).

## Rendering rules

- A property whose value resolves to nothing is omitted; a string mixing text and placeholders is omitted when
  any placeholder is empty; an object left with only `@type` is omitted; an empty list is omitted.
- A string that is exactly one placeholder keeps the value's type (arrays stay arrays, `|int` gives a number).
- Strings are cleaned to plain text (tags stripped, entities decoded, whitespace collapsed) unless `|raw`.
- Rank Math re-encodes `&` as `&amp;` when it prints; that is Rank Math, not the template.

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
| `site.url`, `site.name`, `site.description`, `site.language` | the site |
| `terms.TAXONOMY`, `.slugs`, `.first`, `terms.all` | the post's terms |
| `settings.NAME` | a WordPress option |

## Filters

`image[:size]` (ID, ACF image array or URL → URL; size name or `1024x576`), `permalink`, `year`, `int`, `float`,
`bool`, `lower`, `upper`, `ucfirst`, `first_word`, `slug`, `words:N`, `chars:N`, `join` or `join:'SEP'`,
`first`, `count`, `date:FORMAT`, `default:'x'`, `youtube_id`, `youtube_embed`, `youtube_watch`, `iso_duration`,
`raw`. Quote an argument that carries spaces.

## Settings that matter

- "Always print Rank Math's Organization, WebSite and WebPage nodes on single posts and pages": on, or a merge
  into `#webpage` finds nothing on pages whose Rank Math schema type is off.
- "Remove the sitelinks search box": on when search is noindex.
- "Print an HTML comment naming the templates that ran": on while testing, off for launch.

## Tools

- Admin: Schema Editor → template → Preview (post URL → nodes plus every ACF field with a glimpse of its value).
- WP-CLI: `wp schema-editor list | import <file-or-dir> | export --file=x.json | render <post-id-or-url>`.
- Import updates templates by name; export gives the whole set as one JSON array.

## Google requirements worth remembering

- VideoObject: `name`, `thumbnailUrl`, `uploadDate` required; `description`, `duration`, `embedUrl` or
  `contentUrl` recommended; the video must be embedded on the page.
- Article/BlogPosting: `headline` (visible title), `image`, `datePublished`, `author` with `name` (and `url`).
- BreadcrumbList: `position` and `name` per item.
- LocalBusiness/Organization: `name`, `address`, `url`; `logo` 112px+; no self-serving reviews or ratings.
- Person: `name`; keep one `@id` per person across the site.
