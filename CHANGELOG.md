# Changelog

## Unreleased

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
