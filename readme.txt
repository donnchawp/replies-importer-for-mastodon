=== Replies Importer for Mastodon ===
Contributors: donncha
Tags: mastodon, comments, social media, import
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 0.0.1
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import replies from your Mastodon posts linking to your WordPress site as comments.

== Description ==

The Replies Importer for Mastodon plugin allows you to automatically import replies to your Mastodon posts that link to your site as comments on the mentioned posts. This plugin bridges the gap between your Mastodon presence and your WordPress site, enabling a seamless integration of discussions across platforms.

== Key features ==

* Connect your WordPress site to your Mastodon account
* Automatically import Mastodon replies as WordPress comments
* Schedule imports on an hourly or daily basis
* Manually trigger imports when needed
* Maintain the conversation thread structure from Mastodon

== How to Use ==

1. Share one of your WordPress posts on Mastodon.
2. Reply to your Mastodon post or wait for others to reply.
3. Return to the Replies Importer for Mastodon settings page in your WordPress dashboard.
4. Click the "Check Now" button to manually trigger an import.
5. Within a few minutes, you should see the Mastodon replies appear as moderated comments on your WordPress post.


== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/replies-importer-for-mastodon` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Use the Settings->Replies Importer for Mastodon screen to configure the plugin.

== Frequently Asked Questions ==

= How do I connect my Mastodon account? =

Navigate to the plugin settings page at Settings->Replies Importer for Mastodon, enter your Mastodon instance URL, and click "Authorize with Mastodon". You'll be redirected to your Mastodon instance to approve the connection.

= How often are replies imported? =

You can choose between hourly and daily imports, or trigger a manual import at any time.

= Are all replies imported? =

The plugin imports public replies to your Mastodon posts that contain a link to your WordPress site. Private replies are not imported.

== Changelog ==

= 0.0.1 =
* Initial release

== Upgrade Notice ==

= 0.0.1 =
Initial release of the Replies Importer for Mastodon plugin.
