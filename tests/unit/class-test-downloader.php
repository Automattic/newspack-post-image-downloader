<?php
/**
 * Class DownloaderTest.
 *
 * @package Newspack_Post_Image_Downloader
 */

namespace NewspackPostImageDownloaderTest;

use WP_UnitTestCase;
use WP_Error;
use NewspackPostImageDownloader\Downloader;
use RuntimeException;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Sample test case.
 */
class Test_Downloader extends WP_UnitTestCase {

	/**
	 * Downloader object.
	 *
	 * @var Downloader
	 */
	private $downloader;

	/**
	 * Override setUp.
	 *
	 * @throws RuntimeException In case a temp dir could not have been created.
	 */
	protected function setUp(): void {
		$this->downloader = new Downloader( false );
	}

	/**
	 * Plain absolute HTTP src. No other params.
	 */
	public function test_absolute_src_no_local_images_folder() {
		$src                     = 'http://host.com/path/img.jpg';
		$folder_local_images     = null;
		$default_host_and_schema = null;

		$img_import_path = $this->downloader->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_host_and_schema );

		$this->assertSame( $src, $img_import_path );
	}

	/**
	 * Plain absolute HTTP src. Path to folder with local images is provided, but the image file is not found there.
	 */
	public function test_absolute_src_no_local_file() {
		$src                     = 'http://host.com/path/img.jpg';
		$folder_local_images     = '/tmp/mock';
		$default_host_and_schema = null;

		$img_import_path = $this->downloader->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_host_and_schema );

		$this->assertSame( $src, $img_import_path );
	}

	/**
	 * Plain absolute HTTP src. Path to folder with local images is provided, and the image file is found locally.
	 */
	public function test_absolute_src_with_local_file() {
		$src                     = 'http://host.com/path/img.jpg';
		$folder_local_images     = '/tmp/mock';
		$default_host_and_schema = null;
		$local_file              = $folder_local_images . '/path/img.jpg';

		// Get partial mock for Downloader::file_exists method, to avoid writing to disk.
		$partial_mock = $this->create_downloader_partial_mock_with_file_exists_method( $local_file, true );

		$img_import_path = $partial_mock->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_host_and_schema );

		$this->assertSame( $local_file, $img_import_path );
	}

	/**
	 * Relative reference to host root. But a WP_Error gets returned if the $default_host_and_schema param is not provided.
	 */
	public function test_relative_ref_to_root_src_no_local_images_folder_returns_wp_error() {
		$src                     = '/path/img.jpg';
		$folder_local_images     = null;
		$default_host_and_schema = null;

		$img_import_path = $this->downloader->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_host_and_schema );

		$this->assertInstanceOf( WP_Error::class, $img_import_path );
	}

	/**
	 * Relative reference to host root. All needed params are provided, but the image file is not found there.
	 */
	public function test_relative_ref_to_root_src_no_local_file() {
		$src                     = '/path/img.jpg';
		$folder_local_images     = '/tmp/mock';
		$default_host_and_schema = 'https://deault/download/from';

		$img_import_path = $this->downloader->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_host_and_schema );

		$this->assertSame( $default_host_and_schema . $src, $img_import_path );
	}

	/**
	 * Relative reference to host root. The image file is found locally.
	 */
	public function test_relative_ref_to_root_src_with_local_file() {
		$src                     = '/path/img.jpg';
		$folder_local_images     = '/tmp/mock';
		$default_host_and_schema = 'https://deault/download/from';
		$local_file              = $folder_local_images . '/path/img.jpg';

		// Get partial mock for Downloader::file_exists method, to avoid writing to disk.
		$partial_mock = $this->create_downloader_partial_mock_with_file_exists_method( $local_file, true );

		$img_import_path = $partial_mock->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_host_and_schema );

		$this->assertSame( $local_file, $img_import_path );
	}

	/**
	 * Relative reference src. But a WP_Error gets returned if the $default_host_and_schema param is not provided.
	 */
	public function test_relative_ref_src_no_local_images_folder_returns_wp_error() {
		$src                     = 'path/img.jpg';
		$folder_local_images     = null;
		$default_host_and_schema = null;

		$img_import_path = $this->downloader->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_host_and_schema );

		$this->assertInstanceOf( WP_Error::class, $img_import_path );
	}

	/**
	 * Relative reference src. All needed params are provided, but the image file is not found there.
	 */
	public function test_relative_ref_src_no_local_file() {
		$src                     = '/path/img.jpg';
		$folder_local_images     = '/tmp/mock';
		$default_host_and_schema = 'https://deault/download/from';

		$img_import_path = $this->downloader->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_host_and_schema );

		$this->assertSame( $default_host_and_schema . $src, $img_import_path );
	}

	/**
	 * Relative reference src. The image file is found locally.
	 */
	public function test_relative_ref_src_with_local_file() {
		$src                     = 'path/img.jpg';
		$folder_local_images     = '/tmp/mock';
		$default_host_and_schema = 'https://deault/download/from';
		$local_file              = $folder_local_images . '/path/img.jpg';

		// Get partial mock for Downloader::file_exists method, to avoid writing to disk.
		$partial_mock = $this->create_downloader_partial_mock_with_file_exists_method( $local_file, true );

		$img_import_path = $partial_mock->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_host_and_schema );

		$this->assertSame( $local_file, $img_import_path );
	}

	/**
	 * Tests the `does_uri_match_host` function.
	 *
	 * @dataProvider providerUriHostMatching
	 *
	 * @param string $src             URI whose host we're testing.
	 * @param array  $hosts           Array of hosts to check for.
	 * @param bool   $result_expected Expected result.
	 */
	public function test_uri_host_matching( $src, $hosts, $result_expected ) {
		$result_actual = $this->downloader->does_uri_match_host( $src, $hosts );

		$this->assertSame( $result_expected, $result_actual );
	}

	/**
	 * Tests the `get_non_intermediate_img_url` function.
	 *
	 * @dataProvider providerGetNonIntermediateImgUrl
	 *
	 * @param string      $src             The input image URL.
	 * @param string|null $result_expected The expected non-intermediate image URL, or null.
	 */
	public function test_get_non_intermediate_img_url( $src, $result_expected ) {
		$result_actual = $this->downloader->get_non_intermediate_img_url( $src );
		$this->assertSame( $result_expected, $result_actual );
	}

	/**
	 * Tests the `get_non_scaled_img_url` function.
	 *
	 * @dataProvider providerGetNonScaledImgUrl
	 *
	 * @param string      $src             The input image URL.
	 * @param string|null $result_expected The expected non-scaled image URL, or null.
	 */
	public function test_get_non_scaled_img_url( $src, $result_expected ) {
		$result_actual = $this->downloader->get_non_scaled_img_url( $src );
		$this->assertSame( $result_expected, $result_actual );
	}

	/**
	 * Tests the include_full_sized_images_in_img_data function.
	 *
	 * @dataProvider providerIncludeFullSizedImagesInImgData
	 *
	 * @param string $src The input image URL.
	 * @param array  $result_expected The expected result array for the image data.
	 */
	public function test_include_full_sized_images_in_img_data( $src, $result_expected ) {
		$img_data      = [
			[
				'src'   => $src,
				'title' => '',
				'alt'   => '',
			],
		];
		$result_actual = $this->downloader->include_full_sized_images_in_img_data( $img_data, 1, 1, 1 );
		$this->assertSame( $result_expected, $result_actual[0] );
	}

	/**
	 * Tests the `get_all_img_srcs_from_html` function.
	 *
	 * @dataProvider providerGetAllImgSrcsFromHtml
	 *
	 * @param string $html            The HTML content to parse.
	 * @param array  $result_expected The expected array of image src URLs.
	 */
	public function test_get_all_img_srcs_from_html( $html, $result_expected ) {
		$result_actual = $this->downloader->get_all_img_srcs_from_html( $html );
		$this->assertSame( $result_expected, $result_actual );
	}

	/**
	 * Tests the `get_all_urls_from_html` function.
	 *
	 * @dataProvider providerGetAllUrlsFromHtml
	 *
	 * @param string $html            The HTML content to parse.
	 * @param array  $result_expected The expected array of URLs.
	 */
	public function test_get_all_urls_from_html( $html, $result_expected ) {
		$result_actual = $this->downloader->get_all_urls_from_html( $html );
		$this->assertSame( $result_expected, $result_actual );
	}

	/**
	 * Tests the `get_all_urls_from_html` function with validation disabled.
	 *
	 * @dataProvider providerGetAllUrlsFromHtmlNoValidation
	 *
	 * @param string $html            The HTML content to parse.
	 * @param array  $result_expected The expected array of URLs.
	 */
	public function test_get_all_urls_from_html_no_validation( $html, $result_expected ) {
		$result_actual = $this->downloader->get_all_urls_from_html( $html, false );
		$this->assertSame( $result_expected, $result_actual );
	}

	/**
	 * Tests the `get_urls_from_srcset` function.
	 *
	 * @dataProvider providerGetUrlsFromSrcset
	 *
	 * @param string $srcset          The srcset attribute value.
	 * @param array  $result_expected The expected array of URLs.
	 */
	public function test_get_urls_from_srcset( $srcset, $result_expected ) {
		$result_actual = $this->downloader->get_urls_from_srcset( $srcset );
		$this->assertSame( $result_expected, $result_actual );
	}

	/**
	 * Tests the `get_absolute_urls_from_text` function.
	 *
	 * @dataProvider providerGetAbsoluteUrlsFromText
	 *
	 * @param string $text            The text content to parse.
	 * @param array  $result_expected The expected array of absolute URLs.
	 */
	public function test_get_absolute_urls_from_text( $text, $result_expected ) {
		$result_actual = $this->downloader->get_absolute_urls_from_text( $text );
		$this->assertSame( $result_expected, $result_actual );
	}

	/**
	 * Tests the `get_url_extension` function.
	 *
	 * @dataProvider providerGetUrlExtension
	 *
	 * @param string $url             The URL to extract extension from.
	 * @param string $result_expected The expected file extension.
	 */
	public function test_get_url_extension( $url, $result_expected ) {
		$result_actual = $this->downloader->get_url_extension( $url );
		$this->assertSame( $result_expected, $result_actual );
	}

	/**
	 * Tests the `is_url_absolute` function.
	 *
	 * @dataProvider providerIsUrlAbsolute
	 *
	 * @param string $url             The URL to test.
	 * @param bool   $result_expected The expected result.
	 */
	public function test_is_url_absolute( $url, $result_expected ) {
		$result_actual = $this->downloader->is_url_absolute( $url );
		$this->assertSame( $result_expected, $result_actual );
	}

	/**
	 * Tests the `is_url_root_relative` function.
	 *
	 * @dataProvider providerIsUrlRootRelative
	 *
	 * @param string $url             The URL to test.
	 * @param bool   $result_expected The expected result.
	 */
	public function test_is_url_root_relative( $url, $result_expected ) {
		$result_actual = $this->downloader->is_url_root_relative( $url );
		$this->assertSame( $result_expected, $result_actual );
	}

	/**
	 * Tests the `is_url_protocol_relative` function.
	 *
	 * @dataProvider providerIsUrlProtocolRelative
	 *
	 * @param string $url             The URL to test.
	 * @param bool   $result_expected The expected result.
	 */
	public function test_is_url_protocol_relative( $url, $result_expected ) {
		$result_actual = $this->downloader->is_url_protocol_relative( $url );
		$this->assertSame( $result_expected, $result_actual );
	}

	/**
	 * Tests the `is_url_valid` function.
	 *
	 * @dataProvider providerIsUrlValid
	 *
	 * @param string $url             The URL to test.
	 * @param bool   $result_expected The expected result.
	 */
	public function test_is_url_valid( $url, $result_expected ) {
		$result_actual = $this->downloader->is_url_valid( $url );
		$this->assertSame( $result_expected, $result_actual );
	}

	/**
	 * Tests the `update_img_tag_for_new_attachment` function.
	 *
	 * @dataProvider providerUpdateImgTagForNewAttachment
	 *
	 * @param string $html            The HTML content to process.
	 * @param string $src_local       The src URL to match.
	 * @param int    $attachment_id   The attachment ID to use.
	 * @param string $result_expected The expected HTML output.
	 */
	public function test_update_img_tag_for_new_attachment( $html, $src_local, $attachment_id, $result_expected ) {
		$result_actual = $this->downloader->update_img_tag_for_new_attachment( $html, $src_local, $attachment_id );
		$this->assertSame( $result_expected, $result_actual );
	}

	/**
	 * Creates a partial mock of the Downloader class, and mocks the `file_exists` method with expected input argument and response.
	 * In case of a different input argument, mock will return null.
	 *
	 * @param string $local_file Input argument for the `file_exists` method.
	 * @param bool   $response   Expected result in case of this input argument.
	 *
	 * @return MockObject Partial mock.
	 */
	private function create_downloader_partial_mock_with_file_exists_method( $local_file, $response ) {
		$partial_mock = $this->getMockBuilder( Downloader::class )
							->setMethods( [ 'file_exists' ] )
							->getMock();

		$partial_mock->expects( $this->any() )
					->method( 'file_exists' )
					->will(
						$this->returnCallback(
							function ( $arg ) use ( $local_file, $response ) {
								return ( $local_file === $arg ) ? $response : null;
							}
						)
					);

		return $partial_mock;
	}

	/**
	 * Changes object's method accessibility to public and returns the reflection method.
	 *
	 * @param object $class_object Object whose method accessibility is changed.
	 * @param string $method_name  Method name.
	 * @return \ReflectionMethod The reflection method object.
	 */
	private function make_object_method_public( $class_object, $method_name ) {
		$reflector = new ReflectionObject( $class_object );
		$method    = $reflector->getMethod( $method_name );
		$method->setAccessible( true );
		return $method;
	}

	/**
	 * DataProvider for test_uri_host_matching.
	 *
	 * @return array[]
	 */
	public function providerUriHostMatching() {
		return [
			[
				'https://host1.com/path/img.jpg',
				[ 'host1.com' ],
				true,
			],
			[
				'    https://host1.com/path/with-spaces.jpg    ',
				[ 'host1.com' ],
				true,
			],
			[
				'https://host1.com/path/img.jpg',
				[ 'host2.com' ],
				false,
			],
			[
				'https://host1.com/path/img.jpg',
				[ '*.host1.com' ],
				false,
			],
			[
				'https://host1.com/path/img.jpg',
				[ 'host1.*' ],
				true,
			],
			[
				'https://host1.com/path/img.jpg',
				[ '*.host1.*' ],
				false,
			],
			[
				'https://www.host1.com/path/img.jpg',
				[ '*.host1.com' ],
				true,
			],
			[
				'https://www.host1.com/path/img.jpg',
				[ 'www.host1.*' ],
				true,
			],
			[
				'https://www.host1.com/path/img.jpg',
				[ 'www.host2.*' ],
				false,
			],
		];
	}

	/**
	 * DataProvider for test_get_non_intermediate_img_url.
	 *
	 * @return array[]
	 */
	public function providerGetNonIntermediateImgUrl() {
		return [
			// E.g. 1. intermediate image: returns non-intermediate.
			[
				'https://www.example.com/wp-content/uploads/2025/01/kitten-300x244.jpg',
				'https://www.example.com/wp-content/uploads/2025/01/kitten.jpg',
			],
			// E.g. 3. non-intermediate image: returns null.
			[
				'https://www.example.com/wp-content/uploads/2025/01/kitten.jpg',
				null,
			],
			// E.g. 4. scaled image (not intermediate): returns null.
			[
				'https://www.example.com/wp-content/uploads/2025/01/huge_puppy-scaled.jpg',
				null,
			],
			// E.g. 5. image with query params: returns non-intermediate without query.
			[
				'https://www.example.com/wp-content/uploads/2025/01/kitten-300x244.jpg?foo=bar',
				'https://www.example.com/wp-content/uploads/2025/01/kitten.jpg',
			],
			// E.g. 6. image with spaces and query params: returns non-intermediate without query and trimmed.
			[
				'   https://www.example.com/wp-content/uploads/2025/01/kitten-300x244.jpg?foo=bar   ',
				'https://www.example.com/wp-content/uploads/2025/01/kitten.jpg',
			],
			// E.g. 7. SVG image (should not match intermediate pattern): returns null.
			[
				'https://www.example.com/wp-content/uploads/2025/01/vector-300x244.svg',
				'https://www.example.com/wp-content/uploads/2025/01/vector.svg',
			],
			// E.g. 8. non-intermediate SVG: returns null.
			[
				'https://www.example.com/wp-content/uploads/2025/01/vector.svg',
				null,
			],
		];
	}

	/**
	 * DataProvider for test_get_non_scaled_img_url.
	 *
	 * @return array[]
	 */
	public function providerGetNonScaledImgUrl() {
		return [
			// E.g. 1. scaled image: returns non-scaled.
			[
				'https://www.example.com/wp-content/uploads/2025/01/huge_puppy-scaled.jpg',
				'https://www.example.com/wp-content/uploads/2025/01/huge_puppy.jpg',
			],
			// E.g. 2. non-scaled image: returns null.
			[
				'https://www.example.com/wp-content/uploads/2025/01/regular_puppy.jpg',
				null,
			],
			// E.g. 3. scaled image with query params: returns non-scaled without query.
			[
				'https://www.example.com/wp-content/uploads/2025/01/huge_puppy-scaled.jpg?foo=bar',
				'https://www.example.com/wp-content/uploads/2025/01/huge_puppy.jpg',
			],
			// E.g. 4. scaled image with spaces and query params: returns non-scaled without query and trimmed.
			[
				'   https://www.example.com/wp-content/uploads/2025/01/huge_puppy-scaled.jpg?foo=bar   ',
				'https://www.example.com/wp-content/uploads/2025/01/huge_puppy.jpg',
			],
			// E.g. 5. scaled PNG image: returns non-scaled PNG.
			[
				'https://www.example.com/wp-content/uploads/2025/01/image-scaled.png',
				'https://www.example.com/wp-content/uploads/2025/01/image.png',
			],
			// E.g. 6. scaled WebP image: returns non-scaled WebP.
			[
				'https://www.example.com/wp-content/uploads/2025/01/image-scaled.webp',
				'https://www.example.com/wp-content/uploads/2025/01/image.webp',
			],
			// E.g. 7. scaled SVG image: returns non-scaled SVG.
			[
				'https://www.example.com/wp-content/uploads/2025/01/vector-scaled.svg',
				'https://www.example.com/wp-content/uploads/2025/01/vector.svg',
			],
			// E.g. 8. non-scaled SVG: returns null.
			[
				'https://www.example.com/wp-content/uploads/2025/01/vector.svg',
				null,
			],
			// E.g. 9. intermediate image (not scaled): returns null.
			[
				'https://www.example.com/wp-content/uploads/2025/01/kitten-300x244.jpg',
				null,
			],
		];
	}

	/**
	 * DataProvider for test_include_full_sized_images_in_img_data.
	 *
	 * @return array[]
	 */
	public function providerIncludeFullSizedImagesInImgData() {
		return [
			// Intermediate image.
			[
				'https://www.example.com/wp-content/uploads/2025/01/kitten-300x244.jpg',
				[
					'src'                  => 'https://www.example.com/wp-content/uploads/2025/01/kitten-300x244.jpg',
					'src_non_intermediate' => 'https://www.example.com/wp-content/uploads/2025/01/kitten.jpg',
					'src_non_scaled'       => null,
					'title'                => '',
					'alt'                  => '',
				],
			],
			// Scaled image.
			[
				'https://www.example.com/wp-content/uploads/2025/01/huge_puppy-scaled.jpg',
				[
					'src'                  => 'https://www.example.com/wp-content/uploads/2025/01/huge_puppy-scaled.jpg',
					'src_non_intermediate' => null,
					'src_non_scaled'       => 'https://www.example.com/wp-content/uploads/2025/01/huge_puppy.jpg',
					'title'                => '',
					'alt'                  => '',
				],
			],
			// Non-intermediate, non-scaled image.
			[
				'https://www.example.com/wp-content/uploads/2025/01/kitten.jpg',
				[
					'src'                  => 'https://www.example.com/wp-content/uploads/2025/01/kitten.jpg',
					'src_non_intermediate' => null,
					'src_non_scaled'       => null,
					'title'                => '',
					'alt'                  => '',
				],
			],
			// SVG intermediate.
			[
				'https://www.example.com/wp-content/uploads/2025/01/vector-300x244.svg',
				[
					'src'                  => 'https://www.example.com/wp-content/uploads/2025/01/vector-300x244.svg',
					'src_non_intermediate' => null,
					'src_non_scaled'       => null,
					'title'                => '',
					'alt'                  => '',
				],
			],
			// SVG non-intermediate.
			[
				'https://www.example.com/wp-content/uploads/2025/01/vector.svg',
				[
					'src'                  => 'https://www.example.com/wp-content/uploads/2025/01/vector.svg',
					'src_non_intermediate' => null,
					'src_non_scaled'       => null,
					'title'                => '',
					'alt'                  => '',
				],
			],
		];
	}

	/**
	 * DataProvider for test_get_all_img_srcs_from_html.
	 *
	 * @return array[]
	 */
	public function providerGetAllImgSrcsFromHtml() {
		return [
			// Empty HTML.
			[
				'',
				[],
			],
			// HTML with no images.
			[
				'<div>Some text</div>',
				[],
			],
			// Single image with src.
			[
				'<img src="https://example.com/image.jpg" alt="Test">',
				[ 'https://example.com/image.jpg' ],
			],
			// Multiple images with src.
			[
				'<img src="https://example.com/image1.jpg" alt="Test1"><img src="https://example.com/image2.png" alt="Test2">',
				[ 'https://example.com/image1.jpg', 'https://example.com/image2.png' ],
			],
			// Image with srcset.
			[
				'<img src="https://example.com/image.jpg" srcset="https://example.com/image-300w.jpg 300w, https://example.com/image-600w.jpg 600w" alt="Test">',
				[ 'https://example.com/image.jpg', 'https://example.com/image-300w.jpg', 'https://example.com/image-600w.jpg' ],
			],
			// Image with data-srcset.
			[
				'<img src="https://example.com/image.jpg" data-srcset="https://example.com/image-300w.jpg 300w, https://example.com/image-600w.jpg 600w" alt="Test">',
				[ 'https://example.com/image.jpg', 'https://example.com/image-300w.jpg', 'https://example.com/image-600w.jpg' ],
			],
			// Image with both srcset and data-srcset.
			[
				'<img src="https://example.com/image.jpg" srcset="https://example.com/image-300w.jpg 300w" data-srcset="https://example.com/image-600w.jpg 600w" alt="Test">',
				[ 'https://example.com/image.jpg', 'https://example.com/image-300w.jpg', 'https://example.com/image-600w.jpg' ],
			],
			// Image with empty src.
			[
				'<img src="" alt="Test">',
				[],
			],
			// Image with no src attribute.
			[
				'<img alt="Test">',
				[],
			],
			// Complex HTML with mixed content.
			[
				'<div><p>Text</p><img src="https://example.com/image1.jpg" alt="Test1"><span>More text</span><img src="https://example.com/image2.png" srcset="https://example.com/image2-300w.png 300w" alt="Test2"></div>',
				[ 'https://example.com/image1.jpg', 'https://example.com/image2.png', 'https://example.com/image2-300w.png' ],
			],
		];
	}

	/**
	 * DataProvider for test_get_all_urls_from_html.
	 *
	 * @return array[]
	 */
	public function providerGetAllUrlsFromHtml() {
		return [
			// Empty HTML.
			[
				'',
				[],
			],
			// HTML with no URLs.
			[
				'<div>Some text</div>',
				[],
			],
			// HTML with various URL attributes.
			[
				'<a href="https://example.com/link">Link</a><img src="https://example.com/image.jpg" alt="Test">',
				[ 'https://example.com/link', 'https://example.com/image.jpg' ],
			],
			// HTML with srcset URLs.
			[
				'<img src="https://example.com/image.jpg" srcset="https://example.com/image-300w.jpg 300w, https://example.com/image-600w.jpg 600w" alt="Test">',
				[ 'https://example.com/image.jpg', 'https://example.com/image-300w.jpg', 'https://example.com/image-600w.jpg' ],
			],
			// HTML with data attributes.
			[
				'<img data-src="https://example.com/lazy.jpg" data-background="https://example.com/bg.jpg" alt="Test">',
				[ 'https://example.com/lazy.jpg', 'https://example.com/bg.jpg' ],
			],
			// HTML with video poster and reversed order of attributes.
			[
				'<video poster="https://example.com/poster.jpg" src="https://example.com/video.mp4"></video>',
				// the crawler will capture "src" attribute first, then "poster" based on $simple_attributes array order.
				[ 'https://example.com/video.mp4', 'https://example.com/poster.jpg' ],
			],
			// HTML with absolute URLs in text content.
			[
				'<div>Check out https://example.com/page and http://another.com/resource</div>',
				[ 'https://example.com/page', 'http://another.com/resource' ],
			],
			// Complex HTML with mixed content.
			[
				'<div><a href="https://example.com/link">Link</a><img src="https://example.com/image.jpg" srcset="https://example.com/image-300w.jpg 300w" alt="Test"><p>Visit https://example.com/page for more info</p></div>',
				[ 'https://example.com/link', 'https://example.com/image.jpg', 'https://example.com/image-300w.jpg', 'https://example.com/page' ],
			],
			// Complex HTML with mixed elements, attributes, relative, absolute, protocol-relative, http, https...in mixed attribute order.
			[
				'
					<video poster="//example.com/poster.png"></video>
					<section data-background="http://example.com/background.jpg"></section>
					<script src="https://example.com/app.js"></script>
					<img src="/image.jpg" />
					<a href="relative/audio.mp3">relative</a>
					<a href="/absolute/audio.mp3">absolute</a>
				',
				[
					// href attribute is crawled first, but relative urls are filtered out by default: 'relative/audio.mp3' .
					'/absolute/audio.mp3', // href attribute is crawled first. Root-relative URLs are valid.
					'https://example.com/app.js', // src attribute is crawled after href.
					'/image.jpg', // src attribute is crawled after href.
					'http://example.com/background.jpg', // data-background is crawled after src, but before poster.
					'//example.com/poster.png', // poster is crawled later. Protocol-relative URLs are valid.
				],
			],
		];
	}

	/**
	 * DataProvider for test_get_all_urls_from_html_no_validation.
	 *
	 * @return array[]
	 */
	public function providerGetAllUrlsFromHtmlNoValidation() {
		return [
			// Complex HTML with mixed elements including invalid relative URLs.
			[
				'
					<video poster="//example.com/poster.png"></video>
					<section data-background="http://example.com/background.jpg"></section>
					<script src="https://example.com/app.js"></script>
					<img src="/image.jpg" />
					<a href="relative/audio.mp3">relative</a>
					<a href="/absolute/audio.mp3">absolute</a>
				',
				[
					'relative/audio.mp3', // href attribute is crawled first. Invalid URLs are returned when validation is disabled.
					'/absolute/audio.mp3', // href attribute is crawled first.
					'https://example.com/app.js', // src attribute is crawled after href.
					'/image.jpg', // src attribute is crawled after href.
					'http://example.com/background.jpg', // data-background is crawled after src, but before poster.
					'//example.com/poster.png', // poster is crawled later.
				],
			],
		];
	}

	/**
	 * DataProvider for test_get_urls_from_srcset.
	 *
	 * @return array[]
	 */
	public function providerGetUrlsFromSrcset() {
		return [
			// Empty srcset.
			[
				'',
				[],
			],
			// Single URL without descriptor.
			[
				'https://example.com/image.jpg',
				[ 'https://example.com/image.jpg' ],
			],
			// Single URL with width descriptor.
			[
				'https://example.com/image-300w.jpg 300w',
				[ 'https://example.com/image-300w.jpg' ],
			],
			// Single URL with height descriptor.
			[
				'https://example.com/image-600h.jpg 600h',
				[ 'https://example.com/image-600h.jpg' ],
			],
			// Multiple URLs with descriptors.
			[
				'https://example.com/image-300w.jpg 300w, https://example.com/image-600w.jpg 600w',
				[ 'https://example.com/image-300w.jpg', 'https://example.com/image-600w.jpg' ],
			],
			// Multiple URLs with mixed descriptors.
			[
				'https://example.com/image-300w.jpg 300w, https://example.com/image-600h.jpg 600h, https://example.com/image.jpg 1x',
				[ 'https://example.com/image-300w.jpg', 'https://example.com/image-600h.jpg', 'https://example.com/image.jpg' ],
			],
			// URLs with extra whitespace.
			[
				'  https://example.com/image-300w.jpg  300w  ,  https://example.com/image-600w.jpg  600w  ',
				[ 'https://example.com/image-300w.jpg', 'https://example.com/image-600w.jpg' ],
			],
			// URLs with query parameters.
			[
				'https://example.com/image-300w.jpg?v=1 300w, https://example.com/image-600w.jpg?v=2 600w',
				[ 'https://example.com/image-300w.jpg?v=1', 'https://example.com/image-600w.jpg?v=2' ],
			],
		];
	}

	/**
	 * DataProvider for test_get_absolute_urls_from_text.
	 *
	 * @return array[]
	 */
	public function providerGetAbsoluteUrlsFromText() {
		return [
			// Empty text.
			[
				'',
				[],
			],
			// Text with no URLs.
			[
				'Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
				[],
			],
			// Text with absolute URLs.
			[
				'Visit https://example.com/page for more information.',
				[ 'https://example.com/page' ],
			],
			// Text with multiple absolute URLs.
			[
				'Check out https://example.com/page and http://another.com/resource for details.',
				[ 'https://example.com/page', 'http://another.com/resource' ],
			],
			// Text with URLs in quotes.
			[
				'Download from "https://example.com/file.pdf" or visit "http://another.com/page".',
				[ 'https://example.com/file.pdf', 'http://another.com/page' ],
			],
			// Text with URLs and other content. Note, allowing dot to be a part of the URL because technically it's a valid URL character.
			[
				'Lorem ipsum dolor sit amet. Visit https://example.com/page for more info. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Check http://another.com/resource.',
				[ 'https://example.com/page', 'http://another.com/resource.' ],
			],
			// Text with URLs with query parameters.
			[
				'Visit https://example.com/page?param=value&other=123 for details.',
				[ 'https://example.com/page?param=value&other=123' ],
			],
			// Text with URLs with fragments.
			[
				'Go to https://example.com/page#section for the specific section.',
				[ 'https://example.com/page#section' ],
			],
			// Text with mixed URL types (should only extract absolute). Note, allowing comma and dot to be a part of the URL because technically they are valid URL characters.
			[
				'Visit https://example.com/page, /relative/path, //protocol-relative.com/path, and http://another.com/resource.',
				[ 'https://example.com/page,', 'http://another.com/resource.' ],
			],
			// Text with source-relative URLs and protocol-relative URLs. Note, allowing dot to be a part of the URL because technically it's a valid URL character.
			[
				'Visit https://example.com/page or /relative/path or //protocol-relative.com/path or http://another.com/resource.',
				[ 'https://example.com/page', 'http://another.com/resource.' ],
			],
		];
	}

	/**
	 * DataProvider for test_get_url_extension.
	 *
	 * @return array[]
	 */
	public function providerGetUrlExtension() {
		return [
			// Absolute URLs.
			[
				'https://example.com/image.jpg',
				'jpg',
			],
			[
				'https://example.com/path/to/image.png',
				'png',
			],
			[
				'https://example.com/file.pdf',
				'pdf',
			],
			[
				'https://example.com/document.docx',
				'docx',
			],
			// Root-relative URLs.
			[
				'/wp-content/uploads/image.jpg',
				'jpg',
			],
			[
				'/path/to/file.png',
				'png',
			],
			// Protocol-relative URLs.
			[
				'//cdn.example.com/image.jpg',
				'jpg',
			],
			[
				'//static.example.com/file.png',
				'png',
			],
			// URLs with query parameters.
			[
				'https://example.com/image.jpg?v=1&size=large',
				'jpg',
			],
			[
				'/path/to/file.png?width=300&height=200',
				'png',
			],
			// URLs with fragments.
			[
				'https://example.com/image.jpg#section',
				'jpg',
			],
			[
				'/path/to/file.png#top',
				'png',
			],
			// URLs with both query and fragment.
			[
				'https://example.com/image.jpg?v=1#section',
				'jpg',
			],
			// URLs without extension.
			[
				'https://example.com/page',
				'',
			],
			[
				'/path/to/page',
				'',
			],
			// URLs ending with slash.
			[
				'https://example.com/path/',
				'',
			],
			[
				'/path/to/directory/',
				'',
			],
			// URLs with multiple dots.
			[
				'https://example.com/file.name.jpg',
				'jpg',
			],
			[
				'/path/to/file.name.png',
				'png',
			],
			// URLs with uppercase extensions.
			[
				'https://example.com/image.JPG',
				'JPG',
			],
			[
				'/path/to/file.PNG',
				'PNG',
			],
		];
	}

	/**
	 * DataProvider for test_is_url_absolute.
	 *
	 * @return array[]
	 */
	public function providerIsUrlAbsolute() {
		return [
			// Valid absolute URLs.
			[
				'https://example.com/page',
				true,
			],
			[
				'http://example.com/page',
				true,
			],
			[
				'HTTPS://EXAMPLE.COM/PAGE',
				true,
			],
			[
				'HTTP://EXAMPLE.COM/PAGE',
				true,
			],
			// Non-absolute URLs.
			[
				'/relative/path',
				false,
			],
			[
				'//protocol-relative.com/path',
				false,
			],
			[
				'relative/path',
				false,
			],
			[
				'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwIiBoZWlnaHQ9IjEwMCI+PC9zdmc+',
				false,
			],
			[
				'mailto:user@example.com',
				false,
			],
			[
				'tel:+1234567890',
				false,
			],
			// Edge cases.
			[
				'',
				false,
			],
			[
				'   https://example.com/page   ',
				true,
			],
		];
	}

	/**
	 * DataProvider for test_is_url_root_relative.
	 *
	 * @return array[]
	 */
	public function providerIsUrlRootRelative() {
		return [
			// Valid root-relative URLs.
			[
				'/path/to/page',
				true,
			],
			[
				'/image.jpg',
				true,
			],
			[
				'/',
				true,
			],
			[
				'/path/with/query?param=value',
				true,
			],
			[
				'/path/with/fragment#section',
				true,
			],
			// Non-root-relative URLs.
			[
				'https://example.com/page',
				false,
			],
			[
				'http://example.com/page',
				false,
			],
			[
				'//protocol-relative.com/path',
				false,
			],
			[
				'relative/path',
				false,
			],
			[
				'path/without/leading/slash',
				false,
			],
			[
				'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwIiBoZWlnaHQ9IjEwMCI+PC9zdmc+',
				false,
			],
			// Edge cases.
			[
				'',
				false,
			],
			[
				'   /path/to/page   ',
				true,
			],
			[
				// this should fail since it's not a valid url (ie: `wp_parse_url` returns false).
				'///three-slashes/path',
				false,
			],
		];
	}

	/**
	 * DataProvider for test_is_url_protocol_relative.
	 *
	 * @return array[]
	 */
	public function providerIsUrlProtocolRelative() {
		return [
			// Valid protocol-relative URLs.
			[
				'//example.com/page',
				true,
			],
			[
				'//cdn.example.com/image.jpg',
				true,
			],
			[
				'//static.example.com/path/to/file.png',
				true,
			],
			[
				'//example.com/path/with/query?param=value',
				true,
			],
			[
				'//example.com/path/with/fragment#section',
				true,
			],
			// Non-protocol-relative URLs.
			[
				'https://example.com/page',
				false,
			],
			[
				'http://example.com/page',
				false,
			],
			[
				'/relative/path',
				false,
			],
			[
				'relative/path',
				false,
			],
			[
				'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwIiBoZWlnaHQ9IjEwMCI+PC9zdmc+',
				false,
			],
			// Edge cases.
			[
				'',
				false,
			],
			[
				'   //example.com/page   ',
				true,
			],
			[
				'///example.com/page',
				false,
			],
		];
	}

	/**
	 * DataProvider for test_is_url_valid.
	 *
	 * @return array[]
	 */
	public function providerIsUrlValid() {
		return [
			// Valid absolute URLs.
			[
				'https://example.com/page',
				true,
			],
			[
				'http://example.com/page',
				true,
			],
			[
				'https://example.com/path/to/page?param=value#section',
				true,
			],
			[
				'http://subdomain.example.com:8080/path',
				true,
			],
			// Valid root-relative URLs.
			[
				'/path/to/page',
				true,
			],
			[
				'/image.jpg',
				true,
			],
			[
				'/path/with/query?param=value',
				true,
			],
			[
				'/path/with/fragment#section',
				true,
			],
			// Valid protocol-relative URLs.
			[
				'//example.com/page',
				true,
			],
			[
				'//cdn.example.com/image.jpg',
				true,
			],
			[
				'//example.com/path/with/query?param=value',
				true,
			],
			// Invalid URLs.
			[
				'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwIiBoZWlnaHQ9IjEwMCI+PC9zdmc+',
				false,
			],
			[
				'mailto:user@example.com',
				false,
			],
			[
				'tel:+1234567890',
				false,
			],
			[
				'relative/path',
				false,
			],
			[
				'path/without/leading/slash',
				false,
			],
			[
				'ftp://example.com/file',
				false,
			],
			[
				'http://example.com:invalid/file',
				false,
			],
			[
				'https://example.com:99999/file',
				false,
			],
			// Edge cases.
			[
				'',
				false,
			],
			[
				'   https://example.com/page   ',
				true,
			],
			[
				'   /path/to/page   ',
				true,
			],
			[
				'   //example.com/page   ',
				true,
			],
		];
	}

	/**
	 * DataProvider for test_update_img_tag_for_new_attachment.
	 *
	 * @return array[]
	 */
	public function providerUpdateImgTagForNewAttachment() {
		return [
			// No matching img tag - HTML should remain unchanged.
			'no_matching_img'                      => [
				'<img src="https://example.com/other.jpg" alt="Other">',
				'https://example.com/image.jpg',
				123,
				'<img src="https://example.com/other.jpg" alt="Other">',
			],

			// Empty HTML - should return empty.
			'empty_html'                           => [
				'',
				'https://example.com/image.jpg',
				123,
				'',
			],

			// Basic img with no class - should add wp-image-{id} class.
			'add_class_to_img_without_class'       => [
				'<img src="https://example.com/image.jpg" alt="Test">',
				'https://example.com/image.jpg',
				123,
				'<img src="https://example.com/image.jpg" alt="Test" class="wp-image-123">',
			],

			// Img with existing class - should append wp-image-{id}.
			'append_class_to_existing'             => [
				'<img src="https://example.com/image.jpg" class="kg-image" alt="Test">',
				'https://example.com/image.jpg',
				123,
				'<img src="https://example.com/image.jpg" class="kg-image wp-image-123" alt="Test">',
			],

			// Img with multiple existing classes - should append wp-image-{id}.
			'append_class_to_multiple_existing'    => [
				'<img src="https://example.com/image.jpg" class="kg-image custom-class another" alt="Test">',
				'https://example.com/image.jpg',
				456,
				'<img src="https://example.com/image.jpg" class="kg-image custom-class another wp-image-456" alt="Test">',
			],

			// Img with existing wp-image-{old_id} class - should replace with new ID.
			'replace_existing_wp_image_class'      => [
				'<img src="https://example.com/image.jpg" class="kg-image wp-image-111 customclass" alt="Test">',
				'https://example.com/image.jpg',
				123,
				'<img src="https://example.com/image.jpg" class="kg-image customclass wp-image-123" alt="Test">',
			],

			// Img with only wp-image-{old_id} class - should replace with new ID.
			'replace_only_wp_image_class'          => [
				'<img src="https://example.com/image.jpg" class="wp-image-999" alt="Test">',
				'https://example.com/image.jpg',
				123,
				'<img src="https://example.com/image.jpg" class="wp-image-123" alt="Test">',
			],

			// Img with srcset - should remove srcset  (DOMDocument adds class at end).
			'remove_srcset'                        => [
				'<img src="https://example.com/image.jpg" srcset="https://example.com/image-300w.jpg 300w, https://example.com/image-600w.jpg 600w" alt="Test">',
				'https://example.com/image.jpg',
				123,
				'<img src="https://example.com/image.jpg" alt="Test" class="wp-image-123">',
			],

			// Img with data-srcset - should remove data-srcset (DOMDocument adds class at end).
			'remove_data_srcset'                   => [
				'<img src="https://example.com/image.jpg" data-srcset="https://example.com/image-300w.jpg 300w, https://example.com/image-600w.jpg 600w" alt="Test">',
				'https://example.com/image.jpg',
				123,
				'<img src="https://example.com/image.jpg" alt="Test" class="wp-image-123">',
			],

			// Img with both srcset and data-srcset - should remove both (DOMDocument adds class at end).
			'remove_both_srcsets'                  => [
				'<img src="https://example.com/image.jpg" srcset="https://example.com/image-300w.jpg 300w" data-srcset="https://example.com/image-600w.jpg 600w" alt="Test">',
				'https://example.com/image.jpg',
				123,
				'<img src="https://example.com/image.jpg" alt="Test" class="wp-image-123">',
			],

			// Img with class, srcset, and wp-image - full replacement scenario.
			'full_replacement_scenario'            => [
				'<img src="https://example.com/image.jpg" class="kg-image wp-image-111" srcset="https://example.com/image-300w.jpg 300w" data-srcset="https://example.com/image-600w.jpg 600w" alt="Test">',
				'https://example.com/image.jpg',
				123,
				'<img src="https://example.com/image.jpg" class="kg-image wp-image-123" alt="Test">',
			],

			// Multiple img tags - only matching one should be modified.
			'multiple_imgs_only_matching_modified' => [
				'<div><img src="https://example.com/other.jpg" class="other-class" alt="Other"><img src="https://example.com/image.jpg" class="kg-image" srcset="https://example.com/image-300w.jpg 300w" alt="Test"></div>',
				'https://example.com/image.jpg',
				123,
				'<div><img src="https://example.com/other.jpg" class="other-class" alt="Other"><img src="https://example.com/image.jpg" class="kg-image wp-image-123" alt="Test"></div>',
			],

			// Multiple matching img tags - all should be modified (DOMDocument adds class at end for first img).
			'multiple_matching_imgs_all_modified'  => [
				'<div><img src="https://example.com/image.jpg" alt="First"><img src="https://example.com/image.jpg" class="second" alt="Second"></div>',
				'https://example.com/image.jpg',
				123,
				'<div><img src="https://example.com/image.jpg" alt="First" class="wp-image-123"><img src="https://example.com/image.jpg" class="second wp-image-123" alt="Second"></div>',
			],

			// Img with empty class attribute - should add wp-image-{id}.
			'img_with_empty_class'                 => [
				'<img src="https://example.com/image.jpg" class="" alt="Test">',
				'https://example.com/image.jpg',
				123,
				'<img src="https://example.com/image.jpg" class="wp-image-123" alt="Test">',
			],

			// Img with single quotes for attributes (DOMDocument converts to double quotes).
			'img_with_single_quotes'               => [
				"<img src='https://example.com/image.jpg' class='kg-image' alt='Test'>",
				'https://example.com/image.jpg',
				123,
				'<img src="https://example.com/image.jpg" class="kg-image wp-image-123" alt="Test">',
			],

			// Complex HTML structure (real-world example).
			'ghost_cms_real_world'                 => [
				'<figure class="kg-card kg-image-card kg-card-hascaption"><img src="https://example.com/image.jpg" class="kg-image" alt="" loading="lazy" width="2000" height="1033" srcset="https://example.com/image-600w.jpg 600w, https://example.com/image-1000w.jpg 1000w, https://example.com/image-1600w.jpg 1600w" sizes="(min-width: 720px) 720px"><figcaption>Caption text</figcaption></figure>',
				'https://example.com/image.jpg',
				789,
				'<figure class="kg-card kg-image-card kg-card-hascaption"><img src="https://example.com/image.jpg" class="kg-image wp-image-789" alt="" loading="lazy" width="2000" height="1033" sizes="(min-width: 720px) 720px"><figcaption>Caption text</figcaption></figure>',
			],

			// Img in nested HTML structure (DOMDocument adds class at end).
			'img_in_nested_structure'              => [
				'<article><div class="content"><p>Some text</p><figure><img src="https://example.com/image.jpg" srcset="https://example.com/image-300w.jpg 300w" alt="Test"></figure><p>More text</p></div></article>',
				'https://example.com/image.jpg',
				123,
				'<article><div class="content"><p>Some text</p><figure><img src="https://example.com/image.jpg" alt="Test" class="wp-image-123"></figure><p>More text</p></div></article>',
			],
		];
	}
}
