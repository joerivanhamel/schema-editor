# Refined Landscapes templates

The template set for the new refinedlandscapes.com build, written against the schema plan of 28 September 2026
(`auditor-pack-250926/schema/schema-plan-280926.md`). Import with:

```
wp schema-editor import wp-content/plugins/schema-editor/templates/refined-landscapes/
```

or Schema Editor → Settings & Import → Import, one file at a time or all of them merged into one array.

Rank Math keeps printing the Organization, Place, WebSite, WebPage, BreadcrumbList and BlogPosting nodes; these
templates edit that graph (priority 5 runs before priority 10). The theme's own `Service` and `CreativeWork`
filters (`inc/seo-pass-170926.php` §7 and `inc/schema-250926.php` §2) use the same array keys, so templates 03 and
04 replace their nodes outright; once the templates are live those two theme sections can come out.

| File | Applies to | What it does |
|---|---|---|
| 01 Organization hygiene | every request | Removes `aggregateRating`, `review` and `priceRange` from the Organization node, whatever added them. Google does not allow self-serving review markup on a LocalBusiness or Organization, and no price range is published on the site. |
| 02 About page | `page-about.php` | `AboutPage.mainEntity` → the Organization. |
| 03 Service pages | the six service page templates | `Service` node (name and serviceType = page title, provider, areaServed from Site Settings, description = the visible hero standfirst, image = the hero) as `WebPage.mainEntity`; a `VideoObject` for the process film where the page embeds one, referenced as `WebPage.video`. |
| 04 Case studies | `portfolio_project` singles | `CreativeWork` as `WebPage.mainEntity`: name, headline, description (excerpt → meta description → SEO intro), image, locationCreated, dateCreated (year), keywords, award, creator and publisher, contributor (the visible "Designed by" credit). |
| 05 Articles with a film | posts | `VideoObject` inside the graph with `BlogPosting.video` → it, only on articles whose film field is set. |
| 06 Meet the Team | `page-meet-the-team.php` | One `Person` per published team member (`#firstname` anchors that the founder and author nodes point at): first name, job title, headshot, worksFor. `WebPage.about` → the Organization. |
| 07 Pages with a film | Home, About, Team, Process | `VideoObject` + `WebPage.video` where the page embeds a film. |
| 08 Reviews (opt-in) | Home and the service pages | **Disabled.** Adds `aggregateRating` and `Review` nodes to the Organization from the Site Settings score, count and testimonials, as audit item 7 described. Google's guidelines rule this out (self-serving reviews on the business entity; ratings copied from another platform), so it ships off. Enabling it is a deliberate, documented choice; template 01 must then also drop those two property names. |

## Field names to confirm on the live theme

Everything reads the database, so a wrong field name produces no node rather than a wrong one, and the Preview
box on each template shows exactly what a given page gets. Names known from the theme and the pack are used as
they are; where the pack did not show a name the templates use a glob (`acf.*video_id` matches the first
non-empty field whose name ends in `video_id`) with fallbacks. Confirm and tighten these:

| Template | Placeholder | Known / to confirm |
|---|---|---|
| 03 | `acf.*_hero_subtitle`, `acf.*_hero_image` | `gd_hero_subtitle`, `gd_hero_image` confirmed on Garden Design; the other five pages use the prefixes `gc`, `pd`, `ol`, `wgl`, `ga` (from the theme's `rl_seo_services()`). |
| 03 | `acf.*_process_video_id`, `acf.*_video_id`, `*_video_heading`, `*_video_body`, `*_video_still` | Garden Design uses `gd_process_video_id` / `gd_process_video_still` / `gd_process_video_still_alt`; the other service groups use `<prefix>_video_id`, `<prefix>_video_heading` ("Film Heading"), `<prefix>_video_body` ("Film Supporting Copy") and `<prefix>_video_still` (confirmed on Garden Construction, 28 September). A `<prefix>_video_duration` field does not exist yet; the template reads it the day it does. |
| 04 | `acf.project_h1`, `project_excerpt`, `seo_intro`, `hero_image`, `project_location`, `project_year`, `award_title`, `project_designer` | From `schema-250926.php`. |
| 04 | `terms.all` | Every public taxonomy of the project (`rl_aesthetic`, `rl_feature`, `rl_service`, `rl_location`, `rl_sector`, `portfolio_category`), the same set the theme joined. |
| 05 | `acf.journal_video_url`, `journal_video_label`, `journal_deck`, `journal_hero_image` | From the "Journal Article" field group (28 September). The URL field takes any YouTube URL form; `youtube_id` extracts the ID. Duration: no field exists yet; the theme's own film module prints one (PT0M18S on the Calm Garden Reveal), so Tom can say where it comes from and expose it as `journal_video_duration`. |
| 07 | `acf.*video_url`, `*video_id`, … | **No ACF video field exists on the Home, About, Team or Process pages** (Home group and Site Settings checked on 28 September); those films are hard-coded in the theme, so this template produces nothing until Tom adds fields for them. Kept so it starts working the day a field appears. |
| 06 | `acf.team_role` | Confirmed. The headshot field name is unknown: `post.featured_image` first, then `acf.*headshot`, then `acf.*photo`. |
| 08 | `option.google_review_score`, `google_review_count`, `site_testimonials` | Confirmed in Site Settings. |

## Settings to tick

Schema Editor → Settings & Import: "Remove the sitelinks search box" on (search is noindex and disallowed); "Print
an HTML comment naming the templates that ran" on while checking staging, off for launch.
