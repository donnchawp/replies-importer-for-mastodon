<?php
/**
 * Rendering the facepile, which only happens when the ActivityPub plugin is inactive.
 *
 * @package RepliesImporterForMastodon
 */

define( 'RIFM_TEST_MODERN_WP', true );
require __DIR__ . '/bootstrap.php';
require RIFM_PLUGIN_DIR . '/includes/comment-types.php';

$types                    = 'Replies_Importer_For_Mastodon_Comment_Types';
$GLOBALS['rifm_enqueued'] = array();

describe( 'A post with no reactions costs one query and renders nothing' );
$GLOBALS['rifm_queries'] = 0;
assert_same( 'nothing is rendered', '', $types::render_reactions( 10 ) );
assert_same( 'a single COUNT query', 1, $GLOBALS['rifm_queries'] );
assert_same( 'no stylesheet is loaded', array(), $GLOBALS['rifm_enqueued'] );
assert_same( 'the content is untouched', '<p>Body</p>', $types::append_reactions( '<p>Body</p>' ) );

describe( 'Requirement 6: each type gets its own group' );
rifm_seed_comment( array( 'type' => 'like', 'author' => 'Alice', 'author_url' => 'https://mas.to/@alice' ) );
rifm_seed_comment( array( 'type' => 'like', 'author' => 'Bob', 'author_url' => 'https://mas.to/@bob' ) );
rifm_seed_comment( array( 'type' => 'repost', 'author' => 'Carol', 'author_url' => 'https://mas.to/@carol' ) );
rifm_seed_comment( array( 'type' => 'quote', 'author' => 'Dave', 'author_url' => 'https://mas.to/@dave/9', 'content' => str_repeat( 'word ', 40 ) . 'tail' ) );

$html = $types::render_reactions( 10 );
assert_true( 'two likes read as plural', false !== strpos( $html, '2 likes' ) );
assert_true( 'one repost reads as singular', false !== strpos( $html, '1 repost' ) && false === strpos( $html, '1 reposts' ) );
assert_true( 'likes are a facepile', false !== strpos( $html, 'rifm-reactions__faces' ) );
assert_true( 'quotes are cards', false !== strpos( $html, 'rifm-reactions__quotes' ) );
assert_true( 'long quotes are cut short', false !== strpos( $html, 'word word…' ) && false === strpos( $html, 'tail' ) );
assert_true( 'the order is likes, reposts, quotes', strpos( $html, '--like' ) < strpos( $html, '--repost' ) && strpos( $html, '--repost' ) < strpos( $html, '--quote' ) );
assert_true( 'the stylesheet is loaded', in_array( 'replies-importer-for-mastodon', $GLOBALS['rifm_enqueued'], true ) );
assert_true( 'it goes after the post', 0 === strpos( $types::append_reactions( '<p>Body</p>' ), '<p>Body</p><div class="rifm-reactions"' ) );

describe( 'Requirement 6: a popular post does not render thousands of avatars' );
$GLOBALS['rifm_comments'] = array();
for ( $i = 0; $i < 500; $i++ ) {
	rifm_seed_comment( array( 'type' => 'like', 'author' => 'U' . $i, 'author_url' => 'https://mas.to/@u' . $i ) );
}
$html = $types::render_reactions( 10 );
assert_true( 'the heading still shows the real total', false !== strpos( $html, '500 likes' ) );
assert_same( 'the faces are capped', 50, substr_count( $html, 'rifm-reactions__face"' ) );

$GLOBALS['rifm_filters']['replies_importer_for_mastodon_max_faces'] = 5;
assert_same( 'and the cap can be filtered', 5, substr_count( $types::render_reactions( 10 ), 'rifm-reactions__face"' ) );
unset( $GLOBALS['rifm_filters']['replies_importer_for_mastodon_max_faces'] );

describe( 'Where the facepile must not appear' );
$cases = array(
	'archives'                  => array( 'is_singular' => false ),
	'outside the loop'          => array( 'in_the_loop' => false ),
	'secondary queries'         => array( 'is_main_query' => false ),
	'feeds'                     => array( 'is_feed' => true ),
	'password-protected posts'  => array( 'password_required' => true ),
);
foreach ( $cases as $label => $context ) {
	$GLOBALS['rifm_context'] = $context;
	assert_same( $label, 'X', $types::append_reactions( 'X' ) );
}
$GLOBALS['rifm_context'] = array();
assert_true( 'but an ordinary post gets one', 'X' !== $types::append_reactions( 'X' ) );

describe( 'Every link out is nofollow' );
$GLOBALS['rifm_comments'] = array();
rifm_seed_comment( array( 'type' => 'like', 'author' => 'Alice', 'author_url' => 'https://mas.to/@alice' ) );
rifm_seed_comment( array( 'type' => 'repost', 'author' => 'Bob', 'author_url' => 'https://mas.to/@bob' ) );
rifm_seed_comment( array( 'type' => 'quote', 'author' => 'Carol', 'author_url' => 'https://mas.to/@carol/9', 'content' => 'Worth a look' ) );
$html = $types::render_reactions( 10 );

preg_match_all( '/<a\s[^>]*>/', $html, $links );
assert_same( 'every reaction is a link', 3, count( $links[0] ) );

$without_rel = array_filter(
	$links[0],
	function ( $tag ) {
		return false === strpos( $tag, 'rel="external nofollow ugc"' );
	}
);
assert_same( 'and every one of them is external nofollow ugc', array(), array_values( $without_rel ) );

describe( 'Remote text is escaped on the way out' );
$GLOBALS['rifm_comments'] = array();
rifm_seed_comment( array( 'type' => 'like', 'author' => '<script>alert(1)</script>', 'author_url' => 'javascript:alert(1)' ) );
$html = $types::render_reactions( 10 );
assert_true( 'the author name is escaped', false === strpos( $html, '<script>' ) );
assert_true( 'a javascript: URL is dropped', false === strpos( $html, 'javascript:' ) );

finish();
