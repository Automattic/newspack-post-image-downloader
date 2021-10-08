<?php
/**
 * Class DownloaderTest.
 *
 * @package Newspack_Post_Image_Downloader
 */

namespace NewspackPostImageDownloaderTest;

use WP_UnitTestCase;
use NewspackPostImageDownloader\WpBlockManipulator;
use RuntimeException;

/**
 * Sample test case.
 */
class Test_WPBlockManipulator extends WP_UnitTestCase {

	/**
	 * WpBlockManipulator object.
	 *
	 * @var WpBlockManipulator
	 */
	private $manipulator;

	/**
	 * DataProviderWPBlockManipulator object.
	 *
	 * @var DataProviderWPBlockManipulator
	 */
	private $data_provider;

	/**
	 * Override setUp.
	 *
	 * @throws RuntimeException In case a temp dir could not have been created.
	 */
	public function setUp() {
		parent::setUp();
		$this->manipulator = new WpBlockManipulator();
		$this->data_provider = new DataProviderWPBlockManipulator();
	}

	/**
	 * Tests that match_wp_blocks returns the correct number of image blocks.
	 *
	 * @covers WpBlockManipulator::match_wp_blocks
	 */
	public function test_matches_blocks_multiple_images() {
		$content = $this->data_provider->get_content_with_two_image_blocks();
		$number_of_blocks_expected = 2;

		$matches = $this->manipulator->match_wp_blocks( 'wp:image', $content );

		$this->assertSame( $number_of_blocks_expected, count( $matches ) );
	}

	/**
	 * Tests that match_wp_blocks doesn't find any image blocks in content where none exist.
	 *
	 * @covers WpBlockManipulator::match_wp_blocks
	 */
	public function test_matches_blocks_no_results() {
		$content = $this->data_provider->get_content_with_no_blocks();

		$matches = $this->manipulator->match_wp_blocks( 'wp:image', $content );

		$this->assertSame( null, $matches );
	}

	/**
	 * Tests that get_block_attribute_value returns an attribute's value.
	 *
	 * @covers WpBlockManipulator::get_block_attribute_value
	 */
	public function test_gets_block_attribute_value() {
		$content = $this->data_provider->get_content_with_two_image_blocks();
		$id_expected = 2;

		$matches = $this->manipulator->match_wp_blocks( 'wp:image', $content );
		$first_block = $matches[0][0][0];
		$id_actual = $this->manipulator->get_block_attribute_value( $first_block, 'id' );

		$this->assertSame( $id_expected, $id_actual );
	}

	/**
	 * Tests that get_block_attribute_value doesn't return an attribute which doesn't exist.
	 *
	 * @covers WpBlockManipulator::get_block_attribute_value
	 */
	public function test_gets_block_attribute_value_attribute_not_found() {
		$content = $this->data_provider->get_content_with_two_image_blocks();

		$matches = $this->manipulator->match_wp_blocks( 'wp:image', $content );
		$first_block = $matches[0][0][0];
		$value_actual = $this->manipulator->get_block_attribute_value( $first_block, 'someRandomAttribute' );

		$this->assertSame( null, $value_actual );
	}

	/**
	 * Tests that update_block_attribute updates an existing attribute's value.
	 *
	 * @covers WpBlockManipulator::update_block_attribute
	 */
	public function test_updates_block_attribute() {
		$content = $this->data_provider->get_content_with_two_image_blocks();
		$value_expected = 123;
		$first_line_expected = '<!-- wp:image {"id":' . $value_expected . ',"sizeSlug":"large","linkDestination":"none"} -->';

		$matches = $this->manipulator->match_wp_blocks( 'wp:image', $content );
		$first_block = $matches[0][0][0];
		$first_block_updated = $this->manipulator->update_block_attribute( $first_block, 'id', $value_expected );
		$first_block_updated_exploded = explode( "\n", $first_block_updated );
		$first_line_actual = $first_block_updated_exploded[0];

		$this->assertSame( $first_line_expected, $first_line_actual );
	}

	/**
	 * Tests that update_block_attribute adds a new attribute to the block.
	 *
	 * @covers WpBlockManipulator::update_block_attribute
	 */
	public function test_adds_block_attribute() {
		$content = $this->data_provider->get_content_with_two_image_blocks();
		$attribute = 'customAttr';
		$value_expected = 123;
		$first_line_expected = '<!-- wp:image {"id":2,"sizeSlug":"large","linkDestination":"none","' . $attribute . '":' . $value_expected . '} -->';

		$matches = $this->manipulator->match_wp_blocks( 'wp:image', $content );
		$first_block = $matches[0][0][0];
		$first_block_updated = $this->manipulator->update_block_attribute( $first_block, $attribute, $value_expected );
		$first_block_updated_exploded = explode( "\n", $first_block_updated );
		$first_line_actual = $first_block_updated_exploded[0];

		$this->assertSame( $first_line_expected, $first_line_actual );
	}
}
