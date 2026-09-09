<?php
/**
 * Comment type registration and the fallback hooks, with ActivityPub absent.
 *
 * @package RepliesImporterForMastodon
 */

define( 'RIFM_TEST_MODERN_WP', true );
require __DIR__ . '/bootstrap.php';
require RIFM_PLUGIN_DIR . '/includes/comment-types.php';

$types = 'Replies_Importer_For_Mastodon_Comment_Types';

describe( 'Slugs match the ones the ActivityPub plugin registers' );
assert_same( 'slugs', array( 'like', 'repost', 'quote' ), $types::get_slugs() );
assert_same( 'every slug has a definition', array(), array_diff( $types::get_slugs(), array_keys( $types::get_types() ) ) );
assert_same( 'the like excerpt is what gets stored as content', '… liked this!', $types::get_type( 'like' )['excerpt'] );
assert_same( 'an unknown type is empty', array(), $types::get_type( 'nope' ) );

describe( 'Requirement 1: with no ActivityPub, nothing is delegated' );
assert_same( 'has_activitypub', false, $types::has_activitypub() );
assert_same( 'is_handled_by_activitypub', false, $types::is_handled_by_activitypub( 'like' ) );
assert_same( 'every type is imported', true, $types::should_import( 'like' ) && $types::should_import( 'repost' ) && $types::should_import( 'quote' ) );

describe( 'Requirement 2: the fallback hooks are registered' );
$types::register();
assert_same(
	'all six hooks',
	array( 'pre_get_comments', 'pre_wp_update_comment_count_now', 'get_avatar_comment_types', 'pre_get_avatar_data', 'the_content', 'wp_enqueue_scripts' ),
	array_keys( $GLOBALS['rifm_hooks'] )
);

describe( 'Requirement 3: reactions are kept out of the comment list' );
$query = new WP_Comment_Query();
$types::exclude_from_comment_query( $query );
assert_same( 'a singular front-end query excludes them', array( 'like', 'repost', 'quote' ), $query->query_vars['type__not_in'] );

$cases = array(
	'the admin comments screen is left alone' => array( 'is_admin' => true ),
	'REST is left alone'                      => array( 'is_rest' => true ),
	'archives are left alone'                 => array( 'is_singular' => false ),
);
foreach ( $cases as $label => $context ) {
	$GLOBALS['rifm_context'] = $context;
	$query                   = new WP_Comment_Query();
	$types::exclude_from_comment_query( $query );
	assert_true( $label, ! isset( $query->query_vars['type__not_in'] ) );
}
$GLOBALS['rifm_context'] = array();

foreach ( array( 'type', 'type__in', 'type__not_in' ) as $var ) {
	$query                     = new WP_Comment_Query();
	$query->query_vars[ $var ] = 'like';
	$types::exclude_from_comment_query( $query );
	assert_true( 'a query asking for ' . $var . ' is not overridden', 'like' === $query->query_vars[ $var ] );
}

describe( 'Requirement 4: an earlier count filter wins' );
assert_same( 'a non-null count passes through', 7, $types::exclude_from_comment_count( 7, 3, 1 ) );

describe( 'Requirement 5: avatars come from the stored URL' );
$GLOBALS['rifm_options']['show_avatars'] = 1;
$comment                                 = new WP_Comment( 'like' );
$comment->comment_ID                     = 1;
add_comment_meta( 1, 'avatar_url', 'https://mas.to/a.png' );

$args = $types::pre_get_avatar_data( array( 'size' => 48, 'class' => '' ), $comment );
assert_same( 'the stored avatar is used', 'https://mas.to/a.png', $args['url'] );
assert_same( 'found_avatar stops core adding avatar-default', true, $args['found_avatar'] );
assert_same( 'core classes are not repeated', array( 'avatar-mastodon' ), $args['class'] );

$ordinary = new WP_Comment( 'comment' );
assert_true( 'an ordinary comment is untouched', ! isset( $types::pre_get_avatar_data( array( 'size' => 48, 'class' => '' ), $ordinary )['url'] ) );

$GLOBALS['rifm_options']['show_avatars'] = 0;
assert_true( 'the show_avatars setting is respected', ! isset( $types::pre_get_avatar_data( array( 'size' => 48, 'class' => '' ), $comment )['url'] ) );

finish();
