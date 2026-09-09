<?php
/**
 * Behaviour when the ActivityPub plugin is active.
 *
 * The stand-in below mirrors ActivityPub 9.3.1: is_comment_type_enabled() reads the
 * per-type options, and the comment count filter builds its exclusion list from only
 * the types that are switched on.
 *
 * @package RepliesImporterForMastodon
 */

namespace Activitypub {
	function register_comment_type( $type, $args = array() ) {}

	class Comment {
		/**
		 * Mirrors ActivityPub's class-comment.php.
		 *
		 * @param string $type The comment type.
		 * @return bool Whether the type is switched on.
		 */
		public static function is_comment_type_enabled( $type ) {
			return '1' === \get_option( "activitypub_allow_{$type}s", '1' );
		}

		/**
		 * Mirrors the exclusion list ActivityPub's count filter builds.
		 *
		 * @return array The excluded comment type slugs.
		 */
		public static function excluded_types() {
			$enabled = \array_filter( array( 'like', 'repost', 'quote' ), array( self::class, 'is_comment_type_enabled' ) );

			return \apply_filters( 'activitypub_excluded_comment_types', \array_values( $enabled ) );
		}
	}
}

namespace {
	define( 'RIFM_TEST_ACTIVITYPUB', true );
	define( 'RIFM_TEST_MODERN_WP', true );
	require __DIR__ . '/bootstrap.php';
	require RIFM_PLUGIN_DIR . '/includes/comment-types.php';

	$types = 'Replies_Importer_For_Mastodon_Comment_Types';

	describe( 'Requirement 1: with everything switched on, ActivityPub owns the types' );
	$types::register();
	assert_same( 'ActivityPub is detected', true, $types::has_activitypub() );
	assert_same( 'only its count filter is added', array( 'activitypub_excluded_comment_types' ), array_keys( $GLOBALS['rifm_hooks'] ) );
	foreach ( $types::get_slugs() as $slug ) {
		assert_same( "ActivityPub handles '" . $slug . "'", true, $types::is_handled_by_activitypub( $slug ) );
		assert_same( "'" . $slug . "' is still imported", true, $types::should_import( $slug ) );
	}

	describe( 'Requirement 1: likes and reposts unticked in the ActivityPub settings' );
	$GLOBALS['rifm_options']['activitypub_allow_likes']   = '0';
	$GLOBALS['rifm_options']['activitypub_allow_reposts'] = '0';
	assert_same( 'it no longer handles likes', false, $types::is_handled_by_activitypub( 'like' ) );
	assert_same( 'it no longer handles reposts', false, $types::is_handled_by_activitypub( 'repost' ) );
	assert_same( 'it still handles quotes, which have no setting', true, $types::is_handled_by_activitypub( 'quote' ) );

	describe( 'Requirement 18: a type ActivityPub has switched off is not imported' );
	assert_same( 'likes are skipped', false, $types::should_import( 'like' ) );
	assert_same( 'reposts are skipped', false, $types::should_import( 'repost' ) );
	assert_same( 'quotes are still imported', true, $types::should_import( 'quote' ) );

	describe( 'Requirement 17: the comment count stays right even so' );
	$excluded = \Activitypub\Comment::excluded_types();
	sort( $excluded );
	assert_same( 'all three types are excluded from the count', array( 'like', 'quote', 'repost' ), $excluded );

	describe( 'An older ActivityPub with no per-type settings' );
	$GLOBALS['rifm_options'] = array();
	assert_same( 'the types default to switched on', true, $types::is_handled_by_activitypub( 'like' ) );

	finish();
}
