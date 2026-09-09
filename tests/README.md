# Tests

Run them with `make test` from the plugin root, or `php tests/run.php`.
Run one file with `make test FILTER=facepile`.

## How they work

There is no WordPress here, and no PHPUnit. `bootstrap.php` declares just enough of
WordPress for the plugin code under test, so the tests need nothing but PHP.

Each file runs in its own process. That is not tidiness: `test-activitypub.php` needs
the ActivityPub plugin to exist, `test-comment-types.php` needs it not to, and PHP
cannot forget a function once it has been declared. `test-legacy-wordpress.php` leaves
`wp_is_serving_rest_request()` undeclared to stand in for WordPress before 6.5.

## What they do not cover

Testing against a copy of WordPress rather than the real thing has a cost, and it is
worth being plain about where it falls:

- `exclude_from_comment_count()` builds SQL and runs it through `$wpdb`. Nothing here
  executes it. It needs a real database.
- Every Mastodon response in these files was written by hand from Mastodon's route and
  serializer definitions. None came off a real server.
- The interaction with the real ActivityPub plugin is modelled on its 9.3.1 source, not
  tested against it. If that plugin changes how `is_comment_type_enabled()` or
  `activitypub_excluded_comment_types` behave, these tests will not notice.
