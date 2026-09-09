<?php
/**
 * The comment list exclusion on WordPress before 6.5.
 *
 * wp_is_serving_rest_request() arrived in WordPress 6.5 and the plugin supports 5.0, so
 * this file leaves that function undeclared and checks the REST_REQUEST fallback.
 *
 * @package RepliesImporterForMastodon
 */

require __DIR__ . '/bootstrap.php';
require RIFM_PLUGIN_DIR . '/includes/comment-types.php';

$types = 'Replies_Importer_For_Mastodon_Comment_Types';

describe( 'WordPress 5.0 to 6.4 has no wp_is_serving_rest_request()' );
assert_same( 'the function really is absent', false, function_exists( 'wp_is_serving_rest_request' ) );

$query = new WP_Comment_Query();
$types::exclude_from_comment_query( $query );
assert_same( 'a normal page still excludes reactions', array( 'like', 'repost', 'quote' ), $query->query_vars['type__not_in'] );

define( 'REST_REQUEST', true );
$query = new WP_Comment_Query();
$types::exclude_from_comment_query( $query );
assert_true( 'REST_REQUEST is honoured instead', ! isset( $query->query_vars['type__not_in'] ) );

finish();
