# Examples

Working templates from a real build, with neutral names. The same files ship with the plugin in
`templates/examples/` (import them with `wp schema-editor import templates/examples/` and adapt the field names to
your theme: a field that doesn't exist produces no node, never a wrong one).

- `organization-hygiene.json`: a priority-5 `remove` on `#organization` that keeps self-serving review markup and an
  unpublished price range out, whatever else adds them.
- `service-pages.json`: `Service` as `WebPage.mainEntity` on six service page templates, `areaServed` from a Site Settings
  repeater via `@each`, and a conditional `VideoObject` linked as `WebPage.video`.
- `case-studies.json`: `CreativeWork` with location, year, keywords from `terms.all`, award and a visible designer
  credit as `contributor`.
- `team-persons.json`: `@repeat` over a team post type, one `Person` per member with a stable `#firstname` anchor,
  and a `merge` that aligns the founder's node with the Organization's founder.
- `article-video.json`: `VideoObject` inside the graph only when the film URL field is set, linked from
  `BlogPosting.video`.
