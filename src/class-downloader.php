<?php
/**
 * Main plugin class.
 *
 * @package Newspack_Post_Image_Downloader
 */

namespace NewspackPostImageDownloader;

use WP_CLI;
use Symfony\Component\DomCrawler\Crawler;
use RuntimeException;

/**
 * Image Downloader CLI commands and logic.
 *
 * @package NewspackPostImageDownloader
 */
class Downloader {

	/**
	 * Log file names. Split by error types for easier debugging.
	 */
	const LOG_FILES_EXTENSION                = '.log';
	const LOG_FILE_DOWNLOAD                  = 'imagedownloader__download.log';
	const LOG_FILE_ERR_DOWNLOAD_FAILED       = 'imagedownloader__err_download.log';
	const LOG_FILE_ERR_IMPORT_FAILED         = 'imagedownloader__err_import.log';
	const LOG_FILE_ERR_DOWNLOADING_REFERENCE = 'imagedownloader__err_downloading_reference.log';
	const LOG_FILE_ERR_OTHER                 = 'imagedownloader__err_other.log';

	/**
	 * Custom codes for local runtime exception handling.
	 */
	const EXCEPTION_CODE_NO_DEFAULT_HOST_PROVIDED = 100;
	const EXCEPTION_CODE_DOWNLOAD_FAILED          = 101;
	const EXCEPTION_CODE_IMPORT_FAILED            = 102;

	/**
	 * Registers CLI commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-post-image-downloader scan-existing-images-hostnames',
			array( $this, 'cmd_scan_existing_images_hostnames' ),
			array(
				'shortdesc' => 'Helper command. Goes through all the Posts and Pages, and searches for all existing images\' hostnames (useful to ascertain a list of hostnames to exclude from downloading).',
				'synopsis'  => array(
					array(
						'type'        => 'flag',
						'name'        => 'list-all-post-ids',
						'description' => 'Besides listing the results with all the images `src` hostnames found in your Posts, also list all the Post IDs where these were found.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'post-types',
						'description' => 'Optional CSV Post types. Defaults are `post,page`',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'post-statuses',
						'description' => 'Optional CSV Post statuses. Defaults is `publish`',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'post-ids-csv',
						'description' => 'Specify Posts to scan with a CSV list of Post IDs.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'post-id-from',
						'description' => 'Specify Post IDs to scan with a from-to range.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'post-id-to',
						'description' => 'Specify Post IDs to scan with a from-to range.',
						'optional'    => true,
						'repeating'   => false,
					),
				),

			)
		);
		WP_CLI::add_command(
			'newspack-post-image-downloader import-images',
			array( $this, 'cmd_import_images' ),
			array(
				'shortdesc' => 'Downloads all remote images to local.',
				'synopsis'  => array(
					array(
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Perform a dry run, making no changes.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'default-image-host-and-schema',
						'description' => 'Used for relative URLs, provide th full schema and hostname where to download these from, e.g. `https://defaulthost.com`.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'exclude-hosts',
						'description' => 'CSV, list of hosts to exclude downloading from. Can use a wildcard, e.g. to cover a host and all its subdomains, use these two values `google.com,*.google.com`, or for multiple domain extensions use `www.google.*`, or can even use `*.google.*` for all subdomains and all domain extensions.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'only-download-from-hosts',
						'description' => 'CSV, list of specific hosts to download images from. If provided, it will skip downloading from any other host, and if this param is provided, the `exclude-param` will not work. Can use a wildcard, e.g. to cover a host and all its subdomains, use these two values `somehost.com,*.somehost.com`, or for multiple domain extensions use `www.somehost.*`, or can even use `*.somehost.*` for all subdomains and all domain extensions.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'folder-local-images',
						'description' => 'Local folder which contains the image files. Images which are found here, get imported from local files, otherwise they get downloaded via HTTP.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'post-types',
						'description' => 'Optional CSV Post types. Defaults are `post,page`',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'post-statuses',
						'description' => 'Optional CSV Post statuses. Defaults is `publish`',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'post-ids-csv',
						'description' => 'CSV list of Post IDs.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'post-id-from',
						'description' => 'Only scan Post IDs from-to.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'post-id-to',
						'description' => 'Only scan Post IDs from-to.',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			)
		);
	}

	/**
	 * Callable for `newspack-post-image-downloader scan-existing-images-hostnames`.
	 * See command description in \NewspackPostImageDownloader\Downloader::register_commands.
	 *
	 * @param array $args        CLI arguments.
	 * @param array $assoc_args  CLI associative arguments.
	 */
	public function cmd_scan_existing_images_hostnames( $args, $assoc_args ) {
		$list_all_post_ids = isset( $assoc_args['list-all-post-ids'] );
		$post_types        = isset( $assoc_args['post-types'] ) ? explode( ',', $assoc_args['post-types'] ) : array( 'post', 'page' );
		$post_statuses     = isset( $assoc_args['post-statuses'] ) ? explode( ',', $assoc_args['post-statuses'] ) : array( 'publish' );
		$post_ids_specific = isset( $assoc_args['post-ids-csv'] ) ? explode( ',', $assoc_args['post-ids-csv'] ) : null;
		$post_id_from      = isset( $assoc_args['post-id-from'] ) ? (int) $assoc_args['post-id-from'] : null;
		$post_id_to        = isset( $assoc_args['post-id-to'] ) ? (int) $assoc_args['post-id-to'] : null;

		if ( ( $post_ids_specific && $post_id_from ) || ( $post_ids_specific && $post_id_to ) ) {
			WP_CLI::error( '❗ Sorry, you can either specify a CSV list of Post IDs, or a range of Post IDs.' );
		}
		if ( ( $post_id_from && ( null === $post_id_to ) ) || ( ( null === $post_id_from ) && $post_id_to ) ) {
			WP_CLI::error( '❗ Both post ID ranges are required.' );
		}

		$time_start = microtime( true );
		$posts      = $this->get_posts_ids_and_contents( $post_ids_specific, $post_id_from, $post_id_to, $post_types, $post_statuses );
		if ( empty( $posts ) ) {
			WP_CLI::warning( 'No Posts found... 🤔' );
			exit;
		}

		WP_CLI::line( sprintf( 'Checking image hosts in %d posts...', count( $posts ) ) );
		$img_hostnames = array();
		foreach ( $posts as $i => $post ) {
			$img_srcs = $this->get_all_img_srcs( $post->post_content );
			if ( empty( $img_srcs ) ) {
				continue;
			}
			foreach ( $img_srcs as $img_src ) {
				$parsed = wp_parse_url( $img_src );
				if ( false === $parsed ) {
					continue;
				} else if ( isset( $parsed['host'] ) ) {
					if ( in_array( $post->ID, $img_hostnames[ $parsed['host'] ] ) ) {
						continue;
					}
					$img_hostnames[ $parsed['host'] ][] = $post->ID;
				} else {
					if ( in_array( $post->ID, $img_hostnames[ "relative URL paths" ] ) ) {
						continue;
					}
					// There could be different types of `src` e.g. `src="data:image/svg+xml;base64"`, so this won't be perfect.
					$img_hostnames[ "relative URL paths" ][] = $post->ID;
				}
			}
		}

		// Tada!
		WP_CLI::success( sprintf( '👉 Found %d total image hosts%s', count( $img_hostnames ), ( count( $img_hostnames ) > 0 ? ':' : '.' ) ) );
		if ( count( $img_hostnames ) ) {
			foreach ( $img_hostnames as $img_hostname => $post_ids ) {
				WP_CLI::line(
					sprintf(
						'- %s%s',
						$img_hostname,
						$list_all_post_ids ? ' -- in IDs: ' . implode( ',', $post_ids ) : ''
					)
				);
			}
		}

		WP_CLI::line( sprintf( 'Done in %d mins! 🙌 ', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for `newspack-post-image-downloader import-images`.
	 * See command description in \NewspackPostImageDownloader\Downloader::register_commands.
	 *
	 * @param array $args       CLI arguments.
	 * @param array $assoc_args CLI associative arguments.
	 */
	public function cmd_import_images( $args, $assoc_args ) {
		$dry_run                       = isset( $assoc_args['dry-run'] ) ? true : false;
		$post_types                    = isset( $assoc_args['post-types'] ) ? explode( ',', $assoc_args['post-types'] ) : array( 'post', 'page' );
		$post_statuses                 = isset( $assoc_args['post-statuses'] ) ? explode( ',', $assoc_args['post-statuses'] ) : array( 'publish' );
		$post_ids_specific             = isset( $assoc_args['post-ids-csv'] ) ? explode( ',', $assoc_args['post-ids-csv'] ) : null;
		$post_id_from                  = isset( $assoc_args['post-id-from'] ) ? (int) $assoc_args['post-id-from'] : null;
		$post_id_to                    = isset( $assoc_args['post-id-to'] ) ? (int) $assoc_args['post-id-to'] : null;
		$hosts_excluded                = isset( $assoc_args['exclude-hosts'] ) ? explode( ',', $assoc_args['exclude-hosts'] ) : null;
		$only_download_from_hosts      = isset( $assoc_args['only-download-from-hosts'] ) ? explode( ',', $assoc_args['only-download-from-hosts'] ) : null;
		$default_image_host_and_schema = isset( $assoc_args['default-image-host-and-schema'] ) ? rtrim( $assoc_args['default-image-host-and-schema'], '/' ) : null;
		$folder_local_images           = isset( $assoc_args['folder-local-images'] ) ? rtrim( $assoc_args['folder-local-images'], '/' ) : null;

		if ( ( $post_ids_specific && $post_id_from ) || ( $post_ids_specific && $post_id_to ) ) {
			WP_CLI::error( '❗ Sorry, you can either specify a CSV list of Post IDs, or a range of Post IDs.' );
		}
		if ( ( $post_id_from && ( null === $post_id_to ) ) || ( ( null === $post_id_from ) && $post_id_to ) ) {
			WP_CLI::error( '❗ Both `--post-id-from` and `--post-id-to` ranges are required.' );
		}
		if ( $only_download_from_hosts && $hosts_excluded ) {
			WP_CLI::error( '❗ When providing the `--only-download-from-hosts` param, do not use the `--exclude-hosts` at the same time.' );
		}

		global $wpdb;
		$time_start     = microtime( true );
		$hosts_excluded = $this->get_all_excluded_hosts( $hosts_excluded );

		// Flush the log files.
		$logs = array(
			$this->get_log_name( self::LOG_FILE_DOWNLOAD, $post_id_from, $post_id_to ),
			$this->get_log_name( self::LOG_FILE_ERR_DOWNLOAD_FAILED, $post_id_from, $post_id_to ),
			$this->get_log_name( self::LOG_FILE_ERR_IMPORT_FAILED, $post_id_from, $post_id_to ),
			$this->get_log_name( self::LOG_FILE_ERR_DOWNLOADING_REFERENCE, $post_id_from, $post_id_to ),
			$this->get_log_name( self::LOG_FILE_ERR_OTHER, $post_id_from, $post_id_to ),
		);
		foreach ( $logs as $log ) {
			if ( file_exists( $log ) ) {
				// phpcs:ignore
				unlink( $log );
			}
		}

		WP_CLI::line( 'Fetching Posts...' );
		$posts = $this->get_posts_ids_and_contents( $post_ids_specific, $post_id_from, $post_id_to, $post_types, $post_statuses );
		if ( empty( $posts ) ) {
			WP_CLI::warning( 'No Posts found... 🤔' );
			exit;
		}

		foreach ( $posts as $i => $post ) {
			// Extract attributes from all the `<img>`s.
			$img_data = ( new Crawler( $post->post_content ) )->filterXpath( '//img' )->extract( array( 'src', 'title', 'alt' ) );

			WP_CLI::line( sprintf( '👉 (%d/%d) ID %d, found %d images...', $i + 1, count( $posts ), $post->ID, count( $img_data ) ) );
			if ( empty( $img_data ) ) {
				continue;
			}

			$post_content_updated = $post->post_content;
			foreach ( $img_data as $img_datum ) {
				$src   = trim( $img_datum[0] );
				$title = $img_datum[1];
				$alt   = $img_datum[2];

				// Check if this image `src` was used multiple times in the content, and has possibly already downloaded.
				if ( false === strpos( $post_content_updated, $src ) && false === strpos( $post_content_updated, esc_attr( $src ) ) ) {
					WP_CLI::line( sprintf( '✖ skipping, already downloaded %s', $src ) );
					continue;
				}

				// Filter `src` by host -- we can either download just from certain select hosts, or we can use a negative
				// approach and download from all the hosts except from some specifically excluded ones.
				if ( $only_download_from_hosts ) {
					if ( ! $this->does_uri_match_host( $src, $only_download_from_hosts ) ) {
						WP_CLI::line( sprintf( '✖ skipping, off target host %s', $src ) );
						continue;
					}
				} elseif ( $hosts_excluded ) {
					if ( $this->does_uri_match_host( $src, $hosts_excluded ) ) {
						WP_CLI::line( sprintf( '✖ skipping, excluded host %s', $src ) );
						continue;
					}
				}

				// Check if the local image file exists, which will decide whether the image will be imported form file or downloaded.
				try {
					$img_import_path = $this->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_image_host_and_schema );
				} catch ( \Exception $e ) {
					if ( self::EXCEPTION_CODE_NO_DEFAULT_HOST_PROVIDED == $e->getCode() ) {
						WP_CLI::warning( sprintf( '❗ Default download host+schema missing: %s', $e->getMessage() ) );
						$this->log( $this->get_log_name( self::LOG_FILE_ERR_DOWNLOADING_REFERENCE, $post_id_from, $post_id_to ), sprintf( 'ID %d src %s', $post->ID, $src ) );
					} else {
						WP_CLI::warning( sprintf( '❗ Unknown error when getting image path: %s', $e->getMessage() ) );
						$this->log( $this->get_log_name( self::LOG_FILE_ERR_OTHER, $post_id_from, $post_id_to ), sprintf( 'ID %d src %s', $post->ID, $src ) );
					}

					continue;
				}

				// If the `<img>` `title` and `alt` are still empty, let's use the image file name without the extension.
				$filename_wo_extension = str_replace( '.' . pathinfo( $img_import_path, PATHINFO_EXTENSION ), '', pathinfo( $img_import_path, PATHINFO_FILENAME ) );
				$title                 = empty( $title ) ? $filename_wo_extension : $title;
				$alt                   = empty( $alt ) ? $filename_wo_extension : $alt;

				// Download or import the image file.
				WP_CLI::line( sprintf( '✓ %s %s ...', $this->file_exists( $img_import_path ) ? 'importing file' : 'downloading', $img_import_path ) );
				try {
					$attachment_id = ! $dry_run
						? $this->import_external_file( $img_import_path, $title, $caption = null, $description = null, $alt, $post->ID )
						: null;
				} catch ( \Exception $e ) {
					if ( self::EXCEPTION_CODE_DOWNLOAD_FAILED == $e->getCode() ) {
						WP_CLI::warning( sprintf( '❗ Error while downloading image: %s', $e->getMessage() ) );
						$this->log( $this->get_log_name( self::LOG_FILE_ERR_DOWNLOAD_FAILED, $post_id_from, $post_id_to ), sprintf( 'ID %d src %s : %s', $post->ID, $src, $e->getMessage() ) );
					} elseif ( self::EXCEPTION_CODE_IMPORT_FAILED == $e->getCode() ) {
						WP_CLI::warning( sprintf( '❗ Error during import to Media Library: %s', $e->getMessage() ) );
						$this->log( $this->get_log_name( self::LOG_FILE_ERR_IMPORT_FAILED, $post_id_from, $post_id_to ), sprintf( 'ID %d src %s : %s', $post->ID, $src, $e->getMessage() ) );
					} else {
						WP_CLI::warning( sprintf( '❗ Unknown error: %s', $e->getMessage() ) );
						$this->log( $this->get_log_name( self::LOG_FILE_ERR_OTHER, $post_id_from, $post_id_to ), sprintf( 'ID %d src %s : %s', $post->ID, $src, $e->getMessage() ) );
					}

					continue;
				}

				// Replace the URI in Post content with the new one.
				$img_uri_new          = ! $dry_run
					? wp_get_attachment_url( $attachment_id )
					: 'https://dry-run/new-url';
				$post_content_updated = str_replace( array( esc_attr( $src ), $src ), $img_uri_new, $post_content_updated );
				$this->log(
					$this->get_log_name( self::LOG_FILE_DOWNLOAD, $post_id_from, $post_id_to ),
					sprintf( 'Post ID %d ; original src %s ; new src %s ; imported attachment ID %d', $post->ID, $src, $img_uri_new, $attachment_id )
				);
			}

			// Update the Post content.
			if ( ! $dry_run && $post_content_updated != $post->post_content ) {
				$wpdb->update( $wpdb->prefix . 'posts', array( 'post_content' => $post_content_updated ), array( 'ID' => $post->ID ) );
				WP_CLI::line( '✓ Post content updated 👍' );
			} elseif ( $dry_run && $post_content_updated != $post->post_content ) {
				WP_CLI::line( '✓ Post content updated 👍' );
			}
		}

		// Required for the $wpdb->update() to sink in.
		wp_cache_flush();

		// Closing remarks.
		$this->cli_echo_log_info( $default_image_host_and_schema, $post_id_from, $post_id_to );
		WP_CLI::line( sprintf( 'All done!  🙌  Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Outputs warnings to the CLI regarding all encountered and logged errors.
	 *
	 * @param string $default_image_host_and_schema Default schema+hostname to download non-fully qualified URIs from.
	 * @param int    $post_id_from                 --post-id-from command argument.
	 * @param int    $post_id_to                   --post-id-to command argument.
	 */
	public function cli_echo_log_info( $default_image_host_and_schema, $post_id_from, $post_id_to ) {
		if ( $this->file_exists( $this->get_log_name( self::LOG_FILE_DOWNLOAD, $post_id_from, $post_id_to ) ) ) {
			WP_CLI::warning(
				sprintf(
					'👍 For a full list of downloaded images, see `%s`.',
					$this->get_log_name( self::LOG_FILE_DOWNLOAD, $post_id_from, $post_id_to )
				)
			);
		}
		if ( $this->file_exists( $this->get_log_name( self::LOG_FILE_ERR_DOWNLOAD_FAILED, $post_id_from, $post_id_to ) ) ) {
			WP_CLI::warning(
				sprintf(
					'❗ Some image could not be downloaded. See the `%s` log file for a full list.',
					$this->get_log_name( self::LOG_FILE_ERR_DOWNLOAD_FAILED, $post_id_from, $post_id_to )
				)
			);
		}
		if ( $this->file_exists( $this->get_log_name( self::LOG_FILE_ERR_IMPORT_FAILED, $post_id_from, $post_id_to ) ) ) {
			WP_CLI::warning(
				sprintf(
					'❗ Some image could not be imported into the Media Library. See the `%s` log file for a full list.',
					$this->get_log_name( self::LOG_FILE_ERR_IMPORT_FAILED, $post_id_from, $post_id_to )
				)
			);
		}
		if ( $this->file_exists( $this->get_log_name( self::LOG_FILE_ERR_DOWNLOADING_REFERENCE, $post_id_from, $post_id_to ) ) ) {
			WP_CLI::warning(
				sprintf(
					'❗ Some non-fully-qualified images URLs could not be downloaded %s. See the `%s` log file for a full list. You will probably want to set this parameter and rerun this command.',
					( ! $default_image_host_and_schema ? ', probably because you did not provide the `--default-image-host-and-schema` param' : '' ),
					$this->get_log_name( self::LOG_FILE_ERR_DOWNLOADING_REFERENCE, $post_id_from, $post_id_to )
				)
			);
		}
		if ( $this->file_exists( $this->get_log_name( self::LOG_FILE_ERR_OTHER, $post_id_from, $post_id_to ) ) ) {
			WP_CLI::warning(
				sprintf(
					'❗ Some unknown errors occurred. See the `%s` log file for a full list.',
					$this->get_log_name( self::LOG_FILE_ERR_OTHER, $post_id_from, $post_id_to )
				)
			);
		}
	}

	/**
	 * Returns a full image path to import or download from. If a local image file is available, returns the full path to the file,
	 * or else returns a fully qualified HTTP path to download from.
	 *
	 * @param string $src                           Img `src` URI.
	 * @param string $folder_local_images           Path to local folder where image files can be found at.
	 * @param string $default_image_host_and_schema Default schema+host used to download relative referenced URLs,
	 *                                              e.g. if you provide the value 'https://dl_host`, it will attempt to download
	 *                                              a relative `src="/path/img.jpg"` from 'https://dl_host/path/img.jpg'
	 *
	 * @return string Either a full path to a local image file, or a fully qualified HTTP path to download the image from.
	 *
	 * @throws RuntimeException If image could not be downloaded. Sets custom exception codes.
	 */
	public function get_fully_qualified_img_import_or_download_path( $src, $folder_local_images = null, $default_image_host_and_schema = null ) {
		$img_import_path = null;

		// Get the path (without host), and remove possible query params.
		$src_path = wp_parse_url( $src )['path'];

		// Try and get the local image file.
		$is_local_file = false;
		if ( $folder_local_images ) {
			$img_local_file_path = $folder_local_images . '/' . ltrim( $src_path, '/' );
			if ( $this->file_exists( $img_local_file_path ) ) {
				$is_local_file   = true;
				$img_import_path = $img_local_file_path;
			}
		}
		if ( $is_local_file ) {
			return $img_import_path;
		}

		/**
		 * Handles three types of `src`s like this:
		 *      - an absolute HTTP URL, e.g. 'https://host.com/img.jpg'
		 *      - a relative reference from root, e.g. '/segment/img.jpg', and uses the `--default-image-host-and-schema` to try
		 *        and download it
		 *      - a relative reference without the beginning `/`, e.g. 'segment/img.jpg'. Although this could also be a different
		 *        kind of `src`, e.g. `src="data:image/svg+xml;base64..."`, it still tries to transform it to a fully qualified
		 *        URL by using the `--default-image-host-and-schema` to download from.
		 */
		$is_src_absolute     = ( 0 === strpos( strtolower( $src ), 'http' ) );
		$is_src_relative_ref = ! $is_src_absolute;

		// If no local image file is used, get a fully qualified remote URI.
		if ( $is_src_absolute ) {
			// A good old absolute URL.
			$img_import_path = $src;
		} else if ( $is_src_relative_ref && ! $default_image_host_and_schema ) {
			// Use the `--default-image-host-and-schema` to try and download a relative URL.
			throw ( new RuntimeException(
				sprintf( 'Could not download src %s since no `--default-image-host-and-schema` was provided.', $src ),
				self::EXCEPTION_CODE_NO_DEFAULT_HOST_PROVIDED
			) );
		} else if ( $is_src_relative_ref && $default_image_host_and_schema ) {
			// A relative reference from root -- turning it to a fully qualified (absolute) one.
			$img_import_path = $default_image_host_and_schema
				. ( ( 0 !== strpos( strtolower( $src ), '/' ) ) ? '/' : '' )
				. $src;
		}

		return $img_import_path;
	}

	/**
	 * Returns a full list of hosts to exclude from downloading/importing. Merges this site's host (the `siteurl` Option) with the
	 * user provided list of excluded hosts.
	 *
	 * @param array $excluded_hosts User specified list of hosts to exclude.
	 *
	 * @return array
	 */
	private function get_all_excluded_hosts( $excluded_hosts ) {
		$hosts = array();

		$siteurl = get_option( 'siteurl' );
		if ( $siteurl ) {
			$host_this = wp_parse_url( $siteurl )['host'];
			array_push( $hosts, $host_this );
		}

		if ( ! empty( $excluded_hosts ) ) {
			foreach ( $excluded_hosts as $host ) {
				array_push( $hosts, $host );
			}
		}

		return $hosts;
	}

	/**
	 * Checks whether URI's host matches an array element of the given hosts array (the hosts array supports wildcard).
	 *
	 * @param string $uri   URI which host is checked.
	 * @param array  $hosts Hostnames to check against.
	 *
	 * @return false
	 */
	public function does_uri_match_host( string $uri, array $hosts ) {
		if ( empty( $hosts ) || empty( $uri ) ) {
			return false;
		}

		$parsed   = wp_parse_url( $uri );
		$host_uri = $parsed['host'] ?? null;
		if ( null === $host_uri ) {
			return false;
		}

		foreach ( $hosts as $host ) {
			if ( fnmatch( $host, $host_uri ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Imports/downloads external media file to the Media Library, either from a URL or from a local path.
	 *
	 * To untangle the terminology, the optional params Title, Caption, Description and Alt are the params we see in the
	 * Attachment edit form in WP Admin.
	 *
	 * @param string $path        Media file full URL or full local path, or URL to the media file.
	 * @param string $title       Optional. Attachment title.
	 * @param string $caption     Optional. Attachment caption.
	 * @param string $description Optional. Attachment description.
	 * @param string $alt         Optional. Image Attachment `alt` attribute.
	 * @param int    $post_id     Optional.  Post ID the media is associated with; this will ensure it gets uploaded to the same
	 *                            `yyyy/mm` folder.
	 * @param array  $args        Optional. Attachment creation argument to override used by the \media_handle_sideload(), used
	 *                            internally by the \wp_insert_attachment(), and even more internally by the \wp_insert_post().
	 *
	 * @return int|WP_Error Attachment ID.
	 *
	 * @throws RuntimeException If error during download or import. Sets custom exception codes.
	 */
	public function import_external_file( $path, $title = null, $caption = null, $description = null, $alt = null, $post_id = 0, $args = array() ) {
		// Fetch remote or local file.
		$is_http = 'http' == substr( $path, 0, 4 );
		if ( $is_http ) {
			$tmpfname = download_url( $path );
			if ( is_wp_error( $tmpfname ) ) {
				throw ( new RuntimeException( $tmpfname->get_error_message(), self::EXCEPTION_CODE_DOWNLOAD_FAILED ) );
			}
		} else {
			// The `media_handle_sideload()` function deletes the local file after import, so to preserve the local path, we're
			// first saving it to a temp location, in exactly the same way the WP's own `\download_url()` function above does.
			$tmpfname = wp_tempnam( $path );
			copy( $path, $tmpfname );
		}

		// Get the file name - using the `wp_parse_url()` eliminates the query params.
		$file_name = basename( wp_parse_url( $path )['path'] );
		// Where URI which seres an image containsd no extensions (e.g. some images from googleusercontent.com), try and detect the extension directly from the downloaded image binary's mime encoding.
		$file_has_extension = isset( pathinfo( $file_name )['extension'] );
		if ( ! $file_has_extension ) {
			$extension = $this->get_image_extension_from_binary_file( $tmpfname );
			if ( $extension ) {
				$file_name .= '.' . $extension;
			}
		}

		$file_array = array(
			'name'     => $file_name,
			'tmp_name' => $tmpfname,
		);

		if ( $title ) {
			$args['post_title'] = $title;
		}
		if ( $caption ) {
			$args['post_excerpt'] = $caption;
		}
		if ( $description ) {
			$args['post_content'] = $description;
		}
		$att_id = media_handle_sideload( $file_array, $post_id, $title, $args );

		// If there was an error importing after downloading, first clean up the temp file.
		if ( is_wp_error( $att_id ) && $is_http ) {
			if ( file_exists( $file_array['tmp_name'] ) ) {
				// phpcs:ignore
				unlink( $file_array['tmp_name'] );
			}
			throw ( new RuntimeException( $att_id->get_error_message(), self::EXCEPTION_CODE_IMPORT_FAILED ) );
		}

		if ( $alt ) {
			update_post_meta( $att_id, '_wp_attachment_image_alt', $alt );
		}

		return $att_id;
	}

	/**
	 * Fetches `ID` and `post_content` from the posts table.
	 *
	 * Post IDs can be specified by either of these:
	 *  1. an array of $post_ids
	 *  2. a range of $post_id_from and $post_id_to
	 *  3. if neither of these are given, all post IDs are fetched
	 *
	 * @param array|null $post_ids      IDs.
	 * @param int|null   $post_id_from  ID from.
	 * @param int|null   $post_id_to    ID to.
	 * @param array|null $post_types    Post types.
	 * @param array|null $post_statuses Post statuses.
	 *
	 * @return object|null
	 */
	private function get_posts_ids_and_contents(
		$post_ids = null,
		$post_id_from = null,
		$post_id_to = null,
		$post_types = array( 'post', 'page' ),
		$post_statuses = array( 'publish' )
	) {
		global $wpdb;

		$types_placeholders    = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$statuses_placeholders = implode( ',', array_fill( 0, count( $post_statuses ), '%s' ) );
		$query                 = "SELECT ID, post_content FROM {$wpdb->prefix}posts WHERE post_type IN ( $types_placeholders ) AND post_status IN ( $statuses_placeholders ) ";
		$prepare_args          = array();
		foreach ( $post_types as $post_type ) {
			array_push( $prepare_args, $post_type );
		}
		foreach ( $post_statuses as $post_statuse ) {
			array_push( $prepare_args, $post_statuse );
		}

		if ( null !== $post_ids ) {
			$ids_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			$query .= " AND ID IN ({$ids_placeholders}) ";
			$prepare_args = array_merge( $prepare_args, $post_ids );
		} else if ( null !== $post_id_from && null !== $post_id_to ) {
			$query .= ' AND ID BETWEEN %d AND %d ';
			array_push( $prepare_args, $post_id_from, $post_id_to );
		}
		// phpcs:ignore -- false positive.
		$query = $wpdb->prepare( $query, $prepare_args );

		// phpcs:ignore -- statement prepared above.
		return $wpdb->get_results( $query );
	}

	/**
	 * Gets all the unique `<img>` `src` attributes in post.
	 *
	 * @param string $html HTML.
	 *
	 * @return array
	 */
	private function get_all_img_srcs( $html ) {
		$img_srcs = array();

		$crawler = ( new Crawler( $html ) )->filter( 'img' );
		if ( 0 == $crawler->count() ) {
			return $img_srcs;
		}

		foreach ( $crawler->getIterator() as $node ) {
			$img_srcs[] = $node->getAttribute( 'src' );
		}

		// Unique values and updated keys.
		$img_srcs = array_values( array_unique( $img_srcs ) );

		return $img_srcs;
	}

	/**
	 * Attempts to determine image file extension from the mime encoding of the image file.
	 *
	 * @param string $filename Full file path.
	 *
	 * @return string|null Image format extension, no dot.
	 */
	private function get_image_extension_from_binary_file( $filename ) {
		$extension = null;

		$mime_type       = ( new \finfo( FILEINFO_MIME ) )->file( $filename );
		$mime_img_prefix = 'image/';
		if ( is_string( $mime_type ) && ( 0 === strpos( $mime_type, $mime_img_prefix ) ) ) {
			$extension = substr( $mime_type, strlen( $mime_img_prefix ), strpos( $mime_type, ';' ) - strlen( $mime_img_prefix ) );
		}

		return $extension ? $extension : null;
	}

	/**
	 * Wrapper function for `file_exists` for easy mocking.
	 *
	 * @param string $file Full path to file.
	 */
	public function file_exists( $file ) {
		return file_exists( $file );
	}

	/**
	 * Super simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	private function log( $file, $message ) {
		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}

	/**
	 * Appends the `_{$post_id_from}-{$post_id_to}_` to log file name.
	 *
	 * @param string $log_filename Log file name.
	 * @param string $post_id_from --post-id-from command argument.
	 * @param string $post_id_to   --post-id-to command argument.
	 *
	 * @return mixed
	 */
	private function get_log_name( $log_filename, $post_id_from, $post_id_to ) {
		if ( ! $post_id_from || ! $post_id_to ) {
			return $log_filename;
		}

		// Double check if file ends with known extension.
		$extension_pos = strrpos( $log_filename, self::LOG_FILES_EXTENSION );
		if ( strlen( self::LOG_FILES_EXTENSION ) != ( strlen( $log_filename ) - $extension_pos ) ) {
			return $log_filename;
		}

		// Append the ID range to log name.
		$log_filename_custom = sprintf(
			'%s_%s_%s',
			substr( $log_filename, 0, $extension_pos ),
			$post_id_from . '-' . $post_id_to,
			self::LOG_FILES_EXTENSION
		);

		return $log_filename_custom;
	}
}
