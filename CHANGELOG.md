# Changelog

## 0.2.0 — 2026-09-29

The plugin can now carry a site's whole graph, so Rank Math's Schema module can be switched off while the rest of
Rank Math stays.

- Output: "Automatic" now edits Rank Math's graph only while Rank Math's Schema (Structured Data) module is on.
  With the module off Rank Math prints no JSON-LD at all, and the plugin prints the whole graph itself.
- Templates can say when they run: `"output": "standalone"` (only when the plugin prints its own graph, for
  templates that build what Rank Math would otherwise provide), `"rankmath"`, or `"any"` (default). A standalone
  template set can be imported while Rank Math's module is still on without changing the live output.
- Preview on the live site: an administrator can add `?schema_editor_preview=standalone` to any front-end address
  to get the graph the plugin would print on its own, as JSON, before switching over.
- `page.*`: the page's canonical URL, SEO title and SEO description (read from Rank Math's own head data when Rank
  Math is active, from WordPress otherwise) and its breadcrumb trail (`page.breadcrumbs`). The trail is the
  theme's visible trail when Settings names the theme's trail function, else it is built from WordPress titles.
- `request.*`: what kind of request is rendered (is_front_page, is_home, is_singular, is_page, is_archive,
  is_collection, is_search, is_author, is_404, is_paged, post_type, page_template, slug, post_id).
- Template `"vars"`: named expressions evaluated once per render, read as `var.NAME`.
- `@if` accepts a list (all must hold) and comparisons (`a == b`, `a != b`), and works on nested objects too.
- `@each` on a node renders the node once per entry; `@each` may also take a query object (the `@repeat` shape),
  with each result as `post`; `loop.index` is the 1-based position.
- A list entry that renders to a list is spliced into the surrounding list; duplicate `@type` entries collapse.
- Filters: `not`, `trim`, `before`, `after`, `split`, `lines`, `last`, `key`, `pluck`, `unique`, `time`,
  `opening_hours` (Rank Math's opening-hours rows grouped into specifications), `map_url` (stored coordinates to a
  map link), `image_width`, `image_height`, `image_alt`, `image_caption`, `attachment_id`, `post:KEY`, `meta:KEY`,
  `acf:FIELD`, `template_page` (a page-template file to the page that uses it) and `find_post:TYPE` (a name to the
  one post of that type with that title or first word).
- `site.home_url` (the home address without the trailing slash).
- The editor's Preview and `wp schema-editor render` show the standalone graph.

## Unreleased (0.1.x)

- Site-neutral repository: generic example templates in `templates/examples/` (organization hygiene, service
  pages, case studies, team members, articles with a video) replace the client-specific set, and the tests and
  end-to-end scripts use a neutral example business. No change to the plugin's behaviour.

## 0.1.4 — 2026-09-28

- Fix: importing a template whose name already exists now replaces its content. A safeguard meant only for
  Quick Edit also ran during admin imports and restored the old content, so re-imports reported "updated" but
  changed nothing. The safeguard now applies to Quick Edit requests with an empty body only.
- End-to-end check covers re-import: a changed template imported over an existing one must change the output.

## 0.1.3 — 2026-09-28

- `@if` and `@each` accept the expression with or without the `{{ … }}` braces (a braced single-alternative `@if`
  silently never matched before).
- `terms.all` includes taxonomies that are shown in the admin even when registered as non-public (a theme's
  portfolio taxonomies, for example), and excludes post formats.

## 0.1.2 — 2026-09-28

Author schema groundwork, ahead of the author bio pages.

- `author` target type (author archives, optionally one author).
- `author.*` placeholders (the queried author on an archive, else the post's author) and `post.author.*`
  extended with `description`, `avatar`, `login`, `first_name`, `last_name`, `user_url` and `acf.FIELD` for ACF
  user fields.
- Claude skill `schema-editor-templates` in `.claude/skills/` for writing and reviewing templates.

## 0.1.1 — 2026-09-28

Found on the first staging review of a real template set.

- `acf.*glob` placeholders now match the field names the ACF field groups define, not only the values saved in
  meta, so a field still holding its default value resolves (the service standfirsts fell through to the meta
  description before).
- `terms.all`: every public taxonomy of the post in one list.
- `youtube_id` filter; `youtube_embed` and `youtube_watch` accept a bare ID or any YouTube URL and yield nothing
  for anything else.
- The Preview box lists every ACF field on the chosen post with a glimpse of its value.

## 0.1.0 — 2026-09-28

First build.

- Schema templates as a post type with a JSON code editor, validation on save, a preview against any post, and
  list-table columns for targets, priority and validity.
- Placeholder language with alternatives and filters; omit-when-empty rendering; `@required`, `@if`, `@repeat`,
  `@each`; `add`, `merge` and `remove` operations with `#fragment`, `@type:` and `@key:` selectors.
- Rank Math bridge (`rank_math/json_ld`, priority 50) and a standalone printer when Rank Math is absent.
- Settings (output mode, sitelinks search box off, debug comment, delete on uninstall), JSON import and export,
  WP-CLI `list`, `import`, `export`, `render`.
- Example templates in `templates/examples/`.
