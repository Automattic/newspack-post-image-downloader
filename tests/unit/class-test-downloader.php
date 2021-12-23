<?php
/**
 * Class DownloaderTest.
 *
 * @package Newspack_Post_Image_Downloader
 */

namespace NewspackPostImageDownloaderTest;

use NewspackPostImageDownloader\WpBlockManipulator;
use WP_UnitTestCase;
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
	 * WpBlockManipulator.
	 *
	 * @var WpBlockManipulator
	 */
	private $block_manipulator;

	/**
	 * Override setUp.
	 *
	 * @throws RuntimeException In case a temp dir could not have been created.
	 */
	public function setUp() {
		$this->block_manipulator = new WpBlockManipulator();
		$this->downloader = new Downloader( $this->block_manipulator );
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
	 * Relative reference to host root. But an exception gets thrown if the $default_image_host_and_schema param is not provided.
	 */
	public function test_relative_ref_to_root_src_no_local_images_folder_throws_exception() {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionCode( Downloader::EXCEPTION_CODE_NO_DEFAULT_HOST_PROVIDED );

		$src                           = '/path/img.jpg';
		$folder_local_images           = null;
		$default_image_host_and_schema = null;

		$img_import_path = $this->downloader->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_image_host_and_schema );

		$this->assertSame( $src, $img_import_path );
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
	 * Relative reference src. But an exception gets thrown if the $default_image_host_and_schema param is not provided.
	 */
	public function test_relative_ref_src_no_local_images_folder_throws_exception() {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionCode( Downloader::EXCEPTION_CODE_NO_DEFAULT_HOST_PROVIDED );

		$src                           = 'path/img.jpg';
		$folder_local_images           = null;
		$default_image_host_and_schema = null;

		$img_import_path = $this->downloader->get_fully_qualified_img_import_or_download_path( $src, $folder_local_images, $default_image_host_and_schema );

		$this->assertSame( $src, $img_import_path );
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

		$this->assertSame( $result_expected, $result_expected );
	}

	/**
	 * Checks that the `image_block_update_image` updates the Image Block's ID, img class and src while keeping custom resolution
	 * in filename and the query params.
	 *
	 * @covers \NewspackPostImageDownloader\Downloader::image_block_update_image
	 *
	 * @dataProvider providerImageBlockSingleWithId
	 */
	public function test_image_block_update_image_should_update_attachment_id_class_and_src( $block_HTML, $id, $src_original ) {
		$downloader_partial_mock = $this->create_downloader_partial_mock_with_wp_get_attachment_url_method( $id, $src_original );
		$new_id = 123;
		$new_src = 'https://menu-live.test/wp-content/uploads/2021/10/WP-art-1.png';
		$block_HTML_expected = <<<BLOCK
<!-- wp:image {"id":123,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="https://menu-live.test/wp-content/uploads/2021/10/WP-art-1-1024x439.png?query=param" alt="" class="wp-image-123"/></figure>
<!-- /wp:image -->
BLOCK;

		$block_HTML_actual = $downloader_partial_mock->image_block_update_image( $block_HTML, $new_id, $new_src );

		$this->assertSame( $block_HTML_expected, $block_HTML_actual );
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
							 ->setConstructorArgs( [ $this->block_manipulator ] )
							 ->setMethods( array( 'file_exists' ) )
							 ->getMock();

		$partial_mock->expects( $this->any() )
					 ->method( 'file_exists' )
					->will(
						$this->returnCallback(
							function( $arg ) use ( $local_file, $response ) {
								return ( $local_file === $arg ) ? $response : null;
							}
						)
					);

		return $partial_mock;
	}

	/**
	 * Creates a partial mock of the Downloader class, and mocks the `wp_get_attachment_url` method.
	 * In case of a different input argument, mock will return null.
	 *
	 * @param string $attachment_id Input argument for the `file_exists` method.
	 * @param bool   $response      Expected result in case of this input argument.
	 *
	 * @return MockObject Partial mock.
	 */
	private function create_downloader_partial_mock_with_wp_get_attachment_url_method( $attachment_id, $response ) {
		$partial_mock = $this->getMockBuilder( Downloader::class )
							 ->setConstructorArgs( [ $this->block_manipulator ] )
							 ->setMethods( array( 'wp_get_attachment_url' ) )
							 ->getMock();

		$partial_mock->expects( $this->any() )
					 ->method( 'wp_get_attachment_url' )
					->will(
						$this->returnCallback(
							function( $arg ) use ( $attachment_id, $response ) {
								return ( $attachment_id === $arg ) ? $response : null;
							}
						)
					);

		return $partial_mock;
	}

	/**
	 * Changes object's method accessibility to public.
	 *
	 * @param object $object      Object whose method accessibility is changed.
	 * @param string $method_name Method name.
	 */
	private function make_object_method_public( $object, $method_name ) {
		$reflector = new ReflectionObject( $object );
		$method    = $reflector->getMethod( $method_name );
		$method->setAccessible( true );
	}

	/**
	 * DataProvider for test_uri_host_matching.
	 *
	 * @return array[]
	 */
	public function providerUriHostMatching() {
		return array(
			array(
				'https://host1.com/path/img.jpg',
				array( 'host1.com' ),
				true,
			),
			array(
				'    https://host1.com/path/with-spaces.jpg    ',
				array( 'host1.com' ),
				true,
			),
			array(
				'https://host1.com/path/img.jpg',
				array( 'host2.com' ),
				false,
			),
			array(
				'https://host1.com/path/img.jpg',
				array( '*.host1.com' ),
				false,
			),
			array(
				'https://host1.com/path/img.jpg',
				array( 'host1.*' ),
				true,
			),
			array(
				'https://host1.com/path/img.jpg',
				array( '*.host1.*' ),
				false,
			),
			array(
				'https://www.host1.com/path/img.jpg',
				array( '*.host1.com' ),
				true,
			),
			array(
				'https://www.host1.com/path/img.jpg',
				array( 'www.host1.*' ),
				true,
			),
			array(
				'https://www.host1.com/path/img.jpg',
				array( 'www.host2.*' ),
				true,
			),
		);
	}

	/**
	 * DataProvider for image_block_update_image.
	 *
	 * @return array[]
	 */
	public function providerImageBlockSingleWithId() {
		$block_HTML = <<<BLOCK
<!-- wp:image {"id":15,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="https://menu-live.test/wp-content/uploads/2021/10/WP-art-111111-1024x439.png?query=param" alt="" class="wp-image-15"/></figure>
<!-- /wp:image -->
BLOCK;
		$src_clean = 'https://menu-live.test/wp-content/uploads/2021/10/WP-art-111111.png';

		return [
			[ $block_HTML, 15, $src_clean, ]
		];
	}
}
