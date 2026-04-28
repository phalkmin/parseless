=== ParseLess ===
Contributors: phalkmin
Tags: ai, markdown, llms, bots, crawlers
Requires at least: 6.8
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 0.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Less parsing, fewer tokens, cleaner AI context — serves WordPress content as Markdown to AI crawlers and on ?format=md requests.

== Description ==

ParseLess serves your WordPress post content as clean Markdown to AI crawlers (via User-Agent detection) and on manual `?format=md` requests. It also exposes `/llms.txt` for the emerging AI-indexing standard.

Site owners serve AI systems lightweight, structured content instead of bloated HTML — and can see exactly who's fetching what.

**Features:**

* Automatic Markdown serving to known AI bots (GPTBot, ClaudeBot, Perplexity, and more)
* Manual preview via `?format=md` on any post URL
* `/llms.txt` endpoint listing all published posts
* Respects noindex settings from Yoast SEO, Rank Math, and Genesis
* Skips private, draft, password-protected, and trashed posts
* Supports all public post types (configurable)
* Transient-based caching with configurable TTL
* Extensible via filters: `md4ai_bot_list`, `md4ai_supported_post_types`, `md4ai_markdown_output`, `md4ai_cache_ttl`

== Installation ==

1. Upload the `wp-botfood` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. That's it — AI crawlers will automatically receive Markdown content, and you can preview any post at `?format=md`

== Frequently Asked Questions ==

= How do I preview the Markdown output for a post? =

Append `?format=md` to any post URL, e.g. `https://example.com/my-post/?format=md`.

= Which AI bots are detected? =

GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, Claude-User, anthropic-ai, PerplexityBot, CCBot, Google-Extended, Applebot-Extended, Bytespider, Meta-ExternalAgent, cohere-ai. You can extend or replace this list with the `md4ai_bot_list` filter.

= Will this affect my normal visitors? =

No. Markdown is only served to requests that match a known bot User-Agent or include `?format=md`. All other requests receive normal WordPress HTML output.

== Changelog ==

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

== Upgrade Notice ==

= 0.2.0 =
Recommended update — improves Markdown quality and adds privacy controls so noindex posts are never shared with AI crawlers.
