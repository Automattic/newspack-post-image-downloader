<?php

namespace NewspackPostImageDownloader;

/**
 * WP Blocks manipulator class.
 *
 * @package NewspackPostImageDownloader
 */
class WpBlockManipulator {

	const BLOCK_PATTERN = '|
		\<\!--      # beginning of the block element
		\s          # followed by a space
		%1$s        # element name/designation, should be substituted by using sprintf(), eg. sprintf( $this_pattern, \'wp:video\' );
		.*?         # anything in the middle
		--\>        # end of opening tag
		.*?         # anything in the middle
		(\<\!--     # beginning of the closing tag
		\s          # followed by a space
		/           # one forward slash
		%1$s        # element name/designation, should be substituted by using sprintf(), eg. sprintf( $this_pattern, \'wp:video\' );
		\s          # followed by a space
		--\>)       # end of block
				    # "s" modifier also needed here to match across multi-lines
		|xims';


	/**
	 * Searches and matches block elements in given source.
	 * Runs the preg_match_all() with the PREG_OFFSET_CAPTURE option, and returns the $match.
	 *
	 * @param string $block_name Block name to search for (match).
	 * @param string $content    Blocks content source in which to search for blocks.
	 *
	 * @return array|null| $matches from the preg_match_all() or null.
	 */
	public function match_wp_blocks( $block_name, $content ) {

		$pattern = sprintf( self::BLOCK_PATTERN, $block_name );
		$preg_match_all_result = preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE );

		return ( false === $preg_match_all_result || 0 === $preg_match_all_result ) ? null : $matches;
	}

	/**
	 * Gets Block attribute value.
	 *
	 * @param string $block_element Block element source.
	 * @param string $attribute     Block attribute name.
	 *
	 * @return mixed|null
	 */
	public function get_block_attribute_value( $block_element, $attribute ) {
		$block_element_lines    = explode( "\n", $block_element );
		$block_element_1st_line = $block_element_lines[0];

		$pos_from_curly = strpos( $block_element_1st_line, '{' );
		$pos_to_curly = strpos( $block_element_1st_line, '}' );
		$contains_existing_attributes = $pos_from_curly && $pos_to_curly;
		if ( ! $contains_existing_attributes ) {
			return null;
		}

		$json_part = substr( $block_element_1st_line, $pos_from_curly, $pos_to_curly - $pos_from_curly + 1 );
		$attributes = \json_decode( $json_part, true );

		return $attributes[ $attribute ] ?? null;
	}

	/**
	 * Updates or sets Block attribute.
	 *
	 * @param string $block_element Block element full source.
	 * @param string $attribute     Attribute name.
	 * @param mixed  $new_value     Attribute value.
	 *
	 * @return string Updated Block element full source.
	 */
	public function update_block_attribute( $block_element, $attribute, $new_value ) {
		$block_element_lines    = explode( "\n", $block_element );
		$block_element_1st_line = $block_element_lines[0];

		$pos_from_curly = strpos( $block_element_1st_line, '{' );
		$pos_to_curly = strpos( $block_element_1st_line, '}' );
		$contains_existing_attributes = $pos_from_curly && $pos_to_curly;
		// Update attributes section if already exists.
		if ( $contains_existing_attributes ) {
			$json_part = substr( $block_element_1st_line, $pos_from_curly, $pos_to_curly - $pos_from_curly + 1 );
			$attributes = \json_decode( $json_part, true );
			$attributes[ $attribute ] = $new_value;
			$json_part_updated = json_encode( $attributes );
			$block_element_1st_line_patched = str_replace( $json_part, $json_part_updated, $block_element_1st_line );
		} else {
			// Insert the whole attributes section.
			$json_part = json_encode( [ $attribute => $new_value ] );
			$pos_closing = strpos( $block_element_1st_line, '-->' );
			$block_element_1st_line_patched = substr( $block_element_1st_line, 0, $pos_closing ) . $json_part . ' -->';
		}

		$block_element_lines[0] = $block_element_1st_line_patched;
		$block_element_patched  = implode( "\n", $block_element_lines );

		return $block_element_patched;
	}
}
