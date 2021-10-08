<?php
/**
 * Data Provider for tests.
 *
 * @package Newspack
 */

namespace NewspackPostImageDownloaderTest;

/**
 * Class DataProviderWPBlockManipulator
 *
 * Data Provider for tests in \NewspackPostImageDownloaderTest\Test_WPBlockManipulator.
 */
class DataProviderWPBlockManipulator {

	public function get_content_with_two_image_blocks() {
		return <<<CONTENT
<!-- wp:image {"id":2,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="https://menu-live.test/wp-content/uploads/2021/10/WP-art-1-1024x439.png" alt="" class="wp-image-2"/></figure>
<!-- /wp:image -->

<!-- wp:paragraph -->
<p>some text</p>
<!-- /wp:paragraph -->

<!-- wp:image {"id":5,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="https://menu-live.test/wp-content/uploads/2021/10/WP-art-2-1024x341.jpg" alt="" class="wp-image-5"/></figure>
<!-- /wp:image -->

<!-- wp:paragraph -->
<p>some text</p>
<!-- /wp:paragraph -->
CONTENT;
	}

	public function get_content_with_no_image_blocks() {
		return <<<CONTENT
<!-- wp:paragraph -->
<p>some text</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>some text</p>
<!-- /wp:paragraph -->
CONTENT;
	}

}
