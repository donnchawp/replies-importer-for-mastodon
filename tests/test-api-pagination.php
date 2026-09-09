<?php
/**
 * Fetching paged collections from Mastodon, and refusing to follow links off the instance.
 *
 * @package RepliesImporterForMastodon
 */

define( 'RIFM_TEST_MODERN_WP', true );
require __DIR__ . '/bootstrap.php';
require RIFM_PLUGIN_DIR . '/includes/debug.php';
require RIFM_PLUGIN_DIR . '/includes/comment-types.php';
require RIFM_PLUGIN_DIR . '/includes/api-functions.php';

/**
 * Stand in for the plugin's config.
 */
class Replies_Importer_For_Mastodon_Config {
	public static function get_instance() {
		return new self();
	}
	public static function get( $key, $default = null ) {
		return $default;
	}
	public function get_connection_option( $key, $default = null ) {
		return 'token';
	}
}

$api  = new Replies_Importer_For_Mastodon_API();
$base = 'https://mas.to';

/**
 * Build a response carrying a next link.
 *
 * @param string $url The next URL.
 * @return array The response.
 */
function next_link( $url ) {
	return array( 'headers' => array( 'link' => '<' . $url . '>; rel="next"' ) );
}

describe( 'Requirement 10: the Link header drives paging' );
$GLOBALS['rifm_http']      = array(
	rifm_response( array( array( 'url' => 'https://mas.to/@a' ) ), '<https://mas.to/api/v1/statuses/1/favourited_by?max_id=2>; rel="next", <https://mas.to/api/v1/statuses/1/favourited_by?since_id=9>; rel="prev"' ),
	rifm_response( array( array( 'url' => 'https://mas.to/@b' ) ) ),
);
$GLOBALS['rifm_requested'] = array();
$results                   = call_private( $api, 'get_paged_results', array( $base, $base . '/api/v1/statuses/1/favourited_by' ) );
assert_same( 'both pages are merged', 2, count( $results ) );
assert_same( 'the second request used the next link', 'https://mas.to/api/v1/statuses/1/favourited_by?max_id=2', $GLOBALS['rifm_requested'][1] );

$GLOBALS['rifm_http']      = array_fill( 0, 10, rifm_response( array( array( 'url' => 'https://mas.to/@x' ) ), '<https://mas.to/next>; rel="next"' ) );
$GLOBALS['rifm_requested'] = array();
$results                   = call_private( $api, 'get_paged_results', array( $base, $base . '/start' ) );
assert_same( 'paging stops at the page cap', REPLIES_IMPORTER_FOR_MASTODON_MAX_PAGES, count( $GLOBALS['rifm_requested'] ) );

describe( 'Requirement 9: a missing endpoint or a failure gives nothing' );
$GLOBALS['rifm_http'] = array( array( 'code' => 404, 'body' => '{}', 'headers' => array() ) );
assert_same( '404 from /quotes is not fatal', array(), call_private( $api, 'get_paged_results', array( $base, $base . '/q', true ) ) );

$GLOBALS['rifm_http'] = array( new WP_Error() );
assert_same( 'a transport error gives nothing', array(), call_private( $api, 'get_paged_results', array( $base, $base . '/q' ) ) );

$GLOBALS['rifm_http'] = array( rifm_response( array() ) );
assert_same( 'an empty page ends the loop', array(), call_private( $api, 'get_paged_results', array( $base, $base . '/q' ) ) );

describe( 'A next link is only followed when it stays on the instance' );
$refused = array(
	'localhost'            => 'http://127.0.0.1:8080/admin',
	'cloud metadata'       => 'http://169.254.169.254/latest/meta-data/',
	'another host'         => 'https://evil.example/steal',
	'a plain-text scheme'  => 'http://mas.to/api/v1/x',
	'a lookalike host'     => 'https://mas.to.evil.example/x',
	'a relative link'      => '/api/v1/x?max_id=2',
);
foreach ( $refused as $label => $url ) {
	assert_same( $label . ' is refused', '', call_private( $api, 'get_next_page_url', array( next_link( $url ), $base ) ) );
}
assert_same( 'the same host is followed', 'https://mas.to/api/v1/x?max_id=2', call_private( $api, 'get_next_page_url', array( next_link( 'https://mas.to/api/v1/x?max_id=2' ), $base ) ) );
assert_same( 'the host is compared without case', 'https://MAS.TO/api/v1/x', call_private( $api, 'get_next_page_url', array( next_link( 'https://MAS.TO/api/v1/x' ), $base ) ) );

$GLOBALS['rifm_http']      = array( rifm_response( array( array( 'url' => 'https://mas.to/@a' ) ), '<http://127.0.0.1/x>; rel="next"' ), rifm_response( array( array( 'url' => 'https://mas.to/@b' ) ) ) );
$GLOBALS['rifm_requested'] = array();
$results                   = call_private( $api, 'get_paged_results', array( $base, $base . '/api/v1/statuses/1/favourited_by' ) );
assert_same( 'a refused link stops the loop', 1, count( $GLOBALS['rifm_requested'] ) );

describe( 'Other Link header shapes' );
assert_same( 'no Link header', '', call_private( $api, 'get_next_page_url', array( array( 'headers' => array() ), $base ) ) );
assert_same( 'only rel=prev', '', call_private( $api, 'get_next_page_url', array( array( 'headers' => array( 'link' => '<https://mas.to/p>; rel="prev"' ) ), $base ) ) );
assert_same( 'an array of header values', 'https://mas.to/n', call_private( $api, 'get_next_page_url', array( array( 'headers' => array( 'link' => array( '<https://mas.to/n>; rel="next"' ) ) ), $base ) ) );

describe( 'Requirement 19: the run stops when it is out of time' );
$deadline = new ReflectionProperty( $api, 'deadline' );
$deadline->setValue( $api, time() - 1 );
$GLOBALS['rifm_http']      = array( rifm_response( array( array( 'url' => 'https://mas.to/@a' ) ) ) );
$GLOBALS['rifm_requested'] = array();
assert_same( 'no request is made once the deadline has passed', array(), call_private( $api, 'get_paged_results', array( $base, $base . '/x' ) ) );
assert_same( 'nothing was fetched', 0, count( $GLOBALS['rifm_requested'] ) );

$deadline->setValue( $api, 0 );
$GLOBALS['rifm_http'] = array( rifm_response( array( array( 'url' => 'https://mas.to/@a' ) ) ) );
assert_same( 'with no deadline set the fetch runs', 1, count( call_private( $api, 'get_paged_results', array( $base, $base . '/x' ) ) ) );

call_private( $api, 'start_time_budget', array() );
$budget = $deadline->getValue( $api ) - time();
assert_true( 'a budget is set, and it is sane', $budget > 0 && $budget <= REPLIES_IMPORTER_FOR_MASTODON_MAX_RUN_SECONDS );

finish();
