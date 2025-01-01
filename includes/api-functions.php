<?php
/**
 * API functions for Replies Importer for Mastodon
 *
 * @package RepliesImporterForMastodon
 */

class Replies_Importer_For_Mastodon_API {
	use Replies_Importer_For_Mastodon_Logger;
	private $config;

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
						__( 'Error creating Mastodon app: Too many requests. Please try again later.', 'replies_importer_for_mastodon' ),
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
					'client_name'   => 'Mastodon Replies Importer',
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
			return __( 'Mastodon instance URL or access token is missing.', 'replies_importer_for_mastodon' );
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
			return __( 'Failed to retrieve user information: ', 'replies_importer_for_mastodon' ) . $user_info->get_error_message();
		}

		$user_data        = json_decode( wp_remote_retrieve_body( $user_info ), true );
		$mastodon_rss_url = $user_data['url'] . '.rss';

		$response = wp_remote_get( $mastodon_rss_url );
		if ( is_wp_error( $response ) ) {
			$this->debug_log( 'fetching rss error: ' . $response->get_error_message() );
			return __( 'Failed to retrieve Mastodon RSS feed: ', 'replies_importer_for_mastodon' ) . $response->get_error_message();
		}

		$rss_body = wp_remote_retrieve_body( $response );
		$rss      = simplexml_load_string( $rss_body );

		$parsed_url   = wp_parse_url( $mastodon_rss_url );
		$base_api_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];

		// Iterate over each item in the RSS feed
		foreach ( $rss->channel->item as $item ) {
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
						'comment_author'     => $reply['account']['display_name'],
						'comment_author_url' => $reply['url'],
						'comment_content'    => wp_strip_all_tags( $reply['content'] ),
						'comment_type'       => '',
						'comment_parent'     => $comment_parent,
						'user_id'            => 0,
						'comment_author_IP'  => '',
						'comment_agent'      => 'Mastodon',
						'comment_date'       => gmdate( 'Y-m-d H:i:s', strtotime( $reply['created_at'] ) ),
						'comment_approved'   => 0,
					);

					// Insert new comment and get the new comment ID
					$comment_id                   = wp_insert_comment( $commentdata );
					$comment_map[ $reply['id'] ]  = $comment_id;
				}
			}
		}
	}

	/**
	 * Disconnect from Mastodon.
	 */
	public function disconnect() {
		if ( empty( $this->config->get( 'mastodon_instance_url' ) ) || empty( $this->config->get_connection_option( 'access_token' ) ) ) {
			return esc_html__( 'Mastodon instance URL or access token is missing.', 'replies_importer_for_mastodon' );
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
			return esc_html__( 'Failed to retrieve user information: ', 'replies_importer_for_mastodon' ) . $response->get_error_message();
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
			return esc_html__( 'Failed to revoke access token: ', 'replies_importer_for_mastodon' ) . $revoke_response->get_error_message();
		}
		$this->config->delete_connection();
	}
}