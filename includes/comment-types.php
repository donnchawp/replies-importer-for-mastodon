<?php
/**
 * Comment types for Replies Importer for Mastodon
 *
 * Mastodon likes, reposts and quotes are stored as WordPress comments using the
 * same `like`, `repost` and `quote` slugs the ActivityPub plugin uses. When that
 * plugin is active it already registers those types, renders them and keeps them
 * out of comment queries and counts, so this class stays out of its way entirely.
 * Without it, this class provides the same support on its own.
 *
 * @package RepliesImporterForMastodon
 */

class Replies_Importer_For_Mastodon_Comment_Types {

	const LIKE   = 'like';
	const REPOST = 'repost';
	const QUOTE  = 'quote';

	/**
	 * Register the hooks needed when the ActivityPub plugin is not handling these types.
	 *
	 * Runs on `init` at priority 20, after the ActivityPub plugin registers its own
	 * comment types on `init` at priority 10, so the detection below is reliable.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 20 );
	}

	/**
	 * Whether the ActivityPub plugin is handling these comment types.
	 *
	 * @return bool True if the ActivityPub plugin is active.
	 */
	public static function has_activitypub() {
		return function_exists( 'Activitypub\register_comment_type' );
	}

	/**
	 * Get the comment types.
	 *
	 * Always available, whether or not the ActivityPub plugin is active, because the
	 * importer needs the excerpt text to store as comment content.
	 *
	 * @return array The comment types, keyed by slug.
	 */
	public static function get_types() {
		return array(
			self::LIKE   => array(
				'type'         => self::LIKE,
				'label'        => __( 'Likes', 'replies-importer-for-mastodon' ),
				'singular'     => __( 'Like', 'replies-importer-for-mastodon' ),
				'icon'         => '&#128077;',
				'class'        => 'p-like',
				'collection'   => 'likes',
				'excerpt'      => html_entity_decode( __( '&hellip; liked this!', 'replies-importer-for-mastodon' ) ),
				/* translators: %s: Number of likes */
				'count_single' => _x( '%s like', 'number of likes', 'replies-importer-for-mastodon' ),
				/* translators: %s: Number of likes */
				'count_plural' => _x( '%s likes', 'number of likes', 'replies-importer-for-mastodon' ),
			),
			self::REPOST => array(
				'type'         => self::REPOST,
				'label'        => __( 'Reposts', 'replies-importer-for-mastodon' ),
				'singular'     => __( 'Repost', 'replies-importer-for-mastodon' ),
				'icon'         => '&#9851;',
				'class'        => 'p-repost',
				'collection'   => 'reposts',
				'excerpt'      => html_entity_decode( __( '&hellip; reposted this!', 'replies-importer-for-mastodon' ) ),
				/* translators: %s: Number of reposts */
				'count_single' => _x( '%s repost', 'number of reposts', 'replies-importer-for-mastodon' ),
				/* translators: %s: Number of reposts */
				'count_plural' => _x( '%s reposts', 'number of reposts', 'replies-importer-for-mastodon' ),
			),
			self::QUOTE  => array(
				'type'         => self::QUOTE,
				'label'        => __( 'Quotes', 'replies-importer-for-mastodon' ),
				'singular'     => __( 'Quote', 'replies-importer-for-mastodon' ),
				'icon'         => '&#10078;',
				'class'        => 'p-quote',
				'collection'   => 'quotes',
				'excerpt'      => html_entity_decode( __( '&hellip; quoted this!', 'replies-importer-for-mastodon' ) ),
				/* translators: %s: Number of quotes */
				'count_single' => _x( '%s quote', 'number of quotes', 'replies-importer-for-mastodon' ),
				/* translators: %s: Number of quotes */
				'count_plural' => _x( '%s quotes', 'number of quotes', 'replies-importer-for-mastodon' ),
			),
		);
	}

	/**
	 * Get the comment type slugs.
	 *
	 * @return string[] The comment type slugs.
	 */
	public static function get_slugs() {
		return array( self::LIKE, self::REPOST, self::QUOTE );
	}

	/**
	 * Get a single comment type.
	 *
	 * @param string $type The comment type slug.
	 * @return array The comment type, or an empty array if it is not one of ours.
	 */
	public static function get_type( $type ) {
		$types = self::get_types();

		return isset( $types[ $type ] ) ? $types[ $type ] : array();
	}

	/**
	 * Register the fallback hooks.
	 *
	 * Does nothing when the ActivityPub plugin is active: it registers the same slugs
	 * and hooks the same filters, and adding a second set would fight with its own.
	 */
	public static function register() {
		if ( self::has_activitypub() ) {
			return;
		}

		add_action( 'pre_get_comments', array( __CLASS__, 'exclude_from_comment_query' ) );
		add_filter( 'pre_wp_update_comment_count_now', array( __CLASS__, 'exclude_from_comment_count' ), 5, 3 );
		add_filter( 'get_avatar_comment_types', array( __CLASS__, 'get_avatar_comment_types' ), 99 );
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'pre_get_avatar_data' ), 11, 2 );
	}

	/**
	 * Keep likes, reposts and quotes out of the theme's comment list.
	 *
	 * They are rendered separately as a facepile, so leaving them in the query would
	 * show them twice, once as "&hellip; liked this!" text in the comment list.
	 *
	 * @param WP_Comment_Query $query The comment query.
	 */
	public static function exclude_from_comment_query( $query ) {
		if ( ! $query instanceof WP_Comment_Query ) {
			return;
		}

		// The admin comments screen should still show them, so they can be moderated.
		if ( is_admin() || wp_is_serving_rest_request() ) {
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		// A query that already asks for specific types knows what it wants.
		if (
			! empty( $query->query_vars['type'] ) ||
			! empty( $query->query_vars['type__in'] ) ||
			! empty( $query->query_vars['type__not_in'] )
		) {
			return;
		}

		$query->query_vars['type__not_in'] = self::get_slugs();
	}

	/**
	 * Keep likes, reposts and quotes out of a post's comment count.
	 *
	 * Core counts every approved comment except `note`, so without this a post with
	 * two replies and twenty likes would report twenty-two comments.
	 *
	 * @param int|null $new_count The new comment count, or null to let core count.
	 * @param int      $old_count The old comment count.
	 * @param int      $post_id   The post ID.
	 * @return int|null The comment count.
	 */
	public static function exclude_from_comment_count( $new_count, $old_count, $post_id ) {
		if ( null !== $new_count ) {
			return $new_count;
		}

		global $wpdb;

		$excluded = self::get_slugs();

		// Core excludes 'note' itself; repeat it here because this replaces core's query.
		$excluded[] = 'note';

		$placeholders = implode( ', ', array_fill( 0, count( $excluded ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM $wpdb->comments WHERE comment_post_ID = %d AND comment_approved = '1' AND comment_type NOT IN ( $placeholders )",
				array_merge( array( $post_id ), $excluded )
			)
		);
	}

	/**
	 * Allow avatars on our comment types.
	 *
	 * @param array $types List of comment types that show avatars.
	 * @return array The filtered list.
	 */
	public static function get_avatar_comment_types( $types ) {
		return array_unique( array_merge( $types, self::get_slugs() ) );
	}

	/**
	 * Serve the Mastodon avatar stored against the comment.
	 *
	 * @param array             $args        Arguments passed to get_avatar_data().
	 * @param int|string|object $id_or_email A user ID, email address, or comment object.
	 * @return array The filtered arguments.
	 */
	public static function pre_get_avatar_data( $args, $id_or_email ) {
		if ( ! $id_or_email instanceof WP_Comment || $id_or_email->user_id ) {
			return $args;
		}

		if ( ! in_array( $id_or_email->comment_type, self::get_slugs(), true ) ) {
			return $args;
		}

		if ( ! get_option( 'show_avatars' ) ) {
			return $args;
		}

		$avatar = get_comment_meta( $id_or_email->comment_ID, 'avatar_url', true );

		if ( ! $avatar ) {
			return $args;
		}

		if ( empty( $args['class'] ) ) {
			$args['class'] = array();
		} elseif ( is_string( $args['class'] ) ) {
			$args['class'] = explode( ' ', $args['class'] );
		}

		/** This filter is documented in wp-includes/link-template.php */
		$args['url']     = apply_filters( 'get_avatar_url', $avatar, $id_or_email, $args );
		$args['class'][] = 'avatar';
		$args['class'][] = 'avatar-mastodon';
		$args['class'][] = 'avatar-' . (int) $args['size'];
		$args['class'][] = 'photo';
		$args['class']   = array_unique( $args['class'] );

		return $args;
	}
}
