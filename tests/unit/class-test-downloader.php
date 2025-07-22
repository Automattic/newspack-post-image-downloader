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
		$src                           = 'http://host.com/path/img.jpg';
		$folder_local_images           = null;
		$default_image_host_and_schema = null;

		$img_import_path = $this->downloader->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_image_host_and_schema );

		$this->assertSame( $src, $img_import_path );
	}

	/**
	 * Plain absolute HTTP src. Path to folder with local images is provided, but the image file is not found there.
	 */
	public function test_absolute_src_no_local_file() {
		$src                           = 'http://host.com/path/img.jpg';
		$folder_local_images           = '/tmp/mock';
		$default_image_host_and_schema = null;

		$img_import_path = $this->downloader->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_image_host_and_schema );

		$this->assertSame( $src, $img_import_path );
	}

	/**
	 * Plain absolute HTTP src. Path to folder with local images is provided, and the image file is found locally.
	 */
	public function test_absolute_src_with_local_file() {
		$src                           = 'http://host.com/path/img.jpg';
		$folder_local_images           = '/tmp/mock';
		$default_image_host_and_schema = null;
		$local_file                    = $folder_local_images . '/path/img.jpg';

		// Get partial mock for Downloader::file_exists method, to avoid writing to disk.
		$partial_mock = $this->create_downloader_partial_mock_with_file_exists_method( $local_file, true );

		$img_import_path = $partial_mock->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_image_host_and_schema );

		$this->assertSame( $local_file, $img_import_path );
	}

	/**
	 * Relative reference to host root. But a WP_Error gets returned if the $default_image_host_and_schema param is not provided.
	 */
	public function test_relative_ref_to_root_src_no_local_images_folder_returns_wp_error() {
		$src                           = '/path/img.jpg';
		$folder_local_images           = null;
		$default_image_host_and_schema = null;

		$img_import_path = $this->downloader->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_image_host_and_schema );

		$this->assertInstanceOf( WP_Error::class, $img_import_path );
	}

	/**
	 * Relative reference to host root. All needed params are provided, but the image file is not found there.
	 */
	public function test_relative_ref_to_root_src_no_local_file() {
		$src                           = '/path/img.jpg';
		$folder_local_images           = '/tmp/mock';
		$default_image_host_and_schema = 'https://deault/download/from';

		$img_import_path = $this->downloader->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_image_host_and_schema );

		$this->assertSame( $default_image_host_and_schema . $src, $img_import_path );
	}

	/**
	 * Relative reference to host root. The image file is found locally.
	 */
	public function test_relative_ref_to_root_src_with_local_file() {
		$src                           = '/path/img.jpg';
		$folder_local_images           = '/tmp/mock';
		$default_image_host_and_schema = 'https://deault/download/from';
		$local_file                    = $folder_local_images . '/path/img.jpg';

		// Get partial mock for Downloader::file_exists method, to avoid writing to disk.
		$partial_mock = $this->create_downloader_partial_mock_with_file_exists_method( $local_file, true );

		$img_import_path = $partial_mock->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_image_host_and_schema );

		$this->assertSame( $local_file, $img_import_path );
	}

	/**
	 * Relative reference src. But a WP_Error gets returned if the $default_image_host_and_schema param is not provided.
	 */
	public function test_relative_ref_src_no_local_images_folder_returns_wp_error() {
		$src                           = 'path/img.jpg';
		$folder_local_images           = null;
		$default_image_host_and_schema = null;

		$img_import_path = $this->downloader->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_image_host_and_schema );

		$this->assertInstanceOf( WP_Error::class, $img_import_path );
	}

	/**
	 * Relative reference src. All needed params are provided, but the image file is not found there.
	 */
	public function test_relative_ref_src_no_local_file() {
		$src                           = 'path/img.jpg';
		$folder_local_images           = '/tmp/mock';
		$default_image_host_and_schema = 'https://deault/download/from';

		$img_import_path = $this->downloader->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_image_host_and_schema );

		$this->assertSame( $default_image_host_and_schema . '/' . $src, $img_import_path );
	}

	/**
	 * Relative reference src. The image file is found locally.
	 */
	public function test_relative_ref_src_with_local_file() {
		$src                           = 'path/img.jpg';
		$folder_local_images           = '/tmp/mock';
		$default_image_host_and_schema = 'https://deault/download/from';
		$local_file                    = $folder_local_images . '/path/img.jpg';

		// Get partial mock for Downloader::file_exists method, to avoid writing to disk.
		$partial_mock = $this->create_downloader_partial_mock_with_file_exists_method( $local_file, true );

		$img_import_path = $partial_mock->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_image_host_and_schema );

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
	 * Changes object's method accessibility to public.
	 *
	 * @param object $class_object Object whose method accessibility is changed.
	 * @param string $method_name  Method name.
	 */
	private function make_object_method_public( $class_object, $method_name ) {
		$reflector = new ReflectionObject( $class_object );
		$method    = $reflector->getMethod( $method_name );
		$method->setAccessible( true );
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
				'https://www.mysite.com/wp-content/uploads/2025/01/kitten-300x244.jpg',
				'https://www.mysite.com/wp-content/uploads/2025/01/kitten.jpg',
			],
			// E.g. 3. non-intermediate image: returns null.
			[
				'https://www.mysite.com/wp-content/uploads/2025/01/kitten.jpg',
				null,
			],
			// E.g. 4. scaled image (not intermediate): returns null.
			[
				'https://www.mysite.com/wp-content/uploads/2025/01/huge_puppy-scaled.jpg',
				null,
			],
			// E.g. 5. image with query params: returns non-intermediate without query.
			[
				'https://www.mysite.com/wp-content/uploads/2025/01/kitten-300x244.jpg?foo=bar',
				'https://www.mysite.com/wp-content/uploads/2025/01/kitten.jpg',
			],
			// E.g. 6. image with spaces and query params: returns non-intermediate without query and trimmed.
			[
				'   https://www.mysite.com/wp-content/uploads/2025/01/kitten-300x244.jpg?foo=bar   ',
				'https://www.mysite.com/wp-content/uploads/2025/01/kitten.jpg',
			],
			// E.g. 7. SVG image (should not match intermediate pattern): returns null.
			[
				'https://www.mysite.com/wp-content/uploads/2025/01/vector-300x244.svg',
				'https://www.mysite.com/wp-content/uploads/2025/01/vector.svg',
			],
			// E.g. 8. non-intermediate SVG: returns null.
			[
				'https://www.mysite.com/wp-content/uploads/2025/01/vector.svg',
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
				'https://www.mysite.com/wp-content/uploads/2025/01/huge_puppy-scaled.jpg',
				'https://www.mysite.com/wp-content/uploads/2025/01/huge_puppy.jpg',
			],
			// E.g. 2. non-scaled image: returns null.
			[
				'https://www.mysite.com/wp-content/uploads/2025/01/regular_puppy.jpg',
				null,
			],
			// E.g. 3. scaled image with query params: returns non-scaled without query.
			[
				'https://www.mysite.com/wp-content/uploads/2025/01/huge_puppy-scaled.jpg?foo=bar',
				'https://www.mysite.com/wp-content/uploads/2025/01/huge_puppy.jpg',
			],
			// E.g. 4. scaled image with spaces and query params: returns non-scaled without query and trimmed.
			[
				'   https://www.mysite.com/wp-content/uploads/2025/01/huge_puppy-scaled.jpg?foo=bar   ',
				'https://www.mysite.com/wp-content/uploads/2025/01/huge_puppy.jpg',
			],
			// E.g. 5. scaled PNG image: returns non-scaled PNG.
			[
				'https://www.mysite.com/wp-content/uploads/2025/01/image-scaled.png',
				'https://www.mysite.com/wp-content/uploads/2025/01/image.png',
			],
			// E.g. 6. scaled WebP image: returns non-scaled WebP.
			[
				'https://www.mysite.com/wp-content/uploads/2025/01/image-scaled.webp',
				'https://www.mysite.com/wp-content/uploads/2025/01/image.webp',
			],
			// E.g. 7. scaled SVG image: returns non-scaled SVG.
			[
				'https://www.mysite.com/wp-content/uploads/2025/01/vector-scaled.svg',
				'https://www.mysite.com/wp-content/uploads/2025/01/vector.svg',
			],
			// E.g. 8. non-scaled SVG: returns null.
			[
				'https://www.mysite.com/wp-content/uploads/2025/01/vector.svg',
				null,
			],
			// E.g. 9. intermediate image (not scaled): returns null.
			[
				'https://www.mysite.com/wp-content/uploads/2025/01/kitten-300x244.jpg',
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
				'https://www.mysite.com/wp-content/uploads/2025/01/kitten-300x244.jpg',
				[
					'src'                  => 'https://www.mysite.com/wp-content/uploads/2025/01/kitten-300x244.jpg',
					'src_non_intermediate' => 'https://www.mysite.com/wp-content/uploads/2025/01/kitten.jpg',
					'src_non_scaled'       => null,
					'title'                => '',
					'alt'                  => '',
				],
			],
			// Scaled image.
			[
				'https://www.mysite.com/wp-content/uploads/2025/01/huge_puppy-scaled.jpg',
				[
					'src'                  => 'https://www.mysite.com/wp-content/uploads/2025/01/huge_puppy-scaled.jpg',
					'src_non_intermediate' => null,
					'src_non_scaled'       => 'https://www.mysite.com/wp-content/uploads/2025/01/huge_puppy.jpg',
					'title'                => '',
					'alt'                  => '',
				],
			],
			// Non-intermediate, non-scaled image.
			[
				'https://www.mysite.com/wp-content/uploads/2025/01/kitten.jpg',
				[
					'src'                  => 'https://www.mysite.com/wp-content/uploads/2025/01/kitten.jpg',
					'src_non_intermediate' => null,
					'src_non_scaled'       => null,
					'title'                => '',
					'alt'                  => '',
				],
			],
			// SVG intermediate.
			[
				'https://www.mysite.com/wp-content/uploads/2025/01/vector-300x244.svg',
				[
					'src'                  => 'https://www.mysite.com/wp-content/uploads/2025/01/vector-300x244.svg',
					'src_non_intermediate' => null,
					'src_non_scaled'       => null,
					'title'                => '',
					'alt'                  => '',
				],
			],
			// SVG non-intermediate.
			[
				'https://www.mysite.com/wp-content/uploads/2025/01/vector.svg',
				[
					'src'                  => 'https://www.mysite.com/wp-content/uploads/2025/01/vector.svg',
					'src_non_intermediate' => null,
					'src_non_scaled'       => null,
					'title'                => '',
					'alt'                  => '',
				],
			],
		];
	}
}
