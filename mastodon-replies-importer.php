<?php
/**
 * Plugin Name: Replies Importer for Mastodon
 * Plugin URI: https://odd.blog/replies-importer-for-mastodon/
 * Description: Imports replies from Mastodon as comments on WordPress posts.
 * Version: 0.0.1
 * Author: Donncha Ó Caoimh
 * Author URI: https://odd.blog/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: replies-importer-for-mastodon
 *
 * @package RepliesImporterForMastodon
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define plugin constants.
define( 'REPLIES_IMPORTER_FOR_MASTODON_VERSION', '1.0.0' );
define( 'REPLIES_IMPORTER_FOR_MASTODON_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'REPLIES_IMPORTER_FOR_MASTODON_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include necessary files.
require_once REPLIES_IMPORTER_FOR_MASTODON_PLUGIN_DIR . 'includes/debug.php';
require_once REPLIES_IMPORTER_FOR_MASTODON_PLUGIN_DIR . 'includes/config.php';
require_once REPLIES_IMPORTER_FOR_MASTODON_PLUGIN_DIR . 'includes/admin-functions.php';
require_once REPLIES_IMPORTER_FOR_MASTODON_PLUGIN_DIR . 'includes/api-functions.php';

// Initialize the plugin.
function replies_importer_for_mastodon_init() {
	$admin_functions = new Replies_Importer_For_Mastodon_Admin();
	$admin_functions->init();
}
add_action( 'plugins_loaded', 'replies_importer_for_mastodon_init' );
