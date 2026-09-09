<?php
/**
 * Test bootstrap for Replies Importer for Mastodon.
 *
 * There is no WordPress here. Each test file declares just enough of WordPress to run
 * the plugin code it covers, so `make test` needs nothing but PHP. That buys fast,
 * dependency-free tests at the cost of testing against a copy of WordPress rather than
 * the real thing, so anything that leans on real WordPress behaviour, the database
 * above all, is out of reach and has to be checked by hand.
 *
 * A test file sets the constants it needs, then requires this file:
 *
 *   RIFM_TEST_ACTIVITYPUB   Declare a stand-in for the ActivityPub plugin.
 *   RIFM_TEST_MODERN_WP     Declare wp_is_serving_rest_request(), WordPress 6.5 and up.
 *
 * @package RepliesImporterForMastodon
 */

define( 'RIFM_PLUGIN_DIR', dirname( __DIR__ ) );

/*
 * Deliberately smaller than the value the plugin ships, to keep the tests quick. They
 * check that paging stops at whatever this is, not that the shipped number is right.
 */
if ( ! defined( 'REPLIES_IMPORTER_FOR_MASTODON_MAX_PAGES' ) ) {
	define( 'REPLIES_IMPORTER_FOR_MASTODON_MAX_PAGES', 5 );
}
if ( ! defined( 'REPLIES_IMPORTER_FOR_MASTODON_MAX_RUN_SECONDS' ) ) {
	define( 'REPLIES_IMPORTER_FOR_MASTODON_MAX_RUN_SECONDS', 60 );
}
if ( ! defined( 'REPLIES_IMPORTER_FOR_MASTODON_VERSION' ) ) {
	define( 'REPLIES_IMPORTER_FOR_MASTODON_VERSION', '0.0.1' );
}
if ( ! defined( 'REPLIES_IMPORTER_FOR_MASTODON_PLUGIN_URL' ) ) {
	define( 'REPLIES_IMPORTER_FOR_MASTODON_PLUGIN_URL', 'https://example.com/wp-content/plugins/rifm/' );
}

/*
 * Test state. Tests set these directly; the WordPress stand-ins below read them.
 */
$GLOBALS['rifm_hooks']     = array();
$GLOBALS['rifm_options']   = array();
$GLOBALS['rifm_filters']   = array();
$GLOBALS['rifm_context']   = array();
$GLOBALS['rifm_http']      = array();
$GLOBALS['rifm_requested'] = array();
$GLOBALS['rifm_comments']  = array();
$GLOBALS['rifm_inserted']  = array();
$GLOBALS['rifm_meta']      = array();
$GLOBALS['rifm_queries']   = 0;
$GLOBALS['rifm_disallowed'] = '';

// -- Hooks --------------------------------------------------------------------------

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['rifm_hooks'][ $hook ][] = $callback;
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['rifm_hooks'][ $hook ][] = $callback;
}

function apply_filters( $hook, $value ) {
	if ( isset( $GLOBALS['rifm_filters'][ $hook ] ) ) {
		return $GLOBALS['rifm_filters'][ $hook ];
	}

	foreach ( isset( $GLOBALS['rifm_hooks'][ $hook ] ) ? $GLOBALS['rifm_hooks'][ $hook ] : array() as $callback ) {
		$value = call_user_func( $callback, $value );
	}

	return $value;
}

// -- Options and i18n ---------------------------------------------------------------

function get_option( $key, $default = false ) {
	return isset( $GLOBALS['rifm_options'][ $key ] ) ? $GLOBALS['rifm_options'][ $key ] : $default;
}

function __( $text, $domain = null ) {
	return $text;
}
function _x( $text, $context, $domain = null ) {
	return $text;
}
function esc_html__( $text, $domain = null ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function _nx_noop( $singular, $plural, $context, $domain = null ) {
	return array( 'singular' => $singular, 'plural' => $plural, 'context' => $context, 'domain' => $domain );
}
function translate_nooped_plural( $nooped, $count, $domain = null ) {
	return 1 === (int) $count ? $nooped['singular'] : $nooped['plural'];
}
function number_format_i18n( $number ) {
	return (string) $number;
}

// -- Escaping and sanitizing --------------------------------------------------------

function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function esc_attr( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function esc_url( $url ) {
	return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}
function esc_url_raw( $url ) {
	return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}
function sanitize_text_field( $text ) {
	return trim( wp_strip_all_tags( $text ) );
}
function sanitize_textarea_field( $text ) {
	return trim( $text );
}
function wp_strip_all_tags( $text ) {
	return strip_tags( $text );
}
function wp_kses_post( $text ) {
	return $text;
}
function wp_trim_words( $text, $words ) {
	$parts = preg_split( '/\s+/', trim( $text ) );

	return count( $parts ) > $words ? implode( ' ', array_slice( $parts, 0, $words ) ) . '…' : $text;
}
function is_email( $email ) {
	return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

// -- Dates --------------------------------------------------------------------------

function current_time( $type, $gmt = 0 ) {
	return gmdate( 'Y-m-d H:i:s' );
}
function get_date_from_gmt( $date ) {
	return $date;
}

// -- Template context ---------------------------------------------------------------

function rifm_context( $key, $default = false ) {
	return isset( $GLOBALS['rifm_context'][ $key ] ) ? $GLOBALS['rifm_context'][ $key ] : $default;
}
function is_admin() {
	return rifm_context( 'is_admin', false );
}
function is_singular() {
	return rifm_context( 'is_singular', true );
}
function in_the_loop() {
	return rifm_context( 'in_the_loop', true );
}
function is_main_query() {
	return rifm_context( 'is_main_query', true );
}
function is_feed() {
	return rifm_context( 'is_feed', false );
}
function post_password_required() {
	return rifm_context( 'password_required', false );
}
function get_the_ID() {
	return rifm_context( 'post_id', 10 );
}

if ( defined( 'RIFM_TEST_MODERN_WP' ) && RIFM_TEST_MODERN_WP ) {
	function wp_is_serving_rest_request() {
		return rifm_context( 'is_rest', false );
	}
}

// -- HTTP ---------------------------------------------------------------------------

class WP_Error {
	private $message;

	public function __construct( $message = 'error' ) {
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * Return the next canned response and record the URL that asked for it.
 *
 * @param string $url  The URL.
 * @param array  $args Request arguments.
 * @return array|WP_Error The response.
 */
function wp_safe_remote_get( $url, $args = array() ) {
	$GLOBALS['rifm_requested'][] = $url;
	$next                        = array_shift( $GLOBALS['rifm_http'] );

	return null === $next ? new WP_Error( 'no canned response' ) : $next;
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['code'];
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}
function wp_remote_retrieve_header( $response, $header ) {
	return isset( $response['headers'][ $header ] ) ? $response['headers'][ $header ] : '';
}

/**
 * Build a canned 200 response.
 *
 * @param array  $body The body, encoded to JSON.
 * @param string $link Optional Link header.
 * @return array The response.
 */
function rifm_response( $body, $link = null ) {
	return array(
		'code'    => 200,
		'body'    => wp_json_encode( $body ),
		'headers' => $link ? array( 'link' => $link ) : array(),
	);
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

// -- Comments -----------------------------------------------------------------------

class WP_Comment_Query {
	public $query_vars = array();
}

class WP_Comment {
	public $comment_ID         = 0;
	public $user_id            = 0;
	public $comment_type       = '';
	public $comment_author     = '';
	public $comment_author_url = '';
	public $comment_content    = '';

	public function __construct( $type = '', $author = '', $url = '', $content = '' ) {
		$this->comment_type       = $type;
		$this->comment_author     = $author;
		$this->comment_author_url = $url;
		$this->comment_content    = $content;
	}
}

/**
 * Query the fake comment store.
 *
 * Supports only the arguments the plugin actually uses.
 *
 * @param array $args Query arguments.
 * @return array|int The comments, or a count.
 */
function get_comments( $args ) {
	++$GLOBALS['rifm_queries'];

	$hits = array_filter(
		$GLOBALS['rifm_comments'],
		function ( $row ) use ( $args ) {
			if ( isset( $args['post_id'] ) && (int) $row['post_id'] !== (int) $args['post_id'] ) {
				return false;
			}
			if ( isset( $args['type'] ) && $row['type'] !== $args['type'] ) {
				return false;
			}
			if ( isset( $args['type__in'] ) && ! in_array( $row['type'], $args['type__in'], true ) ) {
				return false;
			}
			if ( isset( $args['author_url'] ) && $row['author_url'] !== $args['author_url'] ) {
				return false;
			}
			if ( isset( $args['author_email'] ) && $row['author_email'] !== $args['author_email'] ) {
				return false;
			}
			if ( isset( $args['status'] ) && 'approve' === $args['status'] && 1 !== (int) $row['approved'] ) {
				return false;
			}
			return true;
		}
	);

	if ( ! empty( $args['count'] ) ) {
		return count( $hits );
	}

	$hits = array_values( $hits );

	if ( ! empty( $args['number'] ) ) {
		$hits = array_slice( $hits, 0, (int) $args['number'] );
	}

	return array_map(
		function ( $row ) {
			$comment                 = new WP_Comment( $row['type'], $row['author'], $row['author_url'], $row['content'] );
			$comment->comment_ID     = $row['id'];
			return $comment;
		},
		$hits
	);
}

/**
 * Add a row to the fake comment store.
 *
 * @param array $row Row fields.
 * @return int The comment ID.
 */
function rifm_seed_comment( $row ) {
	$id                          = count( $GLOBALS['rifm_comments'] ) + 1;
	$GLOBALS['rifm_comments'][]  = array_merge(
		array(
			'id'           => $id,
			'post_id'      => 10,
			'type'         => 'like',
			'author'       => '',
			'author_url'   => '',
			'author_email' => '',
			'content'      => '',
			'approved'     => 1,
		),
		$row
	);

	return $id;
}

function wp_filter_comment( $commentdata ) {
	return $commentdata;
}

function wp_insert_comment( $commentdata ) {
	$GLOBALS['rifm_inserted'][] = $commentdata;

	return rifm_seed_comment(
		array(
			'post_id'      => $commentdata['comment_post_ID'],
			'type'         => $commentdata['comment_type'],
			'author'       => $commentdata['comment_author'],
			'author_url'   => $commentdata['comment_author_url'],
			'author_email' => $commentdata['comment_author_email'],
			'content'      => $commentdata['comment_content'],
			'approved'     => $commentdata['comment_approved'],
		)
	);
}

function add_comment_meta( $comment_id, $key, $value ) {
	$GLOBALS['rifm_meta'][] = array( $comment_id, $key, $value );
}

function get_comment_meta( $comment_id, $key, $single = false ) {
	foreach ( $GLOBALS['rifm_meta'] as $row ) {
		if ( $row[0] === $comment_id && $row[1] === $key ) {
			return $row[2];
		}
	}

	return '';
}

function wp_check_comment_disallowed_list( $author, $email, $url, $comment, $ip, $agent ) {
	if ( '' === $GLOBALS['rifm_disallowed'] ) {
		return false;
	}

	return false !== stripos( $author . ' ' . $comment, $GLOBALS['rifm_disallowed'] );
}

// -- Avatars and styles -------------------------------------------------------------

function get_avatar( $comment, $size, $default = '', $alt = '' ) {
	return '<img alt="' . esc_attr( $alt ) . '" src="https://mas.to/avatar.png" />';
}
function wp_register_style() {}
function wp_enqueue_style( $handle ) {
	$GLOBALS['rifm_enqueued'][] = $handle;
}

// -- Assertions ---------------------------------------------------------------------

$GLOBALS['rifm_passed'] = 0;
$GLOBALS['rifm_failed'] = array();

/**
 * Print a section heading.
 *
 * @param string $title The heading.
 */
function describe( $title ) {
	echo "\n  " . $title . "\n";
}

/**
 * Assert a condition.
 *
 * @param string $label     What is being checked.
 * @param bool   $condition The result.
 */
function assert_true( $label, $condition ) {
	if ( $condition ) {
		++$GLOBALS['rifm_passed'];
		echo "    ok    " . $label . "\n";
		return;
	}

	$GLOBALS['rifm_failed'][] = $label;
	echo "    FAIL  " . $label . "\n";
}

/**
 * Assert two values match.
 *
 * @param string $label    What is being checked.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 */
function assert_same( $label, $expected, $actual ) {
	if ( $expected === $actual ) {
		++$GLOBALS['rifm_passed'];
		echo "    ok    " . $label . "\n";
		return;
	}

	$GLOBALS['rifm_failed'][] = $label;
	echo "    FAIL  " . $label . "\n";
	echo "          expected: " . var_export( $expected, true ) . "\n";
	echo "          actual:   " . var_export( $actual, true ) . "\n";
}

/**
 * Call a private or protected method.
 *
 * @param object $object The object.
 * @param string $method The method name.
 * @param array  $args   Arguments.
 * @return mixed The return value.
 */
function call_private( $object, $method, $args = array() ) {
	$reflection = new ReflectionMethod( $object, $method );

	return $reflection->invokeArgs( $object, $args );
}

/**
 * Print the tally and set the exit code.
 */
function finish() {
	$failed = count( $GLOBALS['rifm_failed'] );

	echo "\n  " . $GLOBALS['rifm_passed'] . ' passed, ' . $failed . " failed\n";

	exit( $failed > 0 ? 1 : 0 );
}
