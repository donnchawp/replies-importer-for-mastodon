<?php
/**
 * Comment types for Replies Importer for Mastodon
 *
 * Mastodon likes, reposts and quotes are stored as WordPress comments using the
 * same `like`, `repost` and `quote` slugs the ActivityPub plugin uses. When that
 * plugin is active it already registers those types, renders them and keeps them
 * out of comment queries and counts, so this class does no more than top up the
 * exclusion list its comment count filter builds. Without it, this class provides
 * the same support on its own.
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
	 * Whether the ActivityPub plugin is active.
	 *
	 * @return bool True if the ActivityPub plugin is active.
	 */
	public static function has_activitypub() {
		return function_exists( 'Activitypub\register_comment_type' );
	}

	/**
	 * Whether the ActivityPub plugin is handling a comment type.
	 *
	 * Being installed is not the same as handling the type. Likes and reposts are
	 * checkboxes in the ActivityPub settings, and it drops a type it has switched off
	 * from the exclusion list its comment count filter builds. So a site running
	 * ActivityPub with likes turned off would count every imported like towards the
	 * post's comment count.
	 *
	 * @param string $type The comment type slug.
	 * @return bool True if the ActivityPub plugin is handling this type.
	 */
	public static function is_handled_by_activitypub( $type ) {
		if ( ! self::has_activitypub() ) {
			return false;
		}

		// is_comment_type_enabled() arrived with the settings that gate these types.
		if ( ! method_exists( 'Activitypub\Comment', 'is_comment_type_enabled' ) ) {
			return true;
		}

		return (bool) Activitypub\Comment::is_comment_type_enabled( $type );
	}

	/**
	 * Whether a reaction of this type is worth importing.
	 *
	 * A site owner who unticks likes in the ActivityPub settings has said they don't
	 * want likes recorded, so there is no point importing them from Mastodon either.
	 *
	 * @param string $type The comment type slug.
	 * @return bool True if reactions of this type should be imported.
	 */
	public static function should_import( $type ) {
		if ( ! self::has_activitypub() ) {
			return true;
		}

		return self::is_handled_by_activitypub( $type );
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
				'count'        => _nx_noop( '%s like', '%s likes', 'number of likes', 'replies-importer-for-mastodon' ),
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
				'count'        => _nx_noop( '%s repost', '%s reposts', 'number of reposts', 'replies-importer-for-mastodon' ),
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
				'count'        => _nx_noop( '%s quote', '%s quotes', 'number of quotes', 'replies-importer-for-mastodon' ),
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
			/*
			 * ActivityPub builds its comment count exclusion list from the types it has
			 * switched on, so a type the site owner disabled there stops being excluded
			 * and starts counting. Adding our slugs back through its own extension point
			 * keeps the count right without hooking the filter ourselves, which would
			 * fight with its handler. Added in ActivityPub 8.0.
			 */
			add_filter( 'activitypub_excluded_comment_types', array( __CLASS__, 'exclude_from_activitypub_count' ) );
			return;
		}

		add_action( 'pre_get_comments', array( __CLASS__, 'exclude_from_comment_query' ) );
		add_filter( 'pre_wp_update_comment_count_now', array( __CLASS__, 'exclude_from_comment_count' ), 5, 3 );
		add_filter( 'get_avatar_comment_types', array( __CLASS__, 'get_avatar_comment_types' ), 99 );
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'pre_get_avatar_data' ), 11, 2 );
		add_filter( 'the_content', array( __CLASS__, 'append_reactions' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
	}

	/**
	 * Register the facepile styles.
	 *
	 * Registered rather than enqueued so the stylesheet only loads on posts that
	 * actually have reactions to show.
	 */
	public static function enqueue_styles() {
		wp_register_style(
			'replies-importer-for-mastodon',
			REPLIES_IMPORTER_FOR_MASTODON_PLUGIN_URL . 'assets/reactions.css',
			array(),
			REPLIES_IMPORTER_FOR_MASTODON_VERSION
		);
	}

	/**
	 * Append the reactions facepile to the post content.
	 *
	 * Hooking `the_content` puts the facepile between the post and the comments in
	 * both classic and block themes, without replacing the comments template.
	 *
	 * @param string $content The post content.
	 * @return string The content, with the facepile appended where there is one.
	 */
	public static function append_reactions( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		// the_content also runs for single post and comment feeds.
		if ( is_feed() ) {
			return $content;
		}

		// Don't disclose who reacted to a post the reader hasn't unlocked.
		if ( post_password_required() ) {
			return $content;
		}

		return $content . self::render_reactions( get_the_ID() );
	}

	/**
	 * Render the reactions facepile for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return string The markup, or an empty string if the post has no reactions.
	 */
	public static function render_reactions( $post_id ) {
		/*
		 * One cheap COUNT to get out of the way of the great majority of posts, which
		 * have no reactions at all and would otherwise pay for a query per type on every
		 * single view.
		 */
		$total = get_comments(
			array(
				'post_id'  => $post_id,
				'type__in' => self::get_slugs(),
				'status'   => 'approve',
				'count'    => true,
			)
		);

		if ( ! $total ) {
			return '';
		}

		/**
		 * Filters how many avatars to render per reaction type.
		 *
		 * The count in the heading is always the real total; this only caps how many
		 * faces are drawn, so a post with thousands of likes stays a sane page.
		 *
		 * @param int $limit   Maximum avatars per type.
		 * @param int $post_id The post ID.
		 */
		$limit = (int) apply_filters( 'replies_importer_for_mastodon_max_faces', 50, $post_id );

		$sections = '';

		foreach ( self::get_types() as $slug => $type ) {
			$count = (int) get_comments(
				array(
					'post_id' => $post_id,
					'type'    => $slug,
					'status'  => 'approve',
					'count'   => true,
				)
			);

			if ( ! $count ) {
				continue;
			}

			$comments = get_comments(
				array(
					'post_id' => $post_id,
					'type'    => $slug,
					'status'  => 'approve',
					'number'  => $limit,
				)
			);

			if ( empty( $comments ) ) {
				continue;
			}

			$label = sprintf(
				translate_nooped_plural( $type['count'], $count, 'replies-importer-for-mastodon' ),
				number_format_i18n( $count )
			);

			$sections .= sprintf(
				'<div class="rifm-reactions__group rifm-reactions__group--%1$s"><h3 class="rifm-reactions__label"><span class="rifm-reactions__icon" aria-hidden="true">%2$s</span> %3$s</h3>%4$s</div>',
				esc_attr( $slug ),
				wp_kses_post( $type['icon'] ),
				esc_html( $label ),
				self::QUOTE === $slug ? self::render_quotes( $comments ) : self::render_facepile( $comments )
			);
		}

		if ( '' === $sections ) {
			return '';
		}

		wp_enqueue_style( 'replies-importer-for-mastodon' );

		return sprintf(
			'<div class="rifm-reactions"><h2 class="screen-reader-text">%s</h2>%s</div>',
			esc_html__( 'Reactions from Mastodon', 'replies-importer-for-mastodon' ),
			$sections
		);
	}

	/**
	 * Render a row of avatars linking to the reacting accounts.
	 *
	 * @param WP_Comment[] $comments The reaction comments.
	 * @return string The markup.
	 */
	private static function render_facepile( $comments ) {
		$items = '';

		foreach ( $comments as $comment ) {
			$avatar = get_avatar( $comment, 40, '', $comment->comment_author );

			if ( ! $avatar ) {
				continue;
			}

			$items .= sprintf(
				'<li class="rifm-reactions__face"><a href="%1$s" title="%2$s" rel="external nofollow ugc">%3$s</a></li>',
				esc_url( $comment->comment_author_url ),
				esc_attr( $comment->comment_author ),
				$avatar
			);
		}

		return '<ul class="rifm-reactions__faces">' . $items . '</ul>';
	}

	/**
	 * Render quotes as short excerpt cards.
	 *
	 * @param WP_Comment[] $comments The quote comments.
	 * @return string The markup.
	 */
	private static function render_quotes( $comments ) {
		$items = '';

		foreach ( $comments as $comment ) {
			$items .= sprintf(
				'<li class="rifm-reactions__quote">%1$s<div class="rifm-reactions__quote-body"><a class="rifm-reactions__quote-author" href="%2$s" rel="external nofollow ugc">%3$s</a><p class="rifm-reactions__quote-text">%4$s</p></div></li>',
				get_avatar( $comment, 40, '', $comment->comment_author ),
				esc_url( $comment->comment_author_url ),
				esc_html( $comment->comment_author ),
				esc_html( wp_trim_words( $comment->comment_content, 30 ) )
			);
		}

		return '<ul class="rifm-reactions__quotes">' . $items . '</ul>';
	}

	/**
	 * Add our comment types to ActivityPub's comment count exclusion list.
	 *
	 * @param string[] $types The comment type slugs ActivityPub is excluding.
	 * @return string[] The list, with ours added.
	 */
	public static function exclude_from_activitypub_count( $types ) {
		return array_unique( array_merge( (array) $types, self::get_slugs() ) );
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

		// wp_is_serving_rest_request() is WordPress 6.5 and later; the plugin supports 5.0.
		if ( function_exists( 'wp_is_serving_rest_request' ) ) {
			$is_rest = wp_is_serving_rest_request();
		} else {
			$is_rest = defined( 'REST_REQUEST' ) && REST_REQUEST;
		}

		// The admin comments screen should still show them, so they can be moderated.
		if ( is_admin() || $is_rest ) {
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
		$args['url'] = apply_filters( 'get_avatar_url', $avatar, $id_or_email, $args );

		/*
		 * get_avatar() adds avatar, avatar-{size} and photo itself, and adds
		 * avatar-default unless found_avatar says a real one turned up. Without this the
		 * Mastodon avatars all render as avatar-default and themes style them as missing.
		 */
		$args['found_avatar'] = true;
		$args['class'][]      = 'avatar-mastodon';
		$args['class']        = array_unique( $args['class'] );

		return $args;
	}
}
