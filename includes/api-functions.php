<?php
/**
 * API functions for Replies Importer for Mastodon
 *
 * @package RepliesImporterForMastodon
 */

class Replies_Importer_For_Mastodon_API {
	use Replies_Importer_For_Mastodon_Logger;
	private $config;

	/**
	 * Unix time this import run has to finish by, or 0 when no run is in progress.
	 *
	 * @var int
	 */
	private $deadline = 0;

	public function __construct() {
		$this->config = Replies_Importer_For_Mastodon_Config::get_instance();
	}

	public function init() {
		add_action( 'replies_importer_for_mastodon_event', array( $this, 'fetch_and_import_mastodon_comments' ) );
	}

	/**
	 * Get authorization URL.
	 *
	 * @param string $instance_url The Mastodon instance URL.
	 * @return string|false The authorization URL or false on failure.
	 */
	public function get_authorization_url( $instance_url ) {
		if (
			empty( $this->config->get_connection_option( 'client_id' ) ) ||
			empty( $this->config->get_connection_option( 'client_secret' ) )
		) {
			$app = $this->create_app( $instance_url );
			if ( ! $app || isset( $app['error'] ) ) {
				if ( isset( $app['error'] ) && 'Too many requests' === $app['error'] ) {
					add_settings_error(
						'replies_importer_for_mastodon_messages',
						'mastodon_app_creation_error',
						__( 'Error creating Mastodon app: Too many requests. Please try again later.', 'replies-importer-for-mastodon' ),
						'error'
					);
				}
				return false;
			}
			$this->debug_log( 'saving client id and secret ' . $app['client_id'] );
			$this->config->set_connection_option( 'client_id', $app['client_id'] );
			$this->config->set_connection_option( 'client_secret', $app['client_secret'] );
		} else {
			$this->debug_log( "client id and secret already saved: " . $this->config->get_connection_option( 'client_id' ) );
		}

		$redirect_uri = admin_url( 'options-general.php?page=replies_importer_for_mastodon' );
		$auth_url     = $instance_url . '/oauth/authorize?client_id=' . $this->config->get_connection_option( 'client_id' ) . '&redirect_uri=' . urlencode( $redirect_uri ) . '&response_type=code&scope=' . urlencode( 'read' );

		return $auth_url;
	}

	/**
	 * Create a Mastodon app.
	 *
	 * @param string $instance_url The Mastodon instance URL.
	 * @return array|false The app data or false on failure.
	 */
	public function create_app( $instance_url ) {
		$response = wp_remote_post(
			$instance_url . '/api/v1/apps',
			array(
				'body' => array(
					'client_name'   => 'Replies Importer for Mastodon',
					'redirect_uris' => admin_url( 'options-general.php?page=replies_importer_for_mastodon' ),
					'scopes'        => 'read',
					'website'       => get_site_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return $body;
	}

	/**
	 * Get access token from Mastodon.
	 *
	 * @param string $instance_url The Mastodon instance URL.
	 * @param string $code The authorization code.
	 * @return string|false The access token or false on failure.
	 */
	public function get_access_token( $instance_url, $code ) {
		if ( ! $this->config->get_connection_option( 'client_id' ) || ! $this->config->get_connection_option( 'client_secret' ) ) {
			return false;
		}
		$redirect_uri = admin_url( 'options-general.php?page=replies_importer_for_mastodon' );

		$response = wp_remote_post(
			$instance_url . '/oauth/token',
			array(
				'body' => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => $this->config->get_connection_option( 'client_id' ),
					'client_secret' => $this->config->get_connection_option( 'client_secret' ),
					'redirect_uri'  => $redirect_uri,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return $body['access_token'] ?? false;
	}

	/**
	 * Fetch and import Mastodon comments.
	 */
	public function fetch_and_import_mastodon_comments() {
		$this->debug_log( 'fetch_and_import_mastodon_comments' );

		if ( empty( $this->config->get( 'mastodon_instance_url' ) ) || empty( $this->config->get_connection_option( 'access_token' ) ) ) {
			$this->debug_log( 'url or access token is missing' );
			return __( 'Mastodon instance URL or access token is missing.', 'replies-importer-for-mastodon' );
		}

		$website_url = home_url();

		$this->debug_log(
			'instance url: ' . $this->config->get( 'mastodon_instance_url' ) .
			"\naccess token: " . $this->config->get_connection_option( 'access_token' )
		);
		// Fetch the user's Mastodon RSS feed URL
		$user_info = wp_remote_get(
			$this->config->get( 'mastodon_instance_url' ) . '/api/v1/accounts/verify_credentials',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $this->config->get_connection_option( 'access_token' ) ),
			)
		);

		if ( is_wp_error( $user_info ) ) {
			$this->debug_log( 'user info error: ' . $user_info->get_error_message() );
			return __( 'Failed to retrieve user information: ', 'replies-importer-for-mastodon' ) . $user_info->get_error_message();
		}

		$user_data        = json_decode( wp_remote_retrieve_body( $user_info ), true );
		$mastodon_rss_url = $user_data['url'] . '.rss';

		$response = wp_remote_get( $mastodon_rss_url );
		if ( is_wp_error( $response ) ) {
			$this->debug_log( 'fetching rss error: ' . $response->get_error_message() );
			return __( 'Failed to retrieve Mastodon RSS feed: ', 'replies-importer-for-mastodon' ) . $response->get_error_message();
		}

		$rss_body = wp_remote_retrieve_body( $response );
		$rss      = simplexml_load_string( $rss_body );

		$this->start_time_budget();

		$parsed_url   = wp_parse_url( $mastodon_rss_url );
		$base_api_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];

		// Iterate over each item in the RSS feed
		foreach ( $rss->channel->item as $item ) {
			if ( $this->out_of_time() ) {
				$this->debug_log( 'Out of time, stopping before the next Mastodon post' );
				break;
			}

			$content = (string) $item->description;

			// Check if the Mastodon post contains any URL from your website
			if ( false === strpos( $content, $website_url ) ) {
				$this->debug_log( 'No URL found in Mastodon post: ' . $content );
				continue;
			}
			$this->debug_log( 'checking for ' . $website_url );
			preg_match_all( '/href=["\'](' . preg_quote( $website_url, '/' ) . '[^"\']+)["\']/', $content, $matches );
			$urls = array_unique( $matches[1] );
			$this->debug_log( 'URL found in Mastodon post: ' . $content );
			foreach ( $urls as $url ) {
				// Check if the URL is from your website
				if ( 0 !== strpos( $url, $website_url ) ) {
					$this->debug_log( 'URL not from website: ' . $url );
					continue;
				} else {
					$this->debug_log( 'URL from website: ' . $url );
				}
				// Fetch WordPress post ID from URL
				$post_id = url_to_postid( $url );

				// Only proceed if a valid post ID is found
				if ( ! $post_id ) {
					$this->debug_log( 'No post ID found for URL: ' . $url );
					continue;
				}

				// Extract the Mastodon post ID from the link
				$mastodon_status_id = basename( wp_parse_url( (string) $item->link, PHP_URL_PATH ) );
				$api_url            = $base_api_url . '/api/v1/statuses/' . $mastodon_status_id . '/context';

				$api_response = wp_remote_get(
					$api_url,
					array(
						'headers' => array( 'Authorization' => 'Bearer ' . $this->config->get_connection_option( 'access_token' ) ),
					)
				);

				if ( is_wp_error( $api_response ) || 200 !== wp_remote_retrieve_response_code( $api_response ) ) {
					$this->debug_log( 'Failed to fetch Mastodon context: ' . $api_response->get_error_message() );
					continue;
				}

				$replies_data = json_decode( wp_remote_retrieve_body( $api_response ), true );

				// Loop through each reply and add it as a comment
				$comment_map = array();
				foreach ( $replies_data['descendants'] as $reply ) {
					if ( 'private' === $reply['visibility'] || 'direct' === $reply['visibility'] ) {
						continue;
					}

					if ( get_comments( array( 'author_url' => $reply['url'] ) ) ) {
						$this->debug_log( 'Comment already exists: ' . $reply['url'] );
						continue;
					}

					if ( isset( $comment_map[ $reply['in_reply_to_id'] ] ) ) {
						$comment_parent = $comment_map[ $reply['in_reply_to_id'] ];
					} else {
						$comment_parent = 0;
					}
					$this->debug_log( "$mastodon_status_id {$reply['id']} parent: {$reply['in_reply_to_id']} => $comment_parent<br />" );

					$commentdata = array(
						'comment_post_ID'    => $post_id,
						'comment_author'     => wp_kses_post( $reply['account']['display_name'] ),
						'comment_author_url' => $reply['url'],
						'comment_content'    => wp_kses_post( wp_strip_all_tags( $reply['content'] ) ),
						'comment_type'       => '',
						'comment_parent'     => $comment_parent,
						'user_id'            => 0,
						'comment_author_IP'  => '',
						'comment_agent'      => 'Mastodon',
						'comment_date'       => gmdate( 'Y-m-d H:i:s', strtotime( $reply['created_at'] ) ),
						'comment_approved'   => 0,
					);

					// Insert new comment and get the new comment ID
					$comment_id                   = wp_insert_comment( wp_filter_comment( $commentdata ) );
					$comment_map[ $reply['id'] ]  = $comment_id;
				}

				$this->import_reactions( $base_api_url, $mastodon_status_id, $post_id );
			}
		}
	}

	/**
	 * Start the clock on an import run.
	 *
	 * Each Mastodon post now costs several HTTP requests, so a busy account can take
	 * longer than PHP will allow. Being killed part way through loses nothing already
	 * written, but it does mean the run never gets to tidy up or log why it stopped.
	 * Stopping on our own terms is easier to follow in the debug log.
	 */
	private function start_time_budget() {
		$limit = (int) ini_get( 'max_execution_time' );

		// 0 means no limit, which is normal under WP-CLI and some cron setups.
		if ( $limit <= 0 ) {
			$limit = REPLIES_IMPORTER_FOR_MASTODON_MAX_RUN_SECONDS;
		}

		// Leave a fifth of the limit spare so the last request has room to come back.
		$budget = (int) floor( $limit * 0.8 );
		$budget = min( $budget, REPLIES_IMPORTER_FOR_MASTODON_MAX_RUN_SECONDS );
		$budget = max( 5, $budget );

		$this->deadline = time() + $budget;

		$this->debug_log( 'Import has ' . $budget . ' seconds' );
	}

	/**
	 * Whether this import run has used up its time.
	 *
	 * @return bool True if the run should stop.
	 */
	private function out_of_time() {
		return $this->deadline > 0 && time() >= $this->deadline;
	}

	/**
	 * Import likes, reposts and quotes for a single Mastodon status.
	 *
	 * @param string $base_api_url       The Mastodon instance base URL.
	 * @param string $mastodon_status_id The Mastodon status ID.
	 * @param int    $post_id            The WordPress post ID.
	 */
	private function import_reactions( $base_api_url, $mastodon_status_id, $post_id ) {
		$status_url = $base_api_url . '/api/v1/statuses/' . $mastodon_status_id;
		$types      = 'Replies_Importer_For_Mastodon_Comment_Types';

		$endpoints = array(
			$types::LIKE   => '/favourited_by',
			$types::REPOST => '/reblogged_by',
			$types::QUOTE  => '/quotes',
		);

		foreach ( $endpoints as $type => $endpoint ) {
			/*
			 * Nothing displays a type the ActivityPub plugin has switched off, so importing
			 * it would only write rows the site owner has already said they don't want.
			 */
			if ( ! $types::should_import( $type ) ) {
				$this->debug_log( 'Skipping ' . $type . ': disabled in the ActivityPub plugin' );
				continue;
			}

			// Quote posts arrived in Mastodon 4.5; older instances 404 on that endpoint.
			$results = $this->get_paged_results( $base_api_url, $status_url . $endpoint, $types::QUOTE === $type );

			foreach ( $results as $result ) {
				if ( $types::QUOTE === $type ) {
					$this->import_quote( $result, $post_id );
				} else {
					$this->import_account_reaction( $result, $post_id, $type );
				}
			}
		}
	}

	/**
	 * Fetch every page of a Mastodon collection endpoint.
	 *
	 * Follows the `next` link relation in the Link header. Capped at
	 * REPLIES_IMPORTER_FOR_MASTODON_MAX_PAGES pages so one very popular post cannot
	 * stall an import run.
	 *
	 * @param string $url The endpoint URL.
	 * @return array The combined results, empty on any failure.
	 */
	private function get_paged_results( $base_api_url, $url, $allow_404 = false ) {
		$results = array();
		$pages   = 0;

		while ( $url && $pages < REPLIES_IMPORTER_FOR_MASTODON_MAX_PAGES ) {
			if ( $this->out_of_time() ) {
				$this->debug_log( 'Out of time, stopping after page ' . $pages . ' of ' . $url );
				break;
			}

			++$pages;

			$response = wp_safe_remote_get(
				$url,
				array(
					'headers' => array( 'Authorization' => 'Bearer ' . $this->config->get_connection_option( 'access_token' ) ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$this->debug_log( 'Failed to fetch ' . $url . ': ' . $response->get_error_message() );
				break;
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( 404 === $code && $allow_404 ) {
				$this->debug_log( 'Endpoint not available on this instance: ' . $url );
				break;
			}

			if ( 200 !== $code ) {
				$this->debug_log( 'Unexpected response ' . $code . ' from ' . $url );
				break;
			}

			$page = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $page ) || empty( $page ) ) {
				break;
			}

			$results = array_merge( $results, $page );
			$url     = $this->get_next_page_url( $response, $base_api_url );
		}

		return $results;
	}

	/**
	 * Extract the `next` URL from a response's Link header.
	 *
	 * The Link header comes from the remote instance, and the URL in it is fetched with
	 * our access token attached, so it is only followed when it stays on the instance we
	 * were already talking to. Without that check a hostile instance could walk us onto
	 * localhost, a cloud metadata endpoint, or anything else the server can reach.
	 *
	 * @param array|WP_Error $response     The response from wp_safe_remote_get().
	 * @param string         $base_api_url The instance we are talking to.
	 * @return string The next page URL, or an empty string if there is none.
	 */
	private function get_next_page_url( $response, $base_api_url ) {
		$link = wp_remote_retrieve_header( $response, 'link' );

		if ( is_array( $link ) ) {
			$link = implode( ', ', $link );
		}

		if ( empty( $link ) || ! preg_match( '/<([^>]+)>;\s*rel="next"/', $link, $matches ) ) {
			return '';
		}

		$next = $matches[1];
		$base = wp_parse_url( $base_api_url );
		$to   = wp_parse_url( $next );

		if ( empty( $to['host'] ) || empty( $base['host'] ) ) {
			return '';
		}

		if ( strtolower( $to['host'] ) !== strtolower( $base['host'] ) ) {
			$this->debug_log( 'Refusing to follow off-instance next link: ' . $next );
			return '';
		}

		$scheme = isset( $to['scheme'] ) ? strtolower( $to['scheme'] ) : '';
		if ( ! isset( $base['scheme'] ) || $scheme !== strtolower( $base['scheme'] ) ) {
			$this->debug_log( 'Refusing to follow next link with a different scheme: ' . $next );
			return '';
		}

		return $next;
	}

	/**
	 * Record a like or repost from a Mastodon account.
	 *
	 * @param array  $account The Mastodon account.
	 * @param int    $post_id The WordPress post ID.
	 * @param string $type    The comment type slug.
	 */
	private function import_account_reaction( $account, $post_id, $type ) {
		if ( empty( $account['url'] ) ) {
			return;
		}

		$reaction = Replies_Importer_For_Mastodon_Comment_Types::get_type( $type );

		/*
		 * The favourited_by and reblogged_by endpoints return accounts, not statuses, so
		 * there is no timestamp for the reaction itself and the import time is the best
		 * available date.
		 */
		$this->insert_reaction_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => $this->get_account_name( $account ),
				'comment_author_url'   => $account['url'],
				'comment_author_email' => $this->get_account_email( $account ),
				'comment_content'      => $reaction['excerpt'],
				'comment_type'         => $type,
				'comment_date'         => current_time( 'mysql' ),
				'comment_date_gmt'     => current_time( 'mysql', 1 ),
			),
			$account
		);
	}

	/**
	 * Record a quote of one of our posts.
	 *
	 * @param array $quote   The Mastodon status quoting our post.
	 * @param int   $post_id The WordPress post ID.
	 */
	private function import_quote( $quote, $post_id ) {
		if ( empty( $quote['url'] ) || empty( $quote['account'] ) ) {
			return;
		}

		if ( isset( $quote['visibility'] ) && in_array( $quote['visibility'], array( 'private', 'direct' ), true ) ) {
			return;
		}

		$author  = $this->get_account_name( $quote['account'] );
		$content = $this->clean_remote_content( isset( $quote['content'] ) ? $quote['content'] : '' );

		/*
		 * wp_insert_comment() skips wp_allow_comment(), so the site's Disallowed Comment
		 * Keys never get a look at this. Replies and quotes are the only reactions
		 * carrying text a stranger wrote, so check them here.
		 */
		$email = $this->get_account_email( $quote['account'] );

		if ( wp_check_comment_disallowed_list( $author, $email, $quote['url'], $content, '', 'Mastodon' ) ) {
			$this->debug_log( 'Quote matched the disallowed list, skipping: ' . $quote['url'] );
			return;
		}

		$timestamp = isset( $quote['created_at'] ) ? strtotime( $quote['created_at'] ) : false;
		$gm_date   = $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : current_time( 'mysql', 1 );

		$this->insert_reaction_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => $author,
				'comment_author_url'   => $quote['url'],
				'comment_author_email' => $email,
				'comment_content'      => $content,
				'comment_type'         => Replies_Importer_For_Mastodon_Comment_Types::QUOTE,
				'comment_date'         => get_date_from_gmt( $gm_date ),
				'comment_date_gmt'     => $gm_date,
			),
			$quote['account']
		);
	}

	/**
	 * Turn a Mastodon HTML status body into plain comment text.
	 *
	 * Mastodon sends HTML, so stripping the tags on its own leaves the entities behind
	 * and readers see `&amp;` and `&#39;` in the text.
	 *
	 * @param string $content The status content.
	 * @return string The plain text.
	 */
	private function clean_remote_content( $content ) {
		$content = wp_strip_all_tags( $content );
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );

		return sanitize_textarea_field( $content );
	}

	/**
	 * Insert a reaction comment, skipping duplicates.
	 *
	 * @param array $commentdata The comment data.
	 * @param array $account     The Mastodon account behind the reaction.
	 */
	private function insert_reaction_comment( $commentdata, $account ) {
		$existing = get_comments(
			array(
				'post_id'    => $commentdata['comment_post_ID'],
				'type'       => $commentdata['comment_type'],
				'author_url' => $commentdata['comment_author_url'],
				'status'     => 'any',
				'count'      => true,
			)
		);

		if ( $existing ) {
			$this->debug_log( $commentdata['comment_type'] . ' already recorded: ' . $commentdata['comment_author_url'] );
			return;
		}

		$commentdata['comment_parent']    = 0;
		$commentdata['user_id']           = 0;
		$commentdata['comment_author_IP'] = '';
		$commentdata['comment_agent']     = 'Mastodon';
		$commentdata['comment_approved']  = $this->is_known_account( $account ) ? 1 : 0;

		$comment_id = wp_insert_comment( wp_filter_comment( $commentdata ) );

		if ( ! $comment_id ) {
			$this->debug_log( 'Failed to insert ' . $commentdata['comment_type'] . ' for ' . $commentdata['comment_author_url'] );
			return;
		}

		if ( ! empty( $account['avatar_static'] ) ) {
			add_comment_meta( $comment_id, 'avatar_url', esc_url_raw( $account['avatar_static'] ) );
		}

		if ( ! empty( $account['url'] ) ) {
			add_comment_meta( $comment_id, 'mastodon_account_url', esc_url_raw( $account['url'] ) );
		}
	}

	/**
	 * Whether an account already has an approved like or repost on this site.
	 *
	 * Approving a reaction from an account whose earlier reaction was approved saves
	 * moderating the same person over and over.
	 *
	 * @param array $account The Mastodon account.
	 * @return bool True if the account has an approved like or repost.
	 */
	private function is_known_account( $account ) {
		/*
		 * Matched on comment_author_email, which is indexed, rather than on the
		 * mastodon_account_url meta: meta_value has no usable index, and this runs once
		 * per imported reaction. An account with no usable identifier is never known,
		 * otherwise an empty email would match every anonymous comment on the site.
		 */
		$email = $this->get_account_email( $account );

		if ( empty( $email ) ) {
			return false;
		}

		return (bool) get_comments(
			array(
				'author_email' => $email,
				'type__in'     => array(
					Replies_Importer_For_Mastodon_Comment_Types::LIKE,
					Replies_Importer_For_Mastodon_Comment_Types::REPOST,
				),
				'status'       => 'approve',
				'count'        => true,
			)
		);
	}

	/**
	 * Get a display name for a Mastodon account.
	 *
	 * @param array $account The Mastodon account.
	 * @return string The display name.
	 */
	private function get_account_name( $account ) {
		if ( ! empty( $account['display_name'] ) ) {
			return sanitize_text_field( $account['display_name'] );
		}

		if ( ! empty( $account['acct'] ) ) {
			return sanitize_text_field( $account['acct'] );
		}

		// Better an instance host in the avatar's alt text than nothing at all.
		$host = wp_parse_url( isset( $account['url'] ) ? $account['url'] : '', PHP_URL_HOST );

		return $host ? sanitize_text_field( $host ) : __( 'Someone on Mastodon', 'replies-importer-for-mastodon' );
	}

	/**
	 * Get an email-shaped identifier for a Mastodon account.
	 *
	 * Mastodon's `acct` is bare for local accounts, so the instance host is appended to
	 * give every account a stable user@host identifier.
	 *
	 * @param array $account The Mastodon account.
	 * @return string The identifier, or an empty string if it cannot be built.
	 */
	private function get_account_email( $account ) {
		if ( empty( $account['acct'] ) ) {
			return '';
		}

		$acct = $account['acct'];

		if ( false === strpos( $acct, '@' ) ) {
			$host = wp_parse_url( $account['url'] ?? '', PHP_URL_HOST );

			if ( ! $host ) {
				return '';
			}

			$acct .= '@' . $host;
		}

		return is_email( $acct ) ? $acct : '';
	}

	/**
	 * Disconnect from Mastodon.
	 */
	public function disconnect() {
		if ( empty( $this->config->get( 'mastodon_instance_url' ) ) || empty( $this->config->get_connection_option( 'access_token' ) ) ) {
			return esc_html__( 'Mastodon instance URL or access token is missing.', 'replies-importer-for-mastodon' );
		}

		$api_url  = $this->config->get( 'mastodon_instance_url' ) . '/api/v1/accounts/verify_credentials';
		$response = wp_remote_get(
			$api_url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $this->config->get_connection_option( 'access_token' ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			$this->debug_log( 'error: ' . $response->get_error_message() );
			return esc_html__( 'Failed to retrieve user information: ', 'replies-importer-for-mastodon' ) . $response->get_error_message();
		}
		$revoke_url      = $this->config->get( 'mastodon_instance_url' ) . '/oauth/revoke';
		$revoke_response = wp_remote_post(
			$revoke_url,
			array(
				'body' => array(
					'client_id'     => $this->config->get_connection_option( 'client_id' ),
					'client_secret' => $this->config->get_connection_option( 'client_secret' ),
					'access_token'  => $this->config->get_connection_option( 'access_token' ),
				),
			)
		);
		if ( is_wp_error( $revoke_response ) ) {
			$this->debug_log( 'error: ' . $revoke_response->get_error_message() );
			return esc_html__( 'Failed to revoke access token: ', 'replies-importer-for-mastodon' ) . $revoke_response->get_error_message();
		}
		$this->config->delete_connection();
	}
}
