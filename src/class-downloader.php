<?php
/**
 * Main plugin class.
 *
 * @package Newspack_Post_Image_Downloader
 */

namespace NewspackPostImageDownloader;

use WP_CLI;
use WP_Error;
use Newspack\MigrationTools\Logic\Attachments;
use Newspack\MigrationTools\Hooks\MemoryCleanupHook;
use Symfony\Component\DomCrawler\Crawler;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Bramus\Monolog\Formatter\ColoredLineFormatter;

/**
 * Image Downloader CLI commands and logic.
 *
 * @package NewspackPostImageDownloader
 */
class Downloader {

	/**
	 * List of image extensions supported by WordPress.
	 */
	public const WP_IMAGE_EXTENSIONS = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'svg' ];

	/**
	 * Regex pattern for matching intermediate image sizes, with a sprintf placeholder for extensions.
	 * E.g. for `https://www.mysite.com/wp-content/uploads/2025/01/img-puppy-300x244.jpg` this matches `300`, `244` and `jpg`.
	 */
	private const INTERMEDIATE_IMG_PATTERN = '/-(\\d+)x(\\d+)\\.(%s)$/i';

	/**
	 * Whether logging is enabled. Useful for testing environment -- if disabled the loggers are NullLogger instances.
	 * 
	 * @var bool Whether logging is enabled.
	 */
	private $enable_logging = true;

	/**
	 * Log outputs.
	 */
	public const LOG_OUTPUTS = [
		'CLI'          => 'cli',
		'FILE'         => 'file', 
		'CLI_AND_FILE' => 'cli_and_file',
	];

	/**
	 * CLI logger.
	 * 
	 * @var Logger|NullLogger CLI logger.
	 */
	protected $logger_cli;

	/**
	 * File logger.
	 * 
	 * @var Logger|NullLogger File logger.
	 */
	protected $logger_file;

	/**
	 * Constructor.
	 * 
	 * @param bool $enable_logging Whether to enable logging (defaults to true).
	 */
	public function __construct( bool $enable_logging = true ) {
		$this->enable_logging = $enable_logging;

		// Initialize with NullLogger if logging is disabled.
		if ( false === $this->enable_logging ) {
			$this->logger_cli  = new NullLogger();
			$this->logger_file = new NullLogger();
		}
	}

	/**
	 * Registers CLI commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-post-image-downloader scan-existing-urls',
			[ $this, 'cmd_scan_existing_urls' ],
			[
				'shortdesc' => 'Searches all existing image URLs in <img src> attributes in posts and pages, and lists hostnames and extensions. Useful to ascertain existing hostnames to include/exclude from downloading.',
				[
					[
						'type'        => 'flag',
						'name'        => 'include-non-image-urls',
						'description' => 'By default, only scans image URLs, but if this flag is set, it will also scan non-image URLs.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-types',
						'description' => 'Optional CSV Post types to scan. Defaults are `post,page`',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-statuses',
						'description' => 'Optional CSV Post statuses to scan. Defaults is `publish`',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-ids-csv',
						'description' => 'Specify Posts to scan with a CSV list of Post IDs.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-id-from',
						'description' => 'Specify Post IDs to scan with a from-to range.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-id-to',
						'description' => 'Specify Post IDs to scan with a from-to range.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-post-image-downloader download-images',
			[ $this, 'cmd_download_images' ],
			[
				'shortdesc' => 'Downloads all remote images to local.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Perform a dry run, making no changes.',
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'default-host-and-schema',
						'description' => 'Used for relative URLs, provide th full schema and hostname where to download these from, e.g. `https://defaulthost.com`.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'do-not-download-large-sizes',
						'description' => 'Unless this flag is set, the command will attempt to import the large sized non-scaled and non-intermediate images together with the smaller image URLs found in post_content. E.g.1. for an intermediate image https://www.mysite.com/wp-content/uploads/2025/01/img-puppy-300x244.jpg the command will attempt to import the non-intermediate image without the `-300x244` suffix https://www.mysite.com/wp-content/uploads/2025/01/img-puppy.jpg . E.g.2. for a scaled image https://www.mysite.com/wp-content/uploads/2025/01/img-kitten-scaled.jpg it will try and import the non-scaled image https://www.mysite.com/wp-content/uploads/2025/01/img-kitten.jpg . See more about intermediate images and image sizes in WordPress docs.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'do-not-download-root-relative-urls',
						'description' => 'Unless this flag is set, the command will automatically download root-relative image URLs (e.g. `/wp-content/uploads/image.jpg`) by prepending the --default-host-and-schema to them.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'do-not-download-protocol-relative-urls',
						'description' => 'Unless this flag is set, the command will automatically download protocol-relative image URLs (e.g. `//cdn.host.com/img.jpg`) by prepending https: to them.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'exclude-hosts',
						'description' => 'CSV, list of hosts to exclude downloading from. Can use a wildcard, e.g. to cover a host and all its subdomains, use these two values `google.com,*.google.com`, or for multiple domain extensions use `www.google.*`, or can even use `*.google.*` for all subdomains and all domain extensions.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'only-download-from-hosts',
						'description' => 'CSV, list of specific hosts to download images from. If provided, it will skip downloading from any other host, and if this param is provided, the `exclude-param` will not work. Can use a wildcard, e.g. to cover a host and all its subdomains, use these two values `somehost.com,*.somehost.com`, or for multiple domain extensions use `www.somehost.*`, or can even use `*.somehost.*` for all subdomains and all domain extensions.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'folder-local-files',
						'description' => 'Local folder which contains the image files. Images which are found here, get imported from local files, otherwise they get downloaded via HTTP.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-types',
						'description' => 'Optional CSV Post types to download images from. Defaults are `post,page`',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-statuses',
						'description' => 'Optional CSV Post statuses to download images from. Defaults is `publish`',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-ids-csv',
						'description' => 'CSV list of Post IDs to download images from.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-id-from',
						'description' => 'Only download images from Post IDs from-to.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-id-to',
						'description' => 'Only download images from Post IDs from-to.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-post-image-downloader download-non-images-files',
			[ $this, 'cmd_download_non_images_files' ],
			[
				'shortdesc' => 'Downloads other non-image files to local.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Perform a dry run, making no changes.',
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'default-host-and-schema',
						'description' => 'Used for relative URLs, provide the full schema and hostname where to download these from, e.g. `https://defaulthost.com`.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'extensions',
						'description' => 'Extensions to download, case insensitive. E.g. --extensions=pdf,docx,xlsx,pptx',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'do-not-download-root-relative-urls',
						'description' => 'Unless this flag is set, the command will automatically download root-relative image URLs (e.g. `/wp-content/uploads/image.jpg`) by prepending the --default-host-and-schema to them.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'do-not-download-protocol-relative-urls',
						'description' => 'Unless this flag is set, the command will automatically download protocol-relative image URLs (e.g. `//cdn.host.com/img.jpg`) by prepending https: to them.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'exclude-hosts',
						'description' => 'CSV, list of hosts to exclude downloading from. Can use a wildcard, e.g. to cover a host and all its subdomains, use these two values `google.com,*.google.com`, or for multiple domain extensions use `www.google.*`, or can even use `*.google.*` for all subdomains and all domain extensions.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'only-download-from-hosts',
						'description' => 'CSV, list of specific hosts to download images from. If provided, it will skip downloading from any other host, and if this param is provided, the `exclude-param` will not work. Can use a wildcard, e.g. to cover a host and all its subdomains, use these two values `somehost.com,*.somehost.com`, or for multiple domain extensions use `www.somehost.*`, or can even use `*.somehost.*` for all subdomains and all domain extensions.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'folder-local-files',
						'description' => 'Local folder which contains the files. Files which are found here, get imported from local files, otherwise they get downloaded via HTTP.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-types',
						'description' => 'Optional CSV Post types to download files from. Defaults are `post,page`',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-statuses',
						'description' => 'Optional CSV Post statuses to download files from. Defaults is `publish`',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-ids-csv',
						'description' => 'CSV list of Post IDs to download files from.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-id-from',
						'description' => 'Only download files from Post IDs from-to.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-id-to',
						'description' => 'Only download files from Post IDs from-to.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for `newspack-post-image-downloader scan-existing-urls`.
	 * See command description in \NewspackPostImageDownloader\Downloader::register_commands.
	 *
	 * @param array $pos_args   CLI arguments.
	 * @param array $assoc_args CLI associative arguments.
	 */
	public function cmd_scan_existing_urls( $pos_args, $assoc_args ) {
		$include_non_image_urls = isset( $assoc_args['include-non-image-urls'] ) ? true : false;
		$post_types             = isset( $assoc_args['post-types'] ) ? explode( ',', $assoc_args['post-types'] ) : [ 'post', 'page' ];
		$post_statuses          = isset( $assoc_args['post-statuses'] ) ? explode( ',', $assoc_args['post-statuses'] ) : [ 'publish' ];
		$post_ids_specific      = isset( $assoc_args['post-ids-csv'] ) ? explode( ',', $assoc_args['post-ids-csv'] ) : null;
		$post_id_from           = isset( $assoc_args['post-id-from'] ) ? (int) $assoc_args['post-id-from'] : null;
		$post_id_to             = isset( $assoc_args['post-id-to'] ) ? (int) $assoc_args['post-id-to'] : null;

		$time_start = microtime( true );
		$this->init_loggers( __FUNCTION__ );
		if ( ( $post_ids_specific && $post_id_from ) || ( $post_ids_specific && $post_id_to ) ) {
			$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::ERROR, '❗ Sorry, you can either specify a CSV list of Post IDs, or a range of Post IDs.' );
			exit;
		}
		if ( ( $post_id_from && ( null === $post_id_to ) ) || ( ( null === $post_id_from ) && $post_id_to ) ) {
			$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::ERROR, '❗ Both --post-id-from and --post-id-to are required when using ranges.' );
			exit;
		}
		
		// Prepare the CSV file.
		$csv_file = __FUNCTION__ . '.csv';
		if ( $this->file_exists( $csv_file ) ) {
			unlink( $csv_file ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink.
		}
		$csv_file_handle = fopen( $csv_file, 'w' ); // phpcs:ignore -- WordPress.WP.AlternativeFunctions.file_system_operations_fopen.
		fputcsv( $csv_file_handle, [ 'post_id', 'hostname', 'extension', 'url' ], ',', '"', '' ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv.

		// Get post IDs.
		$post_ids = $this->get_post_ids( $post_ids_specific, $post_id_from, $post_id_to, $post_types, $post_statuses );
		if ( empty( $post_ids ) ) {
			$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::WARNING, 'No Posts found... 🤔' );
			exit;
		}
		MemoryCleanupHook::cleanup();

		// Scan posts.
		$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( 'Getting %s URLs from %d posts...', $include_non_image_urls ? 'all' : 'image', count( $post_ids ) ) );
		$cli_quick_output = [
			'hostnames'  => [],
			'extensions' => [],
		];
		foreach ( $post_ids as $key_post_id => $post_id ) {
			MemoryCleanupHook::cleanup( 0, $key_post_id + 1, 10 );

			$post_content = $this->get_post_content( $post_id );
			if ( ! $post_content ) {
				continue;
			}

			// Get all URLs, or just image `src`s.
			$urls = $include_non_image_urls
				? $this->get_all_urls_from_html( $post_content )
				: $this->get_all_img_srcs_from_html( $post_content );
			if ( empty( $urls ) ) {
				continue;
			}
			foreach ( $urls as $url ) {
				$url = trim( $url );

				// Validate URL.
				$is_absolute          = $this->is_url_absolute( $url );
				$is_root_relative     = $this->is_url_root_relative( $url );
				$is_protocol_relative = $this->is_url_protocol_relative( $url );
				if ( ! $this->is_url_valid( $url ) ) {
					continue;
				}

				// Get hostname and extension.
				$url_check = null;
				if ( $is_absolute || $is_root_relative ) {
					$url_check = $url;
				} elseif ( $is_protocol_relative ) {
					$url_check = 'https:' . $url;
				} else {
					// Unsupported URL, like `src="data:image/svg+xml;base64"` or invalid URLs.
					fputcsv( $csv_file_handle, [ $post_id, '', '', $url ], ',', '"', '' ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv.
					continue;
				}

				// Get hostname.
				$parsed   = wp_parse_url( $url_check );
				$hostname = $parsed['host'] ?? null;

				// Get extension.
				$extension = $this->get_url_extension( $url_check );

				// Add to CSV.
				fputcsv( $csv_file_handle, [ $post_id, $hostname, $extension, $url, ], ',', '"', '' ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv.

				// Add to quick CLI output variable.
				if ( ! empty( $hostname ) && ! in_array( $hostname, $cli_quick_output['hostnames'] ) ) {
					$cli_quick_output['hostnames'][] = $hostname;
				}
				if ( ! empty( $extension ) && ! in_array( $extension, $cli_quick_output['extensions'] ) ) {
					$cli_quick_output['extensions'][] = $extension;
				}
			}
		}

		fclose( $csv_file_handle );

		// Tada!
		$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::INFO, sprintf( '👉 Found %d total URL hosts', count( $cli_quick_output['hostnames'] ) ) );
		if ( count( $cli_quick_output['hostnames'] ) > 0 ) {
			$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::INFO, sprintf( '- %s', implode( "\n- ", $cli_quick_output['hostnames'] ) ) );
		}
		$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::INFO, sprintf( '👉 Found %d total URL extensions -- note that technically extension is everything after the last dot, so use the following list to determine which ones are valid ', count( $cli_quick_output['extensions'] ) ) );
		if ( count( $cli_quick_output['extensions'] ) > 0 ) {
			$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::INFO, sprintf( '- %s', implode( ', ', $cli_quick_output['extensions'] ) ) );
		}
		$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::INFO, sprintf( 'Done in %d mins! 🙌 ', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
		$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::INFO, sprintf( '👉 See CSV file for full list of post IDs, URLs, hostnames and extensions: %s ', $csv_file ) );
	}

	/**
	 * Callable for `newspack-post-image-downloader import-images`.
	 * See command description in \NewspackPostImageDownloader\Downloader::register_commands.
	 *
	 * @param array $pos_args   CLI arguments.
	 * @param array $assoc_args CLI associative arguments.
	 */
	public function cmd_download_images( $pos_args, $assoc_args ) {
		$dry_run                                = isset( $assoc_args['dry-run'] ) ? true : false;
		$do_not_download_large_sizes            = isset( $assoc_args['do-not-download-large-sizes'] ) ? true : false;
		$do_not_download_root_relative_urls     = isset( $assoc_args['do-not-download-root-relative-urls'] ) ? true : false;
		$do_not_download_protocol_relative_urls = isset( $assoc_args['do-not-download-protocol-relative-urls'] ) ? true : false;
		$post_types                             = isset( $assoc_args['post-types'] ) ? explode( ',', $assoc_args['post-types'] ) : [ 'post', 'page' ];
		$post_statuses                          = isset( $assoc_args['post-statuses'] ) ? explode( ',', $assoc_args['post-statuses'] ) : [ 'publish' ];
		$post_ids_specific                      = isset( $assoc_args['post-ids-csv'] ) ? explode( ',', $assoc_args['post-ids-csv'] ) : null;
		$post_id_from                           = isset( $assoc_args['post-id-from'] ) ? (int) $assoc_args['post-id-from'] : null;
		$post_id_to                             = isset( $assoc_args['post-id-to'] ) ? (int) $assoc_args['post-id-to'] : null;
		$hosts_excluded                         = isset( $assoc_args['exclude-hosts'] ) ? explode( ',', $assoc_args['exclude-hosts'] ) : null;
		$only_download_from_hosts               = isset( $assoc_args['only-download-from-hosts'] ) ? explode( ',', $assoc_args['only-download-from-hosts'] ) : null;
		$default_host_and_schema                = isset( $assoc_args['default-host-and-schema'] ) ? rtrim( $assoc_args['default-host-and-schema'], '/' ) : null;
		$folder_local_files                     = isset( $assoc_args['folder-local-files'] ) ? rtrim( $assoc_args['folder-local-files'], '/' ) : null;

		$this->init_loggers( __FUNCTION__ );
		if ( ( $post_ids_specific && $post_id_from ) || ( $post_ids_specific && $post_id_to ) ) {
			$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::ERROR, '❗ Sorry, you can either specify a CSV list of Post IDs, or a range of Post IDs.' );
			exit;
		}
		if ( ( $post_id_from && ( null === $post_id_to ) ) || ( ( null === $post_id_from ) && $post_id_to ) ) {
			$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::ERROR, '❗ Both --post-id-from and --post-id-to are required when using ranges.' );
			exit;
		}
		if ( $only_download_from_hosts && $hosts_excluded ) {
			$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::ERROR, '❗ When providing the `--only-download-from-hosts` param, do not use the `--exclude-hosts` at the same time.' );
			exit;
		}
		if ( ! $do_not_download_root_relative_urls && ! $default_host_and_schema ) {
			$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::ERROR, '❗ When downloading root relative URLs (will be downloaded by default unless `--do-not-download-root-relative-urls` param is used), you must also provide the `--default-host-and-schema` param.' );
			exit;
		}

		// Prepare the CSV file.
		$csv_file = __FUNCTION__ . '__downloaded.csv';
		if ( $this->file_exists( $csv_file ) ) {
			unlink( $csv_file ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink.
		}
		$csv_file_handle = fopen( $csv_file, 'w' ); // phpcs:ignore -- WordPress.WP.AlternativeFunctions.file_system_operations_fopen.
		fputcsv( $csv_file_handle, [ 'post_id', 'url_original', 'attachment_id', 'url_downloaded' ], ',', '"', '' ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv.

		global $wpdb;
		$time_start        = microtime( true );
		$hosts_excluded    = $this->get_all_excluded_hosts( $hosts_excluded );
		$attachments_logic = new Attachments();

		$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::DEBUG, 'Fetching Posts...' );
		$post_ids = $this->get_post_ids( $post_ids_specific, $post_id_from, $post_id_to, $post_types, $post_statuses );
		if ( empty( $post_ids ) ) {
			$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::WARNING, 'No Posts found... 🤔' );
			exit;
		}
		MemoryCleanupHook::cleanup();

		foreach ( $post_ids as $key_post => $post_id ) {
			$post_content = $this->get_post_content( $post_id );
			if ( empty( $post_content ) ) {
				$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::WARNING, sprintf( 'No post content in post ID %d, skipping...', $post_id ) );
				continue;
			}

			MemoryCleanupHook::cleanup( 0, $key_post + 1, 10 );

			// Extract attributes from all the `<img>`s.
			$img_data = ( new Crawler( $post_content ) )->filterXpath( '//img' )->extract( [ 'src', 'title', 'alt' ] );
			
			// Convert numeric array to associative array for consistent access.
			$img_data = array_map(
				function ( $item ) {
					return [
						'src'   => $item[0],
						'title' => $item[1],
						'alt'   => $item[2],
					];
				},
				$img_data
			);

			$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( '👉 (%d/%d) post ID %d, found %d images...', $key_post + 1, count( $post_ids ), $post_id, count( $img_data ) ) );
			if ( empty( $img_data ) ) {
				continue;
			}

			// Extend the $img_data array with the full sized image URLs to be downloaded (non-intermediate and non-scaled versions of the image).
			if ( ! $do_not_download_large_sizes ) {
				$img_data = $this->include_full_sized_images_in_img_data( $img_data, $post_id );
			}

			// Download images in post content.
			$post_content_updated = $post_content;
			foreach ( $img_data as $img_datum ) {
				$src                  = trim( $img_datum['src'] );
				$src_non_intermediate = ! empty( $img_datum['src_non_intermediate'] ) ? trim( $img_datum['src_non_intermediate'] ) : null;
				$src_non_scaled       = ! empty( $img_datum['src_non_scaled'] ) ? trim( $img_datum['src_non_scaled'] ) : null;
				$title                = trim( $img_datum['title'] );
				$alt                  = trim( $img_datum['alt'] );

				// Boolean flags for simpler logic.
				$is_scaled            = ! empty( $src_non_scaled );
				$is_intermediate      = ! empty( $src_non_intermediate );
				$is_root_relative     = $this->is_url_root_relative( $src );
				$is_protocol_relative = $this->is_url_protocol_relative( $src );
				$is_absolute          = $this->is_url_absolute( $src );

				// Validate URL.
				if ( ! $this->is_url_valid( $src ) ) {
					$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::ERROR, sprintf( "❗ Invalid URL type '%s'", $src ), [ 'post_id' => $post_id ] );
					continue;
				}

				// Skip root-relative URLs if the flag is set.
				if ( $is_root_relative && $do_not_download_root_relative_urls ) {
					$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( "✖ skipping, root-relative URL '%s'", $src ), [ 'post_id' => $post_id ] );
					continue;
				}
				// Skip protocol-relative URLs if the flag is set.
				if ( $is_protocol_relative && $do_not_download_protocol_relative_urls ) {
					$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( "✖ skipping, protocol-relative URL '%s'", $src ), [ 'post_id' => $post_id ] );
					continue;
				}
				
				// Skip if $src was already used/downloaded and replaced.
				if ( false === strpos( $post_content_updated, $src ) && false === strpos( $post_content_updated, esc_attr( $src ) ) ) {
					$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( "✖ skipping, src already downloaded '%s'", $src ), [ 'post_id' => $post_id ] );
					continue;
				}

				// Filter `src` by host.
				if ( $only_download_from_hosts ) {
					// Skip if relative URL host (--default-host-and-schema) does not match $only_download_from_hosts.
					if ( $is_root_relative && ! $this->does_uri_match_host( $default_host_and_schema, $only_download_from_hosts ) ) {
						$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( "✖ skipping root-relative URL, off target host '%s'", $src ), [ 'post_id' => $post_id ] );
						continue;
					} elseif ( $is_protocol_relative && ! $this->does_uri_match_host( 'https:' . $src, $only_download_from_hosts ) ) {
						// Skip if protocol-relative URL host does not match $only_download_from_hosts.
						$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( "✖ skipping protocol-relative URL, off target host '%s'", $src ), [ 'post_id' => $post_id ] );
						continue;
					} elseif ( $is_absolute && ! $this->does_uri_match_host( $src, $only_download_from_hosts ) ) {
						// Skip if absolute URL host does not match $only_download_from_hosts.
						$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( "✖ skipping, off target host '%s'", $src ), [ 'post_id' => $post_id ] );
						continue;
					}
				} elseif ( $hosts_excluded ) {
					// Skip if relative URL host matches $hosts_excluded.
					if ( $is_root_relative && $this->does_uri_match_host( $default_host_and_schema, $hosts_excluded ) ) {
						$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( "✖ skipping root-relative URL, excluded host '%s'", $src ), [ 'post_id' => $post_id ] );
						continue;
					} elseif ( $is_protocol_relative && $this->does_uri_match_host( 'https:' . $src, $hosts_excluded ) ) {
						// Skip if protocol-relative URL host matches $hosts_excluded.
						$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( "✖ skipping protocol-relative URL, excluded host '%s'", $src ), [ 'post_id' => $post_id ] );
						continue;
					} elseif ( $is_absolute && $this->does_uri_match_host( $src, $hosts_excluded ) ) {
						// Skip if absolute URL host matches $hosts_excluded.
						$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( "✖ skipping, excluded host '%s'", $src ), [ 'post_id' => $post_id ] );
						continue;
					}
				}

				/**
				 * A ranked list of this image's srcs, setting the highest quality version at the beginning.
				 * 
				 * @param array $srcs_ranked An ordered array of $img_datum's srcs.
				 * 
				 * The goal is to loop over this $srcs_ranked list, and import just the first one into the Media Library (the highest quality image),
				 * and then just physically download the smaller srcs to the same path where the attachment was imported (it's possible that the
				 * thumbnails are differently registered than on the remote site, so the smaller srcs might need to be downloaded too).
				 */
				$srcs_ranked = [];
				// Add the larger and highest quality versions of the src to the list first.
				if ( $is_scaled ) {
					if ( $src_non_scaled ) {
						$srcs_ranked[] = $src_non_scaled;
					}
				} elseif ( $is_intermediate ) {
					if ( $src_non_intermediate ) {
						$srcs_ranked[] = $src_non_intermediate;
					}
				}
				// Add the "original" src to end of list.
				$srcs_ranked[] = $src;

				// Import the largest image into the Media Library (the first ranked src), then just physically also download the rest of them in the same path.
				$imported        = false;
				$attachment_id   = null;
				$imported_folder = null;
				$src_local       = null;
				foreach ( $srcs_ranked as $key_src_ranked => $src_ranked ) {

					// Get the fully qualified import path of this ranked src file (either from local folder, or from remote URL).
					$img_import_path = $this->get_fully_qualified_img_import_or_download_path( $src_ranked, $folder_local_files, $default_host_and_schema );
					if ( is_wp_error( $img_import_path ) ) {
						$this->log(
							self::LOG_OUTPUTS['CLI_AND_FILE'],
							LogLevel::ERROR,
							sprintf( '❗ Error getting image path: %s', $img_import_path->get_error_message() ),
							[
								'post_id' => $post_id,
								'src'     => $src_ranked,
							] 
						);

						// Try importing the next ranked src.
						continue;
					}

					// If `title` or `alt` are empty, use the image filename (without extension).
					$basename              = basename( $img_import_path );
					$filename_parts        = pathinfo( $basename );
					$filename_wo_extension = $filename_parts['filename'];
					$title_to_use          = empty( $title ) ? $filename_wo_extension : $title;
					$alt_to_use            = empty( $alt ) ? $filename_wo_extension : $alt;

					// Try and import the first (largest) ranked src into the Media Library.
					if ( ! $imported ) {
						// Import the image into the Media Library.
						$attachment_id = null;
						if ( ! $dry_run ) {
							$attachment_id = $attachments_logic->import_external_file( $img_import_path, $title_to_use, null, null, $alt_to_use, $post_id );
							if ( is_wp_error( $attachment_id ) ) {
								// CSV, log error.
								fputcsv( $csv_file_handle, [ $post_id, $src_ranked, sprintf( 'ERROR: %s', $attachment_id->get_error_message() ), '' ], ',', '"', '' ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv.

								$this->log(
									self::LOG_OUTPUTS['CLI_AND_FILE'],
									LogLevel::ERROR,
									sprintf( "❗ Error while importing '%s': '%s'", $img_import_path, $attachment_id->get_error_message() ),
									[
										'post_id' => $post_id,
										'src'     => $src_ranked,
									] 
								);
								continue;
							}
							$imported = true;
							$this->log(
								self::LOG_OUTPUTS['CLI_AND_FILE'],
								LogLevel::INFO,
								sprintf( "✓ Imported '%s' as attachment ID %d", $img_import_path, $attachment_id ),
								[
									'post_id' => $post_id,
									'src'     => $src_ranked,
								] 
							);

							// Imported attachment URL.
							$attachment_url = wp_get_attachment_url( $attachment_id );

							// Get the target directory where the attachment was saved.
							$target_path     = get_attached_file( $attachment_id );
							$imported_folder = dirname( $target_path );

							// If this is the $src, note new the new URL.
							if ( $src == $src_ranked ) {
								$src_local = $attachment_url;
							}

							// CSV, add the imported image to the CSV file.
							fputcsv( $csv_file_handle, [ $post_id, $src_ranked, $attachment_id, $attachment_url ], ',', '"', '' ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv.
						} else {
							// Dry run.
							$imported        = true;
							$upload_dir      = wp_upload_dir();
							$imported_folder = $upload_dir['path'];
							$this->log(
								self::LOG_OUTPUTS['CLI'],
								LogLevel::INFO,
								sprintf( "[Dry Run] Imported src '%s' as attachment ID '%s'", $src_ranked, 'dry_run' ),
								[
									'post_id' => $post_id,
									'src'     => $src_ranked,
								] 
							);
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
								self::LOG_OUTPUTS['CLI_AND_FILE'],
								LogLevel::DEBUG,
								sprintf( "✖ skipping, file '%s' already exists '%s'", $img_import_path, $download_path ),
								[
									'post_id' => $post_id,
									'src'     => $src_ranked,
								] 
							);
							continue;
						}

						// Download the rest of the $src_ranked files to the same folder where the attachment was imported.
						if ( ! $dry_run ) {
							$downloaded = $this->download_file_to_dir( $src_ranked, $target_path );
							// Handle error.
							if ( is_wp_error( $downloaded ) ) {
								// CSV, log error.
								fputcsv( $csv_file_handle, [ $post_id, $src_ranked, sprintf( 'ERROR: %s', $downloaded->get_error_message() ), '' ], ',', '"', '' ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv.

								$this->log(
									self::LOG_OUTPUTS['CLI_AND_FILE'],
									LogLevel::ERROR,
									sprintf( "❗ Failed to download '%s' to '%s': '%s'", $src_ranked, $target_path, $downloaded->get_error_message() ),
									[
										'post_id' => $post_id,
										'src'     => $src_ranked,
									] 
								);
								continue;
							}

							// Downloaded URL.
							$downloaded_url = dirname( wp_get_attachment_url( $attachment_id ) ) . '/' . basename( $src );

							// If this is the $src, and it has been downloaded, note new the new local URL (same folder/URL path as imported $attachment_id, i.e. $src's filename).
							if ( $src == $src_ranked ) {
								$src_local = $downloaded_url;
							}
							
							// CSV, add the imported image to the CSV file.
							fputcsv( $csv_file_handle, [ $post_id, $src_ranked, '', $downloaded_url ], ',', '"', '' ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv.

							$this->log(
								self::LOG_OUTPUTS['CLI_AND_FILE'],
								LogLevel::INFO,
								sprintf( "✓ Downloaded '%s' to '%s'", $src_ranked, $downloaded ),
								[
									'post_id' => $post_id,
									'src'     => $src_ranked,
								] 
							);
						} else {
							// Dry run.
							$this->log(
								self::LOG_OUTPUTS['CLI'],
								LogLevel::INFO,
								sprintf( "[Dry Run] Downloaded src '%s' to '%s'", $src_ranked, $download_path ),
								[
									'post_id' => $post_id,
									'src'     => $src_ranked,
								] 
							);
						}
					}
				}

				// Replace URL $src with $src_local.
				if ( $src_local ) {
					$this->log(
						self::LOG_OUTPUTS['CLI_AND_FILE'],
						LogLevel::DEBUG,
						sprintf( "Replacing in post_content from src '%s' to new '%s'", $src, $src_local ),
						[
							'post_id' => $post_id,
							'src'     => $src,
						] 
					);
					
					// Replace $src (escaped and non-escaped) in Post content with new imported/downloaded $src_local.
					$post_content_updated = str_replace( [ esc_attr( $src ), $src ], $src_local, $post_content_updated );
				} elseif ( ! $dry_run ) {
					// If no version of the image was imported or downloaded, log an error.
					$this->log(
						self::LOG_OUTPUTS['CLI_AND_FILE'],
						LogLevel::ERROR,
						sprintf( "❗ Failed to import or download any version of '%s'", $src ),
						[
							'post_id' => $post_id,
							'src'     => $src,
						] 
					);
				}
			}

			// Update the Post content.
			if ( ! $dry_run && $post_content_updated != $post_content ) {
				$wpdb->update( $wpdb->prefix . 'posts', [ 'post_content' => $post_content_updated ], [ 'ID' => $post_id ] ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.NoCaching.
				$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::INFO, sprintf( '✓ Post content updated 👍' ), [ 'post_id' => $post_id ] );
			} elseif ( $dry_run && $post_content_updated != $post_content ) {
				$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::INFO, sprintf( '✓ Post content updated 👍' ), [ 'post_id' => $post_id ] );
			}
		}

		fclose( $csv_file_handle );

		// Required for the $wpdb->update() to sink in.
		wp_cache_flush();

		// Closing remarks.
		$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::INFO, sprintf( 'All done!  🙌  Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
		$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::INFO, sprintf( 'See the CSV file for results: %s', $csv_file ) );
	}

	/**
	 * Callable for `newspack-post-image-downloader import-non-images-files`.
	 * See command description in \NewspackPostImageDownloader\Downloader::register_commands.
	 *
	 * @param array $pos_args   CLI arguments.
	 * @param array $assoc_args CLI associative arguments.
	 */
	public function cmd_download_non_images_files( $pos_args, $assoc_args ) {
		$dry_run                                = isset( $assoc_args['dry-run'] ) ? true : false;
		$extensions                             = explode( ',', $assoc_args['extensions'] );
		$do_not_download_root_relative_urls     = isset( $assoc_args['do-not-download-root-relative-urls'] ) ? true : false;
		$do_not_download_protocol_relative_urls = isset( $assoc_args['do-not-download-protocol-relative-urls'] ) ? true : false;
		$post_types                             = isset( $assoc_args['post-types'] ) ? explode( ',', $assoc_args['post-types'] ) : [ 'post', 'page' ];
		$post_statuses                          = isset( $assoc_args['post-statuses'] ) ? explode( ',', $assoc_args['post-statuses'] ) : [ 'publish' ];
		$post_ids_specific                      = isset( $assoc_args['post-ids-csv'] ) ? explode( ',', $assoc_args['post-ids-csv'] ) : null;
		$post_id_from                           = isset( $assoc_args['post-id-from'] ) ? (int) $assoc_args['post-id-from'] : null;
		$post_id_to                             = isset( $assoc_args['post-id-to'] ) ? (int) $assoc_args['post-id-to'] : null;
		$hosts_excluded                         = isset( $assoc_args['exclude-hosts'] ) ? explode( ',', $assoc_args['exclude-hosts'] ) : null;
		$only_download_from_hosts               = isset( $assoc_args['only-download-from-hosts'] ) ? explode( ',', $assoc_args['only-download-from-hosts'] ) : null;
		$default_host_and_schema                = isset( $assoc_args['default-host-and-schema'] ) ? rtrim( $assoc_args['default-host-and-schema'], '/' ) : null;
		$folder_local_files                     = isset( $assoc_args['folder-local-files'] ) ? rtrim( $assoc_args['folder-local-files'], '/' ) : null;

		$this->init_loggers( __FUNCTION__ );
		if ( empty( $extensions ) ) {
			$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::ERROR, '❗ Must provide a list of extensions to download, e.g. --extensions=pdf,docx,xlsx,pptx' );
			exit;
		}
		if ( ( $post_ids_specific && $post_id_from ) || ( $post_ids_specific && $post_id_to ) ) {
			$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::ERROR, '❗ Sorry, you can either specify a CSV list of Post IDs, or a range of Post IDs.' );
			exit;
		}
		if ( ( $post_id_from && ( null === $post_id_to ) ) || ( ( null === $post_id_from ) && $post_id_to ) ) {
			$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::ERROR, '❗ Both --post-id-from and --post-id-to are required when using ranges.' );
			exit;
		}
		if ( $only_download_from_hosts && $hosts_excluded ) {
			$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::ERROR, '❗ When providing the `--only-download-from-hosts` param, do not use the `--exclude-hosts` at the same time.' );
			exit;
		}
		if ( ! $do_not_download_root_relative_urls && ! $default_host_and_schema ) {
			$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::ERROR, '❗ When downloading root relative URLs (will be downloaded by default unless `--do-not-download-root-relative-urls` param is used), you must also provide the `--default-host-and-schema` param.' );
			exit;
		}

		// Prepare the CSV file.
		$csv_file = __FUNCTION__ . '.csv';
		if ( $this->file_exists( $csv_file ) ) {
			unlink( $csv_file ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink.
		}
		$csv_file_handle = fopen( $csv_file, 'w' ); // phpcs:ignore -- WordPress.WP.AlternativeFunctions.file_system_operations_fopen.
		fputcsv( $csv_file_handle, [ 'post_id', 'url_original', 'result', 'attachment_id', 'url_downloaded' ], ',', '"', '' ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv.

		global $wpdb;
		$time_start        = microtime( true );
		$hosts_excluded    = $this->get_all_excluded_hosts( $hosts_excluded );
		$attachments_logic = new Attachments();
		
		// Normalize extensions to lowercase.
		$extensions = array_map( 'strtolower', $extensions );

		// Validate extensions.
		foreach ( $extensions as $extension ) {
			if ( ! wp_check_filetype( $extension ) ) {
				$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::ERROR, sprintf( "❗ Extension '%s' is presently not supported by your site's Media Library (@see `wp_check_filetype()`). Please exclude it from the list of extensions to download, or extend the list of supported extensions.", $extension ) );
				exit;
			}
		}

		// Fetch Posts.
		$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::DEBUG, 'Fetching Posts...' );
		$post_ids = $this->get_post_ids( $post_ids_specific, $post_id_from, $post_id_to, $post_types, $post_statuses );
		if ( empty( $post_ids ) ) {
			$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::WARNING, 'No Posts found... 🤔' );
			exit;
		}
		MemoryCleanupHook::cleanup();

		// Loop posts and download URLs with extensions.
		foreach ( $post_ids as $key_post => $post_id ) {
			// Progress.
			$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::INFO, sprintf( '(%d)/(%d) post ID %d', $key_post + 1, count( $post_ids ), $post_id ) );

			// Get post content.
			$post_content = $this->get_post_content( $post_id );
			if ( empty( $post_content ) ) {
				$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::WARNING, sprintf( 'No post content in post ID %d, skipping...', $post_id ) );
				continue;
			}
			MemoryCleanupHook::cleanup();

			// Get all URLs with extensions from HTML.
			$urls_all        = $this->get_all_urls_from_html( $post_content );
			$urls_extensions = [];
			foreach ( $urls_all as $url ) {
				// Validate URL.
				if ( ! $this->is_url_valid( $url ) ) {
					$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::ERROR, sprintf( "❗ Invalid URL type '%s'", $url ), [ 'post_id' => $post_id ] );
					continue;
				}

				// Filter by extension.
				$url_extension = $this->get_url_extension( $url );
				if ( ! empty( $url_extension ) && in_array( strtolower( $url_extension ), $extensions, true ) ) {
					$urls_extensions[] = $url;
				}
			}
			if ( empty( $urls_extensions ) ) {
				continue;
			}

			// Download the filtered URLs.
			$post_content_updated = $post_content;
			foreach ( $urls_extensions as $url ) {
				$url = trim( $url );

				$is_absolute          = $this->is_url_absolute( $url );
				$is_root_relative     = $this->is_url_root_relative( $url );
				$is_protocol_relative = $this->is_url_protocol_relative( $url );

				// Skip root-relative URLs if the flag is set.
				if ( $is_root_relative && $do_not_download_root_relative_urls ) {
					$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( "✖ skipping, root-relative URL '%s'", $url ), [ 'post_id' => $post_id ] );
					continue;
				}
				// Skip protocol-relative URLs if the flag is set.
				if ( $is_protocol_relative && $do_not_download_protocol_relative_urls ) {
					$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( "✖ skipping, protocol-relative URL '%s'", $url ), [ 'post_id' => $post_id ] );
					continue;
				}

				// Filter URL by host.
				if ( $only_download_from_hosts ) {
					// Skip if root-relative URL host (--default-host-and-schema) does not match $only_download_from_hosts.
					if ( $is_root_relative && ! $this->does_uri_match_host( $default_host_and_schema, $only_download_from_hosts ) ) {
						$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( "✖ skipping root-relative URL, off target host '%s'", $url ), [ 'post_id' => $post_id ] );
						continue;
					} elseif ( $is_protocol_relative && ! $this->does_uri_match_host( 'https:' . $url, $only_download_from_hosts ) ) {
						$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( "✖ skipping protocol-relative URL, off target host '%s'", $url ), [ 'post_id' => $post_id ] );
						continue;
					} elseif ( $is_absolute && ! $this->does_uri_match_host( $url, $only_download_from_hosts ) ) {
						// Skip if absolute URL host does not match $only_download_from_hosts.
						$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( "✖ skipping, off target host '%s'", $url ), [ 'post_id' => $post_id ] );
						continue;
					}
				} elseif ( $hosts_excluded ) {
					// Skip if root-relative URL host matches $hosts_excluded.
					if ( $is_root_relative && $this->does_uri_match_host( $default_host_and_schema, $hosts_excluded ) ) {
						$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( "✖ skipping root-relative URL, excluded host '%s'", $url ), [ 'post_id' => $post_id ] );
						continue;
					} elseif ( $is_protocol_relative && $this->does_uri_match_host( 'https:' . $url, $hosts_excluded ) ) {
						$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( "✖ skipping protocol-relative URL, excluded host '%s'", $url ), [ 'post_id' => $post_id ] );
						continue;
					} elseif ( $is_absolute && $this->does_uri_match_host( $url, $hosts_excluded ) ) {
						// Skip if absolute URL host matches $hosts_excluded.
						$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( "✖ skipping, excluded host '%s'", $url ), [ 'post_id' => $post_id ] );
						continue;
					}
				}

				// Skip if $url was already used/downloaded and replaced.
				if ( false === strpos( $post_content_updated, $url ) && false === strpos( $post_content_updated, esc_attr( $url ) ) ) {
					$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::DEBUG, sprintf( "✖ skipping, URL already downloaded '%s'", $url ), [ 'post_id' => $post_id ] );
					continue;
				}

				// Get the fully qualified import path of this file (either from local folder, or from remote URL).
				$file_import_path = $this->get_fully_qualified_img_import_or_download_path( $url, $folder_local_files, $default_host_and_schema );
				if ( is_wp_error( $file_import_path ) ) {
					$this->log( self::LOG_OUTPUTS['CLI_AND_FILE'], LogLevel::ERROR, sprintf( "❗ Error getting file path for '%s', error: %s", $url, $file_import_path->get_error_message() ), [ 'post_id' => $post_id ] );
					continue;
				}

				// Set title to image filename (no extension).
				$basename       = basename( $file_import_path );
				$filename_parts = pathinfo( $basename );
				$title          = $filename_parts['filename'];

				// Clean up memory.
				MemoryCleanupHook::cleanup();

				// Download the file.
				$attachment_id = ! $dry_run ? $attachments_logic->import_external_file( $file_import_path, $title, null, null, null, $post_id ) : 'dry_run';
				if ( is_wp_error( $attachment_id ) ) {
					// CSV, log error.
					fputcsv( $csv_file_handle, [ $post_id, $url, sprintf( "Error downloading '%s': (%s) %s", $file_import_path, $attachment_id->get_error_code(), $attachment_id->get_error_message() ), '', '' ], ',', '"', '' ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv.

					$this->log(
						self::LOG_OUTPUTS['CLI_AND_FILE'],
						LogLevel::ERROR,
						sprintf( "❗ Error importing file '%s', error: (%s) %s", $file_import_path, $attachment_id->get_error_code(), $attachment_id->get_error_message() ),
						[
							'url'     => $url,
							'post_id' => $post_id,
						] 
					);
					continue;
				}

				$this->log(
					self::LOG_OUTPUTS['CLI_AND_FILE'],
					LogLevel::INFO,
					sprintf( "✓ %sImported file '%s' to '%s'", $dry_run ? '[Dry Run] ' : '', $file_import_path, $attachment_id ),
					[
						'url'     => $url,
						'post_id' => $post_id,
					] 
				);

				// Replace $url with imported URL.
				$url_imported         = ! $dry_run ? wp_get_attachment_url( $attachment_id ) : 'dry_run';
				$post_content_updated = str_replace( $url, $url_imported, $post_content_updated );

				// CSV, add the imported file to the CSV file.
				fputcsv( $csv_file_handle, [ $post_id, $url, 'success', $attachment_id, $url_imported ], ',', '"', '' ); // phpcs:ignore -- WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv.
			}

			// Update the Post content.
			if ( ! $dry_run && $post_content_updated != $post_content ) {
				$wpdb->update( $wpdb->prefix . 'posts', [ 'post_content' => $post_content_updated ], [ 'ID' => $post_id ] ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.NoCaching.
				$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::INFO, sprintf( '✓ Post content updated 👍' ), [ 'post_id' => $post_id ] );
			} elseif ( $dry_run && $post_content_updated != $post_content ) {
				$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::INFO, sprintf( '✓ Post content updated 👍' ), [ 'post_id' => $post_id ] );
			}
		}

		fclose( $csv_file_handle );

		// Required for the $wpdb->update() to sink in.
		wp_cache_flush();

		// Closing remarks.
		$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::INFO, sprintf( 'All done!  🙌  Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
		$this->log( self::LOG_OUTPUTS['CLI'], LogLevel::INFO, sprintf( 'See the CSV file for results: %s', $csv_file ) );
	}

	/**
	 * Gets the extension of a URL path segment (i.e. the URLs file extension).
	 * Works on absolute, root-relative, and protocol-relative URLs.
	 *
	 * @param string $url URL.
	 * @return string Extension.
	 */
	public function get_url_extension( string $url ): string {
		
		// Cleanups.
		$url = trim( $url );
		// Remove query parameters.
		$url = preg_replace( '/\?.*$/', '', $url );
		// Remove fragment identifier.
		$url = preg_replace( '/#.*$/', '', $url );
		// Remove trailing slash.
		$url = rtrim( $url, '/' );
		
		// Get the path segment.
		$parsed_url = wp_parse_url( $url );
		$path       = $parsed_url['path'] ?? '';
		if ( empty( $path ) || '/' === $path ) {
			return '';
		}
		
		// Get extension of the path segment.
		$extension = pathinfo( $path, PATHINFO_EXTENSION );

		return $extension;
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
	 * @param array $img_data {
	 *      Array of image data, each element is a subarray with three keys.
	 *      @type string 'src'   The image's URL.
	 *      @type string 'title' The image's title attribute.
	 *      @type string 'alt'   The image's alt attribute.
	 * }
	 * @param int   $post_id      The Post ID.
	 * @return array {
	 *      Array of image data same as input, but with additional full-sized image elements if found.
	 *      @type string 'src'                   The image's URL.
	 *      @type ?string 'src_non_intermediate' Added by this function. The image's URL without the intermediate suffix, or null.
	 *      @type ?string 'src_non_scaled'       Added by this function. The image's URL without the scaled suffix, or null.
	 *      @type string 'title'                 The image's title attribute.
	 *      @type string 'alt'                   The image's alt attribute.
	 * }
	 */
	public function include_full_sized_images_in_img_data( array $img_data, int $post_id ): array {
		$img_data_with_large = [];
		foreach ( $img_data as $key_img_datum => $img_datum ) {
			$src = trim( $img_datum['src'] );

			// Validate URL.
			if ( ! $this->is_url_valid( $src ) ) {
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
				$this->log(
					self::LOG_OUTPUTS['CLI_AND_FILE'],
					LogLevel::DEBUG,
					sprintf( "… adding large non-intermediate image '%s'", $src_non_intermediate ),
					[
						'post_id' => $post_id,
						'src'     => $src,
					] 
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
				$this->log(
					self::LOG_OUTPUTS['CLI_AND_FILE'],
					LogLevel::DEBUG,
					sprintf( "… adding large non-scaled image '%s'", $src_non_scaled ),
					[
						'post_id' => $post_id,
						'src'     => $src,
					] 
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
	 * E.g. 2. if provided a scaled image: https://www.mysite.com/wp-content/uploads/2025/01/huge_puppy-scaled.jpg
	 * it will return null, because it's already non-intermediate: null
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
	 * E.g. 3. if provided an intermediate image (not scaled): https://www.mysite.com/wp-content/uploads/2025/01/regular_puppy-300x244.jpg
	 * it will return null, because it's not a scaled image: null
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
	 * @param string $src                     Img `src` URI.
	 * @param string $folder_local_files      Path to local folder where image files can be found at.
	 * @param string $default_host_and_schema Default schema+host used to download relative referenced URLs.
	 *                                        e.g. if you provide the value 'https://dl_host`, it will attempt to download.
	 *                                        a relative `src="/path/img.jpg"` from 'https://dl_host/path/img.jpg'.
	 *
	 * @return string|WP_Error Either a full path to a local image file, or a fully qualified HTTP path to download the image from.
	 */
	public function get_fully_qualified_img_import_or_download_path( $src, $folder_local_files = null, $default_host_and_schema = null ): string|WP_Error {
		$img_import_path = null;

		// Get the path (without host), and remove possible query params.
		$src_path = wp_parse_url( $src )['path'];
		if ( false === $src_path ) {
			return new WP_Error(
				'invalid_url_type',
				sprintf( "Could not parse URL '%s'.", esc_url( $src ) ),
				wp_json_encode( [ 'src' => $src ] )
			);
		}

		// Try and get the local image file.
		$is_local_file = false;
		if ( $folder_local_files ) {
			$img_local_file_path = $folder_local_files . '/' . ltrim( $src_path, '/' );
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
		 *      - a protocol-relative URL, e.g. '//cdn.host.com/img.jpg'
		 *      - a relative reference from root, e.g. '/segment/img.jpg', and uses the `--default-host-and-schema` to try
		 *        and download it
		 */
		$is_absolute          = $this->is_url_absolute( $src );
		$is_root_relative     = $this->is_url_root_relative( $src );
		$is_protocol_relative = $this->is_url_protocol_relative( $src );

		// If no local image file is used, get a fully qualified remote URI.
		if ( $is_absolute ) {
			// A good old absolute URL.
			$img_import_path = $src;
		} elseif ( $is_protocol_relative ) {
			// Transform protocol-relative URL to absolute URL.
			$img_import_path = 'https:' . $src;
		} elseif ( $is_root_relative ) {
			if ( ! $default_host_and_schema ) {
				return new WP_Error(
					'no_default_host_provided',
					sprintf( "Could not download relative src '%s' since --default-host-and-schema was not provided.", esc_url( $src ) ),
					wp_json_encode( [ 'src' => $src ] )
				);
			}
			// Use the `--default-host-and-schema` to try and download a root-relative URL.
			$img_import_path = $default_host_and_schema
				. ( ( 0 !== strpos( strtolower( $src ), '/' ) ) ? '/' : '' )
				. $src;
		} else {
			return new WP_Error(
				'invalid_url_type',
				sprintf( "Could not download unsupported src type '%s'.", esc_url( $src ) ),
				wp_json_encode( [ 'src' => $src ] )
			);
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
		$hosts = [];

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
	 * Checks if a URL is absolute, i.e. starting with `http` or `https`, e.g. `https://example.com/path`.
	 * Note: this method will trim the input URL before checking if it's absolute.
	 * 
	 * @param string $url URL.
	 * 
	 * @return bool True if the URL is absolute, false otherwise.
	 */
	public function is_url_absolute( string $url ): bool {
		$url = trim( $url );
		return 0 === strpos( strtolower( $url ), 'http' );
	}

	/**
	 * Checks if a URL is root-relative, i.e. starting with `/`, e.g. `/path/to/image.jpg`.
	 * Note: this method will trim the input URL before checking if it's root-relative.
	 * 
	 * @param string $url URL.
	 * 
	 * @return bool True if the URL is relative, false otherwise.
	 */
	public function is_url_root_relative( string $url ): bool {
		$url                    = trim( $url );
		$ends_with_single_slash = 0 === strpos( $url, '/' );
		$is_protocol_relative   = $this->is_url_protocol_relative( $url );
		
		return $ends_with_single_slash && ! $is_protocol_relative;
	}

	/**
	 * Checks if a URL is protocol-relative, i.e. starting with `//`, e.g. `//example.com/path`.
	 * Note: this method will trim the input URL before checking if it's protocol-relative.
	 * 
	 * @param string $url URL.
	 * 
	 * @return bool True if the URL is relative, false otherwise.
	 */
	public function is_url_protocol_relative( string $url ): bool {
		$url = trim( $url );
		return ( 0 === strpos( $url, '//' ) ) && ! ( 0 === strpos( $url, '///' ) );
	}

	/**
	 * Checks if a URL is a valid root-relative (starting with `/`), protocol-relative (starting with `//`), or absolute (starting with `http` or `https`) URL.
	 * Note: this method will trim the input URL before checking if it's valid.
	 * 
	 * @param string $url URL.
	 * 
	 * @return bool True for root-relative, protocol-relative, or absolute URLs, false for other types of URLs like `data:image/svg+xml;base64` or
	 *              invalid URLs like `http://example.com:invalid`.
	 */
	public function is_url_valid( string $url ): bool {
		$url = trim( $url );

		$is_root_relative     = $this->is_url_root_relative( $url );
		$is_protocol_relative = $this->is_url_protocol_relative( $url );
		$is_absolute          = $this->is_url_absolute( $url );

		$is_valid = false;
		if ( $is_root_relative ) {
			$is_valid = ! empty( $url ) && 
				0 === strpos( $url, '/' ) && 
				0 !== strpos( $url, '//' ) && 
				null === wp_parse_url( $url, PHP_URL_HOST ) && 
				false !== filter_var( 'https://example.com' . $url, FILTER_VALIDATE_URL );
		} elseif ( $is_protocol_relative ) {
			$is_valid = ! empty( $url ) && 
				0 === strpos( $url, '//' ) && 
				false !== filter_var( 'https:' . $url, FILTER_VALIDATE_URL );
		} elseif ( $is_absolute ) {
			$is_valid = ! empty( $url ) && 
				false !== filter_var( $url, FILTER_VALIDATE_URL ) && 
				in_array( wp_parse_url( $url, PHP_URL_SCHEME ), [ 'http', 'https' ], true );
		}

		return $is_valid;
	}

	/**
	 * Fetches `ID`s of posts table.
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
	 * @return array|null An array with post IDs.
	 */
	public function get_post_ids(
		$post_ids = null,
		$post_id_from = null,
		$post_id_to = null,
		$post_types = [ 'post', 'page' ],
		$post_statuses = [ 'publish' ]
	): ?array {
		global $wpdb;

		$types_placeholders    = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$statuses_placeholders = implode( ',', array_fill( 0, count( $post_statuses ), '%s' ) );
		$query                 = "SELECT ID FROM {$wpdb->prefix}posts WHERE post_type IN ( $types_placeholders ) AND post_status IN ( $statuses_placeholders ) ";
		$prepare_args          = [];
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
		// phpcs:ignore -- false positive, using prepared query with placeholders.
		$query = $wpdb->prepare( $query, $prepare_args );

		// phpcs:ignore -- statement fully prepared.
		return $wpdb->get_col( $query );
	}

	/**
	 * Fetches `post_content` from the posts table.
	 * 
	 * @param int $post_id Post ID.
	 * 
	 * @return string|null Post content.
	 */
	public function get_post_content( $post_id ): ?string {
		global $wpdb;

		$result = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->prefix}posts WHERE ID = %d", $post_id ) ); // phpcs:ignore -- WordPress.DB.DirectDatabaseQuery.NoCaching.

		return $result;
	}

	/**
	 * Gets all the unique <img> `src` and `srcset` URLs from HTML.
	 *
	 * @param string $html HTML.
	 *
	 * @return array
	 */
	public function get_all_img_srcs_from_html( $html ) {
		$img_srcs = [];

		$crawler = ( new Crawler( $html ) )->filter( 'img' );
		if ( 0 == $crawler->count() ) {
			return $img_srcs;
		}

		foreach ( $crawler->getIterator() as $node ) {
			// Extract src attribute.
			$src = $node->getAttribute( 'src' );
			if ( ! empty( $src ) ) {
				$img_srcs[] = $src;
			}

			// Extract srcset attribute and parse individual URLs.
			$srcset = $node->getAttribute( 'srcset' );
			if ( ! empty( $srcset ) ) {
				$srcset_urls = $this->get_urls_from_srcset( $srcset );
				$img_srcs    = array_merge( $img_srcs, $srcset_urls );
			}

			// Extract data-srcset attribute and parse individual URLs.
			$data_srcset = $node->getAttribute( 'data-srcset' );
			if ( ! empty( $data_srcset ) ) {
				$data_srcset_urls = $this->get_urls_from_srcset( $data_srcset );
				$img_srcs         = array_merge( $img_srcs, $data_srcset_urls );
			}
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
	 * Gets unique URLs from HTML.
	 * Supported URL types are absolute, root-relative (e.g. `/path/to/image.jpg`) and protocol-relative (e.g. `//example.com/path/to/image.jpg`), but not `data` B64 or page-relative URLs (e.g. `../page/image.jpg`).
	 * First fetches URLs from various DOM attributes to get the most relevant URLs, and then additionally extracts just potential remaining 
	 * absolute URLs from text content. This hybrid approach tries to optimize relevance and accuracy.
	 *
	 * @param string $html HTML.
	 *
	 * @return array An array of unique and valid/supported URLs.
	 */
	public function get_all_urls_from_html( string $html ): array {
		$urls    = [];
		$crawler = new Crawler( $html );
		
		/**
		 * First, extract URLs from various DOM attributes.
		 */
		// Simple attributes that contain single URLs.
		$simple_attributes = [
			'href',
			'src',
			'data-src',
			'data-background',
			'data-lazy-src',
			'data-url',
			'data-href',
			'poster',
			'data-poster',
			'data-thumbnail',
			'data-preview',
		];
		foreach ( $simple_attributes as $attribute ) {
			$crawler = $crawler->filter( "[{$attribute}]" );
			foreach ( $crawler->getIterator() as $node ) {
				$urls[] = $node->getAttribute( $attribute );
			}
		}
		// Handle srcsets with  multiple URLs.
		$srcset_attributes = [ 'srcset', 'data-srcset' ];
		foreach ( $srcset_attributes as $attribute ) {
			$crawler = $crawler->filter( "[{$attribute}]" );
			foreach ( $crawler->getIterator() as $node ) {
				// Extract URLs from srcset.
				$srcset = $node->getAttribute( $attribute );
				if ( ! empty( $srcset ) ) {
					$urls = array_merge( $urls, $this->get_urls_from_srcset( $srcset ) );
				}
			}
		}

		/**
		 * Then additionally extract just the absolute URLs from entire HTML text.
		 */
		$urls = array_merge( $urls, $this->get_absolute_urls_from_text( $html ) );

		// Clean up results.
		$urls = array_unique( $urls );
		$urls = array_filter(
			$urls,
			function ( $url ) {
				return ! empty( $url );
			}
		);
		$urls = array_map( 'trim', $urls );
		
		// Validate URLs.
		$urls = array_filter(
			$urls,
			function ( $url ) {
				return $this->is_url_valid( $url );
			}
		);
		
		// Update keys.
		$urls = array_values( $urls );

		return $urls;
	}

	/**
	 * Extracts only absolute URLs from plain text content.
	 *
	 * @param string $text The text content.
	 * @return array Array of absolute URLs.
	 */
	public function get_absolute_urls_from_text( string $text ): array {
		$urls = [];
		if ( empty( $text ) ) {
			return $urls;
		}

		// Match only absolute URLs (http/https).
		preg_match_all( '/https?:\/\/[^\s"<>]+/i', $text, $matches );
		if ( isset( $matches[0] ) ) {
			$urls = array_merge( $urls, $matches[0] );
		}

		return $urls;
	}

	/**
	 * Extract URLs from srcset attribute.
	 *
	 * @param string $srcset The srcset attribute value.
	 * @return array Array of URLs.
	 */
	public function get_urls_from_srcset( string $srcset ): array {
		$urls = [];
		if ( empty( $srcset ) ) {
			return $urls;
		}

		// Split srcset by commas and extract URLs.
		$parts = explode( ',', $srcset );
		foreach ( $parts as $part ) {
			$part = trim( $part );
			// Extract URL before any space (which might be followed by width/height descriptors).
			$url = preg_replace( '/\s.*$/', '', $part );
			if ( ! empty( $url ) ) {
				$urls[] = $url;
			}
		}

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
	public function get_image_extension_from_binary_file( $filename ) {
		$extension = null;

		$mime_type       = ( new \finfo( FILEINFO_MIME ) )->file( $filename );
		$mime_img_prefix = 'image/';
		if ( is_string( $mime_type ) && ( 0 === strpos( $mime_type, $mime_img_prefix ) ) ) {
			$extension = substr( $mime_type, strlen( $mime_img_prefix ), strpos( $mime_type, ';' ) - strlen( $mime_img_prefix ) );
		}

		return $extension ?? null;
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
	 * Log to one or both loggers based on parameters.
	 * 
	 * Basic CLI colors are:
	 *   - LogLevel::DEBUG -- no color
	 *   - LogLevel::INFO -- green
	 *   - LogLevel::WARNING -- orange
	 *   - LogLevel::ERROR -- red
	 *   and more levels and colors exist, @see \Bramus\Monolog\Formatter\ColoredLineFormatter::getColorScheme().
	 *
	 * @param string $output   Log output, allowed values self::LOG_OUTPUTS.
	 * @param string $level    \Psr\Log\LogLevel: debug, info, notice, warning, error, critical, alert, emergency.
	 * @param string $message  Log message.
	 * @param array  $context  Log context.
	 */
	private function log( string $output, string $level, string $message, array $context = [] ): void {
		// Is level "basic" -- DEBUG, INFO or NOTICE? Will not output these levels in CLI.
		$is_level_basic = in_array( $level, [ LogLevel::NOTICE, LogLevel::INFO, LogLevel::DEBUG ] );

		switch ( $output ) {
			case 'cli':
				$this->logger_cli->$level( ( $is_level_basic ? '' : $level . ': ' ) . $message, $context );
				break;
			case 'file':
				$this->logger_file->$level( $message, $context );   
				break;
			case 'cli_and_file':
				$this->logger_cli->$level( ( $is_level_basic ? '' : $level . ': ' ) . $message, $context );
				$this->logger_file->$level( $message, $context );
				break;
		}
	}

	/**
	 * Sets up the loggers.
	 *
	 * @param string|null $logger_slug        CLI and file logger slug.
	 * @param bool        $log_init_timestamp Whether to log the timestamp of the start of the loggers.
	 */
	protected function init_loggers( ?string $logger_slug = null, bool $log_init_timestamp = true ): void {
		// If logging is disabled, NullLogger have been set instances.
		if ( false === $this->enable_logging ) {
			return;
		}

		// If slug is not provided, do not initialize the loggers.
		if ( is_null( $logger_slug ) ) {
			return;
		}

		/**
		 * File logger uses no color and full timestamp.
		 */
		$formatter_file = new LineFormatter(
			'[%datetime%] %level_name%: %message% %context%' . PHP_EOL,
			'Y-m-d H:i:s.u',
			true,
			true
		);
		$logger_file    = new Logger( $logger_slug . '_file' );
		$handler_file   = new StreamHandler( $logger_slug . '.log' );
		$handler_file->setFormatter( $formatter_file );
		$logger_file->pushHandler( $handler_file );
		$this->logger_file = $logger_file;

		/**
		 * CLI logger uses color, does not output a timestamp, and does not include level_name.
		 */
		$formatter_cli = new ColoredLineFormatter(
			null,
			'%message% %context%' . PHP_EOL,
			'Y-m-d H:i:s.u',
			true,
			true
		);
		$logger_cli    = new Logger( $logger_slug . '_cli' );
		$handler_cli   = new StreamHandler( 'php://stdout' );
		$handler_cli->setFormatter( $formatter_cli );
		$logger_cli->pushHandler( $handler_cli );
		$this->logger_cli = $logger_cli;

		// If $log_init_timestamp is set, write an init logging message with a timestampto both loggers.
		if ( $log_init_timestamp ) {
			$this->log( 'cli_and_file', LogLevel::DEBUG, '[Init logging at ' . gmdate( 'Y-m-d H:i:s.u' ) . ']' );
		}
	}
}
