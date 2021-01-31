<?php
/**
 * Plugin Name: Newspack External Image Downloader
 * Description: Downloads all the images in Posts which are hosted externally, or imports them directly from local files.
 * Plugin URI:  https://newspack.pub/
 * Author:      Automattic
 * Author URI:  https://newspack.pub/
 * Version:     0.1.0
 *
 * @package  Newspack_Post_Image_Downloader
 */

namespace NewspackPostImageDownloader;

require __DIR__ . '/vendor/autoload.php';

// Don't do anything outside WP CLI.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once ABSPATH . 'wp-settings.php';

( new Downloader() )->register_commands();
