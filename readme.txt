=== ParseLess ===
Contributors: phalkmin
Tags: ai, markdown, llms, bots, crawlers
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Serve AI the good version of your content, see which bots really read it, and cap what they cost you. Markdown for crawlers and agents — 95%+ less bandwidth.

== Description ==

ParseLess serves your WordPress content as clean Markdown to AI crawlers and agents — same URL, same content, none of the theme chrome, navigation, widgets or page-builder scaffolding a bot discards anyway. A typical post goes from ~80 KB of HTML to ~4 KB of Markdown, with 60–80% fewer database queries per request.

**Serve the good version.** Your CDN may offer "Markdown for agents" now, but it converts the *rendered page* — menus, search buttons, cookie banners and all. ParseLess converts your post content, so an agent gets your article, not your layout. Agents can ask for it the standard way (`Accept: text/markdown`, a `.md` address, or `?format=md`), and every page advertises where to find it. WooCommerce products come with price, stock and variations.

**See who's really reading.** Optional request logging shows which AI companies fetch your content and why — model training, search indexing, live answers while someone chats with an assistant — with a per-bot "last seen" so you can tell when your host is quietly blocking a crawler at the edge. Everything stays in your database; IPs are only ever stored as salted hashes.

**Cap what it costs you.** Block specific crawlers from your Markdown, declare your terms in robots.txt (Content Signals, AI bot rules, RSL licence), and — if a bot ignores all that — throttle it to a sensible number of requests per minute. Every control is off by default: your stance on AI is yours, not ours.

**Agent-native.** On WordPress 6.9+ ParseLess registers Abilities (`parseless/get-markdown`, `parseless/list-markdown-urls`, `parseless/get-stats`) so MCP clients and AI agents can discover and fetch your Markdown through the WordPress Abilities API instead of guessing URLs.

It also publishes `/llms.txt` and `/llms-full.txt` for AI indexers, and an experimental Open Knowledge Format bundle.

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

* Automatic Markdown for known AI bots (GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, CCBot, Google-Extended, Applebot-Extended, Bytespider, Meta-ExternalAgent, Amazonbot, cohere-ai, and more), plus `Accept: text/markdown`, `.md` addresses and `?format=md`
* Respects noindex flags from Yoast SEO, Rank Math, and Genesis; skips private, draft, password-protected and trashed posts; per-post "Exclude from AI" checkbox
* Works with all public post types (configurable); WooCommerce products include price, SKU, stock, attributes and variations
* Optional YAML frontmatter and schema.org Article block
* Analytics: requests per bot, company and purpose; chat impressions; top URLs; unknown bot-like User-Agents with one-click "add to list"; per-bot reachability with last seen; CSV export. Optional site-wide crawler logging to see bots that get HTML too.
* Controls: Blocked Bots (403), per-bot rate limit (429 + `Retry-After`), Content Signals, AI bot rules and RSL licence in robots.txt — all off by default
* Abilities API registration for MCP clients on WordPress 6.9+
* `/llms.txt`, `/llms-full.txt`, an AI sitemap advertised in robots.txt, and an experimental OKF bundle
* Settings page at **Tools → ParseLess**; per-post meta box with Markdown preview, copy-to-clipboard and the exclude toggle
* Zero dependencies, no build step, nothing phones home

= For developers =

The public API below is frozen for the 1.x series: names, arguments and return types will not change without a major version bump.

**Filters**

* `md4ai_should_serve_markdown( bool $serve )` — final say on whether the current request gets Markdown.
* `md4ai_bot_list( string[] $bots )` — User-Agent substrings treated as AI crawlers.
* `md4ai_blocked_bots( string[] $bots )` — User-Agent substrings refused with a 403.
* `md4ai_bot_taxonomy( array $map )` — `bot_name => [ company, purpose ]`; purposes are `training`, `search`, `live`, `agent`.
* `md4ai_rate_limit( int $per_minute, string $bot )` — per-bot request cap when rate limiting is on; return 0 to exempt a bot.
* `md4ai_unknown_ua_tokens( string[] $tokens )` — substrings that make an unlisted User-Agent "bot-like" for the unknown-UA log.
* `md4ai_supported_post_types( string[] $types )` — post types served as Markdown.
* `md4ai_excluded_post_types( string[] $types )` — public post types never served (page-builder templates and the like).
* `md4ai_excluded_post_ids( int[] $ids )` — individual posts never served on any endpoint.
* `md4ai_markdown_output( string $markdown, WP_Post $post )` — the finished Markdown document.
* `md4ai_product_details( string $table, WC_Product $product )` — the WooCommerce product details section.
* `md4ai_max_variations( int $max )` — cap on listed variations (default 25).
* `md4ai_cache_ttl( int $seconds, int $post_id )` — cache lifetime; `$post_id` is 0 for site-wide documents.
* `md4ai_cache_bypass( bool $bypass )` — skip the cache for this request (default: logged-in users).
* `md4ai_llmstxt_max_posts( int $max )`, `md4ai_llmsfull_max_bytes( int $bytes )`, `md4ai_sitemap_max_posts( int $max )`, `md4ai_okf_max_posts( int $max )` — endpoint size caps.
* `md4ai_okf_endpoint( string $slug )` — path segment of the OKF bundle.
* `md4ai_okf_concept( string $markdown, WP_Post $post )` — one OKF concept file.
* `md4ai_rsl_terms_url( string $url )`, `md4ai_rsl_xml( string $xml )` — the RSL licence document.
* `md4ai_log_request( bool $log, array $context )` — veto a log row before it is written.

**Actions**

* `md4ai_logged_request( int $row_id, array $context )` — after a request row is written.

**Abilities** (WordPress 6.9+, via `wp_register_ability()`)

* `parseless/get-markdown` — input `post_id` or `url`; returns `post_id`, `url`, `title`, `markdown`, `tokens`.
* `parseless/list-markdown-urls` — input `limit` (capped by the llms.txt Max Posts setting); returns `post_id`, `title`, `url`, `markdown_url`, `modified` per post.
* `parseless/get-stats` — input `days` (1–365); returns totals, cache hit rate, chat impressions, distinct crawlers and top bots. Requires `manage_options`.

**Storage**: one option, `md4ai_settings`; one table, `{prefix}md4ai_requests` (only when logging is on); post meta `_md4ai_exclude`; transients prefixed `md4ai_`. Uninstall removes all of them.

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

GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, Claude-User, Claude-SearchBot, anthropic-ai, PerplexityBot, Perplexity-User, CCBot, Google-Extended, Applebot-Extended, Bytespider, Meta-ExternalAgent, Meta-ExternalFetcher, cohere-ai, Amazonbot, DuckAssistBot, MistralAI-User. You can extend, replace, or restrict this list in the settings or via the `md4ai_bot_list` filter. The Analytics tab also surfaces bot-like User-Agents that didn't match, with a one-click "add to list".

= Does this block AI bots? =

Not by default — the opposite. ParseLess makes your content *easier* for AI bots to ingest, so you keep the AEO (Answer Engine Optimization) benefit of being cited by ChatGPT, Claude, Perplexity, and AI Overviews. It just delivers that content in a format that's cheap for both you and them.

If you *want* to push back on specific crawlers, you can: the Blocked Bots list refuses them with a 403, the rate limit throttles any bot that exceeds a per-minute cap with a 429, and robots.txt can carry Content Signals, AI bot rules and an RSL licence. All of that is off until you turn it on.

= My host says it blocks AI bots. How do I check? =

Enable logging and "Log All AI Crawler Traffic" under Tools → ParseLess, wait a few days, and look at the "Known bots — reachability" table on the Analytics tab. A crawler that is on your list but never seen — or only ever seen blocked or throttled — is being stopped before it reaches WordPress. Pages served from a full-page cache or CDN never reach PHP, so those visits are invisible to any plugin.

= Can bots fake their User-Agent? =

Yes. Anyone can send a request claiming to be GPTBot, and ParseLess currently trusts that label, so bot counts in Analytics can be inflated and a block or rate limit applies to whoever claims the name. Verifying crawlers against the IP ranges their operators publish is planned for a 1.x release.

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
* What the request received: Markdown, the normal HTML page, a block (403) or
  a throttle (429)

HTML page views are only recorded when "Log All AI Crawler Traffic" is also
enabled (off by default), and only for User-Agents on your bot list. Human
visitors are never logged.

IP hashes cannot be reversed from an email address, so the WordPress privacy
exporter/eraser tools will report no personal data on demand.

Logs are pruned daily according to the configured retention window
(7/30/90/365 days, default 30). Site owners can disable logging or click
"Delete all logged requests" at any time from Tools → ParseLess.

== Changelog ==

= 1.0.0 =

**Know your bots**

* New: the Analytics tab now tells you *who* is reading and *why*. Every known crawler is labelled with its company (OpenAI, Anthropic, Perplexity, Google, Meta…) and purpose — model training, search indexing, live answers, or coding agents — with breakdowns by both. The headline number is "chat impressions": how often your page was fetched live while someone was talking to an AI assistant.
* New: "Log All AI Crawler Traffic" (off by default) records known AI bots even when they receive your normal HTML page, not just when they get Markdown. With it on, a new "Known bots — reachability" table shows every crawler on your list with when it was last seen and what it received. A bot that is never seen is the tell-tale sign that your host or CDN is blocking it before it reaches WordPress.
* New: blocked (403) and throttled (429) requests are now logged alongside served ones, and the CSV export gains a "served" column.
* Improved: five more crawlers recognised out of the box — Claude-SearchBot, Meta-ExternalFetcher, Amazonbot, DuckAssistBot and MistralAI-User.

**Control the cost**

* New: per-bot rate limiting (off by default). Turn it on under Tools → ParseLess and each AI crawler is capped at a number of requests per minute (60 by default); over the limit it receives a 429 with a Retry-After header. Applies to Markdown, llms-full.txt and the OKF bundle. People using ?format=md and normal HTML browsing are never throttled.
* New: an "Exclude from AI" checkbox in the ParseLess box on every post and page. Ticked, the post disappears from the Markdown endpoint, llms.txt, the AI sitemap and the OKF bundle — for pages you'd rather AI systems didn't read.

**Agent-native**

* New: on WordPress 6.9 and later, ParseLess registers three Abilities — get a post as Markdown, list the Markdown URLs, and (for administrators) read crawler stats — so MCP clients and AI agents can use your content through the WordPress Abilities API instead of sniffing User-Agents.
* Improved: the public API — every filter, action and ability — is documented under "For developers" and frozen for the 1.x series.
* Fixed: uninstalling on a site with Redis or Memcached left cached Markdown behind in the object cache. Uninstall now clears it there too.

**Not in this release**

* Verifying that a crawler really is who it claims to be, against the IP ranges AI companies publish, is planned for a 1.x update. Until then, bot counts trust the User-Agent header.

= 0.9.0 =
* New: WooCommerce products now include the details an AI agent actually needs — price, sale price, SKU, stock status and quantity, categories, and attributes — as a clean table. Until now a product was served as a title and a paragraph, so an agent asked "what does this cost and can I buy it" had nothing to work with.
* New: variable products list their variations, each with its own options, SKU, price and availability. Long variation lists are capped and the output tells you when it capped them.
* New: your OKF bundle marks products as `type: "Product"` and gives them a `# Schema` section, which is what OKF-aware consumers look for.
* New: product categories and attributes now appear as tags in the OKF bundle. Custom post types with their own taxonomies benefit from the same fix — previously only standard post categories and tags were picked up.
* New: products get their own, shorter cache lifetime (one hour by default), because a stale price is worse than a stale blog post. Both settings live in a WooCommerce section on Tools → ParseLess that only appears when WooCommerce is active.
* Improved: cart, checkout, my-account and terms pages are never served as Markdown. They render as empty shells without a shopping session, so they were only ever noise in your llms.txt and OKF bundle.
* Developer: new filters `md4ai_excluded_post_ids`, `md4ai_product_details` and `md4ai_max_variations`; `md4ai_cache_ttl` now receives the post ID as a second argument.

= 0.8.0 =
* New: AI agents can now simply ask for Markdown. Clients that send an `Accept: text/markdown` request header — Claude Code, OpenCode and a growing number of agents do this by default — get Markdown without needing to know about `?format=md`. On by default.
* New: every post is also available at a `.md` address, so `/my-post/` can be fetched as `/my-post.md`. This is the form the llms.txt ecosystem and Cloudflare's documentation expect.
* New: your pages now advertise their Markdown version, in both an HTTP `Link` header and a `<link rel="alternate">` tag. Until now the feature existed but nothing told agents it was there — they had to already know to look.
* New: an `X-Markdown-Tokens` header on every Markdown response, estimating how many tokens the response costs an AI model. The receipt for the bandwidth you're saving.
* New: Content Signals in robots.txt. Say whether AI systems may use your content for search, for generated answers, and for training — three independent choices, each of which you can also leave unstated. Optional policy text explains what the signals mean and records restrictions as an express reservation of rights under EU copyright law. All off by default: your licensing stance isn't ours to guess.
* New: your Blocked Bots list can now be declared in robots.txt as well as enforced. Blocked crawlers already got a 403; this tells the polite ones up front so they never fetch at all.
* New: optional machine-readable licence terms at /license.xml, following the RSL (Really Simple Licensing) standard, linked from robots.txt and your response headers. Declaration only — no payment handling.
* Fixed: AI crawlers were being told not to index your content. Every Markdown response carried a `noindex` instruction, including on your normal post URLs — so GPTBot fetching your post was asked to ignore it. `noindex` now applies only to the duplicate `?format=md` and `.md` addresses, where it belongs.
* Fixed: pages built with Elementor (and other page builders) came out empty in Markdown, llms.txt and OKF output. They now render properly.
* Fixed: fetching /llms-full.txt could poison the cache for individual posts, so a post served broken content for up to a day afterwards. The two no longer share a cache entry.
* Fixed: apostrophes, ampersands and dashes appeared as `&#039;`, `&amp;` and `&#8211;` in llms.txt and OKF index and log files. All metadata is now clean plain text.
* Fixed: Elementor's "Default Kit" — an internal container for your global colours and fonts — no longer appears as a page in your OKF bundle. Empty pages are left out of the bundle too.
* Improved: the OKF bundle now targets Open Knowledge Format v0.2, replacing the deprecated timestamp field with the v0.2 `generated` block.
* Fixed: Blocked Bots now also applies to the OKF bundle and /llms-full.txt.
* Fixed: all endpoints work when WordPress is installed in a subdirectory.
* Fixed: "Add to bot list" no longer replaces the built-in bot list.
* Fixed: lazy-loaded images no longer disappear from Markdown output.
* Fixed: the Enabled Post Types setting now applies to llms.txt, the sitemap and the OKF bundle.
* Fixed: OKF bundle requests are much faster on large sites.
* Fixed: sites with a static front page or a separate posts page no longer advertise broken Markdown addresses.
* Fixed: license.xml now validates against the RSL specification.
* Fixed: responses that differ per crawler — the 403 sent to blocked bots, /llms-full.txt and the OKF bundle — now tell shared caches and CDNs not to store them, so one crawler's response can't be served to a different one.
* Fixed: post edits show up in the OKF bundle immediately on sites using a persistent object cache such as Redis or Memcached.
* Fixed: editor autosaves no longer clear the OKF bundle cache, which kept it needlessly cold on busy sites.
* Fixed: titles and excerpts containing a stray "<" character are no longer cut short in Markdown metadata.
* Fixed: posts with non-Latin slugs are reachable in the OKF bundle on sites without pretty permalinks.
* Fixed: the editor's "View as Markdown" button now follows your Detection Mode setting instead of linking to a page that serves plain HTML.
* Fixed: clearing the Max Posts fields in settings no longer silently empties llms.txt and the OKF bundle.

= 0.7.0 =
* New: Open Knowledge Format (OKF) bundle. Enable it under Tools → ParseLess to serve your content as Google's OKF v0.1 bundle for AI agents at /okf/ — an index, a changelog at /okf/log.md, and one cross-linked Markdown file per post (also reachable via ?format=okf). Internal links between your posts are rewritten so agents can follow them within the bundle. The /okf/ path is configurable in case it clashes with an existing page. Off by default; experimental, as few agents crawl OKF bundles yet.
* New: "Top fetched URLs" table on the Analytics tab, covering endpoint fetches (llms.txt, sitemap, OKF) that carry no post ID.
* New: block specific AI crawlers from your Markdown. Add User-Agent substrings to the new "Blocked Bots" list under Tools → ParseLess and matching crawlers get a 403 instead of your content. Empty by default, and it never affects normal HTML browsing.
* Fixed: script and style tags in post content no longer leak their raw code into the Markdown, llms.txt, and OKF output — only real content is served.
* Security: the analytics CSV export now neutralises spreadsheet formula injection, so a crafted User-Agent or URL can't run as a formula when the export is opened in Excel or Google Sheets.

= 0.6.0 =
* New: /llms-full.txt endpoint — the full Markdown content of your latest posts in a single document (capped at 500KB), perfect for AI systems that want everything in one fetch. Shares the llms.txt toggle.
* New: optional schema.org Article block appended to Markdown output — structured metadata (title, author, dates, featured image) for AI systems. Off by default, enable under Tools → ParseLess.
* Fixed: Markdown and llms.txt responses were HTML-entity-encoded (ampersands, angle brackets and quotes showed up as &amp;amp;-style codes). Output is now clean plain text.
* Fixed: bold/italic text with surrounding spaces, table cells containing pipes or paragraphs, multi-line headings, and inline code containing backticks all produced broken Markdown. All render correctly now.
* Fixed: posts marked noindex or password-protected no longer appear in llms.txt (matching the Markdown endpoint and sitemap).
* Fixed: bot names were logged as blank in analytics when using the built-in bot list.
* Improved: llms.txt and llms-full.txt caches are now refreshed when you publish or update a post.

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
