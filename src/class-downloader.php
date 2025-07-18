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
use Newspack\MigrationTools\Logic\Attachments;

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
	const LOG_FILE_URLS                      = 'imagedownloader__postids_urls.csv';

	/**
	 * Custom codes for local runtime exception handling.
	 */
	const EXCEPTION_CODE_NO_DEFAULT_HOST_PROVIDED = 100;
	const EXCEPTION_CODE_DOWNLOAD_FAILED          = 101;
	const EXCEPTION_CODE_IMPORT_FAILED            = 102;

	/**
	 * List of image extensions supported by WordPress.
	 */
	const WP_IMAGE_EXTENSIONS = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'svg' ];

	/**
	 * Regex pattern for matching intermediate image sizes, with a placeholder for extensions.
	 * E.g. for `https://www.mysite.com/wp-content/uploads/2025/01/img-puppy-300x244.jpg` this matches `300`, `244` and `jpg`.
	 */
	private const INTERMEDIATE_IMG_PATTERN = '/-(\\d+)x(\\d+)\\.(%s)$/i';

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
			'newspack-post-image-downloader list-all-urls',
			array( $this, 'cmd_list_all_urls' ),
			array(
				'shortdesc' => 'Helper command. This one generates a more comprehensive list of all the URLs used on the site. It scans HTML contents for any `href` and `src` attributes—covering links, scripts, images, other media types, and more — but ignores plain-text URLs. All extracted URLs are saved to a log file for a custom review.',
				'synopsis'  => array(
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
						'type'        => 'flag',
						'name'        => 'do-not-download-large-sizes',
						'description' => 'Unless this flag is set, the command will attempt to download the large sized non-scaled and non-intermediate images together with the image URLs encountered in post_content. E.g.1. for an intermediate image https://www.mysite.com/wp-content/uploads/2025/01/img-puppy-300x244.jpg the command will additionally attempt to download the non-intermediate image without the `-300x244` suffix https://www.mysite.com/wp-content/uploads/2025/01/img-puppy.jpg . E.g.2. for a scaled image https://www.mysite.com/wp-content/uploads/2025/01/img-kitten-scaled.jpg it will additionally try and download the non-scaled image https://www.mysite.com/wp-content/uploads/2025/01/img-kitten.jpg . E.g.3. And for an image which is both scaled and intermediate https://www.mysite.com/wp-content/uploads/2025/01/img-kitten-scaled-300x244.jpg it will additionally try and download both the https://www.mysite.com/wp-content/uploads/2025/01/img-kitten-scaled.jpg and the https://www.mysite.com/wp-content/uploads/2025/01/img-kitten.jpg images. See more about intermediate images and image sizes in WordPress docs.',
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

		$time_start    = microtime( true );
		$posts         = $this->get_posts_ids_and_contents( $post_ids_specific, $post_id_from, $post_id_to, $post_types, $post_statuses );
		$img_hostnames = $this->get_all_image_hostnames_from_posts( $posts );

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
	 * Callable for `newspack-post-image-downloader list-all-urls`.
	 * See command description in \NewspackPostImageDownloader\Downloader::register_commands.
	 *
	 * @param array $args        CLI arguments.
	 * @param array $assoc_args  CLI associative arguments.
	 */
	public function cmd_list_all_urls( $args, $assoc_args ) {
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
		
		WP_CLI::line( sprintf( 'Getting all URLs from %d posts...', count( $posts ) ) );
		$urls = $this->get_all_urls_from_posts( $posts );

		// Tada!
		$log_file = $this->get_log_name( self::LOG_FILE_URLS, $post_id_from, $post_id_to );
		if ( $this->file_exists( $log_file ) ) {
			unlink( $log_file ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink.
		}
		WP_CLI::success( sprintf( '👉 Found %d total URLs%s', count( $urls ), ( count( $urls ) > 0 ? ' and saved them to `' . $log_file . '`' : '.' ) ) );
		if ( count( $urls ) > 0 ) {
			$log_file_handle = fopen( $log_file, 'w' ); // phpcs:ignore -- WordPress.WP.AlternativeFunctions.file_system_operations_fopen.
			fputcsv( $log_file_handle, [ 'post_id', 'url' ] ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv.
			foreach ( $urls as $post_id => $urls_post ) {
				foreach ( $urls_post as $url ) {
					fputcsv( $log_file_handle, [ $post_id, $url ] ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv.
				}
			}
		}

		WP_CLI::line( sprintf( 'Done in %d mins! 🙌 ', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Searches for all image URLs in post contents.
	 *
	 * @param array $posts Array of post records, contains subarrays with keys 'ID' and 'post_content'.
	 *
	 * @return array An array containing image URL hostnames as keys and post IDs as values, plus a special key
	 *               "relative URL paths" containing relative URLs and post IDs as values.
	 */
	public function get_all_image_hostnames_from_posts( $posts ) {

		if ( empty( $posts ) ) {
			return;
		}

		$img_hostnames = [
			'relative URL paths' => [],
		];

		WP_CLI::line( sprintf( 'Checking image hosts in %d posts...', count( $posts ) ) );
		foreach ( $posts as $i => $post ) {
			$img_srcs = $this->get_all_img_srcs( $post['post_content'] );
			if ( empty( $img_srcs ) ) {
				continue;
			}
			foreach ( $img_srcs as $img_src ) {
				$parsed = wp_parse_url( $img_src );
				if ( false === $parsed ) {
					continue;
				} elseif ( isset( $parsed['host'] ) ) {
					if ( isset( $img_hostnames[ $parsed['host'] ] ) && in_array( $post['ID'], $img_hostnames[ $parsed['host'] ] ) ) {
						continue;
					}
					$img_hostnames[ $parsed['host'] ][] = $post['ID'];
				} else {
					if ( in_array( $post['ID'], $img_hostnames['relative URL paths'] ) ) {
						continue;
					}
					// There could be different types of `src` e.g. `src="data:image/svg+xml;base64"`, so this won't be perfect.
					$img_hostnames['relative URL paths'][] = $post['ID'];
				}
			}
		}

		return $img_hostnames;
	}

	/**
	 * Searches and gets all URLs found in post content as `src` and `href` attributes, not just image URLs.
	 *
	 * @param array $posts Array of post records, contains subarrays with keys 'ID' and 'post_content'.
	 *
	 * @return array An array containing postIDs as keys, and value is a subarray of URLs found in the content.
	 */
	public function get_all_urls_from_posts( array $posts ): array {
		if ( empty( $posts ) ) {
			return [];
		}

		$urls = [];
		foreach ( $posts as $post ) {
			$post_id = $post['ID'];
			$html    = $post['post_content'];
			
			$urls_post = $this->get_all_urls( $html );
			if ( empty( $urls_post ) ) {
				continue;
			}

			$urls[ $post_id ] = $urls_post;
		}

		return $urls;
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
		$do_not_download_large_sizes   = isset( $assoc_args['do-not-download-large-sizes'] ) ? true : false;
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
		$time_start        = microtime( true );
		$hosts_excluded    = $this->get_all_excluded_hosts( $hosts_excluded );
		$attachments_logic = new Attachments();

		// Flush the log files.
		$logs = array(
			$this->get_log_name( self::LOG_FILE_DOWNLOAD, $post_id_from, $post_id_to ),
			$this->get_log_name( self::LOG_FILE_ERR_DOWNLOAD_FAILED, $post_id_from, $post_id_to ),
			$this->get_log_name( self::LOG_FILE_ERR_IMPORT_FAILED, $post_id_from, $post_id_to ),
			$this->get_log_name( self::LOG_FILE_ERR_DOWNLOADING_REFERENCE, $post_id_from, $post_id_to ),
			$this->get_log_name( self::LOG_FILE_ERR_OTHER, $post_id_from, $post_id_to ),
		);
		foreach ( $logs as $log ) {
			if ( $this->file_exists( $log ) ) {
				unlink( $log ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink.
			}
		}

		WP_CLI::line( 'Fetching Posts...' );
		$posts = $this->get_posts_ids_and_contents( $post_ids_specific, $post_id_from, $post_id_to, $post_types, $post_statuses );
		if ( empty( $posts ) ) {
			WP_CLI::warning( 'No Posts found... 🤔' );
			exit;
		}

		foreach ( $posts as $key_post => $post ) {
			// Extract attributes from all the `<img>`s.
			$img_data = ( new Crawler( $post['post_content'] ) )->filterXpath( '//img' )->extract( array( 'src', 'title', 'alt' ) );
			
			// Convert numeric array to associative array for consistent access.
			$img_data = array_map(
				function ( $item ) {
					return array(
						'src'   => $item[0],
						'title' => $item[1],
						'alt'   => $item[2],
					);
				},
				$img_data
			);

			WP_CLI::line( sprintf( '👉 (%d/%d) post ID %d, found %d images...', $key_post + 1, count( $posts ), $post['ID'], count( $img_data ) ) );
			if ( empty( $img_data ) ) {
				continue;
			}

			// Extend the $img_data array with the full sized image URLs to be downloaded (non-intermediate and non-scaled versions of the image).
			if ( ! $do_not_download_large_sizes ) {
				$img_data = $this->include_full_sized_images_in_img_data( $img_data, $post['ID'], $post_id_from, $post_id_to );
			}

			// Download images in post content.
			$post_content_updated = $post['post_content'];
			foreach ( $img_data as $img_datum ) {
				$src                  = trim( $img_datum['src'] );
				$src_non_intermediate = trim( $img_datum['src_non_intermediate'] );
				$src_non_scaled       = trim( $img_datum['src_non_scaled'] );
				$title                = trim( $img_datum['title'] );
				$alt                  = trim( $img_datum['alt'] );

				// Boolean flags for simpler logic.
				$is_scaled       = null !== $src_non_scaled;
				$is_intermediate = null !== $src_non_intermediate;

				// Basic URL validation.
				if ( ! filter_var( $src, FILTER_VALIDATE_URL ) ) {
					WP_CLI::warning( sprintf( '❗ ERROR: Invalid image URL: %s', $src ) );
					$this->log( $this->get_log_name( self::LOG_FILE_ERR_OTHER, $post_id_from, $post_id_to ), sprintf( 'ID %d src %s : ERROR, Invalid URL', $post['ID'], $src ) );
					continue;
				}
				// Skip if $src was already used/downloaded and replaced.
				if ( false === strpos( $post_content_updated, $src ) && false === strpos( $post_content_updated, esc_attr( $src ) ) ) {
					WP_CLI::line( sprintf( '✖ skipping, already downloaded %s', $src ) );
					continue;
				}
				// Filter `src` by host.
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

				/**
				 * Build a ranked list of image srcs by setting the highest quality versions of the src first.
				 * 
				 * @param array $srcs_ranked An ordered array of $img_datum's srcs, where the largest and highest quality versions of the src go first.
				 * 
				 * The goal is to loop over this list $srcs_ranked, import just the first one into the Media Library (so that the the Media Library
				 * attachment gets created from the highest quality image available), and just physically download the smaller srcs next into the same
				 * path as the attachment (in case our local site thumbnails are registered differently than the remote site's, and not all
				 * the same intermediate/thumbnail images get generated during the large attachment import).
				 */
				$srcs_ranked = [];
				// Add the larger and highest quality versions of the src to the list first.
				if ( $is_scaled && $is_intermediate ) {
					// E.g. image-scaled-100x200.jpg: add larger image.jpg, image-scaled.jpg.
					if ( $src_non_scaled ) {
						$srcs_ranked[] = $src_non_scaled;
					}
					if ( $src_non_intermediate ) {
						$srcs_ranked[] = $src_non_intermediate;
					}
				} elseif ( $is_intermediate ) {
					// E.g. image-100x200.jpg: add larger image.jpg.
					if ( $src_non_intermediate ) {
						$srcs_ranked[] = $src_non_intermediate;
					}
				} elseif ( $is_scaled ) {
					// image-scaled.jpg: add image.jpg.
					if ( $src_non_scaled ) {
						$srcs_ranked[] = $src_non_scaled;
					}
				}
				// Add the "original" src (the one from post_content) to end of list.
				$srcs_ranked[] = $src;

				// Import the largest image into the Media Library (the first ranked src), then just physically also download the rest of them in the same path.
				$imported        = false;
				$attachment_id   = null;
				$imported_folder = null;
				$src_local       = null;
				foreach ( $srcs_ranked as $key_src_ranked => $src_ranked ) {

					// Get the fully qualified path of the current ranked src file (either from local folder, or from remote URL).
					$img_import_path = null;
					try {
						$img_import_path = $this->get_fully_qualified_img_import_or_download_path( $src_ranked, $folder_local_images, $default_image_host_and_schema );
					} catch ( \Exception $e ) {
						if ( self::EXCEPTION_CODE_NO_DEFAULT_HOST_PROVIDED == $e->getCode() ) {
							WP_CLI::warning( sprintf( '❗ Default download host+schema missing: %s', $e->getMessage() ) );
							$this->log( $this->get_log_name( self::LOG_FILE_ERR_DOWNLOADING_REFERENCE, $post_id_from, $post_id_to ), sprintf( 'ID %d src %s', $post['ID'], $src_ranked ) );
						} else {
							WP_CLI::warning( sprintf( '❗ Unknown error when getting image path: %s', $e->getMessage() ) );
							$this->log( $this->get_log_name( self::LOG_FILE_ERR_OTHER, $post_id_from, $post_id_to ), sprintf( 'ID %d src %s', $post['ID'], $src_ranked ) );
						}
						// Try importing the next ranked src.
						continue;
					}

					// Check `title` and `alt` -- if they are empty, use the image filename (without extension).
					$basename              = basename( $img_import_path );
					$filename_parts        = pathinfo( $basename );
					$filename_wo_extension = $filename_parts['filename'];
					$title_to_use          = empty( $title ) ? $filename_wo_extension : $title;
					$alt_to_use            = empty( $alt ) ? $filename_wo_extension : $alt;

					// Try and import the first (largest) ranked src into the Media Library.
					if ( ! $imported ) {
						WP_CLI::line( sprintf( '✓ %s %s ...', $this->file_exists( $img_import_path ) ? 'importing file' : 'downloading', $img_import_path ) );
						
						// Import the image into the Media Library.
						$attachment_id = null;
						if ( ! $dry_run ) {
							$attachment_id = $attachments_logic->import_external_file( $img_import_path, $title_to_use, null, null, $alt_to_use, $post['ID'] );
							if ( is_wp_error( $attachment_id ) ) {
								WP_CLI::warning( sprintf( '❗ Error while importing image: %s', $attachment_id->get_error_message() ) );
								$this->log( $this->get_log_name( self::LOG_FILE_ERR_IMPORT_FAILED, $post_id_from, $post_id_to ), sprintf( 'ID %d src %s : %s', $post['ID'], $src_ranked, $attachment_id->get_error_message() ) );
								continue;
							}
							$imported = true;
							$this->log(
								$this->get_log_name( self::LOG_FILE_DOWNLOAD, $post_id_from, $post_id_to ),
								sprintf( "Imported Post ID %d ; src '%s' ; attachment ID %s", $post['ID'], $src_ranked, $attachment_id )
							);
							
							// Get the target directory where the attachment was saved.
							$target_path     = get_attached_file( $attachment_id );
							$imported_folder = dirname( $target_path );
							
							// If this is the $src, note new the new URL.
							if ( $src == $src_ranked ) {
								$src_local = wp_get_attachment_url( $attachment_id );
							}
						} else {
							// Dry run.
							$imported        = true;
							$upload_dir      = wp_upload_dir();
							$imported_folder = $upload_dir['path'];
							WP_CLI::line( sprintf( "[Dry Run] Importing Post ID %d ; src '%s'", $post['ID'], $src_ranked ) );
						}
					} else {
						// Otherwise, if a previous ranked src was already imported, just download the rest of them to the same folder as the imported attachment.
						$target_path   = $imported_folder;
						$download_path = trailingslashit( $target_path ) . basename( $img_import_path );

						// If the file already exists, skip download.
						if ( $this->file_exists( $download_path ) ) {
							// If this is the $src, and it already exists in the target folder, note new the new local URL.
							if ( $src == $src_ranked ) {
								// Same folder (URL path) as imported $attachment_id, with $src's filename.
								$src_local = dirname( wp_get_attachment_url( $attachment_id ) ) . '/' . basename( $src );
							}

							$this->log(
								$this->get_log_name( self::LOG_FILE_DOWNLOAD, $post_id_from, $post_id_to ),
								sprintf( 'Already exists, skipping download: %s', $download_path )
							);
							continue;
						}

						// Download the rest of the files to the same folder where the attachment was imported.
						if ( ! $dry_run ) {
							$downloaded = $this->download_file_to_dir( $src_ranked, $target_path );
							// Handle error.
							if ( is_wp_error( $downloaded ) ) {
								WP_CLI::warning( sprintf( "❗ Failed to download file '%s' to '%s', error: %s", $src_ranked, $target_path, $downloaded->get_error_message() ) );
								$this->log(
									$this->get_log_name( self::LOG_FILE_ERR_DOWNLOAD_FAILED, $post_id_from, $post_id_to ),
									sprintf( "ID %d src '%s' : download_file_to_dir failed, error: %s", $post['ID'], $src_ranked, $downloaded->get_error_message() )
								);
								continue;
							}

							// If this is the $src, and it has been downloaded, note new the new local URL.
							if ( $src == $src_ranked ) {
								// Same folder (URL path) as imported $attachment_id, with $src's filename.
								$src_local = dirname( wp_get_attachment_url( $attachment_id ) ) . '/' . basename( $src );
							}
							
							$this->log(
								$this->get_log_name( self::LOG_FILE_DOWNLOAD, $post_id_from, $post_id_to ),
								sprintf( "Downloaded Post ID %d ; src '%s' ; saved to '%s'", $post['ID'], $src_ranked, $downloaded )
							);
						} else {
							// Dry run.
							WP_CLI::line( sprintf( "[Dry Run] Would download Post ID %d ; src '%s' ; to '%s'", $post['ID'], $src_ranked, $download_path ) );
						}
					}
				}

				// Replace URL $src with $src_local.
				if ( $src_local ) {
					$this->log(
						$this->get_log_name( self::LOG_FILE_DOWNLOAD, $post_id_from, $post_id_to ),
						sprintf( "Replacing in post content: Post ID %d ; original src '%s' ; new src '%s'", $post['ID'], $src, $src_local )
					);
					// Replace $src (escaped and non-escaped) in Post content with new imported/downloaded $src_local.
					$post_content_updated = str_replace( [ esc_attr( $src ), $src ], $src_local, $post_content_updated );
				} elseif ( ! $dry_run ) {
						WP_CLI::warning( sprintf( "❗ Failed to import or download any variant for '%s'", $src ) );
						$this->log( $this->get_log_name( self::LOG_FILE_ERR_OTHER, $post_id_from, $post_id_to ), sprintf( 'ID %d src %s : failed all import/download attempts', $post['ID'], $src ) );
				}
			}

			// Update the Post content.
			if ( ! $dry_run && $post_content_updated != $post['post_content'] ) {
				$wpdb->update( $wpdb->prefix . 'posts', array( 'post_content' => $post_content_updated ), array( 'ID' => $post['ID'] ) ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.NoCaching.
				WP_CLI::line( '✓ Post content updated 👍' );
			} elseif ( $dry_run && $post_content_updated != $post['post_content'] ) {
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
	 * Downloads a file (from URL or local file path) to a directory.
	 * 
	 * If the file is already in the directory, it will be skipped.
	 * 
	 * If the file is not in the directory, it will be downloaded and moved to the directory.
	 * 
	 * If the file is not in the directory, it will be downloaded and moved to the directory.
	 * 
	 * @param string $src         The URL or local file path to download/copy.
	 * @param string $target_path The fully qualified local directory to download the file to.
	 * @return string|WP_Error    The full local path of the downloaded file, or a WP_Error if the file could not be downloaded or moved to the target directory.
	 */
	public function download_file_to_dir( string $src, string $target_path ): string|WP_Error {
		// Ensure the directory exists.
		if ( ! is_dir( $target_path ) || ! wp_is_writable( $target_path ) ) {
			// Log error 'Directory does not exist or is not writable.'.
			$this->log( $this->get_log_name( self::LOG_FILE_ERR_OTHER ), sprintf( 'ID %d src %s : ERROR, Directory does not exist or is not writable.', $post['ID'], $src ) );
			return new WP_Error( 'directory_error', 'Directory does not exist or is not writable.' );
		}
	
		// Get the filename from the URL.
		$filename = wp_basename( wp_parse_url( $src, PHP_URL_PATH ) );
		if ( ! $filename ) {
			return new WP_Error( 'filename_error', 'Could not determine filename from URL.' );
		}
	
		// Download to a temp file using WP's download_url.
		$temp_file = download_url( $src );
		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}
	
		// Build final path in the target directory.
		$final_path = trailingslashit( $target_path ) . $filename;
	
		// Move downloaded file to the target directory.
		if ( ! rename( $temp_file, $final_path ) ) { // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink.
			// Handle error and clean up temp file.
			if ( $this->file_exists( $temp_file ) ) {
				unlink( $temp_file ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink.
			}
			return new WP_Error( 'move_error', 'Failed to move downloaded file.' );
		}
	
		return $final_path;
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
	 * Adds full sized image URLs to the $img_data array, except for SVGs.
	 * 
	 * WP images can be scaled, intermediate, or both.
	 * 
	 * - Scaled images are very large images which have been resized to an optimized width and height.
	 *   WP creates these scaled images during image import, and uses it for all purposes, while the original image is still stored on disk but not actively used.
	 *      E.g.: https://www.mysite.com/wp-content/uploads/2025/01/img-kitten-scaled.jpg
	 * 
	 * - Intermediate images are images which have been resized to a specific width and height, but are not the original image.
	 *      E.g.: https://www.mysite.com/wp-content/uploads/2025/01/img-kitten-300x244.jpg
	 * 
	 * - And also, WP images can be both scaled and intermediate.
	 *      E.g.: https://www.mysite.com/wp-content/uploads/2025/01/img-kitten-scaled-300x244.jpg
	 * 
	 * @param array $img_data {
	 *      Array of image data, each element is a subarray with three keys.
	 *      @type string 'src'   The image's URL.
	 *      @type string 'title' The image's title attribute.
	 *      @type string 'alt'   The image's alt attribute.
	 * }
	 * @param int   $post_id      The Post ID.
	 * @param int   $post_id_from The start of the Post ID range.
	 * @param int   $post_id_to   The end of the Post ID range.
	 * @return array {
	 *      Array of image data same as input, but with additional full-sized image elements if found.
	 *      @type string 'src'                   The image's URL.
	 *      @type ?string 'src_non_intermediate' Added by this function. The image's URL without the intermediate suffix, or null.
	 *      @type ?string 'src_non_scaled'       Added by this function. The image's URL without the scaled suffix, or null.
	 *      @type string 'title'                 The image's title attribute.
	 *      @type string 'alt'                   The image's alt attribute.
	 * }
	 */
	public function include_full_sized_images_in_img_data( array $img_data, int $post_id, ?int $post_id_from, ?int $post_id_to ): array {
		$img_data_with_large = [];
		foreach ( $img_data as $key_img_datum => $img_datum ) {
			$src = trim( $img_datum['src'] );
			// Basic URL validation.
			if ( ! filter_var( $src, FILTER_VALIDATE_URL ) ) {
				continue;
			}

			// SVGs are not resized by WP, so skip adding non-intermediate and non-scaled URLs for them.
			$extension = strtolower( pathinfo( wp_parse_url( $src, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			if ( 'svg' === $extension ) {
				$img_data_with_large[ $key_img_datum ] = [
					'src'                  => $src,
					'src_non_intermediate' => null,
					'src_non_scaled'       => null,
					'title'                => $img_datum['title'],
					'alt'                  => $img_datum['alt'],
				];
				continue;
			}

			// Add the non-intermediate and non-scaled URLs.
			$src_non_intermediate = $this->get_non_intermediate_img_url( $src );
			if ( ! is_null( $src_non_intermediate ) ) {
				WP_CLI::log( sprintf( "Adding non-intermediate image URL: post ID %d src '%s' non-intermediate '%s'", $post_id, $src, $src_non_intermediate ) );
				$this->log(
					$this->get_log_name( self::LOG_FILE_DOWNLOAD, $post_id_from, $post_id_to ),
					sprintf( "Adding non-intermediate image URL: post ID %d src '%s' non-intermediate '%s'", $post_id, $src, $src_non_intermediate )
				);
			}
			$src_non_scaled = null;
			// Writing the following block in a more verbose way just for clarity -- if the 'src' was intermediate, then we try to descale $src_non_intermediate; if it was not intermediate, then we try to descale $src.
			if ( ! is_null( $src_non_intermediate ) ) {
				$src_non_scaled = $this->get_non_scaled_img_url( $src_non_intermediate );
			} else {
				$src_non_scaled = $this->get_non_scaled_img_url( $src );
			}
			if ( ! is_null( $src_non_scaled ) ) {
				WP_CLI::log( sprintf( "Adding non-scaled image URL: post ID %d src '%s' non-scaled '%s'", $post_id, $src, $src_non_scaled ) );
				$this->log(
					$this->get_log_name( self::LOG_FILE_DOWNLOAD, $post_id_from, $post_id_to ),
					sprintf( "Adding non-scaled image URL: post ID %d src '%s' non-scaled '%s'", $post_id, $src, $src_non_scaled )
				);
			}
			$img_data_with_large[ $key_img_datum ] = [
				'src'                  => $src,
				'src_non_intermediate' => $src_non_intermediate,
				'src_non_scaled'       => $src_non_scaled,
				'title'                => $img_datum['title'],
				'alt'                  => $img_datum['alt'],
			];
		}

		return $img_data_with_large;
	}

	/**
	 * Get the non-intermediate image URL from an intermediate image URL.
	 * 
	 * If $src contains an URL of an intermediate image (URL with the `-{WIDTH}x{HEIGHT}` suffix),
	 * returns the non-intermediate URL (without the `-{WIDTH}x{HEIGHT}` suffix),
	 * or null if $src is not intermediate (does not have such suffix).
	 * 
	 * E.g. 1. if $src is an intermediate image: https://www.mysite.com/wp-content/uploads/2025/01/kitten-300x244.jpg
	 * it will return the non-intermediate image: https://www.mysite.com/wp-content/uploads/2025/01/kitten.jpg
	 * 
	 * E.g. 2. if provided an intermediate image based on a scaled image: https://www.mysite.com/wp-content/uploads/2025/01/huge_puppy-scaled-300x244.jpg
	 * it will return the scaled non-intermediateimage: https://www.mysite.com/wp-content/uploads/2025/01/huge_puppy-scaled.jpg
	 * 
	 * E.g. 3. if provided a non-intermediate image: https://www.mysite.com/wp-content/uploads/2025/01/kitten.jpg
	 * it will return null, because it's already non-intermediate: null
	 *
	 * @param string $src  The input image URL.
	 * @return string|null The URL without the `-{WIDTH}x{HEIGHT}` suffix, or null if no `-{WIDTH}x{HEIGHT}` suffix was found.
	 */
	public function get_non_intermediate_img_url( string $src ): ?string {
		// Trim the src and remove any get parameters.
		$src = trim( $src );
		$src = preg_replace( '/\?.*$/', '', $src );

		// Pattern to match the intermediate image size suffix, e.g. '-300x244.jpg'.
		$pattern = sprintf( self::INTERMEDIATE_IMG_PATTERN, implode( '|', self::WP_IMAGE_EXTENSIONS ) );
		if ( preg_match( $pattern, $src ) ) {
			// Remove everything except the extension from the matched pattern -({WIDTH})x({HEIGHT}).({EXTENSION}).
			return preg_replace( $pattern, '.\3', $src );
		}

		// Can't find the intermediate image size suffix.
		return null;
	}

	/**
	 * Get the non-scaled image URL from a scaled image URL.
	 * 
	 * If $src contains an URL of a scaled image (if it ends in '-scaled' suffix),
	 * returns the non-scaled image URL (without the '-scaled' suffix),
	 * or null if $src is not scaled (does not have such suffix).
	 * 
	 * E.g. 1. if provided a scaled image: https://www.mysite.com/wp-content/uploads/2025/01/huge_puppy-scaled.jpg
	 * it will return the non-scaled image: https://www.mysite.com/wp-content/uploads/2025/01/huge_puppy.jpg
	 * 
	 * E.g. 2. if provided a non-scaled image: https://www.mysite.com/wp-content/uploads/2025/01/regular_puppy.jpg
	 * it will return null, because it's already non-scaled: null
	 *
	 * E.g. 3. if provided a scaled and intermediate image: https://www.mysite.com/wp-content/uploads/2025/01/regular_puppy-scaled-300x244.jpg
	 * it will return null: null
	 *
	 * @param string $src  The input image URL.
	 * @return string|null The URL without the '-scaled' suffix, or null if '-scaled' suffix is not used in $src.
	 */
	public function get_non_scaled_img_url( string $src ): ?string {
		// Trim the src and remove any get parameters.
		$src = trim( $src );
		$src = preg_replace( '/\?.*$/', '', $src );

		// Pattern to match the scaled image suffix, e.g. '-scaled.jpg'.
		$pattern = '/-scaled\.(' . implode( '|', self::WP_IMAGE_EXTENSIONS ) . ')$/i';
		if ( preg_match( $pattern, $src ) ) {
			// Remove the `-scaled.{EXTENSION}` part. \1 puts the extension back.
			return preg_replace( $pattern, '.\1', $src );
		}

		// Neither src nor src_nonintermediate had the `-scaled` suffix.
		return null;
	}

	/**
	 * Returns a full image path to import or download from. If a local image file is available, returns the full path to the file,
	 * or else returns a fully qualified HTTP path to download from.
	 *
	 * @param string $src                           Img `src` URI.
	 * @param string $folder_local_images           Path to local folder where image files can be found at.
	 * @param string $default_image_host_and_schema Default schema+host used to download relative referenced URLs.
	 *                                              e.g. if you provide the value 'https://dl_host`, it will attempt to download.
	 *                                              a relative `src="/path/img.jpg"` from 'https://dl_host/path/img.jpg'.
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
		} elseif ( $is_src_relative_ref && ! $default_image_host_and_schema ) {
			// Use the `--default-image-host-and-schema` to try and download a relative URL.
			throw new RuntimeException(
				sprintf( 'Could not download src %s since no `--default-image-host-and-schema` was provided.', esc_url( $src ) ),
				esc_attr( self::EXCEPTION_CODE_NO_DEFAULT_HOST_PROVIDED )
			);
		} elseif ( $is_src_relative_ref && $default_image_host_and_schema ) {
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

		$parsed   = wp_parse_url( trim( $uri ) );
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
	 * @return array|null An array of records from DB, with subarrays with keys 'ID' and 'post_content'.
	 */
	public function get_posts_ids_and_contents(
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
			$query           .= " AND ID IN ({$ids_placeholders}) ";
			$prepare_args     = array_merge( $prepare_args, $post_ids );
		} elseif ( null !== $post_id_from && null !== $post_id_to ) {
			$query .= ' AND ID BETWEEN %d AND %d ';
			array_push( $prepare_args, $post_id_from, $post_id_to );
		}
		// phpcs:ignore -- false positive.
		$query = $wpdb->prepare( $query, $prepare_args );

		// phpcs:ignore -- statement prepared above.
		return $wpdb->get_results( $query, ARRAY_A );
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

		// Remove empty, if it was attributed from an empty node.
		$key_empty = array_search( '', $img_srcs );
		if ( false !== $key_empty ) {
			unset( $img_srcs[ $key_empty ] );
			$img_srcs = array_values( $img_srcs );
		}

		return $img_srcs;
	}

	/**
	 * Gets all the unique and trimmed URLs in HTML from `href` and `src` attributes.
	 *
	 * @param string $html HTML.
	 *
	 * @return array An array of URLs found in the HTML.
	 */
	private function get_all_urls( string $html ): array {
		$urls    = [];
		$crawler = new Crawler( $html );
		
		// Extract all href attributes.
		$crawler->filter( '[href]' )->each(
			function ( $node ) use ( &$urls ) {
				$urls[] = $node->attr( 'href' );
			}
		);
		
		// Extract all src attributes.
		$crawler->filter( '[src]' )->each(
			function ( $node ) use ( &$urls ) {
				$urls[] = $node->attr( 'src' );
			}
		);

		// Trim, unique, and remove empty (if it was attributed from an empty node).
		$urls = array_map( 'trim', $urls );
		$urls = array_unique( $urls );
		$urls = array_filter(
			$urls,
			function ( $url ) {
				return ! empty( $url );
			}
		);
		// Update keys.
		$urls = array_values( $urls );

		return $urls;
	}

	/**
	 * Attempts to determine image file extension from the mime encoding of the image file.
	 * 
	 * Note, this method was previously used, presently discontinued, but could again be used in the future.
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
		file_put_contents( $file, $message, FILE_APPEND ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents.
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
