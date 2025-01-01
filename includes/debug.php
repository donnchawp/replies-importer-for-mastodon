<?php
/**
 * Logger trait for Replies Importer for Mastodon
 *
 * @package RepliesImporterForMastodon
 */

trait Replies_Importer_For_Mastodon_Logger {
    /**
     * Log debug messages.
     *
     * @param mixed $message The message to log.
     */
	public function debug_log( $message) {
		if ( Replies_Importer_For_Mastodon_Config::get( 'debug_mode' ) ) {
			error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

}