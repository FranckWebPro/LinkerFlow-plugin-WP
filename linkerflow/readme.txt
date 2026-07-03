=== LinkerFlow ===
Contributors: linkerflow
Tags: internal links, seo, content, automation, rest api
Requires at least: 6.5
Tested up to: 7.0
Stable tag: 1.0.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to the LinkerFlow service to manage approved internal links in published content.

== Description ==

The LinkerFlow WordPress plugin connects your WordPress site to the LinkerFlow service for internal-link management. After you approve the connection, the LinkerFlow service can read published posts, pages, selected public custom post types, permalinks, language information, and post content through authenticated REST API endpoints.

The LinkerFlow service can also update post content through this plugin to publish approved internal links. Updates use WordPress' native post update flow, so revisions are created when revisions are enabled for the post type.

This plugin does not add public-facing credits or links to your site. It does not load third-party scripts or styles. It does not read WordPress users, passwords, comments, private posts, drafts, settings, or unrelated plugin data.

= External service =

This plugin connects to the LinkerFlow software-as-a-service application operated at https://www.linkerflow.io and https://app.linkerflow.io.

The plugin requires a LinkerFlow account and an active LinkerFlow service plan to provide internal-link management. The GPL license applies to this WordPress plugin's source code, not to the separately operated LinkerFlow SaaS application or service plan.

The site owner initiates the connection from WP Admin. During connection, the plugin redirects the browser to the LinkerFlow application with the site URL, a one-time nonce, and a state token. The LinkerFlow application then confirms the connection by sending the nonce and a generated shared secret to this site's REST API. After connection, the LinkerFlow service uses that secret to authenticate future API requests.

Data sent to or accessed by the LinkerFlow service may include:

* Site URL.
* Published post, page, and selected public custom post type titles.
* Published content from `post_content`.
* Permalinks.
* Post type, publication status, modified date, and language information.
* Updated post content when approved internal links are published.

The service terms are available at https://www.linkerflow.io/terms.
The privacy policy is available at https://www.linkerflow.io/privacy.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/linkerflow` directory, or install the plugin through the WordPress Plugins screen.
2. Activate LinkerFlow from the Plugins screen.
3. Open the LinkerFlow menu in WP Admin.
4. Click "Connect to LinkerFlow" and approve the connection in the LinkerFlow application.

== Frequently Asked Questions ==

= Does this plugin publish links automatically? =

The plugin exposes authenticated endpoints that allow the LinkerFlow service to update published content. Link publication is controlled from the LinkerFlow application.

= Do I need a LinkerFlow account? =

Yes. This plugin is an interface between WordPress and the LinkerFlow service, and it requires a LinkerFlow account and active service plan to be useful.

= What content can the LinkerFlow service read? =

Only published, non-password-protected posts, pages, and selected public custom post types are exposed. Attachments, drafts, private posts, password-protected posts, comments, users, passwords, settings, and unrelated plugin data are not exposed.

= Does this plugin add public credit links? =

No. The plugin does not add public-facing LinkerFlow credit links or "powered by" links.

= Does this plugin load remote JavaScript, CSS, or tracking pixels? =

No. The plugin does not enqueue remote scripts or styles and does not add tracking pixels.

= Can I disconnect the plugin? =

Deleting the plugin removes the stored LinkerFlow service secret and connection tokens. You should also remove or disconnect the site in the LinkerFlow application.

== Changelog ==

= 1.0.1 =

* Exclude Divi 5 block-based pages from ingestion and writes until a block translator exists; only Divi 4 shortcode pages are supported.
* Return a 422 error when a page-builder write finds no matching text block, instead of reporting a successful update.
* Compare the incremental-crawl `modified_after` filter against `post_modified_gmt` so site timezone settings never shift the window.

= 1.0.0 =

* Initial WordPress.org-ready release.
* Expose detected meta-description sources (Yoast SEO, Rank Math, excerpt) on the post-types endpoint.
* Return a per-post meta description on the posts endpoint, resolved from the selected source with an excerpt fallback.
* The /connect endpoint is gated by the one-time nonce; the state token guards the browser redirect (CSRF) and is verified by LinkerFlow, matching the documented contract.
* Add a read endpoint for a single published post so LinkerFlow applies internal links on top of the live content instead of a stale snapshot, preserving edits made in the WordPress editor.
* Support Elementor and Divi pages. Content is read as HTML from the builder's widgets or shortcodes, and approved internal links are written back into the originating text widget or text module without overwriting the rest of the layout.
* Surface builder button and call-to-action links on read so the LinkerFlow link graph sees internal navigation built with the page builder.
* Pages built with still-unsupported builders (WPBakery, Avada Fusion) remain excluded.
* Exclude page-builder and WordPress internal post types (Elementor library, floating buttons, landing pages, Divi layout templates, block and template parts) from the selectable collection list and from crawling. Only editorial content types are offered.
