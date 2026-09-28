# Examples

Finished templates from the Refined Landscapes build (September 2026), copied from `templates/refined-landscapes/`
in the plugin repository, where the full set and its README (with the field names still to confirm) live.

- `organization-hygiene.json`: a priority-5 `remove` on `#organization` that keeps self-serving review markup and an
  unpublished price range out, whatever else adds them.
- `service-pages.json`: `Service` as `WebPage.mainEntity` on six page templates, `areaServed` from a Site Settings
  repeater via `@each`, and a conditional `VideoObject` linked as `WebPage.video`.
- `case-studies.json`: `CreativeWork` with location, year, keywords from `terms.all`, award and a visible designer
  credit as `contributor`.
- `team-persons.json`: `@repeat` over a team post type, one `Person` per member with a stable `#firstname` anchor,
  and a `merge` that aligns the founder's node with the Organization's founder.
- `article-video.json`: `VideoObject` inside the graph only when the film URL field is set, linked from
  `BlogPosting.video`.
