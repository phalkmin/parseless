=== ParseLess ===
Contributors: phalkmin
Tags: ai, markdown, llms, bots, crawlers
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.5.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Serve your posts as Markdown to AI crawlers and CLI tools — typically 95%+ less bandwidth, ~60–80% fewer DB queries, no theme bloat.

== Description ==

ParseLess serves your WordPress content as clean Markdown to AI crawlers (via User-Agent detection) and on manual `?format=md` requests. Same URL, same content, none of the theme chrome, navigation, widgets, or page-builder scaffolding that AI bots and CLI tools don't need.

It also exposes `/llms.txt` for the emerging AI-indexing standard, so models know where to find your content.

= Who is this for? =

**1. Developers using Claude Code, Cursor, Aider, or any CLI that feeds your own site content into an LLM.**

If you've ever piped a blog post into Claude or ChatGPT for analysis, rewriting, or summarization, you've watched it burn through tokens parsing nav menus, footer markup, and CSS classes that have nothing to do with your content. A typical WordPress page measured live: **~19,800 tokens of HTML for ~975 tokens of actual content** — a 20x reduction just by stripping the theme. Heavy page-builder sites (Elementor, Divi) routinely hit 100x or more.

That's the difference between fitting a handful of pages in a context window and fitting dozens.

Just append `?format=md` to any post URL and pipe it straight into your tool of choice:

`curl https://yoursite.com/my-post/?format=md | claude "summarize this"`

**2. Site owners getting "high resource usage" warnings from their host because AI bots are hammering the site.**

GPTBot, ClaudeBot, PerplexityBot, Google-Extended, and a dozen others crawl WordPress sites constantly. Each request renders your full theme, runs widget queries, loads page-builder assets, and ships hundreds of KB of HTML per page — most of which the bot discards before extracting the actual text.

ParseLess intercepts these crawls and serves a tiny Markdown payload instead. You keep the AEO/SEO benefit of being indexed by AI search, without paying the server cost of rendering your full theme for every bot hit.

Typical impact on AI bot traffic (measured on real WordPress pages):

* **95%+ less bandwidth** per crawled page (typically 15–30x smaller; e.g. a 79 KB page → 4 KB. Heavy page-builder sites see 100x+)
* **~60–80% fewer database queries** per request
* **~60% lower peak PHP memory** per request
* **~50–80% faster Time To First Byte** (measured 54% on a content-heavy page, 67% on a short one)
* On a site with 500 posts crawled monthly by major AI bots, that's roughly **gigabytes → tens of megabytes** of monthly bandwidth and a fraction of the cumulative PHP execution time

Numbers vary by theme and content. Heavier setups (Elementor, Divi, Avada) see the biggest savings; lightweight themes see less dramatic but still meaningful gains. The conversion is cached as a transient, so repeated bot hits cost almost nothing.

= How it works =

* AI crawlers are detected by User-Agent and served Markdown automatically — no configuration required.
* Humans, search engines, and unknown bots receive your normal HTML output. ParseLess never affects what real visitors see.
* The `?format=md` query parameter works on any post URL for manual preview or CLI piping.
* `/llms.txt` is published at your site root with a list of available content for AI indexers.
* Conversion happens once per post and is cached. Subsequent requests serve a single transient read.

= Features =

* Automatic Markdown for known AI bots (GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, CCBot, Google-Extended, Applebot-Extended, Bytespider, Meta-ExternalAgent, cohere-ai, and more)
* Manual preview and CLI access via `?format=md` on any post URL
* `/llms.txt` endpoint for AI-indexing standards
* Respects noindex flags from Yoast SEO, Rank Math, and Genesis
* Skips private, draft, password-protected, and trashed posts
* Works with all public post types (configurable)
* Optional YAML frontmatter (title, URL, author, date, categories, tags, excerpt)
* Transient-based caching with configurable TTL
* Settings page at **Tools → ParseLess** for detection mode, bot list, cache TTL, post types, and llms.txt control
* Per-post meta box with Markdown preview and copy-to-clipboard
* Extensible via filters: `md4ai_bot_list`, `md4ai_supported_post_types`, `md4ai_markdown_output`, `md4ai_cache_ttl`, `md4ai_should_serve_markdown`

== Installation ==

1. Upload the `parseless` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. That's it — AI crawlers automatically receive Markdown, and you can preview any post by appending `?format=md` to its URL

Optional: visit **Tools → ParseLess** to adjust the detection mode, bot list, cache TTL, or enable `/llms.txt`.

== Frequently Asked Questions ==

= How do I preview the Markdown output for a post? =

Append `?format=md` to any post URL, e.g. `https://example.com/my-post/?format=md`. The same URL works from `curl`, `wget`, or any CLI tool you want to pipe into.

= Will this affect my normal visitors or my SEO? =

No. Markdown is only served when the request matches a known AI bot User-Agent or explicitly includes `?format=md`. Browsers, Google's regular search crawler, and every other visitor receive your normal WordPress HTML. The Markdown endpoint also sends `X-Robots-Tag: noindex` so it never competes with your HTML pages in traditional search.

= Which AI bots are detected? =

GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, Claude-User, anthropic-ai, PerplexityBot, Perplexity-User, CCBot, Google-Extended, Applebot-Extended, Bytespider, Meta-ExternalAgent, cohere-ai. You can extend, replace, or restrict this list in the settings or via the `md4ai_bot_list` filter.

= Does this block AI bots? =

No — the opposite. ParseLess makes your content *easier* for AI bots to ingest, so you keep the AEO (Answer Engine Optimization) benefit of being cited by ChatGPT, Claude, Perplexity, and AI Overviews. It just delivers that content in a format that's cheap for both you and them.

= How much will this actually reduce my server load? =

It depends on your theme and what's hitting you. A real measurement on a content-rich page: response size dropped from 79 KB to 4 KB (95% smaller, 20x reduction) and TTFB from 251 ms to 115 ms. Database queries typically drop ~60–80% per request — the WordPress bootstrap still runs; only the theme/widget/builder rendering is skipped. Page-builder sites (Elementor, Divi) see the largest savings because their HTML payloads are the heaviest. The conversion is cached, so repeated bot hits on the same post are nearly free.

= What about content built with Elementor / Divi / page builders? =

ParseLess runs `the_content` filter before conversion, so anything those builders render into the post content gets captured and converted. Layout-only wrappers and design scaffolding are stripped out, leaving the actual text, headings, lists, tables, images, and links.

= Will the Markdown include my excerpt, categories, and tags? =

Optionally, yes. Enable **Include frontmatter** in the settings to prepend a YAML block with title, URL, author, date, categories, tags, and excerpt.

== Privacy and data collection ==

When logging is enabled (off by default), ParseLess records the following for
each Markdown request:

* Post ID, URL, and request timestamp
* The full User-Agent header
* A salted SHA-256 hash of the requester's IP address (the raw IP is never stored)
* Matched bot identifier, bytes served, and whether the response came from cache

IP hashes cannot be reversed from an email address, so the WordPress privacy
exporter/eraser tools will report no personal data on demand.

Logs are pruned daily according to the configured retention window
(7/30/90/365 days, default 30). Site owners can disable logging or click
"Delete all logged requests" at any time from Tools → ParseLess.

== Changelog ==

= 0.5.0 =
* New: bot activity chart on the Analytics tab — see how each AI crawler's traffic trends across the last 30 days at a glance.
* New: AI sitemap at /botfood-sitemap.xml listing the Markdown version of every public post, automatically advertised in robots.txt so AI crawlers can find it.
* The sitemap can be toggled off under Tools → ParseLess if you'd rather not expose it.

= 0.4.0 =
* New: optional request logging to a custom database table — see who's fetching what, with logging off by default until you turn it on.
* New: "ParseLess — AI Traffic" dashboard widget on wp-admin showing top bots and top fetched URLs over the past week.
* New: Analytics tab on Tools → ParseLess with a bot breakdown, top URLs, unknown bot-like UAs, and CSV export.
* New: one-click "Add to bot list" action for unknown UAs that look like AI crawlers.
* New: daily retention pruning with configurable windows (7/30/90/365 days).
* New: salted SHA-256 IP hashing and WordPress privacy exporter/eraser registrations.
* New: "Delete all logged requests" button for a one-click purge.

= 0.3.0 =
* Plugin renamed to ParseLess.
* Added Settings page (Tools → ParseLess) with controls for detection mode, bot list, cache TTL, post types, llms.txt, and frontmatter.
* Added per-post meta box with Markdown preview and copy-to-clipboard.

= 0.2.0 =
* The plugin now works with all public content types on your site, not just standard posts — so pages and custom post types are included automatically.
* Posts marked as "noindex" in Yoast SEO, Rank Math, or Genesis will no longer be shared with AI crawlers. Private, draft, and password-protected posts are also excluded.
* Markdown output is now much cleaner: tables convert properly, nested bullet lists keep their structure, and code blocks preserve the programming language when available.
* Cleaned up the plugin's internal code structure so it's easier to maintain and extend in future updates.
* When you uninstall the plugin, all cached data and settings are now properly removed.

= 0.1.0 =
* Initial release.
