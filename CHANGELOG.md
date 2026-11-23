# ParseLess changelog: 0.1.0 to 0.9.0

Releases from 1.0.0 onwards are listed in readme.txt.

## 0.9.0

* New: WooCommerce products now include the details an AI agent actually needs — price, sale price, SKU, stock status and quantity, categories, and attributes — as a clean table. Until now a product was served as a title and a paragraph, so an agent asked "what does this cost and can I buy it" had nothing to work with.
* New: variable products list their variations, each with its own options, SKU, price and availability. Long variation lists are capped and the output tells you when it capped them.
* New: your OKF bundle marks products as `type: "Product"` and gives them a `# Schema` section, which is what OKF-aware consumers look for.
* New: product categories and attributes now appear as tags in the OKF bundle. Custom post types with their own taxonomies benefit from the same fix — previously only standard post categories and tags were picked up.
* New: products get their own, shorter cache lifetime (one hour by default), because a stale price is worse than a stale blog post. Both settings live in a WooCommerce section on Tools → ParseLess that only appears when WooCommerce is active.
* Improved: cart, checkout, my-account and terms pages are never served as Markdown. They render as empty shells without a shopping session, so they were only ever noise in your llms.txt and OKF bundle.
* Developer: new filters `md4ai_excluded_post_ids`, `md4ai_product_details` and `md4ai_max_variations`; `md4ai_cache_ttl` now receives the post ID as a second argument.

## 0.8.0

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

## 0.7.0

* New: Open Knowledge Format (OKF) bundle. Enable it under Tools → ParseLess to serve your content as Google's OKF v0.1 bundle for AI agents at /okf/ — an index, a changelog at /okf/log.md, and one cross-linked Markdown file per post (also reachable via ?format=okf). Internal links between your posts are rewritten so agents can follow them within the bundle. The /okf/ path is configurable in case it clashes with an existing page. Off by default; experimental, as few agents crawl OKF bundles yet.
* New: "Top fetched URLs" table on the Analytics tab, covering endpoint fetches (llms.txt, sitemap, OKF) that carry no post ID.
* New: block specific AI crawlers from your Markdown. Add User-Agent substrings to the new "Blocked Bots" list under Tools → ParseLess and matching crawlers get a 403 instead of your content. Empty by default, and it never affects normal HTML browsing.
* Fixed: script and style tags in post content no longer leak their raw code into the Markdown, llms.txt, and OKF output — only real content is served.
* Security: the analytics CSV export now neutralises spreadsheet formula injection, so a crafted User-Agent or URL can't run as a formula when the export is opened in Excel or Google Sheets.

## 0.6.0

* New: /llms-full.txt endpoint — the full Markdown content of your latest posts in a single document (capped at 500KB), perfect for AI systems that want everything in one fetch. Shares the llms.txt toggle.
* New: optional schema.org Article block appended to Markdown output — structured metadata (title, author, dates, featured image) for AI systems. Off by default, enable under Tools → ParseLess.
* Fixed: Markdown and llms.txt responses were HTML-entity-encoded (ampersands, angle brackets and quotes showed up as &amp;amp;-style codes). Output is now clean plain text.
* Fixed: bold/italic text with surrounding spaces, table cells containing pipes or paragraphs, multi-line headings, and inline code containing backticks all produced broken Markdown. All render correctly now.
* Fixed: posts marked noindex or password-protected no longer appear in llms.txt (matching the Markdown endpoint and sitemap).
* Fixed: bot names were logged as blank in analytics when using the built-in bot list.
* Improved: llms.txt and llms-full.txt caches are now refreshed when you publish or update a post.

## 0.5.0

* New: bot activity chart on the Analytics tab — see how each AI crawler's traffic trends across the last 30 days at a glance.
* New: AI sitemap at /botfood-sitemap.xml listing Markdown versions of up to 1,000 recent public posts, automatically advertised in robots.txt so AI crawlers can find it.
* The sitemap can be toggled off under Tools → ParseLess if you'd rather not expose it.

## 0.4.0

* New: optional request logging to a custom database table — see who's fetching what, with logging off by default until you turn it on.
* New: "ParseLess — AI Traffic" dashboard widget on wp-admin showing top bots and top fetched URLs over the past week.
* New: Analytics tab on Tools → ParseLess with a bot breakdown, top URLs, unknown bot-like UAs, and CSV export.
* New: one-click "Add to bot list" action for unknown UAs that look like AI crawlers.
* New: daily retention pruning with configurable windows (7/30/90/365 days).
* New: salted SHA-256 IP hashing and WordPress privacy exporter/eraser registrations.
* New: "Delete all logged requests" button for a one-click purge.

## 0.3.0

* Plugin renamed to ParseLess.
* Added Settings page (Tools → ParseLess) with controls for detection mode, bot list, cache TTL, post types, llms.txt, and frontmatter.
* Added per-post meta box with Markdown preview and copy-to-clipboard.

## 0.2.0

* The plugin now works with all public content types on your site, not just standard posts — so pages and custom post types are included automatically.
* Posts marked as "noindex" in Yoast SEO, Rank Math, or Genesis will no longer be shared with AI crawlers. Private, draft, and password-protected posts are also excluded.
* Markdown output is now much cleaner: tables convert properly, nested bullet lists keep their structure, and code blocks preserve the programming language when available.
* Cleaned up the plugin's internal code structure so it's easier to maintain and extend in future updates.
* When you uninstall the plugin, all cached data and settings are now properly removed.

## 0.1.0

* Initial release.
