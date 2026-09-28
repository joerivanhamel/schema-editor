# Changelog

## 0.1.2 — 2026-09-28

Author schema groundwork, ahead of the author bio pages.

- `author` target type (author archives, optionally one author).
- `author.*` placeholders (the queried author on an archive, else the post's author) and `post.author.*`
  extended with `description`, `avatar`, `login`, `first_name`, `last_name`, `user_url` and `acf.FIELD` for ACF
  user fields.
- Refined template 09 (disabled): Person on the author page as `ProfilePage.mainEntity`, and `BlogPosting.author`
  pointing at it, once bios exist.
- Claude skill `schema-editor-templates` in `.claude/skills/` for writing and reviewing templates.

## 0.1.1 — 2026-09-28

Found on the first staging review of the Refined Landscapes templates.

- `acf.*glob` placeholders now match the field names the ACF field groups define, not only the values saved in
  meta, so a field still holding its default value resolves (the service standfirsts fell through to the meta
  description before).
- `terms.all`: every public taxonomy of the post in one list.
- `youtube_id` filter; `youtube_embed` and `youtube_watch` accept a bare ID or any YouTube URL and yield nothing
  for anything else.
- The Preview box lists every ACF field on the chosen post with a glimpse of its value.
- Refined templates: case-study keywords from `terms.all`; the founder's Person node on Meet the Team takes the
  full name and title from Site Settings so it agrees with the Organization's founder node; film articles read
  `journal_video_url`.

## 0.1.0 — 2026-09-28

First build.

- Schema templates as a post type with a JSON code editor, validation on save, a preview against any post, and
  list-table columns for targets, priority and validity.
- Placeholder language with alternatives and filters; omit-when-empty rendering; `@required`, `@if`, `@repeat`,
  `@each`; `add`, `merge` and `remove` operations with `#fragment`, `@type:` and `@key:` selectors.
- Rank Math bridge (`rank_math/json_ld`, priority 50) and a standalone printer when Rank Math is absent.
- Settings (output mode, sitelinks search box off, debug comment, delete on uninstall), JSON import and export,
  WP-CLI `list`, `import`, `export`, `render`.
- Refined Landscapes template set in `templates/refined-landscapes/`.
