=== Replies Importer for Mastodon ===
Contributors: donncha
Tags: mastodon, comments, social media, import
Requires at least: 5.0
Tested up to: 6.7.1
Stable tag: 0.0.1
Requires PHP: 7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import replies, likes, reposts and quotes from your Mastodon posts as comments on your WordPress site.

== Description ==

When you share one of your posts on Mastodon, the conversation happens over there and your site never hears about it. This plugin imports that conversation back. Replies become comments on the post you linked to, and the likes, reposts and quotes the post picked up are recorded alongside them.

Likes, reposts and quotes are stored as their own comment types, using the same `like`, `repost` and `quote` names the ActivityPub plugin uses. If you run ActivityPub, this plugin leaves the display to it and your Mastodon reactions show up in its facepile next to the ones it federated itself. If you don't, this plugin renders them under the post and keeps them out of the comment list and the comment count.

== Key features ==

* Connect your WordPress site to your Mastodon account
* Automatically import Mastodon replies as WordPress comments
* Record likes, reposts and quotes as separate comment types
* Works with the ActivityPub plugin, or on its own
* Schedule imports on an hourly or daily basis
* Manually trigger imports when needed
* Maintain the conversation thread structure from Mastodon

== How to Use ==

1. Share one of your WordPress posts on Mastodon.
2. Reply to your Mastodon post, or wait for others to reply, like, boost or quote it.
3. Return to the Replies Importer for Mastodon settings page in your WordPress dashboard.
4. Click the "Check Now" button to manually trigger an import.
5. Within a few minutes, you should see the Mastodon replies appear as moderated comments on your WordPress post. Likes, reposts and quotes are held for moderation too, and appear once you approve them.

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

= What happens if I also run the ActivityPub plugin? =

That plugin already registers the `like`, `repost` and `quote` comment types, so this one doesn't. It just records the reactions and lets ActivityPub display them. Nothing is overwritten either way, and if you turn ActivityPub on later it picks up the reactions already in your database.

If you've unticked likes or reposts in the ActivityPub settings, this plugin stops importing them. You've already said you don't want them, and ActivityPub won't display them.

= Are likes and reposts held for moderation? =

Yes, the same as replies. Once you've approved a like or repost from an account, later likes and reposts from that same account are approved automatically, so you shouldn't have to moderate the same person twice.

= What happens if someone unlikes or unboosts my post? =

Nothing. Once a like or repost is recorded it stays until you delete the comment yourself. Mastodon's API returns the accounts that currently like a post rather than telling you what changed, so removing them would mean re-reading the full list on every import and would treat a failed API call as everyone unliking at once.

= Why don't I see any quotes? =

Quote posts arrived in Mastodon 4.5, and the endpoint this plugin uses doesn't exist on older instances. If yours predates that, replies, likes and reposts still import as normal.

= Is there a limit on how many likes or reposts are imported? =

Yes. The plugin reads up to 25 pages of each. Mastodon sends 40 accounts to a page, so that is 1,000 likes and 1,000 reposts for one post. Quotes come 20 to a page, so 500 of those. If a post goes past that, the rest are not imported and the debug log says so. Raise `REPLIES_IMPORTER_FOR_MASTODON_MAX_PAGES` if you need more.

= Why did an import stop early? =

Each Mastodon post now costs several API calls, so a busy account can take longer than PHP allows. The import watches the clock and stops on its own before it gets killed, which keeps the debug log honest about why it stopped. Nothing already imported is lost, and the next run carries on. If yours regularly stops early, raise `REPLIES_IMPORTER_FOR_MASTODON_MAX_RUN_SECONDS`, or import hourly instead of daily so there is less to do each time.

= Why is the date on a like wrong? =

Mastodon's API gives you the accounts that liked or boosted a post, but not when they did it. The import time is used instead. Quotes carry their real date.

== Changelog ==

= 0.0.1 =
* Initial release

== Upgrade Notice ==

= 0.0.1 =
Initial release of the Replies Importer for Mastodon plugin.
