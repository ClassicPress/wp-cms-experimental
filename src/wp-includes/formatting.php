<?php
/**
 * Main WordPress Formatting API.
 *
 * Handles many functions for formatting output.
 *
 * @package ClassicPress
 */

/**
 * Replaces common plain text characters with formatted entities.
 *
 * Returns given text with transformations of quotes into smart quotes, apostrophes,
 * dashes, ellipses, the trademark symbol, and the multiplication symbol.
 *
 * As an example,
 *
 *     'cause today's effort makes it worth tomorrow's "holiday" ...
 *
 * Becomes:
 *
 *     &#8217;cause today&#8217;s effort makes it worth tomorrow&#8217;s &#8220;holiday&#8221; &#8230;
 *
 * Code within certain HTML blocks are skipped.
 *
 * Do not use this function before the {@see 'init'} action hook; everything will break.
 *
 * @since 0.71
 *
 * @global array $wp_cockneyreplace Array of formatted entities for certain common phrases.
 * @global array $shortcode_tags
 *
 * @param string $text  The text to be formatted.
 * @param bool   $reset Set to true for unit testing. Translated patterns will reset.
 * @return string The string replaced with HTML entities.
 */
function wptexturize( $text, $reset = false ) {
	global $wp_cockneyreplace, $shortcode_tags;
	static $static_characters            = null,
		$static_replacements             = null,
		$dynamic_characters              = null,
		$dynamic_replacements            = null,
		$default_no_texturize_tags       = null,
		$default_no_texturize_shortcodes = null,
		$run_texturize                   = true,
		$apos                            = null,
		$prime                           = null,
		$double_prime                    = null,
		$opening_quote                   = null,
		$closing_quote                   = null,
		$opening_single_quote            = null,
		$closing_single_quote            = null,
		$open_q_flag                     = '<!--oq-->',
		$open_sq_flag                    = '<!--osq-->',
		$apos_flag                       = '<!--apos-->';

	// If there's nothing to do, just stop.
	if ( empty( $text ) || false === $run_texturize ) {
		return $text;
	}

	// Set up static variables. Run once only.
	if ( $reset || ! isset( $static_characters ) ) {
		/**
		 * Filters whether to skip running wptexturize().
		 *
		 * Returning false from the filter will effectively short-circuit wptexturize()
		 * and return the original text passed to the function instead.
		 *
		 * The filter runs only once, the first time wptexturize() is called.
		 *
		 * @since 4.0.0
		 *
		 * @see wptexturize()
		 *
		 * @param bool $run_texturize Whether to short-circuit wptexturize().
		 */
		$run_texturize = apply_filters( 'run_wptexturize', $run_texturize );
		if ( false === $run_texturize ) {
			return $text;
		}

		/* translators: Opening curly double quote. */
		$opening_quote = _x( '&#8220;', 'opening curly double quote' );
		/* translators: Closing curly double quote. */
		$closing_quote = _x( '&#8221;', 'closing curly double quote' );

		/* translators: Apostrophe, for example in 'cause or can't. */
		$apos = _x( '&#8217;', 'apostrophe' );

		/* translators: Prime, for example in 9' (nine feet). */
		$prime = _x( '&#8242;', 'prime' );
		/* translators: Double prime, for example in 9" (nine inches). */
		$double_prime = _x( '&#8243;', 'double prime' );

		/* translators: Opening curly single quote. */
		$opening_single_quote = _x( '&#8216;', 'opening curly single quote' );
		/* translators: Closing curly single quote. */
		$closing_single_quote = _x( '&#8217;', 'closing curly single quote' );

		/* translators: En dash. */
		$en_dash = _x( '&#8211;', 'en dash' );
		/* translators: Em dash. */
		$em_dash = _x( '&#8212;', 'em dash' );

		$default_no_texturize_tags       = array( 'pre', 'code', 'kbd', 'style', 'script', 'tt' );
		$default_no_texturize_shortcodes = array( 'code' );

		// If a plugin has provided an autocorrect array, use it.
		if ( isset( $wp_cockneyreplace ) ) {
			$cockney        = array_keys( $wp_cockneyreplace );
			$cockneyreplace = array_values( $wp_cockneyreplace );
		} else {
			/*
			 * translators: This is a comma-separated list of words that defy the syntax of quotations in normal use,
			 * for example... 'We do not have enough words yet'... is a typical quoted phrase. But when we write
			 * lines of code 'til we have enough of 'em, then we need to insert apostrophes instead of quotes.
			 */
			$cockney = explode(
				',',
				_x(
					"'tain't,'twere,'twas,'tis,'twill,'til,'bout,'nuff,'round,'cause,'em",
					'Comma-separated list of words to texturize in your language'
				)
			);

			$cockneyreplace = explode(
				',',
				_x(
					'&#8217;tain&#8217;t,&#8217;twere,&#8217;twas,&#8217;tis,&#8217;twill,&#8217;til,&#8217;bout,&#8217;nuff,&#8217;round,&#8217;cause,&#8217;em',
					'Comma-separated list of replacement words in your language'
				)
			);
		}

		$static_characters   = array_merge( array( '...', '``', '\'\'', ' (tm)' ), $cockney );
		$static_replacements = array_merge( array( '&#8230;', $opening_quote, $closing_quote, ' &#8482;' ), $cockneyreplace );

		// Pattern-based replacements of characters.
		// Sort the remaining patterns into several arrays for performance tuning.
		$dynamic_characters   = array(
			'apos'  => array(),
			'quote' => array(),
			'dash'  => array(),
		);
		$dynamic_replacements = array(
			'apos'  => array(),
			'quote' => array(),
			'dash'  => array(),
		);
		$dynamic              = array();
		$spaces               = wp_spaces_regexp();

		// '99' and '99" are ambiguous among other patterns; assume it's an abbreviated year at the end of a quotation.
		if ( "'" !== $apos || "'" !== $closing_single_quote ) {
			$dynamic[ '/\'(\d\d)\'(?=\Z|[.,:;!?)}\-\]]|&gt;|' . $spaces . ')/' ] = $apos_flag . '$1' . $closing_single_quote;
		}
		if ( "'" !== $apos || '"' !== $closing_quote ) {
			$dynamic[ '/\'(\d\d)"(?=\Z|[.,:;!?)}\-\]]|&gt;|' . $spaces . ')/' ] = $apos_flag . '$1' . $closing_quote;
		}

		// '99 '99s '99's (apostrophe)  But never '9 or '99% or '999 or '99.0.
		if ( "'" !== $apos ) {
			$dynamic['/\'(?=\d\d(?:\Z|(?![%\d]|[.,]\d)))/'] = $apos_flag;
		}

		// Quoted numbers like '0.42'.
		if ( "'" !== $opening_single_quote && "'" !== $closing_single_quote ) {
			$dynamic[ '/(?<=\A|' . $spaces . ')\'(\d[.,\d]*)\'/' ] = $open_sq_flag . '$1' . $closing_single_quote;
		}

		// Single quote at start, or preceded by (, {, <, [, ", -, or spaces.
		if ( "'" !== $opening_single_quote ) {
			$dynamic[ '/(?<=\A|[([{"\-]|&lt;|' . $spaces . ')\'/' ] = $open_sq_flag;
		}

		// Apostrophe in a word. No spaces, double apostrophes, or other punctuation.
		if ( "'" !== $apos ) {
			$dynamic[ '/(?<!' . $spaces . ')\'(?!\Z|[.,:;!?"\'(){}[\]\-]|&[lg]t;|' . $spaces . ')/' ] = $apos_flag;
		}

		$dynamic_characters['apos']   = array_keys( $dynamic );
		$dynamic_replacements['apos'] = array_values( $dynamic );
		$dynamic                      = array();

		// Quoted numbers like "42".
		if ( '"' !== $opening_quote && '"' !== $closing_quote ) {
			$dynamic[ '/(?<=\A|' . $spaces . ')"(\d[.,\d]*)"/' ] = $open_q_flag . '$1' . $closing_quote;
		}

		// Double quote at start, or preceded by (, {, <, [, -, or spaces, and not followed by spaces.
		if ( '"' !== $opening_quote ) {
			$dynamic[ '/(?<=\A|[([{\-]|&lt;|' . $spaces . ')"(?!' . $spaces . ')/' ] = $open_q_flag;
		}

		$dynamic_characters['quote']   = array_keys( $dynamic );
		$dynamic_replacements['quote'] = array_values( $dynamic );
		$dynamic                       = array();

		// Dashes and spaces.
		$dynamic['/---/'] = $em_dash;
		$dynamic[ '/(?<=^|' . $spaces . ')--(?=$|' . $spaces . ')/' ] = $em_dash;
		$dynamic['/(?<!xn)--/']                                       = $en_dash;
		$dynamic[ '/(?<=^|' . $spaces . ')-(?=$|' . $spaces . ')/' ]  = $en_dash;

		$dynamic_characters['dash']   = array_keys( $dynamic );
		$dynamic_replacements['dash'] = array_values( $dynamic );
	}

	// Must do this every time in case plugins use these filters in a context sensitive manner.
	/**
	 * Filters the list of HTML elements not to texturize.
	 *
	 * @since 2.8.0
	 *
	 * @param string[] $default_no_texturize_tags An array of HTML element names.
	 */
	$no_texturize_tags = apply_filters( 'no_texturize_tags', $default_no_texturize_tags );
	/**
	 * Filters the list of shortcodes not to texturize.
	 *
	 * @since 2.8.0
	 *
	 * @param string[] $default_no_texturize_shortcodes An array of shortcode names.
	 */
	$no_texturize_shortcodes = apply_filters( 'no_texturize_shortcodes', $default_no_texturize_shortcodes );

	$no_texturize_tags_stack       = array();
	$no_texturize_shortcodes_stack = array();

	// Look for shortcodes and HTML elements.

	preg_match_all( '@\[/?([^<>&/\[\]\x00-\x20=]++)@', $text, $matches );
	$tagnames         = array_intersect( array_keys( $shortcode_tags ), $matches[1] );
	$found_shortcodes = ! empty( $tagnames );
	$shortcode_regex  = $found_shortcodes ? _get_wptexturize_shortcode_regex( $tagnames ) : '';
	$regex            = _get_wptexturize_split_regex( $shortcode_regex );

	$textarr = preg_split( $regex, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

	foreach ( $textarr as &$curl ) {
		// Only call _wptexturize_pushpop_element if $curl is a delimiter.
		$first = $curl[0];
		if ( '<' === $first ) {
			if ( '<!--' === substr( $curl, 0, 4 ) ) {
				// This is an HTML comment delimiter.
				continue;
			} else {
				// This is an HTML element delimiter.

				// Replace each & with &#038; unless it already looks like an entity.
				$curl = preg_replace( '/&(?!#(?:\d+|x[a-f0-9]+);|[a-z1-4]{1,8};)/i', '&#038;', $curl );

				_wptexturize_pushpop_element( $curl, $no_texturize_tags_stack, $no_texturize_tags );
			}
		} elseif ( '' === trim( $curl ) ) {
			// This is a newline between delimiters. Performance improves when we check this.
			continue;

		} elseif ( '[' === $first && $found_shortcodes && 1 === preg_match( '/^' . $shortcode_regex . '$/', $curl ) ) {
			// This is a shortcode delimiter.

			if ( '[[' !== substr( $curl, 0, 2 ) && ']]' !== substr( $curl, -2 ) ) {
				// Looks like a normal shortcode.
				_wptexturize_pushpop_element( $curl, $no_texturize_shortcodes_stack, $no_texturize_shortcodes );
			} else {
				// Looks like an escaped shortcode.
				continue;
			}
		} elseif ( empty( $no_texturize_shortcodes_stack ) && empty( $no_texturize_tags_stack ) ) {
			// This is neither a delimiter, nor is this content inside of no_texturize pairs. Do texturize.

			$curl = str_replace( $static_characters, $static_replacements, $curl );

			if ( false !== strpos( $curl, "'" ) ) {
				$curl = preg_replace( $dynamic_characters['apos'], $dynamic_replacements['apos'], $curl );
				$curl = wptexturize_primes( $curl, "'", $prime, $open_sq_flag, $closing_single_quote );
				$curl = str_replace( $apos_flag, $apos, $curl );
				$curl = str_replace( $open_sq_flag, $opening_single_quote, $curl );
			}
			if ( false !== strpos( $curl, '"' ) ) {
				$curl = preg_replace( $dynamic_characters['quote'], $dynamic_replacements['quote'], $curl );
				$curl = wptexturize_primes( $curl, '"', $double_prime, $open_q_flag, $closing_quote );
				$curl = str_replace( $open_q_flag, $opening_quote, $curl );
			}
			if ( false !== strpos( $curl, '-' ) ) {
				$curl = preg_replace( $dynamic_characters['dash'], $dynamic_replacements['dash'], $curl );
			}

			// 9x9 (times), but never 0x9999.
			if ( 1 === preg_match( '/(?<=\d)x\d/', $curl ) ) {
				// Searching for a digit is 10 times more expensive than for the x, so we avoid doing this one!
				$curl = preg_replace( '/\b(\d(?(?<=0)[\d\.,]+|[\d\.,]*))x(\d[\d\.,]*)\b/', '$1&#215;$2', $curl );
			}

			// Replace each & with &#038; unless it already looks like an entity.
			$curl = preg_replace( '/&(?!#(?:\d+|x[a-f0-9]+);|[a-z1-4]{1,8};)/i', '&#038;', $curl );
		}
	}

	return implode( '', $textarr );
}

/**
 * Implements a logic tree to determine whether or not "7'." represents seven feet,
 * then converts the special char into either a prime char or a closing quote char.
 *
 * @since 4.3.0
 *
 * @param string $haystack    The plain text to be searched.
 * @param string $needle      The character to search for such as ' or ".
 * @param string $prime       The prime char to use for replacement.
 * @param string $open_quote  The opening quote char. Opening quote replacement must be
 *                            accomplished already.
 * @param string $close_quote The closing quote char to use for replacement.
 * @return string The $haystack value after primes and quotes replacements.
 */
function wptexturize_primes( $haystack, $needle, $prime, $open_quote, $close_quote ) {
	$spaces           = wp_spaces_regexp();
	$flag             = '<!--wp-prime-or-quote-->';
	$quote_pattern    = "/$needle(?=\\Z|[.,:;!?)}\\-\\]]|&gt;|" . $spaces . ')/';
	$prime_pattern    = "/(?<=\\d)$needle/";
	$flag_after_digit = "/(?<=\\d)$flag/";
	$flag_no_digit    = "/(?<!\\d)$flag/";

	$sentences = explode( $open_quote, $haystack );

	foreach ( $sentences as $key => &$sentence ) {
		if ( false === strpos( $sentence, $needle ) ) {
			continue;
		} elseif ( 0 !== $key && 0 === substr_count( $sentence, $close_quote ) ) {
			$sentence = preg_replace( $quote_pattern, $flag, $sentence, -1, $count );
			if ( $count > 1 ) {
				// This sentence appears to have multiple closing quotes. Attempt Vulcan logic.
				$sentence = preg_replace( $flag_no_digit, $close_quote, $sentence, -1, $count2 );
				if ( 0 === $count2 ) {
					// Try looking for a quote followed by a period.
					$count2 = substr_count( $sentence, "$flag." );
					if ( $count2 > 0 ) {
						// Assume the rightmost quote-period match is the end of quotation.
						$pos = strrpos( $sentence, "$flag." );
					} else {
						// When all else fails, make the rightmost candidate a closing quote.
						// This is most likely to be problematic in the context of bug #18549.
						$pos = strrpos( $sentence, $flag );
					}
					$sentence = substr_replace( $sentence, $close_quote, $pos, strlen( $flag ) );
				}
				// Use conventional replacement on any remaining primes and quotes.
				$sentence = preg_replace( $prime_pattern, $prime, $sentence );
				$sentence = preg_replace( $flag_after_digit, $prime, $sentence );
				$sentence = str_replace( $flag, $close_quote, $sentence );
			} elseif ( 1 == $count ) {
				// Found only one closing quote candidate, so give it priority over primes.
				$sentence = str_replace( $flag, $close_quote, $sentence );
				$sentence = preg_replace( $prime_pattern, $prime, $sentence );
			} else {
				// No closing quotes found. Just run primes pattern.
				$sentence = preg_replace( $prime_pattern, $prime, $sentence );
			}
		} else {
			$sentence = preg_replace( $prime_pattern, $prime, $sentence );
			$sentence = preg_replace( $quote_pattern, $close_quote, $sentence );
		}
		if ( '"' === $needle && false !== strpos( $sentence, '"' ) ) {
			$sentence = str_replace( '"', $close_quote, $sentence );
		}
	}

	return implode( $open_quote, $sentences );
}

/**
 * Searches for disabled element tags. Pushes element to stack on tag open
 * and pops on tag close.
 *
 * Assumes first char of `$text` is tag opening and last char is tag closing.
 * Assumes second char of `$text` is optionally `/` to indicate closing as in `</html>`.
 *
 * @since 2.9.0
 * @access private
 *
 * @param string   $text              Text to check. Must be a tag like `<html>` or `[shortcode]`.
 * @param string[] $stack             Array of open tag elements.
 * @param string[] $disabled_elements Array of tag names to match against. Spaces are not allowed in tag names.
 */
function _wptexturize_pushpop_element( $text, &$stack, $disabled_elements ) {
	// Is it an opening tag or closing tag?
	if ( isset( $text[1] ) && '/' !== $text[1] ) {
		$opening_tag = true;
		$name_offset = 1;
	} elseif ( 0 === count( $stack ) ) {
		// Stack is empty. Just stop.
		return;
	} else {
		$opening_tag = false;
		$name_offset = 2;
	}

	// Parse out the tag name.
	$space = strpos( $text, ' ' );
	if ( false === $space ) {
		$space = -1;
	} else {
		$space -= $name_offset;
	}
	$tag = substr( $text, $name_offset, $space );

	// Handle disabled tags.
	if ( in_array( $tag, $disabled_elements, true ) ) {
		if ( $opening_tag ) {
			/*
			 * This disables texturize until we find a closing tag of our type
			 * (e.g. <pre>) even if there was invalid nesting before that.
			 *
			 * Example: in the case <pre>sadsadasd</code>"baba"</pre>
			 *          "baba" won't be texturized.
			 */

			array_push( $stack, $tag );
		} elseif ( end( $stack ) == $tag ) {
			array_pop( $stack );
		}
	}
}

/**
 * Replaces double line breaks with paragraph elements.
 *
 * A group of regex replaces used to identify text formatted with newlines and
 * replace double line breaks with HTML paragraph tags. The remaining line breaks
 * after conversion become `<br />` tags, unless `$br` is set to '0' or 'false'.
 *
 * @since 0.71
 *
 * @param string $text The text which has to be formatted.
 * @param bool   $br   Optional. If set, this will convert all remaining line breaks
 *                     after paragraphing. Line breaks within `<script>`, `<style>`,
 *                     and `<svg>` tags are not affected. Default true.
 * @return string Text which has been converted into correct paragraph tags.
 */
function wpautop( $text, $br = true ) {
	$pre_tags = array();

	if ( trim( $text ) === '' ) {
		return '';
	}

	// Just to make things a little easier, pad the end.
	$text = $text . "\n";

	/*
	 * Pre tags shouldn't be touched by autop.
	 * Replace pre tags with placeholders and bring them back after autop.
	 */
	if ( strpos( $text, '<pre' ) !== false ) {
		$text_parts = explode( '</pre>', $text );
		$last_part  = array_pop( $text_parts );
		$text       = '';
		$i          = 0;

		foreach ( $text_parts as $text_part ) {
			$start = strpos( $text_part, '<pre' );

			// Malformed HTML?
			if ( false === $start ) {
				$text .= $text_part;
				continue;
			}

			$name              = "<pre wp-pre-tag-$i></pre>";
			$pre_tags[ $name ] = substr( $text_part, $start ) . '</pre>';

			$text .= substr( $text_part, 0, $start ) . $name;
			$i++;
		}

		$text .= $last_part;
	}
	// Change multiple <br>'s into two line breaks, which will turn into paragraphs.
	$text = preg_replace( '|<br\s*/?>\s*<br\s*/?>|', "\n\n", $text );

	$allblocks = '(?:table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|math|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary)';

	// Add a double line break above block-level opening tags.
	$text = preg_replace( '!(<' . $allblocks . '[\s/>])!', "\n\n$1", $text );

	// Add a double line break below block-level closing tags.
	$text = preg_replace( '!(</' . $allblocks . '>)!', "$1\n\n", $text );

	// Add a double line break after hr tags, which are self closing.
	$text = preg_replace( '!(<hr\s*?/?>)!', "$1\n\n", $text );

	// Standardize newline characters to "\n".
	$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

	// Find newlines in all elements and add placeholders.
	$text = wp_replace_in_html_tags( $text, array( "\n" => ' <!-- wpnl --> ' ) );

	// Collapse line breaks before and after <option> elements so they don't get autop'd.
	if ( strpos( $text, '<option' ) !== false ) {
		$text = preg_replace( '|\s*<option|', '<option', $text );
		$text = preg_replace( '|</option>\s*|', '</option>', $text );
	}

	/*
	 * Collapse line breaks inside <object> elements, before <param> and <embed> elements
	 * so they don't get autop'd.
	 */
	if ( strpos( $text, '</object>' ) !== false ) {
		$text = preg_replace( '|(<object[^>]*>)\s*|', '$1', $text );
		$text = preg_replace( '|\s*</object>|', '</object>', $text );
		$text = preg_replace( '%\s*(</?(?:param|embed)[^>]*>)\s*%', '$1', $text );
	}

	/*
	 * Collapse line breaks inside <audio> and <video> elements,
	 * before and after <source> and <track> elements.
	 */
	if ( strpos( $text, '<source' ) !== false || strpos( $text, '<track' ) !== false ) {
		$text = preg_replace( '%([<\[](?:audio|video)[^>\]]*[>\]])\s*%', '$1', $text );
		$text = preg_replace( '%\s*([<\[]/(?:audio|video)[>\]])%', '$1', $text );
		$text = preg_replace( '%\s*(<(?:source|track)[^>]*>)\s*%', '$1', $text );
	}

	// Collapse line breaks before and after <figcaption> elements.
	if ( strpos( $text, '<figcaption' ) !== false ) {
		$text = preg_replace( '|\s*(<figcaption[^>]*>)|', '$1', $text );
		$text = preg_replace( '|</figcaption>\s*|', '</figcaption>', $text );
	}

	// Remove more than two contiguous line breaks.
	$text = preg_replace( "/\n\n+/", "\n\n", $text );

	// Split up the contents into an array of strings, separated by double line breaks.
	$paragraphs = preg_split( '/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY );

	// Reset $text prior to rebuilding.
	$text = '';

	// Rebuild the content as a string, wrapping every bit with a <p>.
	foreach ( $paragraphs as $paragraph ) {
		$text .= '<p>' . trim( $paragraph, "\n" ) . "</p>\n";
	}

	// Under certain strange conditions it could create a P of entirely whitespace.
	$text = preg_replace( '|<p>\s*</p>|', '', $text );

	// Add a closing <p> inside <div>, <address>, or <form> tag if missing.
	$text = preg_replace( '!<p>([^<]+)</(div|address|form)>!', '<p>$1</p></$2>', $text );

	// If an opening or closing block element tag is wrapped in a <p>, unwrap it.
	$text = preg_replace( '!<p>\s*(</?' . $allblocks . '[^>]*>)\s*</p>!', '$1', $text );

	// In some cases <li> may get wrapped in <p>, fix them.
	$text = preg_replace( '|<p>(<li.+?)</p>|', '$1', $text );

	// If a <blockquote> is wrapped with a <p>, move it inside the <blockquote>.
	$text = preg_replace( '|<p><blockquote([^>]*)>|i', '<blockquote$1><p>', $text );
	$text = str_replace( '</blockquote></p>', '</p></blockquote>', $text );

	// If an opening or closing block element tag is preceded by an opening <p> tag, remove it.
	$text = preg_replace( '!<p>\s*(</?' . $allblocks . '[^>]*>)!', '$1', $text );

	// If an opening or closing block element tag is followed by a closing <p> tag, remove it.
	$text = preg_replace( '!(</?' . $allblocks . '[^>]*>)\s*</p>!', '$1', $text );

	// Optionally insert line breaks.
	if ( $br ) {
		// Replace newlines that shouldn't be touched with a placeholder.
		$text = preg_replace_callback( '/<(script|style|svg).*?<\/\\1>/s', '_autop_newline_preservation_helper', $text );

		// Normalize <br>
		$text = str_replace( array( '<br>', '<br/>' ), '<br />', $text );

		// Replace any new line characters that aren't preceded by a <br /> with a <br />.
		$text = preg_replace( '|(?<!<br />)\s*\n|', "<br />\n", $text );

		// Replace newline placeholders with newlines.
		$text = str_replace( '<WPPreserveNewline />', "\n", $text );
	}

	// If a <br /> tag is after an opening or closing block tag, remove it.
	$text = preg_replace( '!(</?' . $allblocks . '[^>]*>)\s*<br />!', '$1', $text );

	// If a <br /> tag is before a subset of opening or closing block tags, remove it.
	$text = preg_replace( '!<br />(\s*</?(?:p|li|div|dl|dd|dt|th|pre|td|ul|ol)[^>]*>)!', '$1', $text );
	$text = preg_replace( "|\n</p>$|", '</p>', $text );

	// Replace placeholder <pre> tags with their original content.
	if ( ! empty( $pre_tags ) ) {
		$text = str_replace( array_keys( $pre_tags ), array_values( $pre_tags ), $text );
	}

	// Restore newlines in all elements.
	if ( false !== strpos( $text, '<!-- wpnl -->' ) ) {
		$text = str_replace( array( ' <!-- wpnl --> ', '<!-- wpnl -->' ), "\n", $text );
	}

	return $text;
}

/**
 * Separates HTML elements and comments from the text.
 *
 * @since 4.2.4
 *
 * @param string $input The text which has to be formatted.
 * @return string[] Array of the formatted text.
 */
function wp_html_split( $input ) {
	return preg_split( get_html_split_regex(), $input, -1, PREG_SPLIT_DELIM_CAPTURE );
}

/**
 * Retrieves the regular expression for an HTML element.
 *
 * @since 4.4.0
 *
 * @return string The regular expression
 */
function get_html_split_regex() {
	static $regex;

	if ( ! isset( $regex ) ) {
		// phpcs:disable Squiz.Strings.ConcatenationSpacing.PaddingFound -- don't remove regex indentation
		$comments =
			'!'             // Start of comment, after the <.
			. '(?:'         // Unroll the loop: Consume everything until --> is found.
			.     '-(?!->)' // Dash not followed by end of comment.
			.     '[^\-]*+' // Consume non-dashes.
			. ')*+'         // Loop possessively.
			. '(?:-->)?';   // End of comment. If not found, match all input.

		$cdata =
			'!\[CDATA\['    // Start of comment, after the <.
			. '[^\]]*+'     // Consume non-].
			. '(?:'         // Unroll the loop: Consume everything until ]]> is found.
			.     '](?!]>)' // One ] not followed by end of comment.
			.     '[^\]]*+' // Consume non-].
			. ')*+'         // Loop possessively.
			. '(?:]]>)?';   // End of comment. If not found, match all input.

		$escaped =
			'(?='             // Is the element escaped?
			.    '!--'
			. '|'
			.    '!\[CDATA\['
			. ')'
			. '(?(?=!-)'      // If yes, which type?
			.     $comments
			. '|'
			.     $cdata
			. ')';

		$regex =
			'/('                // Capture the entire match.
			.     '<'           // Find start of element.
			.     '(?'          // Conditional expression follows.
			.         $escaped  // Find end of escaped element.
			.     '|'           // ...else...
			.         '[^>]*>?' // Find end of normal element.
			.     ')'
			. ')/';
		// phpcs:enable
	}

	return $regex;
}

/**
 * Retrieves the combined regular expression for HTML and shortcodes.
 *
 * @access private
 * @ignore
 * @internal This function will be removed in 4.5.0 per Shortcode API Roadmap.
 * @since 4.4.0
 *
 * @param string $shortcode_regex Optional. The result from _get_wptexturize_shortcode_regex().
 * @return string The regular expression
 */
function _get_wptexturize_split_regex( $shortcode_regex = '' ) {
	static $html_regex;

	if ( ! isset( $html_regex ) ) {
		// phpcs:disable Squiz.Strings.ConcatenationSpacing.PaddingFound -- don't remove regex indentation
		$comment_regex =
			'!'             // Start of comment, after the <.
			. '(?:'         // Unroll the loop: Consume everything until --> is found.
			.     '-(?!->)' // Dash not followed by end of comment.
			.     '[^\-]*+' // Consume non-dashes.
			. ')*+'         // Loop possessively.
			. '(?:-->)?';   // End of comment. If not found, match all input.

		$html_regex = // Needs replaced with wp_html_split() per Shortcode API Roadmap.
			'<'                  // Find start of element.
			. '(?(?=!--)'        // Is this a comment?
			.     $comment_regex // Find end of comment.
			. '|'
			.     '[^>]*>?'      // Find end of element. If not found, match all input.
			. ')';
		// phpcs:enable
	}

	if ( empty( $shortcode_regex ) ) {
		$regex = '/(' . $html_regex . ')/';
	} else {
		$regex = '/(' . $html_regex . '|' . $shortcode_regex . ')/';
	}

	return $regex;
}

/**
 * Retrieves the regular expression for shortcodes.
 *
 * @access private
 * @ignore
 * @since 4.4.0
 *
 * @param string[] $tagnames Array of shortcodes to find.
 * @return string The regular expression
 */
function _get_wptexturize_shortcode_regex( $tagnames ) {
	$tagregexp = implode( '|', array_map( 'preg_quote', $tagnames ) );
	$tagregexp = "(?:$tagregexp)(?=[\\s\\]\\/])"; // Excerpt of get_shortcode_regex().
	// phpcs:disable Squiz.Strings.ConcatenationSpacing.PaddingFound -- don't remove regex indentation
	$regex =
		'\['                // Find start of shortcode.
		. '[\/\[]?'         // Shortcodes may begin with [/ or [[.
		. $tagregexp        // Only match registered shortcodes, because performance.
		. '(?:'
		.     '[^\[\]<>]+'  // Shortcodes do not contain other shortcodes. Quantifier critical.
		. '|'
		.     '<[^\[\]>]*>' // HTML elements permitted. Prevents matching ] before >.
		. ')*+'             // Possessive critical.
		. '\]'              // Find end of shortcode.
		. '\]?';            // Shortcodes may end with ]].
	// phpcs:enable

	return $regex;
}

/**
 * Replaces characters or phrases within HTML elements only.
 *
 * @since 4.2.3
 *
 * @param string $haystack      The text which has to be formatted.
 * @param array  $replace_pairs In the form array('from' => 'to', ...).
 * @return string The formatted text.
 */
function wp_replace_in_html_tags( $haystack, $replace_pairs ) {
	// Find all elements.
	$textarr = wp_html_split( $haystack );
	$changed = false;

	// Optimize when searching for one item.
	if ( 1 === count( $replace_pairs ) ) {
		// Extract $needle and $replace.
		foreach ( $replace_pairs as $needle => $replace ) {
		}

		// Loop through delimiters (elements) only.
		for ( $i = 1, $c = count( $textarr ); $i < $c; $i += 2 ) {
			if ( false !== strpos( $textarr[ $i ], $needle ) ) {
				$textarr[ $i ] = str_replace( $needle, $replace, $textarr[ $i ] );
				$changed       = true;
			}
		}
	} else {
		// Extract all $needles.
		$needles = array_keys( $replace_pairs );

		// Loop through delimiters (elements) only.
		for ( $i = 1, $c = count( $textarr ); $i < $c; $i += 2 ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $textarr[ $i ], $needle ) ) {
					$textarr[ $i ] = strtr( $textarr[ $i ], $replace_pairs );
					$changed       = true;
					// After one strtr() break out of the foreach loop and look at next element.
					break;
				}
			}
		}
	}

	if ( $changed ) {
		$haystack = implode( $textarr );
	}

	return $haystack;
}

/**
 * Newline preservation help function for wpautop().
 *
 * @since 3.1.0
 * @access private
 *
 * @param array $matches preg_replace_callback matches array
 * @return string
 */
function _autop_newline_preservation_helper( $matches ) {
	return str_replace( "\n", '<WPPreserveNewline />', $matches[0] );
}

/**
 * Don't auto-p wrap shortcodes that stand alone.
 *
 * Ensures that shortcodes are not wrapped in `<p>...</p>`.
 *
 * @since 2.9.0
 *
 * @global array $shortcode_tags
 *
 * @param string $text The content.
 * @return string The filtered content.
 */
function shortcode_unautop( $text ) {
	global $shortcode_tags;

	if ( empty( $shortcode_tags ) || ! is_array( $shortcode_tags ) ) {
		return $text;
	}

	$tagregexp = implode( '|', array_map( 'preg_quote', array_keys( $shortcode_tags ) ) );
	$spaces    = wp_spaces_regexp();

	// phpcs:disable Squiz.Strings.ConcatenationSpacing.PaddingFound,WordPress.WhiteSpace.PrecisionAlignment.Found -- don't remove regex indentation
	$pattern =
		'/'
		. '<p>'                              // Opening paragraph.
		. '(?:' . $spaces . ')*+'            // Optional leading whitespace.
		. '('                                // 1: The shortcode.
		.     '\\['                          // Opening bracket.
		.     "($tagregexp)"                 // 2: Shortcode name.
		.     '(?![\\w-])'                   // Not followed by word character or hyphen.
											 // Unroll the loop: Inside the opening shortcode tag.
		.     '[^\\]\\/]*'                   // Not a closing bracket or forward slash.
		.     '(?:'
		.         '\\/(?!\\])'               // A forward slash not followed by a closing bracket.
		.         '[^\\]\\/]*'               // Not a closing bracket or forward slash.
		.     ')*?'
		.     '(?:'
		.         '\\/\\]'                   // Self closing tag and closing bracket.
		.     '|'
		.         '\\]'                      // Closing bracket.
		.         '(?:'                      // Unroll the loop: Optionally, anything between the opening and closing shortcode tags.
		.             '[^\\[]*+'             // Not an opening bracket.
		.             '(?:'
		.                 '\\[(?!\\/\\2\\])' // An opening bracket not followed by the closing shortcode tag.
		.                 '[^\\[]*+'         // Not an opening bracket.
		.             ')*+'
		.             '\\[\\/\\2\\]'         // Closing shortcode tag.
		.         ')?'
		.     ')'
		. ')'
		. '(?:' . $spaces . ')*+'            // Optional trailing whitespace.
		. '<\\/p>'                           // Closing paragraph.
		. '/';
	// phpcs:enable

	return preg_replace( $pattern, '$1', $text );
}

/**
 * Checks to see if a string is utf8 encoded.
 *
 * NOTE: This function checks for 5-Byte sequences, UTF8
 *       has Bytes Sequences with a maximum length of 4.
 *
 * @author bmorel at ssi dot fr (modified)
 * @since 1.2.1
 *
 * @param string $str The string to be checked
 * @return bool True if $str fits a UTF-8 model, false otherwise.
 */
function seems_utf8( $str ) {
	mbstring_binary_safe_encoding();
	$length = strlen( $str );
	reset_mbstring_encoding();
	for ( $i = 0; $i < $length; $i++ ) {
		$c = ord( $str[ $i ] );
		if ( $c < 0x80 ) {
			$n = 0; // 0bbbbbbb
		} elseif ( ( $c & 0xE0 ) == 0xC0 ) {
			$n = 1; // 110bbbbb
		} elseif ( ( $c & 0xF0 ) == 0xE0 ) {
			$n = 2; // 1110bbbb
		} elseif ( ( $c & 0xF8 ) == 0xF0 ) {
			$n = 3; // 11110bbb
		} elseif ( ( $c & 0xFC ) == 0xF8 ) {
			$n = 4; // 111110bb
		} elseif ( ( $c & 0xFE ) == 0xFC ) {
			$n = 5; // 1111110b
		} else {
			return false; // Does not match any model.
		}
		for ( $j = 0; $j < $n; $j++ ) { // n bytes matching 10bbbbbb follow ?
			if ( ( ++$i == $length ) || ( ( ord( $str[ $i ] ) & 0xC0 ) != 0x80 ) ) {
				return false;
			}
		}
	}
	return true;
}

/**
 * Converts a number of special characters into their HTML entities.
 *
 * Specifically deals with: `&`, `<`, `>`, `"`, and `'`.
 *
 * `$quote_style` can be set to ENT_COMPAT to encode `"` to
 * `&quot;`, or ENT_QUOTES to do both. Default is ENT_NOQUOTES where no quotes are encoded.
 *
 * @since 1.2.2
 * @since 5.5.0 `$quote_style` also accepts `ENT_XML1`.
 * @access private
 *
 * @param string       $text          The text which is to be encoded.
 * @param int|string   $quote_style   Optional. Converts double quotes if set to ENT_COMPAT,
 *                                    both single and double if set to ENT_QUOTES or none if set to ENT_NOQUOTES.
 *                                    Converts single and double quotes, as well as converting HTML
 *                                    named entities (that are not also XML named entities) to their
 *                                    code points if set to ENT_XML1. Also compatible with old values;
 *                                    converting single quotes if set to 'single',
 *                                    double if set to 'double' or both if otherwise set.
 *                                    Default is ENT_NOQUOTES.
 * @param false|string $charset       Optional. The character encoding of the string. Default false.
 * @param bool         $double_encode Optional. Whether to encode existing HTML entities. Default false.
 * @return string The encoded text with HTML entities.
 */
function _wp_specialchars( $text, $quote_style = ENT_NOQUOTES, $charset = false, $double_encode = false ) {
	$text = (string) $text;

	if ( 0 === strlen( $text ) ) {
		return '';
	}

	// Don't bother if there are no specialchars - saves some processing.
	if ( ! preg_match( '/[&<>"\']/', $text ) ) {
		return $text;
	}

	// Account for the previous behavior of the function when the $quote_style is not an accepted value.
	if ( empty( $quote_style ) ) {
		$quote_style = ENT_NOQUOTES;
	} elseif ( ENT_XML1 === $quote_style ) {
		$quote_style = ENT_QUOTES | ENT_XML1;
	} elseif ( ! in_array( $quote_style, array( ENT_NOQUOTES, ENT_COMPAT, ENT_QUOTES, 'single', 'double' ), true ) ) {
		$quote_style = ENT_QUOTES;
	}

	// Store the site charset as a static to avoid multiple calls to wp_load_alloptions().
	if ( ! $charset ) {
		static $_charset = null;
		if ( ! isset( $_charset ) ) {
			$alloptions = wp_load_alloptions();
			$_charset   = isset( $alloptions['blog_charset'] ) ? $alloptions['blog_charset'] : '';
		}
		$charset = $_charset;
	}

	if ( in_array( $charset, array( 'utf8', 'utf-8', 'UTF8' ), true ) ) {
		$charset = 'UTF-8';
	}

	$_quote_style = $quote_style;

	if ( 'double' === $quote_style ) {
		$quote_style  = ENT_COMPAT;
		$_quote_style = ENT_COMPAT;
	} elseif ( 'single' === $quote_style ) {
		$quote_style = ENT_NOQUOTES;
	}

	if ( ! $double_encode ) {
		// Guarantee every &entity; is valid, convert &garbage; into &amp;garbage;
		// This is required for PHP < 5.4.0 because ENT_HTML401 flag is unavailable.
		$text = wp_kses_normalize_entities( $text, ( $quote_style & ENT_XML1 ) ? 'xml' : 'html' );
	}

	$text = htmlspecialchars( $text, $quote_style, $charset, $double_encode );

	// Back-compat.
	if ( 'single' === $_quote_style ) {
		$text = str_replace( "'", '&#039;', $text );
	}

	return $text;
}

/**
 * Converts a number of HTML entities into their special characters.
 *
 * Specifically deals with: `&`, `<`, `>`, `"`, and `'`.
 *
 * `$quote_style` can be set to ENT_COMPAT to decode `"` entities,
 * or ENT_QUOTES to do both `"` and `'`. Default is ENT_NOQUOTES where no quotes are decoded.
 *
 * @since 2.8.0
 *
 * @param string     $text        The text which is to be decoded.
 * @param string|int $quote_style Optional. Converts double quotes if set to ENT_COMPAT,
 *                                both single and double if set to ENT_QUOTES or
 *                                none if set to ENT_NOQUOTES.
 *                                Also compatible with old _wp_specialchars() values;
 *                                converting single quotes if set to 'single',
 *                                double if set to 'double' or both if otherwise set.
 *                                Default is ENT_NOQUOTES.
 * @return string The decoded text without HTML entities.
 */
function wp_specialchars_decode( $text, $quote_style = ENT_NOQUOTES ) {
	$text = (string) $text;

	if ( 0 === strlen( $text ) ) {
		return '';
	}

	// Don't bother if there are no entities - saves a lot of processing.
	if ( strpos( $text, '&' ) === false ) {
		return $text;
	}

	// Match the previous behavior of _wp_specialchars() when the $quote_style is not an accepted value.
	if ( empty( $quote_style ) ) {
		$quote_style = ENT_NOQUOTES;
	} elseif ( ! in_array( $quote_style, array( 0, 2, 3, 'single', 'double' ), true ) ) {
		$quote_style = ENT_QUOTES;
	}

	// More complete than get_html_translation_table( HTML_SPECIALCHARS ).
	$single      = array(
		'&#039;' => '\'',
		'&#x27;' => '\'',
	);
	$single_preg = array(
		'/&#0*39;/'   => '&#039;',
		'/&#x0*27;/i' => '&#x27;',
	);
	$double      = array(
		'&quot;' => '"',
		'&#034;' => '"',
		'&#x22;' => '"',
	);
	$double_preg = array(
		'/&#0*34;/'   => '&#034;',
		'/&#x0*22;/i' => '&#x22;',
	);
	$others      = array(
		'&lt;'   => '<',
		'&#060;' => '<',
		'&gt;'   => '>',
		'&#062;' => '>',
		'&amp;'  => '&',
		'&#038;' => '&',
		'&#x26;' => '&',
	);
	$others_preg = array(
		'/&#0*60;/'   => '&#060;',
		'/&#0*62;/'   => '&#062;',
		'/&#0*38;/'   => '&#038;',
		'/&#x0*26;/i' => '&#x26;',
	);

	if ( ENT_QUOTES === $quote_style ) {
		$translation      = array_merge( $single, $double, $others );
		$translation_preg = array_merge( $single_preg, $double_preg, $others_preg );
	} elseif ( ENT_COMPAT === $quote_style || 'double' === $quote_style ) {
		$translation      = array_merge( $double, $others );
		$translation_preg = array_merge( $double_preg, $others_preg );
	} elseif ( 'single' === $quote_style ) {
		$translation      = array_merge( $single, $others );
		$translation_preg = array_merge( $single_preg, $others_preg );
	} elseif ( ENT_NOQUOTES === $quote_style ) {
		$translation      = $others;
		$translation_preg = $others_preg;
	}

	// Remove zero padding on numeric entities.
	$text = preg_replace( array_keys( $translation_preg ), array_values( $translation_preg ), $text );

	// Replace characters according to translation table.
	return strtr( $text, $translation );
}

/**
 * Checks for invalid UTF8 in a string.
 *
 * @since 2.8.0
 *
 * @param string $text   The text which is to be checked.
 * @param bool   $strip  Optional. Whether to attempt to strip out invalid UTF8. Default false.
 * @return string The checked text.
 */
function wp_check_invalid_utf8( $text, $strip = false ) {
	$text = (string) $text;

	if ( 0 === strlen( $text ) ) {
		return '';
	}

	// Store the site charset as a static to avoid multiple calls to get_option().
	static $is_utf8 = null;
	if ( ! isset( $is_utf8 ) ) {
		$is_utf8 = in_array( get_option( 'blog_charset' ), array( 'utf8', 'utf-8', 'UTF8', 'UTF-8' ), true );
	}
	if ( ! $is_utf8 ) {
		return $text;
	}

	// Check for support for utf8 in the installed PCRE library once and store the result in a static.
	static $utf8_pcre = null;
	if ( ! isset( $utf8_pcre ) ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$utf8_pcre = @preg_match( '/^./u', 'a' );
	}
	// We can't demand utf8 in the PCRE installation, so just return the string in those cases.
	if ( ! $utf8_pcre ) {
		return $text;
	}

	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- preg_match fails when it encounters invalid UTF8 in $text.
	if ( 1 === @preg_match( '/^./us', $text ) ) {
		return $text;
	}

	// Attempt to strip the bad chars if requested (not recommended).
	if ( $strip && function_exists( 'iconv' ) ) {
		return iconv( 'utf-8', 'utf-8', $text );
	}

	return '';
}

/**
 * Encodes the Unicode values to be used in the URI.
 *
 * @since 1.5.0
 * @since 5.8.3 Added the `encode_ascii_characters` parameter.
 *
 * @param string $utf8_string             String to encode.
 * @param int    $length                  Max length of the string
 * @param bool   $encode_ascii_characters Whether to encode ascii characters such as < " '
 * @return string String with Unicode encoded for URI.
 */
function utf8_uri_encode( $utf8_string, $length = 0, $encode_ascii_characters = false ) {
	$unicode        = '';
	$values         = array();
	$num_octets     = 1;
	$unicode_length = 0;

	mbstring_binary_safe_encoding();
	$string_length = strlen( $utf8_string );
	reset_mbstring_encoding();

	for ( $i = 0; $i < $string_length; $i++ ) {

		$value = ord( $utf8_string[ $i ] );

		if ( $value < 128 ) {
			$char                = chr( $value );
			$encoded_char        = $encode_ascii_characters ? rawurlencode( $char ) : $char;
			$encoded_char_length = strlen( $encoded_char );
			if ( $length && ( $unicode_length + $encoded_char_length ) > $length ) {
				break;
			}
			$unicode        .= $encoded_char;
			$unicode_length += $encoded_char_length;
		} else {
			if ( count( $values ) == 0 ) {
				if ( $value < 224 ) {
					$num_octets = 2;
				} elseif ( $value < 240 ) {
					$num_octets = 3;
				} else {
					$num_octets = 4;
				}
			}

			$values[] = $value;

			if ( $length && ( $unicode_length + ( $num_octets * 3 ) ) > $length ) {
				break;
			}
			if ( count( $values ) == $num_octets ) {
				for ( $j = 0; $j < $num_octets; $j++ ) {
					$unicode .= '%' . dechex( $values[ $j ] );
				}

				$unicode_length += $num_octets * 3;

				$values     = array();
				$num_octets = 1;
			}
		}
	}

	return $unicode;
}

/**
 * Converts all accent characters to ASCII characters.
 *
 * If there are no accent characters, then the string given is just returned.
 *
 * **Accent characters converted:**
 *
 * Currency signs:
 *
 * |   Code   | Glyph | Replacement |     Description     |
 * | -------- | ----- | ----------- | ------------------- |
 * | U+00A3   | ??     | (empty)     | British Pound sign  |
 * | U+20AC   | ???     | E           | Euro sign           |
 *
 * Decompositions for Latin-1 Supplement:
 *
 * |  Code   | Glyph | Replacement |               Description              |
 * | ------- | ----- | ----------- | -------------------------------------- |
 * | U+00AA  | ??     | a           | Feminine ordinal indicator             |
 * | U+00BA  | ??     | o           | Masculine ordinal indicator            |
 * | U+00C0  | ??     | A           | Latin capital letter A with grave      |
 * | U+00C1  | ??     | A           | Latin capital letter A with acute      |
 * | U+00C2  | ??     | A           | Latin capital letter A with circumflex |
 * | U+00C3  | ??     | A           | Latin capital letter A with tilde      |
 * | U+00C4  | ??     | A           | Latin capital letter A with diaeresis  |
 * | U+00C5  | ??     | A           | Latin capital letter A with ring above |
 * | U+00C6  | ??     | AE          | Latin capital letter AE                |
 * | U+00C7  | ??     | C           | Latin capital letter C with cedilla    |
 * | U+00C8  | ??     | E           | Latin capital letter E with grave      |
 * | U+00C9  | ??     | E           | Latin capital letter E with acute      |
 * | U+00CA  | ??     | E           | Latin capital letter E with circumflex |
 * | U+00CB  | ??     | E           | Latin capital letter E with diaeresis  |
 * | U+00CC  | ??     | I           | Latin capital letter I with grave      |
 * | U+00CD  | ??     | I           | Latin capital letter I with acute      |
 * | U+00CE  | ??     | I           | Latin capital letter I with circumflex |
 * | U+00CF  | ??     | I           | Latin capital letter I with diaeresis  |
 * | U+00D0  | ??     | D           | Latin capital letter Eth               |
 * | U+00D1  | ??     | N           | Latin capital letter N with tilde      |
 * | U+00D2  | ??     | O           | Latin capital letter O with grave      |
 * | U+00D3  | ??     | O           | Latin capital letter O with acute      |
 * | U+00D4  | ??     | O           | Latin capital letter O with circumflex |
 * | U+00D5  | ??     | O           | Latin capital letter O with tilde      |
 * | U+00D6  | ??     | O           | Latin capital letter O with diaeresis  |
 * | U+00D8  | ??     | O           | Latin capital letter O with stroke     |
 * | U+00D9  | ??     | U           | Latin capital letter U with grave      |
 * | U+00DA  | ??     | U           | Latin capital letter U with acute      |
 * | U+00DB  | ??     | U           | Latin capital letter U with circumflex |
 * | U+00DC  | ??     | U           | Latin capital letter U with diaeresis  |
 * | U+00DD  | ??     | Y           | Latin capital letter Y with acute      |
 * | U+00DE  | ??     | TH          | Latin capital letter Thorn             |
 * | U+00DF  | ??     | s           | Latin small letter sharp s             |
 * | U+00E0  | ??     | a           | Latin small letter a with grave        |
 * | U+00E1  | ??     | a           | Latin small letter a with acute        |
 * | U+00E2  | ??     | a           | Latin small letter a with circumflex   |
 * | U+00E3  | ??     | a           | Latin small letter a with tilde        |
 * | U+00E4  | ??     | a           | Latin small letter a with diaeresis    |
 * | U+00E5  | ??     | a           | Latin small letter a with ring above   |
 * | U+00E6  | ??     | ae          | Latin small letter ae                  |
 * | U+00E7  | ??     | c           | Latin small letter c with cedilla      |
 * | U+00E8  | ??     | e           | Latin small letter e with grave        |
 * | U+00E9  | ??     | e           | Latin small letter e with acute        |
 * | U+00EA  | ??     | e           | Latin small letter e with circumflex   |
 * | U+00EB  | ??     | e           | Latin small letter e with diaeresis    |
 * | U+00EC  | ??     | i           | Latin small letter i with grave        |
 * | U+00ED  | ??     | i           | Latin small letter i with acute        |
 * | U+00EE  | ??     | i           | Latin small letter i with circumflex   |
 * | U+00EF  | ??     | i           | Latin small letter i with diaeresis    |
 * | U+00F0  | ??     | d           | Latin small letter Eth                 |
 * | U+00F1  | ??     | n           | Latin small letter n with tilde        |
 * | U+00F2  | ??     | o           | Latin small letter o with grave        |
 * | U+00F3  | ??     | o           | Latin small letter o with acute        |
 * | U+00F4  | ??     | o           | Latin small letter o with circumflex   |
 * | U+00F5  | ??     | o           | Latin small letter o with tilde        |
 * | U+00F6  | ??     | o           | Latin small letter o with diaeresis    |
 * | U+00F8  | ??     | o           | Latin small letter o with stroke       |
 * | U+00F9  | ??     | u           | Latin small letter u with grave        |
 * | U+00FA  | ??     | u           | Latin small letter u with acute        |
 * | U+00FB  | ??     | u           | Latin small letter u with circumflex   |
 * | U+00FC  | ??     | u           | Latin small letter u with diaeresis    |
 * | U+00FD  | ??     | y           | Latin small letter y with acute        |
 * | U+00FE  | ??     | th          | Latin small letter Thorn               |
 * | U+00FF  | ??     | y           | Latin small letter y with diaeresis    |
 *
 * Decompositions for Latin Extended-A:
 *
 * |  Code   | Glyph | Replacement |                    Description                    |
 * | ------- | ----- | ----------- | ------------------------------------------------- |
 * | U+0100  | ??     | A           | Latin capital letter A with macron                |
 * | U+0101  | ??     | a           | Latin small letter a with macron                  |
 * | U+0102  | ??     | A           | Latin capital letter A with breve                 |
 * | U+0103  | ??     | a           | Latin small letter a with breve                   |
 * | U+0104  | ??     | A           | Latin capital letter A with ogonek                |
 * | U+0105  | ??     | a           | Latin small letter a with ogonek                  |
 * | U+01006 | ??     | C           | Latin capital letter C with acute                 |
 * | U+0107  | ??     | c           | Latin small letter c with acute                   |
 * | U+0108  | ??     | C           | Latin capital letter C with circumflex            |
 * | U+0109  | ??     | c           | Latin small letter c with circumflex              |
 * | U+010A  | ??     | C           | Latin capital letter C with dot above             |
 * | U+010B  | ??     | c           | Latin small letter c with dot above               |
 * | U+010C  | ??     | C           | Latin capital letter C with caron                 |
 * | U+010D  | ??     | c           | Latin small letter c with caron                   |
 * | U+010E  | ??     | D           | Latin capital letter D with caron                 |
 * | U+010F  | ??     | d           | Latin small letter d with caron                   |
 * | U+0110  | ??     | D           | Latin capital letter D with stroke                |
 * | U+0111  | ??     | d           | Latin small letter d with stroke                  |
 * | U+0112  | ??     | E           | Latin capital letter E with macron                |
 * | U+0113  | ??     | e           | Latin small letter e with macron                  |
 * | U+0114  | ??     | E           | Latin capital letter E with breve                 |
 * | U+0115  | ??     | e           | Latin small letter e with breve                   |
 * | U+0116  | ??     | E           | Latin capital letter E with dot above             |
 * | U+0117  | ??     | e           | Latin small letter e with dot above               |
 * | U+0118  | ??     | E           | Latin capital letter E with ogonek                |
 * | U+0119  | ??     | e           | Latin small letter e with ogonek                  |
 * | U+011A  | ??     | E           | Latin capital letter E with caron                 |
 * | U+011B  | ??     | e           | Latin small letter e with caron                   |
 * | U+011C  | ??     | G           | Latin capital letter G with circumflex            |
 * | U+011D  | ??     | g           | Latin small letter g with circumflex              |
 * | U+011E  | ??     | G           | Latin capital letter G with breve                 |
 * | U+011F  | ??     | g           | Latin small letter g with breve                   |
 * | U+0120  | ??     | G           | Latin capital letter G with dot above             |
 * | U+0121  | ??     | g           | Latin small letter g with dot above               |
 * | U+0122  | ??     | G           | Latin capital letter G with cedilla               |
 * | U+0123  | ??     | g           | Latin small letter g with cedilla                 |
 * | U+0124  | ??     | H           | Latin capital letter H with circumflex            |
 * | U+0125  | ??     | h           | Latin small letter h with circumflex              |
 * | U+0126  | ??     | H           | Latin capital letter H with stroke                |
 * | U+0127  | ??     | h           | Latin small letter h with stroke                  |
 * | U+0128  | ??     | I           | Latin capital letter I with tilde                 |
 * | U+0129  | ??     | i           | Latin small letter i with tilde                   |
 * | U+012A  | ??     | I           | Latin capital letter I with macron                |
 * | U+012B  | ??     | i           | Latin small letter i with macron                  |
 * | U+012C  | ??     | I           | Latin capital letter I with breve                 |
 * | U+012D  | ??     | i           | Latin small letter i with breve                   |
 * | U+012E  | ??     | I           | Latin capital letter I with ogonek                |
 * | U+012F  | ??     | i           | Latin small letter i with ogonek                  |
 * | U+0130  | ??     | I           | Latin capital letter I with dot above             |
 * | U+0131  | ??     | i           | Latin small letter dotless i                      |
 * | U+0132  | ??     | IJ          | Latin capital ligature IJ                         |
 * | U+0133  | ??     | ij          | Latin small ligature ij                           |
 * | U+0134  | ??     | J           | Latin capital letter J with circumflex            |
 * | U+0135  | ??     | j           | Latin small letter j with circumflex              |
 * | U+0136  | ??     | K           | Latin capital letter K with cedilla               |
 * | U+0137  | ??     | k           | Latin small letter k with cedilla                 |
 * | U+0138  | ??     | k           | Latin small letter Kra                            |
 * | U+0139  | ??     | L           | Latin capital letter L with acute                 |
 * | U+013A  | ??     | l           | Latin small letter l with acute                   |
 * | U+013B  | ??     | L           | Latin capital letter L with cedilla               |
 * | U+013C  | ??     | l           | Latin small letter l with cedilla                 |
 * | U+013D  | ??     | L           | Latin capital letter L with caron                 |
 * | U+013E  | ??     | l           | Latin small letter l with caron                   |
 * | U+013F  | ??     | L           | Latin capital letter L with middle dot            |
 * | U+0140  | ??     | l           | Latin small letter l with middle dot              |
 * | U+0141  | ??     | L           | Latin capital letter L with stroke                |
 * | U+0142  | ??     | l           | Latin small letter l with stroke                  |
 * | U+0143  | ??     | N           | Latin capital letter N with acute                 |
 * | U+0144  | ??     | n           | Latin small letter N with acute                   |
 * | U+0145  | ??     | N           | Latin capital letter N with cedilla               |
 * | U+0146  | ??     | n           | Latin small letter n with cedilla                 |
 * | U+0147  | ??     | N           | Latin capital letter N with caron                 |
 * | U+0148  | ??     | n           | Latin small letter n with caron                   |
 * | U+0149  | ??     | n           | Latin small letter n preceded by apostrophe       |
 * | U+014A  | ??     | N           | Latin capital letter Eng                          |
 * | U+014B  | ??     | n           | Latin small letter Eng                            |
 * | U+014C  | ??     | O           | Latin capital letter O with macron                |
 * | U+014D  | ??     | o           | Latin small letter o with macron                  |
 * | U+014E  | ??     | O           | Latin capital letter O with breve                 |
 * | U+014F  | ??     | o           | Latin small letter o with breve                   |
 * | U+0150  | ??     | O           | Latin capital letter O with double acute          |
 * | U+0151  | ??     | o           | Latin small letter o with double acute            |
 * | U+0152  | ??     | OE          | Latin capital ligature OE                         |
 * | U+0153  | ??     | oe          | Latin small ligature oe                           |
 * | U+0154  | ??     | R           | Latin capital letter R with acute                 |
 * | U+0155  | ??     | r           | Latin small letter r with acute                   |
 * | U+0156  | ??     | R           | Latin capital letter R with cedilla               |
 * | U+0157  | ??     | r           | Latin small letter r with cedilla                 |
 * | U+0158  | ??     | R           | Latin capital letter R with caron                 |
 * | U+0159  | ??     | r           | Latin small letter r with caron                   |
 * | U+015A  | ??     | S           | Latin capital letter S with acute                 |
 * | U+015B  | ??     | s           | Latin small letter s with acute                   |
 * | U+015C  | ??     | S           | Latin capital letter S with circumflex            |
 * | U+015D  | ??     | s           | Latin small letter s with circumflex              |
 * | U+015E  | ??     | S           | Latin capital letter S with cedilla               |
 * | U+015F  | ??     | s           | Latin small letter s with cedilla                 |
 * | U+0160  | ??     | S           | Latin capital letter S with caron                 |
 * | U+0161  | ??     | s           | Latin small letter s with caron                   |
 * | U+0162  | ??     | T           | Latin capital letter T with cedilla               |
 * | U+0163  | ??     | t           | Latin small letter t with cedilla                 |
 * | U+0164  | ??     | T           | Latin capital letter T with caron                 |
 * | U+0165  | ??     | t           | Latin small letter t with caron                   |
 * | U+0166  | ??     | T           | Latin capital letter T with stroke                |
 * | U+0167  | ??     | t           | Latin small letter t with stroke                  |
 * | U+0168  | ??     | U           | Latin capital letter U with tilde                 |
 * | U+0169  | ??     | u           | Latin small letter u with tilde                   |
 * | U+016A  | ??     | U           | Latin capital letter U with macron                |
 * | U+016B  | ??     | u           | Latin small letter u with macron                  |
 * | U+016C  | ??     | U           | Latin capital letter U with breve                 |
 * | U+016D  | ??     | u           | Latin small letter u with breve                   |
 * | U+016E  | ??     | U           | Latin capital letter U with ring above            |
 * | U+016F  | ??     | u           | Latin small letter u with ring above              |
 * | U+0170  | ??     | U           | Latin capital letter U with double acute          |
 * | U+0171  | ??     | u           | Latin small letter u with double acute            |
 * | U+0172  | ??     | U           | Latin capital letter U with ogonek                |
 * | U+0173  | ??     | u           | Latin small letter u with ogonek                  |
 * | U+0174  | ??     | W           | Latin capital letter W with circumflex            |
 * | U+0175  | ??     | w           | Latin small letter w with circumflex              |
 * | U+0176  | ??     | Y           | Latin capital letter Y with circumflex            |
 * | U+0177  | ??     | y           | Latin small letter y with circumflex              |
 * | U+0178  | ??     | Y           | Latin capital letter Y with diaeresis             |
 * | U+0179  | ??     | Z           | Latin capital letter Z with acute                 |
 * | U+017A  | ??     | z           | Latin small letter z with acute                   |
 * | U+017B  | ??     | Z           | Latin capital letter Z with dot above             |
 * | U+017C  | ??     | z           | Latin small letter z with dot above               |
 * | U+017D  | ??     | Z           | Latin capital letter Z with caron                 |
 * | U+017E  | ??     | z           | Latin small letter z with caron                   |
 * | U+017F  | ??     | s           | Latin small letter long s                         |
 * | U+01A0  | ??     | O           | Latin capital letter O with horn                  |
 * | U+01A1  | ??     | o           | Latin small letter o with horn                    |
 * | U+01AF  | ??     | U           | Latin capital letter U with horn                  |
 * | U+01B0  | ??     | u           | Latin small letter u with horn                    |
 * | U+01CD  | ??     | A           | Latin capital letter A with caron                 |
 * | U+01CE  | ??     | a           | Latin small letter a with caron                   |
 * | U+01CF  | ??     | I           | Latin capital letter I with caron                 |
 * | U+01D0  | ??     | i           | Latin small letter i with caron                   |
 * | U+01D1  | ??     | O           | Latin capital letter O with caron                 |
 * | U+01D2  | ??     | o           | Latin small letter o with caron                   |
 * | U+01D3  | ??     | U           | Latin capital letter U with caron                 |
 * | U+01D4  | ??     | u           | Latin small letter u with caron                   |
 * | U+01D5  | ??     | U           | Latin capital letter U with diaeresis and macron  |
 * | U+01D6  | ??     | u           | Latin small letter u with diaeresis and macron    |
 * | U+01D7  | ??     | U           | Latin capital letter U with diaeresis and acute   |
 * | U+01D8  | ??     | u           | Latin small letter u with diaeresis and acute     |
 * | U+01D9  | ??     | U           | Latin capital letter U with diaeresis and caron   |
 * | U+01DA  | ??     | u           | Latin small letter u with diaeresis and caron     |
 * | U+01DB  | ??     | U           | Latin capital letter U with diaeresis and grave   |
 * | U+01DC  | ??     | u           | Latin small letter u with diaeresis and grave     |
 *
 * Decompositions for Latin Extended-B:
 *
 * |   Code   | Glyph | Replacement |                Description                |
 * | -------- | ----- | ----------- | ----------------------------------------- |
 * | U+0218   | ??     | S           | Latin capital letter S with comma below   |
 * | U+0219   | ??     | s           | Latin small letter s with comma below     |
 * | U+021A   | ??     | T           | Latin capital letter T with comma below   |
 * | U+021B   | ??     | t           | Latin small letter t with comma below     |
 *
 * Vowels with diacritic (Chinese, Hanyu Pinyin):
 *
 * |   Code   | Glyph | Replacement |                      Description                      |
 * | -------- | ----- | ----------- | ----------------------------------------------------- |
 * | U+0251   | ??     | a           | Latin small letter alpha                              |
 * | U+1EA0   | ???     | A           | Latin capital letter A with dot below                 |
 * | U+1EA1   | ???     | a           | Latin small letter a with dot below                   |
 * | U+1EA2   | ???     | A           | Latin capital letter A with hook above                |
 * | U+1EA3   | ???     | a           | Latin small letter a with hook above                  |
 * | U+1EA4   | ???     | A           | Latin capital letter A with circumflex and acute      |
 * | U+1EA5   | ???     | a           | Latin small letter a with circumflex and acute        |
 * | U+1EA6   | ???     | A           | Latin capital letter A with circumflex and grave      |
 * | U+1EA7   | ???     | a           | Latin small letter a with circumflex and grave        |
 * | U+1EA8   | ???     | A           | Latin capital letter A with circumflex and hook above |
 * | U+1EA9   | ???     | a           | Latin small letter a with circumflex and hook above   |
 * | U+1EAA   | ???     | A           | Latin capital letter A with circumflex and tilde      |
 * | U+1EAB   | ???     | a           | Latin small letter a with circumflex and tilde        |
 * | U+1EA6   | ???     | A           | Latin capital letter A with circumflex and dot below  |
 * | U+1EAD   | ???     | a           | Latin small letter a with circumflex and dot below    |
 * | U+1EAE   | ???     | A           | Latin capital letter A with breve and acute           |
 * | U+1EAF   | ???     | a           | Latin small letter a with breve and acute             |
 * | U+1EB0   | ???     | A           | Latin capital letter A with breve and grave           |
 * | U+1EB1   | ???     | a           | Latin small letter a with breve and grave             |
 * | U+1EB2   | ???     | A           | Latin capital letter A with breve and hook above      |
 * | U+1EB3   | ???     | a           | Latin small letter a with breve and hook above        |
 * | U+1EB4   | ???     | A           | Latin capital letter A with breve and tilde           |
 * | U+1EB5   | ???     | a           | Latin small letter a with breve and tilde             |
 * | U+1EB6   | ???     | A           | Latin capital letter A with breve and dot below       |
 * | U+1EB7   | ???     | a           | Latin small letter a with breve and dot below         |
 * | U+1EB8   | ???     | E           | Latin capital letter E with dot below                 |
 * | U+1EB9   | ???     | e           | Latin small letter e with dot below                   |
 * | U+1EBA   | ???     | E           | Latin capital letter E with hook above                |
 * | U+1EBB   | ???     | e           | Latin small letter e with hook above                  |
 * | U+1EBC   | ???     | E           | Latin capital letter E with tilde                     |
 * | U+1EBD   | ???     | e           | Latin small letter e with tilde                       |
 * | U+1EBE   | ???     | E           | Latin capital letter E with circumflex and acute      |
 * | U+1EBF   | ???     | e           | Latin small letter e with circumflex and acute        |
 * | U+1EC0   | ???     | E           | Latin capital letter E with circumflex and grave      |
 * | U+1EC1   | ???     | e           | Latin small letter e with circumflex and grave        |
 * | U+1EC2   | ???     | E           | Latin capital letter E with circumflex and hook above |
 * | U+1EC3   | ???     | e           | Latin small letter e with circumflex and hook above   |
 * | U+1EC4   | ???     | E           | Latin capital letter E with circumflex and tilde      |
 * | U+1EC5   | ???     | e           | Latin small letter e with circumflex and tilde        |
 * | U+1EC6   | ???     | E           | Latin capital letter E with circumflex and dot below  |
 * | U+1EC7   | ???     | e           | Latin small letter e with circumflex and dot below    |
 * | U+1EC8   | ???     | I           | Latin capital letter I with hook above                |
 * | U+1EC9   | ???     | i           | Latin small letter i with hook above                  |
 * | U+1ECA   | ???     | I           | Latin capital letter I with dot below                 |
 * | U+1ECB   | ???     | i           | Latin small letter i with dot below                   |
 * | U+1ECC   | ???     | O           | Latin capital letter O with dot below                 |
 * | U+1ECD   | ???     | o           | Latin small letter o with dot below                   |
 * | U+1ECE   | ???     | O           | Latin capital letter O with hook above                |
 * | U+1ECF   | ???     | o           | Latin small letter o with hook above                  |
 * | U+1ED0   | ???     | O           | Latin capital letter O with circumflex and acute      |
 * | U+1ED1   | ???     | o           | Latin small letter o with circumflex and acute        |
 * | U+1ED2   | ???     | O           | Latin capital letter O with circumflex and grave      |
 * | U+1ED3   | ???     | o           | Latin small letter o with circumflex and grave        |
 * | U+1ED4   | ???     | O           | Latin capital letter O with circumflex and hook above |
 * | U+1ED5   | ???     | o           | Latin small letter o with circumflex and hook above   |
 * | U+1ED6   | ???     | O           | Latin capital letter O with circumflex and tilde      |
 * | U+1ED7   | ???     | o           | Latin small letter o with circumflex and tilde        |
 * | U+1ED8   | ???     | O           | Latin capital letter O with circumflex and dot below  |
 * | U+1ED9   | ???     | o           | Latin small letter o with circumflex and dot below    |
 * | U+1EDA   | ???     | O           | Latin capital letter O with horn and acute            |
 * | U+1EDB   | ???     | o           | Latin small letter o with horn and acute              |
 * | U+1EDC   | ???     | O           | Latin capital letter O with horn and grave            |
 * | U+1EDD   | ???     | o           | Latin small letter o with horn and grave              |
 * | U+1EDE   | ???     | O           | Latin capital letter O with horn and hook above       |
 * | U+1EDF   | ???     | o           | Latin small letter o with horn and hook above         |
 * | U+1EE0   | ???     | O           | Latin capital letter O with horn and tilde            |
 * | U+1EE1   | ???     | o           | Latin small letter o with horn and tilde              |
 * | U+1EE2   | ???     | O           | Latin capital letter O with horn and dot below        |
 * | U+1EE3   | ???     | o           | Latin small letter o with horn and dot below          |
 * | U+1EE4   | ???     | U           | Latin capital letter U with dot below                 |
 * | U+1EE5   | ???     | u           | Latin small letter u with dot below                   |
 * | U+1EE6   | ???     | U           | Latin capital letter U with hook above                |
 * | U+1EE7   | ???     | u           | Latin small letter u with hook above                  |
 * | U+1EE8   | ???     | U           | Latin capital letter U with horn and acute            |
 * | U+1EE9   | ???     | u           | Latin small letter u with horn and acute              |
 * | U+1EEA   | ???     | U           | Latin capital letter U with horn and grave            |
 * | U+1EEB   | ???     | u           | Latin small letter u with horn and grave              |
 * | U+1EEC   | ???     | U           | Latin capital letter U with horn and hook above       |
 * | U+1EED   | ???     | u           | Latin small letter u with horn and hook above         |
 * | U+1EEE   | ???     | U           | Latin capital letter U with horn and tilde            |
 * | U+1EEF   | ???     | u           | Latin small letter u with horn and tilde              |
 * | U+1EF0   | ???     | U           | Latin capital letter U with horn and dot below        |
 * | U+1EF1   | ???     | u           | Latin small letter u with horn and dot below          |
 * | U+1EF2   | ???     | Y           | Latin capital letter Y with grave                     |
 * | U+1EF3   | ???     | y           | Latin small letter y with grave                       |
 * | U+1EF4   | ???     | Y           | Latin capital letter Y with dot below                 |
 * | U+1EF5   | ???     | y           | Latin small letter y with dot below                   |
 * | U+1EF6   | ???     | Y           | Latin capital letter Y with hook above                |
 * | U+1EF7   | ???     | y           | Latin small letter y with hook above                  |
 * | U+1EF8   | ???     | Y           | Latin capital letter Y with tilde                     |
 * | U+1EF9   | ???     | y           | Latin small letter y with tilde                       |
 *
 * German (`de_DE`), German formal (`de_DE_formal`), German (Switzerland) formal (`de_CH`),
 * German (Switzerland) informal (`de_CH_informal`), and German (Austria) (`de_AT`) locales:
 *
 * |   Code   | Glyph | Replacement |               Description               |
 * | -------- | ----- | ----------- | --------------------------------------- |
 * | U+00C4   | ??     | Ae          | Latin capital letter A with diaeresis   |
 * | U+00E4   | ??     | ae          | Latin small letter a with diaeresis     |
 * | U+00D6   | ??     | Oe          | Latin capital letter O with diaeresis   |
 * | U+00F6   | ??     | oe          | Latin small letter o with diaeresis     |
 * | U+00DC   | ??     | Ue          | Latin capital letter U with diaeresis   |
 * | U+00FC   | ??     | ue          | Latin small letter u with diaeresis     |
 * | U+00DF   | ??     | ss          | Latin small letter sharp s              |
 *
 * Danish (`da_DK`) locale:
 *
 * |   Code   | Glyph | Replacement |               Description               |
 * | -------- | ----- | ----------- | --------------------------------------- |
 * | U+00C6   | ??     | Ae          | Latin capital letter AE                 |
 * | U+00E6   | ??     | ae          | Latin small letter ae                   |
 * | U+00D8   | ??     | Oe          | Latin capital letter O with stroke      |
 * | U+00F8   | ??     | oe          | Latin small letter o with stroke        |
 * | U+00C5   | ??     | Aa          | Latin capital letter A with ring above  |
 * | U+00E5   | ??     | aa          | Latin small letter a with ring above    |
 *
 * Catalan (`ca`) locale:
 *
 * |   Code   | Glyph | Replacement |               Description               |
 * | -------- | ----- | ----------- | --------------------------------------- |
 * | U+00B7   | l??l   | ll          | Flown dot (between two Ls)              |
 *
 * Serbian (`sr_RS`) and Bosnian (`bs_BA`) locales:
 *
 * |   Code   | Glyph | Replacement |               Description               |
 * | -------- | ----- | ----------- | --------------------------------------- |
 * | U+0110   | ??     | DJ          | Latin capital letter D with stroke      |
 * | U+0111   | ??     | dj          | Latin small letter d with stroke        |
 *
 * @since 1.2.1
 * @since 4.6.0 Added locale support for `de_CH`, `de_CH_informal`, and `ca`.
 * @since 4.7.0 Added locale support for `sr_RS`.
 * @since 4.8.0 Added locale support for `bs_BA`.
 * @since 5.7.0 Added locale support for `de_AT`.
 * @since 6.0.0 Added the `$locale` parameter.
 * @since 6.1.0 Added Unicode NFC encoding normalization support.
 *
 * @param string $text   Text that might have accent characters.
 * @param string $locale Optional. The locale to use for accent removal. Some character
 *                       replacements depend on the locale being used (e.g. 'de_DE').
 *                       Defaults to the current locale.
 * @return string Filtered string with replaced "nice" characters.
 */
function remove_accents( $text, $locale = '' ) {
	if ( ! preg_match( '/[\x80-\xff]/', $text ) ) {
		return $text;
	}

	if ( seems_utf8( $text ) ) {

		// Unicode sequence normalization from NFD (Normalization Form Decomposed)
		// to NFC (Normalization Form [Pre]Composed), the encoding used in this function.
		if ( function_exists( 'normalizer_is_normalized' )
			&& function_exists( 'normalizer_normalize' )
		) {
			if ( ! normalizer_is_normalized( $text ) ) {
				$text = normalizer_normalize( $text );
			}
		}

		$chars = array(
			// Decompositions for Latin-1 Supplement.
			'??' => 'a',
			'??' => 'o',
			'??' => 'A',
			'??' => 'A',
			'??' => 'A',
			'??' => 'A',
			'??' => 'A',
			'??' => 'A',
			'??' => 'AE',
			'??' => 'C',
			'??' => 'E',
			'??' => 'E',
			'??' => 'E',
			'??' => 'E',
			'??' => 'I',
			'??' => 'I',
			'??' => 'I',
			'??' => 'I',
			'??' => 'D',
			'??' => 'N',
			'??' => 'O',
			'??' => 'O',
			'??' => 'O',
			'??' => 'O',
			'??' => 'O',
			'??' => 'U',
			'??' => 'U',
			'??' => 'U',
			'??' => 'U',
			'??' => 'Y',
			'??' => 'TH',
			'??' => 's',
			'??' => 'a',
			'??' => 'a',
			'??' => 'a',
			'??' => 'a',
			'??' => 'a',
			'??' => 'a',
			'??' => 'ae',
			'??' => 'c',
			'??' => 'e',
			'??' => 'e',
			'??' => 'e',
			'??' => 'e',
			'??' => 'i',
			'??' => 'i',
			'??' => 'i',
			'??' => 'i',
			'??' => 'd',
			'??' => 'n',
			'??' => 'o',
			'??' => 'o',
			'??' => 'o',
			'??' => 'o',
			'??' => 'o',
			'??' => 'o',
			'??' => 'u',
			'??' => 'u',
			'??' => 'u',
			'??' => 'u',
			'??' => 'y',
			'??' => 'th',
			'??' => 'y',
			'??' => 'O',
			// Decompositions for Latin Extended-A.
			'??' => 'A',
			'??' => 'a',
			'??' => 'A',
			'??' => 'a',
			'??' => 'A',
			'??' => 'a',
			'??' => 'C',
			'??' => 'c',
			'??' => 'C',
			'??' => 'c',
			'??' => 'C',
			'??' => 'c',
			'??' => 'C',
			'??' => 'c',
			'??' => 'D',
			'??' => 'd',
			'??' => 'D',
			'??' => 'd',
			'??' => 'E',
			'??' => 'e',
			'??' => 'E',
			'??' => 'e',
			'??' => 'E',
			'??' => 'e',
			'??' => 'E',
			'??' => 'e',
			'??' => 'E',
			'??' => 'e',
			'??' => 'G',
			'??' => 'g',
			'??' => 'G',
			'??' => 'g',
			'??' => 'G',
			'??' => 'g',
			'??' => 'G',
			'??' => 'g',
			'??' => 'H',
			'??' => 'h',
			'??' => 'H',
			'??' => 'h',
			'??' => 'I',
			'??' => 'i',
			'??' => 'I',
			'??' => 'i',
			'??' => 'I',
			'??' => 'i',
			'??' => 'I',
			'??' => 'i',
			'??' => 'I',
			'??' => 'i',
			'??' => 'IJ',
			'??' => 'ij',
			'??' => 'J',
			'??' => 'j',
			'??' => 'K',
			'??' => 'k',
			'??' => 'k',
			'??' => 'L',
			'??' => 'l',
			'??' => 'L',
			'??' => 'l',
			'??' => 'L',
			'??' => 'l',
			'??' => 'L',
			'??' => 'l',
			'??' => 'L',
			'??' => 'l',
			'??' => 'N',
			'??' => 'n',
			'??' => 'N',
			'??' => 'n',
			'??' => 'N',
			'??' => 'n',
			'??' => 'n',
			'??' => 'N',
			'??' => 'n',
			'??' => 'O',
			'??' => 'o',
			'??' => 'O',
			'??' => 'o',
			'??' => 'O',
			'??' => 'o',
			'??' => 'OE',
			'??' => 'oe',
			'??' => 'R',
			'??' => 'r',
			'??' => 'R',
			'??' => 'r',
			'??' => 'R',
			'??' => 'r',
			'??' => 'S',
			'??' => 's',
			'??' => 'S',
			'??' => 's',
			'??' => 'S',
			'??' => 's',
			'??' => 'S',
			'??' => 's',
			'??' => 'T',
			'??' => 't',
			'??' => 'T',
			'??' => 't',
			'??' => 'T',
			'??' => 't',
			'??' => 'U',
			'??' => 'u',
			'??' => 'U',
			'??' => 'u',
			'??' => 'U',
			'??' => 'u',
			'??' => 'U',
			'??' => 'u',
			'??' => 'U',
			'??' => 'u',
			'??' => 'U',
			'??' => 'u',
			'??' => 'W',
			'??' => 'w',
			'??' => 'Y',
			'??' => 'y',
			'??' => 'Y',
			'??' => 'Z',
			'??' => 'z',
			'??' => 'Z',
			'??' => 'z',
			'??' => 'Z',
			'??' => 'z',
			'??' => 's',
			// Decompositions for Latin Extended-B.
			'??' => 'S',
			'??' => 's',
			'??' => 'T',
			'??' => 't',
			// Euro sign.
			'???' => 'E',
			// GBP (Pound) sign.
			'??' => '',
			// Vowels with diacritic (Vietnamese).
			// Unmarked.
			'??' => 'O',
			'??' => 'o',
			'??' => 'U',
			'??' => 'u',
			// Grave accent.
			'???' => 'A',
			'???' => 'a',
			'???' => 'A',
			'???' => 'a',
			'???' => 'E',
			'???' => 'e',
			'???' => 'O',
			'???' => 'o',
			'???' => 'O',
			'???' => 'o',
			'???' => 'U',
			'???' => 'u',
			'???' => 'Y',
			'???' => 'y',
			// Hook.
			'???' => 'A',
			'???' => 'a',
			'???' => 'A',
			'???' => 'a',
			'???' => 'A',
			'???' => 'a',
			'???' => 'E',
			'???' => 'e',
			'???' => 'E',
			'???' => 'e',
			'???' => 'I',
			'???' => 'i',
			'???' => 'O',
			'???' => 'o',
			'???' => 'O',
			'???' => 'o',
			'???' => 'O',
			'???' => 'o',
			'???' => 'U',
			'???' => 'u',
			'???' => 'U',
			'???' => 'u',
			'???' => 'Y',
			'???' => 'y',
			// Tilde.
			'???' => 'A',
			'???' => 'a',
			'???' => 'A',
			'???' => 'a',
			'???' => 'E',
			'???' => 'e',
			'???' => 'E',
			'???' => 'e',
			'???' => 'O',
			'???' => 'o',
			'???' => 'O',
			'???' => 'o',
			'???' => 'U',
			'???' => 'u',
			'???' => 'Y',
			'???' => 'y',
			// Acute accent.
			'???' => 'A',
			'???' => 'a',
			'???' => 'A',
			'???' => 'a',
			'???' => 'E',
			'???' => 'e',
			'???' => 'O',
			'???' => 'o',
			'???' => 'O',
			'???' => 'o',
			'???' => 'U',
			'???' => 'u',
			// Dot below.
			'???' => 'A',
			'???' => 'a',
			'???' => 'A',
			'???' => 'a',
			'???' => 'A',
			'???' => 'a',
			'???' => 'E',
			'???' => 'e',
			'???' => 'E',
			'???' => 'e',
			'???' => 'I',
			'???' => 'i',
			'???' => 'O',
			'???' => 'o',
			'???' => 'O',
			'???' => 'o',
			'???' => 'O',
			'???' => 'o',
			'???' => 'U',
			'???' => 'u',
			'???' => 'U',
			'???' => 'u',
			'???' => 'Y',
			'???' => 'y',
			// Vowels with diacritic (Chinese, Hanyu Pinyin).
			'??' => 'a',
			// Macron.
			'??' => 'U',
			'??' => 'u',
			// Acute accent.
			'??' => 'U',
			'??' => 'u',
			// Caron.
			'??' => 'A',
			'??' => 'a',
			'??' => 'I',
			'??' => 'i',
			'??' => 'O',
			'??' => 'o',
			'??' => 'U',
			'??' => 'u',
			'??' => 'U',
			'??' => 'u',
			// Grave accent.
			'??' => 'U',
			'??' => 'u',
		);

		// Used for locale-specific rules.
		if ( empty( $locale ) ) {
			$locale = get_locale();
		}

		/*
		 * German has various locales (de_DE, de_CH, de_AT, ...) with formal and informal variants.
		 * There is no 3-letter locale like 'def', so checking for 'de' instead of 'de_' is safe,
		 * since 'de' itself would be a valid locale too.
		 */
		if ( str_starts_with( $locale, 'de' ) ) {
			$chars['??'] = 'Ae';
			$chars['??'] = 'ae';
			$chars['??'] = 'Oe';
			$chars['??'] = 'oe';
			$chars['??'] = 'Ue';
			$chars['??'] = 'ue';
			$chars['??'] = 'ss';
		} elseif ( 'da_DK' === $locale ) {
			$chars['??'] = 'Ae';
			$chars['??'] = 'ae';
			$chars['??'] = 'Oe';
			$chars['??'] = 'oe';
			$chars['??'] = 'Aa';
			$chars['??'] = 'aa';
		} elseif ( 'ca' === $locale ) {
			$chars['l??l'] = 'll';
		} elseif ( 'sr_RS' === $locale || 'bs_BA' === $locale ) {
			$chars['??'] = 'DJ';
			$chars['??'] = 'dj';
		}

		$text = strtr( $text, $chars );
	} else {
		$chars = array();
		// Assume ISO-8859-1 if not UTF-8.
		$chars['in'] = "\x80\x83\x8a\x8e\x9a\x9e"
			. "\x9f\xa2\xa5\xb5\xc0\xc1\xc2"
			. "\xc3\xc4\xc5\xc7\xc8\xc9\xca"
			. "\xcb\xcc\xcd\xce\xcf\xd1\xd2"
			. "\xd3\xd4\xd5\xd6\xd8\xd9\xda"
			. "\xdb\xdc\xdd\xe0\xe1\xe2\xe3"
			. "\xe4\xe5\xe7\xe8\xe9\xea\xeb"
			. "\xec\xed\xee\xef\xf1\xf2\xf3"
			. "\xf4\xf5\xf6\xf8\xf9\xfa\xfb"
			. "\xfc\xfd\xff";

		$chars['out'] = 'EfSZszYcYuAAAAAACEEEEIIIINOOOOOOUUUUYaaaaaaceeeeiiiinoooooouuuuyy';

		$text                = strtr( $text, $chars['in'], $chars['out'] );
		$double_chars        = array();
		$double_chars['in']  = array( "\x8c", "\x9c", "\xc6", "\xd0", "\xde", "\xdf", "\xe6", "\xf0", "\xfe" );
		$double_chars['out'] = array( 'OE', 'oe', 'AE', 'DH', 'TH', 'ss', 'ae', 'dh', 'th' );
		$text                = str_replace( $double_chars['in'], $double_chars['out'], $text );
	}

	return $text;
}

/**
 * Sanitizes a filename, replacing whitespace with dashes.
 *
 * Removes special characters that are illegal in filenames on certain
 * operating systems and special characters requiring special escaping
 * to manipulate at the command line. Replaces spaces and consecutive
 * dashes with a single dash. Trims period, dash and underscore from beginning
 * and end of filename. It is not guaranteed that this function will return a
 * filename that is allowed to be uploaded.
 *
 * @since 2.1.0
 *
 * @param string $filename The filename to be sanitized.
 * @return string The sanitized filename.
 */
function sanitize_file_name( $filename ) {
	$filename_raw = $filename;
	$filename     = remove_accents( $filename );

	$special_chars = array( '?', '[', ']', '/', '\\', '=', '<', '>', ':', ';', ',', "'", '"', '&', '$', '#', '*', '(', ')', '|', '~', '`', '!', '{', '}', '%', '+', '???', '??', '??', '???', '???', chr( 0 ) );

	// Check for support for utf8 in the installed PCRE library once and store the result in a static.
	static $utf8_pcre = null;
	if ( ! isset( $utf8_pcre ) ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$utf8_pcre = @preg_match( '/^./u', 'a' );
	}

	if ( ! seems_utf8( $filename ) ) {
		$_ext     = pathinfo( $filename, PATHINFO_EXTENSION );
		$_name    = pathinfo( $filename, PATHINFO_FILENAME );
		$filename = sanitize_title_with_dashes( $_name ) . '.' . $_ext;
	}

	if ( $utf8_pcre ) {
		$filename = preg_replace( "#\x{00a0}#siu", ' ', $filename );
	}

	/**
	 * Filters the list of characters to remove from a filename.
	 *
	 * @since 2.8.0
	 *
	 * @param string[] $special_chars Array of characters to remove.
	 * @param string   $filename_raw  The original filename to be sanitized.
	 */
	$special_chars = apply_filters( 'sanitize_file_name_chars', $special_chars, $filename_raw );

	$filename = str_replace( $special_chars, '', $filename );
	$filename = str_replace( array( '%20', '+' ), '-', $filename );
	$filename = preg_replace( '/[\r\n\t -]+/', '-', $filename );
	$filename = trim( $filename, '.-_' );

	if ( false === strpos( $filename, '.' ) ) {
		$mime_types = wp_get_mime_types();
		$filetype   = wp_check_filetype( 'test.' . $filename, $mime_types );
		if ( $filetype['ext'] === $filename ) {
			$filename = 'unnamed-file.' . $filetype['ext'];
		}
	}

	// Split the filename into a base and extension[s].
	$parts = explode( '.', $filename );

	// Return if only one extension.
	if ( count( $parts ) <= 2 ) {
		/** This filter is documented in wp-includes/formatting.php */
		return apply_filters( 'sanitize_file_name', $filename, $filename_raw );
	}

	// Process multiple extensions.
	$filename  = array_shift( $parts );
	$extension = array_pop( $parts );
	$mimes     = get_allowed_mime_types();

	/*
	 * Loop over any intermediate extensions. Postfix them with a trailing underscore
	 * if they are a 2 - 5 character long alpha string not in the allowed extension list.
	 */
	foreach ( (array) $parts as $part ) {
		$filename .= '.' . $part;

		if ( preg_match( '/^[a-zA-Z]{2,5}\d?$/', $part ) ) {
			$allowed = false;
			foreach ( $mimes as $ext_preg => $mime_match ) {
				$ext_preg = '!^(' . $ext_preg . ')$!i';
				if ( preg_match( $ext_preg, $part ) ) {
					$allowed = true;
					break;
				}
			}
			if ( ! $allowed ) {
				$filename .= '_';
			}
		}
	}

	$filename .= '.' . $extension;

	/**
	 * Filters a sanitized filename string.
	 *
	 * @since 2.8.0
	 *
	 * @param string $filename     Sanitized filename.
	 * @param string $filename_raw The filename prior to sanitization.
	 */
	return apply_filters( 'sanitize_file_name', $filename, $filename_raw );
}

/**
 * Sanitizes a username, stripping out unsafe characters.
 *
 * Removes tags, octets, entities, and if strict is enabled, will only keep
 * alphanumeric, _, space, ., -, @. After sanitizing, it passes the username,
 * raw username (the username in the parameter), and the value of $strict as
 * parameters for the {@see 'sanitize_user'} filter.
 *
 * @since 2.0.0
 *
 * @param string $username The username to be sanitized.
 * @param bool   $strict   Optional. If set limits $username to specific characters.
 *                         Default false.
 * @return string The sanitized username, after passing through filters.
 */
function sanitize_user( $username, $strict = false ) {
	$raw_username = $username;
	$username     = wp_strip_all_tags( $username );
	$username     = remove_accents( $username );
	// Kill octets.
	$username = preg_replace( '|%([a-fA-F0-9][a-fA-F0-9])|', '', $username );
	// Kill entities.
	$username = preg_replace( '/&.+?;/', '', $username );

	// If strict, reduce to ASCII for max portability.
	if ( $strict ) {
		$username = preg_replace( '|[^a-z0-9 _.\-@]|i', '', $username );
	}

	$username = trim( $username );
	// Consolidate contiguous whitespace.
	$username = preg_replace( '|\s+|', ' ', $username );

	/**
	 * Filters a sanitized username string.
	 *
	 * @since 2.0.1
	 *
	 * @param string $username     Sanitized username.
	 * @param string $raw_username The username prior to sanitization.
	 * @param bool   $strict       Whether to limit the sanitization to specific characters.
	 */
	return apply_filters( 'sanitize_user', $username, $raw_username, $strict );
}

/**
 * Sanitizes a string key.
 *
 * Keys are used as internal identifiers. Lowercase alphanumeric characters,
 * dashes, and underscores are allowed.
 *
 * @since 3.0.0
 *
 * @param string $key String key.
 * @return string Sanitized key.
 */
function sanitize_key( $key ) {
	$sanitized_key = '';

	if ( is_scalar( $key ) ) {
		$sanitized_key = strtolower( $key );
		$sanitized_key = preg_replace( '/[^a-z0-9_\-]/', '', $sanitized_key );
	}

	/**
	 * Filters a sanitized key string.
	 *
	 * @since 3.0.0
	 *
	 * @param string $sanitized_key Sanitized key.
	 * @param string $key           The key prior to sanitization.
	 */
	return apply_filters( 'sanitize_key', $sanitized_key, $key );
}

/**
 * Sanitizes a string into a slug, which can be used in URLs or HTML attributes.
 *
 * By default, converts accent characters to ASCII characters and further
 * limits the output to alphanumeric characters, underscore (_) and dash (-)
 * through the {@see 'sanitize_title'} filter.
 *
 * If `$title` is empty and `$fallback_title` is set, the latter will be used.
 *
 * @since 1.0.0
 *
 * @param string $title          The string to be sanitized.
 * @param string $fallback_title Optional. A title to use if $title is empty. Default empty.
 * @param string $context        Optional. The operation for which the string is sanitized.
 *                               When set to 'save', the string runs through remove_accents().
 *                               Default 'save'.
 * @return string The sanitized string.
 */
function sanitize_title( $title, $fallback_title = '', $context = 'save' ) {
	$raw_title = $title;

	if ( 'save' === $context ) {
		$title = remove_accents( $title );
	}

	/**
	 * Filters a sanitized title string.
	 *
	 * @since 1.2.0
	 *
	 * @param string $title     Sanitized title.
	 * @param string $raw_title The title prior to sanitization.
	 * @param string $context   The context for which the title is being sanitized.
	 */
	$title = apply_filters( 'sanitize_title', $title, $raw_title, $context );

	if ( '' === $title || false === $title ) {
		$title = $fallback_title;
	}

	return $title;
}

/**
 * Sanitizes a title with the 'query' context.
 *
 * Used for querying the database for a value from URL.
 *
 * @since 3.1.0
 *
 * @param string $title The string to be sanitized.
 * @return string The sanitized string.
 */
function sanitize_title_for_query( $title ) {
	return sanitize_title( $title, '', 'query' );
}

/**
 * Sanitizes a title, replacing whitespace and a few other characters with dashes.
 *
 * Limits the output to alphanumeric characters, underscore (_) and dash (-).
 * Whitespace becomes a dash.
 *
 * @since 1.2.0
 *
 * @param string $title     The title to be sanitized.
 * @param string $raw_title Optional. Not used. Default empty.
 * @param string $context   Optional. The operation for which the string is sanitized.
 *                          When set to 'save', additional entities are converted to hyphens
 *                          or stripped entirely. Default 'display'.
 * @return string The sanitized title.
 */
function sanitize_title_with_dashes( $title, $raw_title = '', $context = 'display' ) {
	$title = strip_tags( $title );
	// Preserve escaped octets.
	$title = preg_replace( '|%([a-fA-F0-9][a-fA-F0-9])|', '---$1---', $title );
	// Remove percent signs that are not part of an octet.
	$title = str_replace( '%', '', $title );
	// Restore octets.
	$title = preg_replace( '|---([a-fA-F0-9][a-fA-F0-9])---|', '%$1', $title );

	if ( seems_utf8( $title ) ) {
		if ( function_exists( 'mb_strtolower' ) ) {
			$title = mb_strtolower( $title, 'UTF-8' );
		}
		$title = utf8_uri_encode( $title, 200 );
	}

	$title = strtolower( $title );

	if ( 'save' === $context ) {
		// Convert &nbsp, &ndash, and &mdash to hyphens.
		$title = str_replace( array( '%c2%a0', '%e2%80%93', '%e2%80%94' ), '-', $title );
		// Convert &nbsp, &ndash, and &mdash HTML entities to hyphens.
		$title = str_replace( array( '&nbsp;', '&#160;', '&ndash;', '&#8211;', '&mdash;', '&#8212;' ), '-', $title );
		// Convert forward slash to hyphen.
		$title = str_replace( '/', '-', $title );

		// Strip these characters entirely.
		$title = str_replace(
			array(
				// Soft hyphens.
				'%c2%ad',
				// &iexcl and &iquest.
				'%c2%a1',
				'%c2%bf',
				// Angle quotes.
				'%c2%ab',
				'%c2%bb',
				'%e2%80%b9',
				'%e2%80%ba',
				// Curly quotes.
				'%e2%80%98',
				'%e2%80%99',
				'%e2%80%9c',
				'%e2%80%9d',
				'%e2%80%9a',
				'%e2%80%9b',
				'%e2%80%9e',
				'%e2%80%9f',
				// Bullet.
				'%e2%80%a2',
				// &copy, &reg, &deg, &hellip, and &trade.
				'%c2%a9',
				'%c2%ae',
				'%c2%b0',
				'%e2%80%a6',
				'%e2%84%a2',
				// Acute accents.
				'%c2%b4',
				'%cb%8a',
				'%cc%81',
				'%cd%81',
				// Grave accent, macron, caron.
				'%cc%80',
				'%cc%84',
				'%cc%8c',
				// Non-visible characters that display without a width.
				'%e2%80%8b', // Zero width space.
				'%e2%80%8c', // Zero width non-joiner.
				'%e2%80%8d', // Zero width joiner.
				'%e2%80%8e', // Left-to-right mark.
				'%e2%80%8f', // Right-to-left mark.
				'%e2%80%aa', // Left-to-right embedding.
				'%e2%80%ab', // Right-to-left embedding.
				'%e2%80%ac', // Pop directional formatting.
				'%e2%80%ad', // Left-to-right override.
				'%e2%80%ae', // Right-to-left override.
				'%ef%bb%bf', // Byte order mark.
				'%ef%bf%bc', // Object replacement character.
			),
			'',
			$title
		);

		// Convert non-visible characters that display with a width to hyphen.
		$title = str_replace(
			array(
				'%e2%80%80', // En quad.
				'%e2%80%81', // Em quad.
				'%e2%80%82', // En space.
				'%e2%80%83', // Em space.
				'%e2%80%84', // Three-per-em space.
				'%e2%80%85', // Four-per-em space.
				'%e2%80%86', // Six-per-em space.
				'%e2%80%87', // Figure space.
				'%e2%80%88', // Punctuation space.
				'%e2%80%89', // Thin space.
				'%e2%80%8a', // Hair space.
				'%e2%80%a8', // Line separator.
				'%e2%80%a9', // Paragraph separator.
				'%e2%80%af', // Narrow no-break space.
			),
			'-',
			$title
		);

		// Convert &times to 'x'.
		$title = str_replace( '%c3%97', 'x', $title );
	}

	// Kill entities.
	$title = preg_replace( '/&.+?;/', '', $title );
	$title = str_replace( '.', '-', $title );

	$title = preg_replace( '/[^%a-z0-9 _-]/', '', $title );
	$title = preg_replace( '/\s+/', '-', $title );
	$title = preg_replace( '|-+|', '-', $title );
	$title = trim( $title, '-' );

	return $title;
}

/**
 * Ensures a string is a valid SQL 'order by' clause.
 *
 * Accepts one or more columns, with or without a sort order (ASC / DESC).
 * e.g. 'column_1', 'column_1, column_2', 'column_1 ASC, column_2 DESC' etc.
 *
 * Also accepts 'RAND()'.
 *
 * @since 2.5.1
 *
 * @param string $orderby Order by clause to be validated.
 * @return string|false Returns $orderby if valid, false otherwise.
 */
function sanitize_sql_orderby( $orderby ) {
	if ( preg_match( '/^\s*(([a-z0-9_]+|`[a-z0-9_]+`)(\s+(ASC|DESC))?\s*(,\s*(?=[a-z0-9_`])|$))+$/i', $orderby ) || preg_match( '/^\s*RAND\(\s*\)\s*$/i', $orderby ) ) {
		return $orderby;
	}
	return false;
}

/**
 * Sanitizes an HTML classname to ensure it only contains valid characters.
 *
 * Strips the string down to A-Z,a-z,0-9,_,-. If this results in an empty
 * string then it will return the alternative value supplied.
 *
 * @todo Expand to support the full range of CDATA that a class attribute can contain.
 *
 * @since 2.8.0
 *
 * @param string $class    The classname to be sanitized
 * @param string $fallback Optional. The value to return if the sanitization ends up as an empty string.
 *  Defaults to an empty string.
 * @return string The sanitized value
 */
function sanitize_html_class( $class, $fallback = '' ) {
	// Strip out any %-encoded octets.
	$sanitized = preg_replace( '|%[a-fA-F0-9][a-fA-F0-9]|', '', $class );

	// Limit to A-Z, a-z, 0-9, '_', '-'.
	$sanitized = preg_replace( '/[^A-Za-z0-9_-]/', '', $sanitized );

	if ( '' === $sanitized && $fallback ) {
		return sanitize_html_class( $fallback );
	}
	/**
	 * Filters a sanitized HTML class string.
	 *
	 * @since 2.8.0
	 *
	 * @param string $sanitized The sanitized HTML class.
	 * @param string $class     HTML class before sanitization.
	 * @param string $fallback  The fallback string.
	 */
	return apply_filters( 'sanitize_html_class', $sanitized, $class, $fallback );
}

/**
 * Converts lone & characters into `&#038;` (a.k.a. `&amp;`)
 *
 * @since 0.71
 *
 * @param string $content    String of characters to be converted.
 * @param string $deprecated Not used.
 * @return string Converted string.
 */
function convert_chars( $content, $deprecated = '' ) {
	if ( ! empty( $deprecated ) ) {
		_deprecated_argument( __FUNCTION__, '0.71' );
	}

	if ( strpos( $content, '&' ) !== false ) {
		$content = preg_replace( '/&([^#])(?![a-z1-4]{1,8};)/i', '&#038;$1', $content );
	}

	return $content;
}

/**
 * Converts invalid Unicode references range to valid range.
 *
 * @since 4.3.0
 *
 * @param string $content String with entities that need converting.
 * @return string Converted string.
 */
function convert_invalid_entities( $content ) {
	$wp_htmltranswinuni = array(
		'&#128;' => '&#8364;', // The Euro sign.
		'&#129;' => '',
		'&#130;' => '&#8218;', // These are Windows CP1252 specific characters.
		'&#131;' => '&#402;',  // They would look weird on non-Windows browsers.
		'&#132;' => '&#8222;',
		'&#133;' => '&#8230;',
		'&#134;' => '&#8224;',
		'&#135;' => '&#8225;',
		'&#136;' => '&#710;',
		'&#137;' => '&#8240;',
		'&#138;' => '&#352;',
		'&#139;' => '&#8249;',
		'&#140;' => '&#338;',
		'&#141;' => '',
		'&#142;' => '&#381;',
		'&#143;' => '',
		'&#144;' => '',
		'&#145;' => '&#8216;',
		'&#146;' => '&#8217;',
		'&#147;' => '&#8220;',
		'&#148;' => '&#8221;',
		'&#149;' => '&#8226;',
		'&#150;' => '&#8211;',
		'&#151;' => '&#8212;',
		'&#152;' => '&#732;',
		'&#153;' => '&#8482;',
		'&#154;' => '&#353;',
		'&#155;' => '&#8250;',
		'&#156;' => '&#339;',
		'&#157;' => '',
		'&#158;' => '&#382;',
		'&#159;' => '&#376;',
	);

	if ( strpos( $content, '&#1' ) !== false ) {
		$content = strtr( $content, $wp_htmltranswinuni );
	}

	return $content;
}

/**
 * Balances tags if forced to, or if the 'use_balanceTags' option is set to true.
 *
 * @since 0.71
 *
 * @param string $text  Text to be balanced
 * @param bool   $force If true, forces balancing, ignoring the value of the option. Default false.
 * @return string Balanced text
 */
function balanceTags( $text, $force = false ) {  // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	if ( $force || (int) get_option( 'use_balanceTags' ) === 1 ) {
		return force_balance_tags( $text );
	} else {
		return $text;
	}
}

/**
 * Balances tags of string using a modified stack.
 *
 * @since 2.0.4
 * @since 5.3.0 Improve accuracy and add support for custom element tags.
 *
 * @author Leonard Lin <leonard@acm.org>
 * @license GPL
 * @copyright November 4, 2001
 * @version 1.1
 * @todo Make better - change loop condition to $text in 1.2
 * @internal Modified by Scott Reilly (coffee2code) 02 Aug 2004
 *      1.1  Fixed handling of append/stack pop order of end text
 *           Added Cleaning Hooks
 *      1.0  First Version
 *
 * @param string $text Text to be balanced.
 * @return string Balanced text.
 */
function force_balance_tags( $text ) {
	$tagstack  = array();
	$stacksize = 0;
	$tagqueue  = '';
	$newtext   = '';
	// Known single-entity/self-closing tags.
	$single_tags = array( 'area', 'base', 'basefont', 'br', 'col', 'command', 'embed', 'frame', 'hr', 'img', 'input', 'isindex', 'link', 'meta', 'param', 'source', 'track', 'wbr' );
	// Tags that can be immediately nested within themselves.
	$nestable_tags = array( 'article', 'aside', 'blockquote', 'details', 'div', 'figure', 'object', 'q', 'section', 'span' );

	// WP bug fix for comments - in case you REALLY meant to type '< !--'.
	$text = str_replace( '< !--', '<    !--', $text );
	// WP bug fix for LOVE <3 (and other situations with '<' before a number).
	$text = preg_replace( '#<([0-9]{1})#', '&lt;$1', $text );

	/**
	 * Matches supported tags.
	 *
	 * To get the pattern as a string without the comments paste into a PHP
	 * REPL like `php -a`.
	 *
	 * @see https://html.spec.whatwg.org/#elements-2
	 * @see https://html.spec.whatwg.org/multipage/custom-elements.html#valid-custom-element-name
	 *
	 * @example
	 * ~# php -a
	 * php > $s = [paste copied contents of expression below including parentheses];
	 * php > echo $s;
	 */
	$tag_pattern = (
		'#<' . // Start with an opening bracket.
		'(/?)' . // Group 1 - If it's a closing tag it'll have a leading slash.
		'(' . // Group 2 - Tag name.
			// Custom element tags have more lenient rules than HTML tag names.
			'(?:[a-z](?:[a-z0-9._]*)-(?:[a-z0-9._-]+)+)' .
				'|' .
			// Traditional tag rules approximate HTML tag names.
			'(?:[\w:]+)' .
		')' .
		'(?:' .
			// We either immediately close the tag with its '>' and have nothing here.
			'\s*' .
			'(/?)' . // Group 3 - "attributes" for empty tag.
				'|' .
			// Or we must start with space characters to separate the tag name from the attributes (or whitespace).
			'(\s+)' . // Group 4 - Pre-attribute whitespace.
			'([^>]*)' . // Group 5 - Attributes.
		')' .
		'>#' // End with a closing bracket.
	);

	while ( preg_match( $tag_pattern, $text, $regex ) ) {
		$full_match        = $regex[0];
		$has_leading_slash = ! empty( $regex[1] );
		$tag_name          = $regex[2];
		$tag               = strtolower( $tag_name );
		$is_single_tag     = in_array( $tag, $single_tags, true );
		$pre_attribute_ws  = isset( $regex[4] ) ? $regex[4] : '';
		$attributes        = trim( isset( $regex[5] ) ? $regex[5] : $regex[3] );
		$has_self_closer   = '/' === substr( $attributes, -1 );

		$newtext .= $tagqueue;

		$i = strpos( $text, $full_match );
		$l = strlen( $full_match );

		// Clear the shifter.
		$tagqueue = '';
		if ( $has_leading_slash ) { // End tag.
			// If too many closing tags.
			if ( $stacksize <= 0 ) {
				$tag = '';
				// Or close to be safe $tag = '/' . $tag.

				// If stacktop value = tag close value, then pop.
			} elseif ( $tagstack[ $stacksize - 1 ] === $tag ) { // Found closing tag.
				$tag = '</' . $tag . '>'; // Close tag.
				array_pop( $tagstack );
				$stacksize--;
			} else { // Closing tag not at top, search for it.
				for ( $j = $stacksize - 1; $j >= 0; $j-- ) {
					if ( $tagstack[ $j ] === $tag ) {
						// Add tag to tagqueue.
						for ( $k = $stacksize - 1; $k >= $j; $k-- ) {
							$tagqueue .= '</' . array_pop( $tagstack ) . '>';
							$stacksize--;
						}
						break;
					}
				}
				$tag = '';
			}
		} else { // Begin tag.
			if ( $has_self_closer ) { // If it presents itself as a self-closing tag...
				// ...but it isn't a known single-entity self-closing tag, then don't let it be treated as such
				// and immediately close it with a closing tag (the tag will encapsulate no text as a result).
				if ( ! $is_single_tag ) {
					$attributes = trim( substr( $attributes, 0, -1 ) ) . "></$tag";
				}
			} elseif ( $is_single_tag ) { // Else if it's a known single-entity tag but it doesn't close itself, do so.
				$pre_attribute_ws = ' ';
				$attributes      .= '/';
			} else { // It's not a single-entity tag.
				// If the top of the stack is the same as the tag we want to push, close previous tag.
				if ( $stacksize > 0 && ! in_array( $tag, $nestable_tags, true ) && $tagstack[ $stacksize - 1 ] === $tag ) {
					$tagqueue = '</' . array_pop( $tagstack ) . '>';
					$stacksize--;
				}
				$stacksize = array_push( $tagstack, $tag );
			}

			// Attributes.
			if ( $has_self_closer && $is_single_tag ) {
				// We need some space - avoid <br/> and prefer <br />.
				$pre_attribute_ws = ' ';
			}

			$tag = '<' . $tag . $pre_attribute_ws . $attributes . '>';
			// If already queuing a close tag, then put this tag on too.
			if ( ! empty( $tagqueue ) ) {
				$tagqueue .= $tag;
				$tag       = '';
			}
		}
		$newtext .= substr( $text, 0, $i ) . $tag;
		$text     = substr( $text, $i + $l );
	}

	// Clear tag queue.
	$newtext .= $tagqueue;

	// Add remaining text.
	$newtext .= $text;

	while ( $x = array_pop( $tagstack ) ) {
		$newtext .= '</' . $x . '>'; // Add remaining tags to close.
	}

	// WP fix for the bug with HTML comments.
	$newtext = str_replace( '< !--', '<!--', $newtext );
	$newtext = str_replace( '<    !--', '< !--', $newtext );

	return $newtext;
}

/**
 * Acts on text which is about to be edited.
 *
 * The $content is run through esc_textarea(), which uses htmlspecialchars()
 * to convert special characters to HTML entities. If `$richedit` is set to true,
 * it is simply a holder for the {@see 'format_to_edit'} filter.
 *
 * @since 0.71
 * @since 4.4.0 The `$richedit` parameter was renamed to `$rich_text` for clarity.
 *
 * @param string $content   The text about to be edited.
 * @param bool   $rich_text Optional. Whether `$content` should be considered rich text,
 *                          in which case it would not be passed through esc_textarea().
 *                          Default false.
 * @return string The text after the filter (and possibly htmlspecialchars()) has been run.
 */
function format_to_edit( $content, $rich_text = false ) {
	/**
	 * Filters the text to be formatted for editing.
	 *
	 * @since 1.2.0
	 *
	 * @param string $content The text, prior to formatting for editing.
	 */
	$content = apply_filters( 'format_to_edit', $content );
	if ( ! $rich_text ) {
		$content = esc_textarea( $content );
	}
	return $content;
}

/**
 * Add leading zeros when necessary.
 *
 * If you set the threshold to '4' and the number is '10', then you will get
 * back '0010'. If you set the threshold to '4' and the number is '5000', then you
 * will get back '5000'.
 *
 * Uses sprintf to append the amount of zeros based on the $threshold parameter
 * and the size of the number. If the number is large enough, then no zeros will
 * be appended.
 *
 * @since 0.71
 *
 * @param int $number     Number to append zeros to if not greater than threshold.
 * @param int $threshold  Digit places number needs to be to not have zeros added.
 * @return string Adds leading zeros to number if needed.
 */
function zeroise( $number, $threshold ) {
	return sprintf( '%0' . $threshold . 's', $number );
}

/**
 * Adds backslashes before letters and before a number at the start of a string.
 *
 * @since 0.71
 *
 * @param string $value Value to which backslashes will be added.
 * @return string String with backslashes inserted.
 */
function backslashit( $value ) {
	if ( isset( $value[0] ) && $value[0] >= '0' && $value[0] <= '9' ) {
		$value = '\\\\' . $value;
	}
	return addcslashes( $value, 'A..Za..z' );
}

/**
 * Appends a trailing slash.
 *
 * Will remove trailing forward and backslashes if it exists already before adding
 * a trailing forward slash. This prevents double slashing a string or path.
 *
 * The primary use of this is for paths and thus should be used for paths. It is
 * not restricted to paths and offers no specific path support.
 *
 * @since 1.2.0
 *
 * @param string $value Value to which trailing slash will be added.
 * @return string String with trailing slash added.
 */
function trailingslashit( $value ) {
	return untrailingslashit( $value ) . '/';
}

/**
 * Removes trailing forward slashes and backslashes if they exist.
 *
 * The primary use of this is for paths and thus should be used for paths. It is
 * not restricted to paths and offers no specific path support.
 *
 * @since 2.2.0
 *
 * @param string $text Value from which trailing slashes will be removed.
 * @return string String without the trailing slashes.
 */
function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}

/**
 * Adds slashes to a string or recursively adds slashes to strings within an array.
 *
 * @since 0.71
 *
 * @param string|array $gpc String or array of data to slash.
 * @return string|array Slashed `$gpc`.
 */
function addslashes_gpc( $gpc ) {
	return wp_slash( $gpc );
}

/**
 * Navigates through an array, object, or scalar, and removes slashes from the values.
 *
 * @since 2.0.0
 *
 * @param mixed $value The value to be stripped.
 * @return mixed Stripped value.
 */
function stripslashes_deep( $value ) {
	return map_deep( $value, 'stripslashes_from_strings_only' );
}

/**
 * Callback function for `stripslashes_deep()` which strips slashes from strings.
 *
 * @since 4.4.0
 *
 * @param mixed $value The array or string to be stripped.
 * @return mixed The stripped value.
 */
function stripslashes_from_strings_only( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

/**
 * Navigates through an array, object, or scalar, and encodes the values to be used in a URL.
 *
 * @since 2.2.0
 *
 * @param mixed $value The array or string to be encoded.
 * @return mixed The encoded value.
 */
function urlencode_deep( $value ) {
	return map_deep( $value, 'urlencode' );
}

/**
 * Navigates through an array, object, or scalar, and raw-encodes the values to be used in a URL.
 *
 * @since 3.4.0
 *
 * @param mixed $value The array or string to be encoded.
 * @return mixed The encoded value.
 */
function rawurlencode_deep( $value ) {
	return map_deep( $value, 'rawurlencode' );
}

/**
 * Navigates through an array, object, or scalar, and decodes URL-encoded values
 *
 * @since 4.4.0
 *
 * @param mixed $value The array or string to be decoded.
 * @return mixed The decoded value.
 */
function urldecode_deep( $value ) {
	return map_deep( $value, 'urldecode' );
}

/**
 * Converts email addresses characters to HTML entities to block spam bots.
 *
 * @since 0.71
 *
 * @param string $email_address Email address.
 * @param int    $hex_encoding  Optional. Set to 1 to enable hex encoding.
 * @return string Converted email address.
 */
function antispambot( $email_address, $hex_encoding = 0 ) {
	$email_no_spam_address = '';
	for ( $i = 0, $len = strlen( $email_address ); $i < $len; $i++ ) {
		$j = rand( 0, 1 + $hex_encoding );
		if ( 0 == $j ) {
			$email_no_spam_address .= '&#' . ord( $email_address[ $i ] ) . ';';
		} elseif ( 1 == $j ) {
			$email_no_spam_address .= $email_address[ $i ];
		} elseif ( 2 == $j ) {
			$email_no_spam_address .= '%' . zeroise( dechex( ord( $email_address[ $i ] ) ), 2 );
		}
	}

	return str_replace( '@', '&#64;', $email_no_spam_address );
}

/**
 * Callback to convert URI match to HTML A element.
 *
 * This function was backported from 2.5.0 to 2.3.2. Regex callback for make_clickable().
 *
 * @since 2.3.2
 * @access private
 *
 * @param array $matches Single Regex Match.
 * @return string HTML A element with URI address.
 */
function _make_url_clickable_cb( $matches ) {
	$url = $matches[2];

	if ( ')' === $matches[3] && strpos( $url, '(' ) ) {
		// If the trailing character is a closing parethesis, and the URL has an opening parenthesis in it,
		// add the closing parenthesis to the URL. Then we can let the parenthesis balancer do its thing below.
		$url   .= $matches[3];
		$suffix = '';
	} else {
		$suffix = $matches[3];
	}

	// Include parentheses in the URL only if paired.
	while ( substr_count( $url, '(' ) < substr_count( $url, ')' ) ) {
		$suffix = strrchr( $url, ')' ) . $suffix;
		$url    = substr( $url, 0, strrpos( $url, ')' ) );
	}

	$url = esc_url( $url );
	if ( empty( $url ) ) {
		return $matches[0];
	}

	if ( 'comment_text' === current_filter() ) {
		$rel = 'nofollow ugc';
	} else {
		$rel = 'nofollow';
	}

	/**
	 * Filters the rel value that is added to URL matches converted to links.
	 *
	 * @since 5.3.0
	 *
	 * @param string $rel The rel value.
	 * @param string $url The matched URL being converted to a link tag.
	 */
	$rel = apply_filters( 'make_clickable_rel', $rel, $url );
	$rel = esc_attr( $rel );

	return $matches[1] . "<a href=\"$url\" rel=\"$rel\">$url</a>" . $suffix;
}

/**
 * Callback to convert URL match to HTML A element.
 *
 * This function was backported from 2.5.0 to 2.3.2. Regex callback for make_clickable().
 *
 * @since 2.3.2
 * @access private
 *
 * @param array $matches Single Regex Match.
 * @return string HTML A element with URL address.
 */
function _make_web_ftp_clickable_cb( $matches ) {
	$ret  = '';
	$dest = $matches[2];
	$dest = 'http://' . $dest;

	// Removed trailing [.,;:)] from URL.
	$last_char = substr( $dest, -1 );
	if ( in_array( $last_char, array( '.', ',', ';', ':', ')' ), true ) === true ) {
		$ret  = $last_char;
		$dest = substr( $dest, 0, strlen( $dest ) - 1 );
	}

	$dest = esc_url( $dest );
	if ( empty( $dest ) ) {
		return $matches[0];
	}

	if ( 'comment_text' === current_filter() ) {
		$rel = 'nofollow ugc';
	} else {
		$rel = 'nofollow';
	}

	/** This filter is documented in wp-includes/formatting.php */
	$rel = apply_filters( 'make_clickable_rel', $rel, $dest );
	$rel = esc_attr( $rel );

	return $matches[1] . "<a href=\"$dest\" rel=\"$rel\">$dest</a>$ret";
}

/**
 * Callback to convert email address match to HTML A element.
 *
 * This function was backported from 2.5.0 to 2.3.2. Regex callback for make_clickable().
 *
 * @since 2.3.2
 * @access private
 *
 * @param array $matches Single Regex Match.
 * @return string HTML A element with email address.
 */
function _make_email_clickable_cb( $matches ) {
	$email = $matches[2] . '@' . $matches[3];
	return $matches[1] . "<a href=\"mailto:$email\">$email</a>";
}

/**
 * Converts plaintext URI to HTML links.
 *
 * Converts URI, www and ftp, and email addresses. Finishes by fixing links
 * within links.
 *
 * @since 0.71
 *
 * @param string $text Content to convert URIs.
 * @return string Content with converted URIs.
 */
function make_clickable( $text ) {
	$r               = '';
	$textarr         = preg_split( '/(<[^<>]+>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE ); // Split out HTML tags.
	$nested_code_pre = 0; // Keep track of how many levels link is nested inside <pre> or <code>.
	foreach ( $textarr as $piece ) {

		if ( preg_match( '|^<code[\s>]|i', $piece ) || preg_match( '|^<pre[\s>]|i', $piece ) || preg_match( '|^<script[\s>]|i', $piece ) || preg_match( '|^<style[\s>]|i', $piece ) ) {
			$nested_code_pre++;
		} elseif ( $nested_code_pre && ( '</code>' === strtolower( $piece ) || '</pre>' === strtolower( $piece ) || '</script>' === strtolower( $piece ) || '</style>' === strtolower( $piece ) ) ) {
			$nested_code_pre--;
		}

		if ( $nested_code_pre || empty( $piece ) || ( '<' === $piece[0] && ! preg_match( '|^<\s*[\w]{1,20}+://|', $piece ) ) ) {
			$r .= $piece;
			continue;
		}

		// Long strings might contain expensive edge cases...
		if ( 10000 < strlen( $piece ) ) {
			// ...break it up.
			foreach ( _split_str_by_whitespace( $piece, 2100 ) as $chunk ) { // 2100: Extra room for scheme and leading and trailing paretheses.
				if ( 2101 < strlen( $chunk ) ) {
					$r .= $chunk; // Too big, no whitespace: bail.
				} else {
					$r .= make_clickable( $chunk );
				}
			}
		} else {
			$ret = " $piece "; // Pad with whitespace to simplify the regexes.

			$url_clickable = '~
				([\\s(<.,;:!?])                                # 1: Leading whitespace, or punctuation.
				(                                              # 2: URL.
					[\\w]{1,20}+://                                # Scheme and hier-part prefix.
					(?=\S{1,2000}\s)                               # Limit to URLs less than about 2000 characters long.
					[\\w\\x80-\\xff#%\\~/@\\[\\]*(+=&$-]*+         # Non-punctuation URL character.
					(?:                                            # Unroll the Loop: Only allow puctuation URL character if followed by a non-punctuation URL character.
						[\'.,;:!?)]                                    # Punctuation URL character.
						[\\w\\x80-\\xff#%\\~/@\\[\\]*(+=&$-]++         # Non-punctuation URL character.
					)*
				)
				(\)?)                                          # 3: Trailing closing parenthesis (for parethesis balancing post processing).
			~xS';
			// The regex is a non-anchored pattern and does not have a single fixed starting character.
			// Tell PCRE to spend more time optimizing since, when used on a page load, it will probably be used several times.

			$ret = preg_replace_callback( $url_clickable, '_make_url_clickable_cb', $ret );

			$ret = preg_replace_callback( '#([\s>])((www|ftp)\.[\w\\x80-\\xff\#$%&~/.\-;:=,?@\[\]+]+)#is', '_make_web_ftp_clickable_cb', $ret );
			$ret = preg_replace_callback( '#([\s>])([.0-9a-z_+-]+)@(([0-9a-z-]+\.)+[0-9a-z]{2,})#i', '_make_email_clickable_cb', $ret );

			$ret = substr( $ret, 1, -1 ); // Remove our whitespace padding.
			$r  .= $ret;
		}
	}

	// Cleanup of accidental links within links.
	return preg_replace( '#(<a([ \r\n\t]+[^>]+?>|>))<a [^>]+?>([^>]+?)</a></a>#i', '$1$3</a>', $r );
}

/**
 * Breaks a string into chunks by splitting at whitespace characters.
 *
 * The length of each returned chunk is as close to the specified length goal as possible,
 * with the caveat that each chunk includes its trailing delimiter.
 * Chunks longer than the goal are guaranteed to not have any inner whitespace.
 *
 * Joining the returned chunks with empty delimiters reconstructs the input string losslessly.
 *
 * Input string must have no null characters (or eventual transformations on output chunks must not care about null characters)
 *
 *     _split_str_by_whitespace( "1234 67890 1234 67890a cd 1234   890 123456789 1234567890a    45678   1 3 5 7 90 ", 10 ) ==
 *     array (
 *         0 => '1234 67890 ',  // 11 characters: Perfect split.
 *         1 => '1234 ',        //  5 characters: '1234 67890a' was too long.
 *         2 => '67890a cd ',   // 10 characters: '67890a cd 1234' was too long.
 *         3 => '1234   890 ',  // 11 characters: Perfect split.
 *         4 => '123456789 ',   // 10 characters: '123456789 1234567890a' was too long.
 *         5 => '1234567890a ', // 12 characters: Too long, but no inner whitespace on which to split.
 *         6 => '   45678   ',  // 11 characters: Perfect split.
 *         7 => '1 3 5 7 90 ',  // 11 characters: End of $text.
 *     );
 *
 * @since 3.4.0
 * @access private
 *
 * @param string $text   The string to split.
 * @param int    $goal   The desired chunk length.
 * @return array Numeric array of chunks.
 */
function _split_str_by_whitespace( $text, $goal ) {
	$chunks = array();

	$string_nullspace = strtr( $text, "\r\n\t\v\f ", "\000\000\000\000\000\000" );

	while ( $goal < strlen( $string_nullspace ) ) {
		$pos = strrpos( substr( $string_nullspace, 0, $goal + 1 ), "\000" );

		if ( false === $pos ) {
			$pos = strpos( $string_nullspace, "\000", $goal + 1 );
			if ( false === $pos ) {
				break;
			}
		}

		$chunks[]         = substr( $text, 0, $pos + 1 );
		$text             = substr( $text, $pos + 1 );
		$string_nullspace = substr( $string_nullspace, $pos + 1 );
	}

	if ( $text ) {
		$chunks[] = $text;
	}

	return $chunks;
}

/**
 * Callback to add a rel attribute to HTML A element.
 *
 * Will remove already existing string before adding to prevent invalidating (X)HTML.
 *
 * @since 5.3.0
 *
 * @param array  $matches Single match.
 * @param string $rel     The rel attribute to add.
 * @return string HTML A element with the added rel attribute.
 */
function wp_rel_callback( $matches, $rel ) {
	$text = $matches[1];
	$atts = wp_kses_hair( $matches[1], wp_allowed_protocols() );

	if ( ! empty( $atts['href'] ) ) {
		if ( in_array( strtolower( wp_parse_url( $atts['href']['value'], PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) ) {
			if ( strtolower( wp_parse_url( $atts['href']['value'], PHP_URL_HOST ) ) === strtolower( wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
				return "<a $text>";
			}
		}
	}

	if ( ! empty( $atts['rel'] ) ) {
		$parts     = array_map( 'trim', explode( ' ', $atts['rel']['value'] ) );
		$rel_array = array_map( 'trim', explode( ' ', $rel ) );
		$parts     = array_unique( array_merge( $parts, $rel_array ) );
		$rel       = implode( ' ', $parts );
		unset( $atts['rel'] );

		$html = '';
		foreach ( $atts as $name => $value ) {
			if ( isset( $value['vless'] ) && 'y' === $value['vless'] ) {
				$html .= $name . ' ';
			} else {
				$html .= "{$name}=\"" . esc_attr( $value['value'] ) . '" ';
			}
		}
		$text = trim( $html );
	}
	return "<a $text rel=\"" . esc_attr( $rel ) . '">';
}

/**
 * Adds `rel="nofollow"` string to all HTML A elements in content.
 *
 * @since 1.5.0
 *
 * @param string $text Content that may contain HTML A elements.
 * @return string Converted content.
 */
function wp_rel_nofollow( $text ) {
	// This is a pre-save filter, so text is already escaped.
	$text = stripslashes( $text );
	$text = preg_replace_callback(
		'|<a (.+?)>|i',
		static function( $matches ) {
			return wp_rel_callback( $matches, 'nofollow' );
		},
		$text
	);
	return wp_slash( $text );
}

/**
 * Callback to add `rel="nofollow"` string to HTML A element.
 *
 * @since 2.3.0
 * @deprecated 5.3.0 Use wp_rel_callback()
 *
 * @param array $matches Single match.
 * @return string HTML A Element with `rel="nofollow"`.
 */
function wp_rel_nofollow_callback( $matches ) {
	return wp_rel_callback( $matches, 'nofollow' );
}

/**
 * Adds `rel="nofollow ugc"` string to all HTML A elements in content.
 *
 * @since 5.3.0
 *
 * @param string $text Content that may contain HTML A elements.
 * @return string Converted content.
 */
function wp_rel_ugc( $text ) {
	// This is a pre-save filter, so text is already escaped.
	$text = stripslashes( $text );
	$text = preg_replace_callback(
		'|<a (.+?)>|i',
		static function( $matches ) {
			return wp_rel_callback( $matches, 'nofollow ugc' );
		},
		$text
	);
	return wp_slash( $text );
}

/**
 * Adds `rel="noopener"` to all HTML A elements that have a target.
 *
 * @since 5.1.0
 * @since 5.6.0 Removed 'noreferrer' relationship.
 *
 * @param string $text Content that may contain HTML A elements.
 * @return string Converted content.
 */
function wp_targeted_link_rel( $text ) {
	// Don't run (more expensive) regex if no links with targets.
	if ( stripos( $text, 'target' ) === false || stripos( $text, '<a ' ) === false || is_serialized( $text ) ) {
		return $text;
	}

	$script_and_style_regex = '/<(script|style).*?<\/\\1>/si';

	preg_match_all( $script_and_style_regex, $text, $matches );
	$extra_parts = $matches[0];
	$html_parts  = preg_split( $script_and_style_regex, $text );

	foreach ( $html_parts as &$part ) {
		$part = preg_replace_callback( '|<a\s([^>]*target\s*=[^>]*)>|i', 'wp_targeted_link_rel_callback', $part );
	}

	$text = '';
	for ( $i = 0; $i < count( $html_parts ); $i++ ) {
		$text .= $html_parts[ $i ];
		if ( isset( $extra_parts[ $i ] ) ) {
			$text .= $extra_parts[ $i ];
		}
	}

	return $text;
}

/**
 * Callback to add `rel="noopener"` string to HTML A element.
 *
 * Will not duplicate an existing 'noopener' value to avoid invalidating the HTML.
 *
 * @since 5.1.0
 * @since 5.6.0 Removed 'noreferrer' relationship.
 *
 * @param array $matches Single match.
 * @return string HTML A Element with `rel="noopener"` in addition to any existing values.
 */
function wp_targeted_link_rel_callback( $matches ) {
	$link_html          = $matches[1];
	$original_link_html = $link_html;

	// Consider the HTML escaped if there are no unescaped quotes.
	$is_escaped = ! preg_match( '/(^|[^\\\\])[\'"]/', $link_html );
	if ( $is_escaped ) {
		// Replace only the quotes so that they are parsable by wp_kses_hair(), leave the rest as is.
		$link_html = preg_replace( '/\\\\([\'"])/', '$1', $link_html );
	}

	$atts = wp_kses_hair( $link_html, wp_allowed_protocols() );

	/**
	 * Filters the rel values that are added to links with `target` attribute.
	 *
	 * @since 5.1.0
	 *
	 * @param string $rel       The rel values.
	 * @param string $link_html The matched content of the link tag including all HTML attributes.
	 */
	$rel = apply_filters( 'wp_targeted_link_rel', 'noopener', $link_html );

	// Return early if no rel values to be added or if no actual target attribute.
	if ( ! $rel || ! isset( $atts['target'] ) ) {
		return "<a $original_link_html>";
	}

	if ( isset( $atts['rel'] ) ) {
		$all_parts = preg_split( '/\s/', "{$atts['rel']['value']} $rel", -1, PREG_SPLIT_NO_EMPTY );
		$rel       = implode( ' ', array_unique( $all_parts ) );
	}

	$atts['rel']['whole'] = 'rel="' . esc_attr( $rel ) . '"';
	$link_html            = implode( ' ', array_column( $atts, 'whole' ) );

	if ( $is_escaped ) {
		$link_html = preg_replace( '/[\'"]/', '\\\\$0', $link_html );
	}

	return "<a $link_html>";
}

/**
 * Adds all filters modifying the rel attribute of targeted links.
 *
 * @since 5.1.0
 */
function wp_init_targeted_link_rel_filters() {
	$filters = array(
		'title_save_pre',
		'content_save_pre',
		'excerpt_save_pre',
		'content_filtered_save_pre',
		'pre_comment_content',
		'pre_term_description',
		'pre_link_description',
		'pre_link_notes',
		'pre_user_description',
	);

	foreach ( $filters as $filter ) {
		add_filter( $filter, 'wp_targeted_link_rel' );
	}
}

/**
 * Removes all filters modifying the rel attribute of targeted links.
 *
 * @since 5.1.0
 */
function wp_remove_targeted_link_rel_filters() {
	$filters = array(
		'title_save_pre',
		'content_save_pre',
		'excerpt_save_pre',
		'content_filtered_save_pre',
		'pre_comment_content',
		'pre_term_description',
		'pre_link_description',
		'pre_link_notes',
		'pre_user_description',
	);

	foreach ( $filters as $filter ) {
		remove_filter( $filter, 'wp_targeted_link_rel' );
	}
}

/**
 * WordPress function that converts text equivalent of smilies to images.
 * Removing this BS. it does nothing now, keep it here for some compat.
 */
function convert_smilies( $text ) {
	return $text; // Convert nothing.
}

/**
 * Verifies that an email is valid.
 *
 * Does not grok i18n domains. Not RFC compliant.
 *
 * @since 0.71
 *
 * @param string $email      Email address to verify.
 * @param bool   $deprecated Deprecated.
 * @return string|false Valid email address on success, false on failure.
 */
function is_email( $email, $deprecated = false ) {
	if ( ! empty( $deprecated ) ) {
		_deprecated_argument( __FUNCTION__, '3.0.0' );
	}

	// Test for the minimum length the email can be.
	if ( strlen( $email ) < 6 ) {
		/**
		 * Filters whether an email address is valid.
		 *
		 * This filter is evaluated under several different contexts, such as 'email_too_short',
		 * 'email_no_at', 'local_invalid_chars', 'domain_period_sequence', 'domain_period_limits',
		 * 'domain_no_periods', 'sub_hyphen_limits', 'sub_invalid_chars', or no specific context.
		 *
		 * @since 2.8.0
		 *
		 * @param string|false $is_email The email address if successfully passed the is_email() checks, false otherwise.
		 * @param string       $email    The email address being checked.
		 * @param string       $context  Context under which the email was tested.
		 */
		return apply_filters( 'is_email', false, $email, 'email_too_short' );
	}

	// Test for an @ character after the first position.
	if ( strpos( $email, '@', 1 ) === false ) {
		/** This filter is documented in wp-includes/formatting.php */
		return apply_filters( 'is_email', false, $email, 'email_no_at' );
	}

	// Split out the local and domain parts.
	list( $local, $domain ) = explode( '@', $email, 2 );

	// LOCAL PART
	// Test for invalid characters.
	if ( ! preg_match( '/^[a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~\.-]+$/', $local ) ) {
		/** This filter is documented in wp-includes/formatting.php */
		return apply_filters( 'is_email', false, $email, 'local_invalid_chars' );
	}

	// DOMAIN PART
	// Test for sequences of periods.
	if ( preg_match( '/\.{2,}/', $domain ) ) {
		/** This filter is documented in wp-includes/formatting.php */
		return apply_filters( 'is_email', false, $email, 'domain_period_sequence' );
	}

	// Test for leading and trailing periods and whitespace.
	if ( trim( $domain, " \t\n\r\0\x0B." ) !== $domain ) {
		/** This filter is documented in wp-includes/formatting.php */
		return apply_filters( 'is_email', false, $email, 'domain_period_limits' );
	}

	// Split the domain into subs.
	$subs = explode( '.', $domain );

	// Assume the domain will have at least two subs.
	if ( 2 > count( $subs ) ) {
		/** This filter is documented in wp-includes/formatting.php */
		return apply_filters( 'is_email', false, $email, 'domain_no_periods' );
	}

	// Loop through each sub.
	foreach ( $subs as $sub ) {
		// Test for leading and trailing hyphens and whitespace.
		if ( trim( $sub, " \t\n\r\0\x0B-" ) !== $sub ) {
			/** This filter is documented in wp-includes/formatting.php */
			return apply_filters( 'is_email', false, $email, 'sub_hyphen_limits' );
		}

		// Test for invalid characters.
		if ( ! preg_match( '/^[a-z0-9-]+$/i', $sub ) ) {
			/** This filter is documented in wp-includes/formatting.php */
			return apply_filters( 'is_email', false, $email, 'sub_invalid_chars' );
		}
	}

	// Congratulations, your email made it!
	/** This filter is documented in wp-includes/formatting.php */
	return apply_filters( 'is_email', $email, $email, null );
}

/**
 * Converts to ASCII from email subjects.
 *
 * @since 1.2.0
 *
 * @param string $subject Subject line.
 * @return string Converted string to ASCII.
 */
function wp_iso_descrambler( $subject ) {
	/* this may only work with iso-8859-1, I'm afraid */
	if ( ! preg_match( '#\=\?(.+)\?Q\?(.+)\?\=#i', $subject, $matches ) ) {
		return $subject;
	}

	$subject = str_replace( '_', ' ', $matches[2] );
	return preg_replace_callback( '#\=([0-9a-f]{2})#i', '_wp_iso_convert', $subject );
}

/**
 * Helper function to convert hex encoded chars to ASCII.
 *
 * @since 3.1.0
 * @access private
 *
 * @param array $matches The preg_replace_callback matches array.
 * @return string Converted chars.
 */
function _wp_iso_convert( $matches ) {
	return chr( hexdec( strtolower( $matches[1] ) ) );
}

/**
 * Given a date in the timezone of the site, returns that date in UTC.
 *
 * Requires and returns a date in the Y-m-d H:i:s format.
 * Return format can be overridden using the $format parameter.
 *
 * @since 1.2.0
 *
 * @param string $date_string The date to be converted, in the timezone of the site.
 * @param string $format      The format string for the returned date. Default 'Y-m-d H:i:s'.
 * @return string Formatted version of the date, in UTC.
 */
function get_gmt_from_date( $date_string, $format = 'Y-m-d H:i:s' ) {
	$datetime = date_create( $date_string, wp_timezone() );

	if ( false === $datetime ) {
		return gmdate( $format, 0 );
	}

	return $datetime->setTimezone( new DateTimeZone( 'UTC' ) )->format( $format );
}

/**
 * Given a date in UTC or GMT timezone, returns that date in the timezone of the site.
 *
 * Requires a date in the Y-m-d H:i:s format.
 * Default return format of 'Y-m-d H:i:s' can be overridden using the `$format` parameter.
 *
 * @since 1.2.0
 *
 * @param string $date_string The date to be converted, in UTC or GMT timezone.
 * @param string $format      The format string for the returned date. Default 'Y-m-d H:i:s'.
 * @return string Formatted version of the date, in the site's timezone.
 */
function get_date_from_gmt( $date_string, $format = 'Y-m-d H:i:s' ) {
	$datetime = date_create( $date_string, new DateTimeZone( 'UTC' ) );

	if ( false === $datetime ) {
		return gmdate( $format, 0 );
	}

	return $datetime->setTimezone( wp_timezone() )->format( $format );
}

/**
 * Given an ISO 8601 timezone, returns its UTC offset in seconds.
 *
 * @since 1.5.0
 *
 * @param string $timezone Either 'Z' for 0 offset or '??hhmm'.
 * @return int|float The offset in seconds.
 */
function iso8601_timezone_to_offset( $timezone ) {
	// $timezone is either 'Z' or '[+|-]hhmm'.
	if ( 'Z' === $timezone ) {
		$offset = 0;
	} else {
		$sign    = ( '+' === substr( $timezone, 0, 1 ) ) ? 1 : -1;
		$hours   = (int) substr( $timezone, 1, 2 );
		$minutes = (int) substr( $timezone, 3, 4 ) / 60;
		$offset  = $sign * HOUR_IN_SECONDS * ( $hours + $minutes );
	}
	return $offset;
}

/**
 * Given an ISO 8601 (Ymd\TH:i:sO) date, returns a MySQL DateTime (Y-m-d H:i:s) format used by post_date[_gmt].
 *
 * @since 1.5.0
 *
 * @param string $date_string Date and time in ISO 8601 format {@link https://en.wikipedia.org/wiki/ISO_8601}.
 * @param string $timezone    Optional. If set to 'gmt' returns the result in UTC. Default 'user'.
 * @return string|false The date and time in MySQL DateTime format - Y-m-d H:i:s, or false on failure.
 */
function iso8601_to_datetime( $date_string, $timezone = 'user' ) {
	$timezone    = strtolower( $timezone );
	$wp_timezone = wp_timezone();
	$datetime    = date_create( $date_string, $wp_timezone ); // Timezone is ignored if input has one.

	if ( false === $datetime ) {
		return false;
	}

	if ( 'gmt' === $timezone ) {
		return $datetime->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	if ( 'user' === $timezone ) {
		return $datetime->setTimezone( $wp_timezone )->format( 'Y-m-d H:i:s' );
	}

	return false;
}

/**
 * Strips out all characters that are not allowable in an email.
 *
 * @since 1.5.0
 *
 * @param string $email Email address to filter.
 * @return string Filtered email address.
 */
function sanitize_email( $email ) {
	// Test for the minimum length the email can be.
	if ( strlen( $email ) < 6 ) {
		/**
		 * Filters a sanitized email address.
		 *
		 * This filter is evaluated under several contexts, including 'email_too_short',
		 * 'email_no_at', 'local_invalid_chars', 'domain_period_sequence', 'domain_period_limits',
		 * 'domain_no_periods', 'domain_no_valid_subs', or no context.
		 *
		 * @since 2.8.0
		 *
		 * @param string $sanitized_email The sanitized email address.
		 * @param string $email           The email address, as provided to sanitize_email().
		 * @param string|null $message    A message to pass to the user. null if email is sanitized.
		 */
		return apply_filters( 'sanitize_email', '', $email, 'email_too_short' );
	}

	// Test for an @ character after the first position.
	if ( strpos( $email, '@', 1 ) === false ) {
		/** This filter is documented in wp-includes/formatting.php */
		return apply_filters( 'sanitize_email', '', $email, 'email_no_at' );
	}

	// Split out the local and domain parts.
	list( $local, $domain ) = explode( '@', $email, 2 );

	// LOCAL PART
	// Test for invalid characters.
	$local = preg_replace( '/[^a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~\.-]/', '', $local );
	if ( '' === $local ) {
		/** This filter is documented in wp-includes/formatting.php */
		return apply_filters( 'sanitize_email', '', $email, 'local_invalid_chars' );
	}

	// DOMAIN PART
	// Test for sequences of periods.
	$domain = preg_replace( '/\.{2,}/', '', $domain );
	if ( '' === $domain ) {
		/** This filter is documented in wp-includes/formatting.php */
		return apply_filters( 'sanitize_email', '', $email, 'domain_period_sequence' );
	}

	// Test for leading and trailing periods and whitespace.
	$domain = trim( $domain, " \t\n\r\0\x0B." );
	if ( '' === $domain ) {
		/** This filter is documented in wp-includes/formatting.php */
		return apply_filters( 'sanitize_email', '', $email, 'domain_period_limits' );
	}

	// Split the domain into subs.
	$subs = explode( '.', $domain );

	// Assume the domain will have at least two subs.
	if ( 2 > count( $subs ) ) {
		/** This filter is documented in wp-includes/formatting.php */
		return apply_filters( 'sanitize_email', '', $email, 'domain_no_periods' );
	}

	// Create an array that will contain valid subs.
	$new_subs = array();

	// Loop through each sub.
	foreach ( $subs as $sub ) {
		// Test for leading and trailing hyphens.
		$sub = trim( $sub, " \t\n\r\0\x0B-" );

		// Test for invalid characters.
		$sub = preg_replace( '/[^a-z0-9-]+/i', '', $sub );

		// If there's anything left, add it to the valid subs.
		if ( '' !== $sub ) {
			$new_subs[] = $sub;
		}
	}

	// If there aren't 2 or more valid subs.
	if ( 2 > count( $new_subs ) ) {
		/** This filter is documented in wp-includes/formatting.php */
		return apply_filters( 'sanitize_email', '', $email, 'domain_no_valid_subs' );
	}

	// Join valid subs into the new domain.
	$domain = implode( '.', $new_subs );

	// Put the email back together.
	$sanitized_email = $local . '@' . $domain;

	// Congratulations, your email made it!
	/** This filter is documented in wp-includes/formatting.php */
	return apply_filters( 'sanitize_email', $sanitized_email, $email, null );
}

/**
 * Determines the difference between two timestamps.
 *
 * The difference is returned in a human readable format such as "1 hour",
 * "5 mins", "2 days".
 *
 * @since 1.5.0
 * @since 5.3.0 Added support for showing a difference in seconds.
 *
 * @param int $from Unix timestamp from which the difference begins.
 * @param int $to   Optional. Unix timestamp to end the time difference. Default becomes time() if not set.
 * @return string Human readable time difference.
 */
function human_time_diff( $from, $to = 0 ) {
	if ( empty( $to ) ) {
		$to = time();
	}

	$diff = (int) abs( $to - $from );

	if ( $diff < MINUTE_IN_SECONDS ) {
		$secs = $diff;
		if ( $secs <= 1 ) {
			$secs = 1;
		}
		/* translators: Time difference between two dates, in seconds. %s: Number of seconds. */
		$since = sprintf( _n( '%s second', '%s seconds', $secs ), $secs );
	} elseif ( $diff < HOUR_IN_SECONDS && $diff >= MINUTE_IN_SECONDS ) {
		$mins = round( $diff / MINUTE_IN_SECONDS );
		if ( $mins <= 1 ) {
			$mins = 1;
		}
		/* translators: Time difference between two dates, in minutes (min=minute). %s: Number of minutes. */
		$since = sprintf( _n( '%s min', '%s mins', $mins ), $mins );
	} elseif ( $diff < DAY_IN_SECONDS && $diff >= HOUR_IN_SECONDS ) {
		$hours = round( $diff / HOUR_IN_SECONDS );
		if ( $hours <= 1 ) {
			$hours = 1;
		}
		/* translators: Time difference between two dates, in hours. %s: Number of hours. */
		$since = sprintf( _n( '%s hour', '%s hours', $hours ), $hours );
	} elseif ( $diff < WEEK_IN_SECONDS && $diff >= DAY_IN_SECONDS ) {
		$days = round( $diff / DAY_IN_SECONDS );
		if ( $days <= 1 ) {
			$days = 1;
		}
		/* translators: Time difference between two dates, in days. %s: Number of days. */
		$since = sprintf( _n( '%s day', '%s days', $days ), $days );
	} elseif ( $diff < MONTH_IN_SECONDS && $diff >= WEEK_IN_SECONDS ) {
		$weeks = round( $diff / WEEK_IN_SECONDS );
		if ( $weeks <= 1 ) {
			$weeks = 1;
		}
		/* translators: Time difference between two dates, in weeks. %s: Number of weeks. */
		$since = sprintf( _n( '%s week', '%s weeks', $weeks ), $weeks );
	} elseif ( $diff < YEAR_IN_SECONDS && $diff >= MONTH_IN_SECONDS ) {
		$months = round( $diff / MONTH_IN_SECONDS );
		if ( $months <= 1 ) {
			$months = 1;
		}
		/* translators: Time difference between two dates, in months. %s: Number of months. */
		$since = sprintf( _n( '%s month', '%s months', $months ), $months );
	} elseif ( $diff >= YEAR_IN_SECONDS ) {
		$years = round( $diff / YEAR_IN_SECONDS );
		if ( $years <= 1 ) {
			$years = 1;
		}
		/* translators: Time difference between two dates, in years. %s: Number of years. */
		$since = sprintf( _n( '%s year', '%s years', $years ), $years );
	}

	/**
	 * Filters the human readable difference between two timestamps.
	 *
	 * @since 4.0.0
	 *
	 * @param string $since The difference in human readable text.
	 * @param int    $diff  The difference in seconds.
	 * @param int    $from  Unix timestamp from which the difference begins.
	 * @param int    $to    Unix timestamp to end the time difference.
	 */
	return apply_filters( 'human_time_diff', $since, $diff, $from, $to );
}

/**
 * Generates an excerpt from the content, if needed.
 *
 * Returns a maximum of 55 words with an ellipsis appended if necessary.
 *
 * The 55 word limit can be modified by plugins/themes using the {@see 'excerpt_length'} filter
 * The ' [&hellip;]' string can be modified by plugins/themes using the {@see 'excerpt_more'} filter
 *
 * @since 1.5.0
 * @since 5.2.0 Added the `$post` parameter.
 *
 * @param string             $text Optional. The excerpt. If set to empty, an excerpt is generated.
 * @param WP_Post|object|int $post Optional. WP_Post instance or Post ID/object. Default null.
 * @return string The excerpt.
 */
function wp_trim_excerpt( $text = '', $post = null ) {
	$raw_excerpt = $text;

	if ( '' === trim( $text ) ) {
		$post = get_post( $post );
		$text = get_the_content( '', false, $post );

		$text = strip_shortcodes( $text );

		/** This filter is documented in wp-includes/post-template.php */
		$text = apply_filters( 'the_content', $text );
		$text = str_replace( ']]>', ']]&gt;', $text );

		/* translators: Maximum number of words used in a post excerpt. */
		$excerpt_length = (int) _x( '55', 'excerpt_length' );

		/**
		 * Filters the maximum number of words in a post excerpt.
		 *
		 * @since 2.7.0
		 *
		 * @param int $number The maximum number of words. Default 55.
		 */
		$excerpt_length = (int) apply_filters( 'excerpt_length', $excerpt_length );

		/**
		 * Filters the string in the "more" link displayed after a trimmed excerpt.
		 *
		 * @since 2.9.0
		 *
		 * @param string $more_string The string shown within the more link.
		 */
		$excerpt_more = apply_filters( 'excerpt_more', ' ' . '[&hellip;]' );
		$text         = wp_trim_words( $text, $excerpt_length, $excerpt_more );
	}

	/**
	 * Filters the trimmed excerpt string.
	 *
	 * @since 2.8.0
	 *
	 * @param string $text        The trimmed text.
	 * @param string $raw_excerpt The text prior to trimming.
	 */
	return apply_filters( 'wp_trim_excerpt', $text, $raw_excerpt );
}

/**
 * Trims text to a certain number of words.
 *
 * This function is localized. For languages that count 'words' by the individual
 * character (such as East Asian languages), the $num_words argument will apply
 * to the number of individual characters.
 *
 * @since 3.3.0
 *
 * @param string $text      Text to trim.
 * @param int    $num_words Number of words. Default 55.
 * @param string $more      Optional. What to append if $text needs to be trimmed. Default '&hellip;'.
 * @return string Trimmed text.
 */
function wp_trim_words( $text, $num_words = 55, $more = null ) {
	if ( null === $more ) {
		$more = __( '&hellip;' );
	}

	$original_text = $text;
	$text          = wp_strip_all_tags( $text );
	$num_words     = (int) $num_words;

	/*
	 * translators: If your word count is based on single characters (e.g. East Asian characters),
	 * enter 'characters_excluding_spaces' or 'characters_including_spaces'. Otherwise, enter 'words'.
	 * Do not translate into your own language.
	 */
	if ( strpos( _x( 'words', 'Word count type. Do not translate!' ), 'characters' ) === 0 && preg_match( '/^utf\-?8$/i', get_option( 'blog_charset' ) ) ) {
		$text = trim( preg_replace( "/[\n\r\t ]+/", ' ', $text ), ' ' );
		preg_match_all( '/./u', $text, $words_array );
		$words_array = array_slice( $words_array[0], 0, $num_words + 1 );
		$sep         = '';
	} else {
		$words_array = preg_split( "/[\n\r\t ]+/", $text, $num_words + 1, PREG_SPLIT_NO_EMPTY );
		$sep         = ' ';
	}

	if ( count( $words_array ) > $num_words ) {
		array_pop( $words_array );
		$text = implode( $sep, $words_array );
		$text = $text . $more;
	} else {
		$text = implode( $sep, $words_array );
	}

	/**
	 * Filters the text content after words have been trimmed.
	 *
	 * @since 3.3.0
	 *
	 * @param string $text          The trimmed text.
	 * @param int    $num_words     The number of words to trim the text to. Default 55.
	 * @param string $more          An optional string to append to the end of the trimmed text, e.g. &hellip;.
	 * @param string $original_text The text before it was trimmed.
	 */
	return apply_filters( 'wp_trim_words', $text, $num_words, $more, $original_text );
}

/**
 * Converts named entities into numbered entities.
 *
 * @since 1.5.1
 *
 * @param string $text The text within which entities will be converted.
 * @return string Text with converted entities.
 */
function ent2ncr( $text ) {

	/**
	 * Filters text before named entities are converted into numbered entities.
	 *
	 * A non-null string must be returned for the filter to be evaluated.
	 *
	 * @since 3.3.0
	 *
	 * @param string|null $converted_text The text to be converted. Default null.
	 * @param string      $text           The text prior to entity conversion.
	 */
	$filtered = apply_filters( 'pre_ent2ncr', null, $text );
	if ( null !== $filtered ) {
		return $filtered;
	}

	$to_ncr = array(
		'&quot;'     => '&#34;',
		'&amp;'      => '&#38;',
		'&lt;'       => '&#60;',
		'&gt;'       => '&#62;',
		'|'          => '&#124;',
		'&nbsp;'     => '&#160;',
		'&iexcl;'    => '&#161;',
		'&cent;'     => '&#162;',
		'&pound;'    => '&#163;',
		'&curren;'   => '&#164;',
		'&yen;'      => '&#165;',
		'&brvbar;'   => '&#166;',
		'&brkbar;'   => '&#166;',
		'&sect;'     => '&#167;',
		'&uml;'      => '&#168;',
		'&die;'      => '&#168;',
		'&copy;'     => '&#169;',
		'&ordf;'     => '&#170;',
		'&laquo;'    => '&#171;',
		'&not;'      => '&#172;',
		'&shy;'      => '&#173;',
		'&reg;'      => '&#174;',
		'&macr;'     => '&#175;',
		'&hibar;'    => '&#175;',
		'&deg;'      => '&#176;',
		'&plusmn;'   => '&#177;',
		'&sup2;'     => '&#178;',
		'&sup3;'     => '&#179;',
		'&acute;'    => '&#180;',
		'&micro;'    => '&#181;',
		'&para;'     => '&#182;',
		'&middot;'   => '&#183;',
		'&cedil;'    => '&#184;',
		'&sup1;'     => '&#185;',
		'&ordm;'     => '&#186;',
		'&raquo;'    => '&#187;',
		'&frac14;'   => '&#188;',
		'&frac12;'   => '&#189;',
		'&frac34;'   => '&#190;',
		'&iquest;'   => '&#191;',
		'&Agrave;'   => '&#192;',
		'&Aacute;'   => '&#193;',
		'&Acirc;'    => '&#194;',
		'&Atilde;'   => '&#195;',
		'&Auml;'     => '&#196;',
		'&Aring;'    => '&#197;',
		'&AElig;'    => '&#198;',
		'&Ccedil;'   => '&#199;',
		'&Egrave;'   => '&#200;',
		'&Eacute;'   => '&#201;',
		'&Ecirc;'    => '&#202;',
		'&Euml;'     => '&#203;',
		'&Igrave;'   => '&#204;',
		'&Iacute;'   => '&#205;',
		'&Icirc;'    => '&#206;',
		'&Iuml;'     => '&#207;',
		'&ETH;'      => '&#208;',
		'&Ntilde;'   => '&#209;',
		'&Ograve;'   => '&#210;',
		'&Oacute;'   => '&#211;',
		'&Ocirc;'    => '&#212;',
		'&Otilde;'   => '&#213;',
		'&Ouml;'     => '&#214;',
		'&times;'    => '&#215;',
		'&Oslash;'   => '&#216;',
		'&Ugrave;'   => '&#217;',
		'&Uacute;'   => '&#218;',
		'&Ucirc;'    => '&#219;',
		'&Uuml;'     => '&#220;',
		'&Yacute;'   => '&#221;',
		'&THORN;'    => '&#222;',
		'&szlig;'    => '&#223;',
		'&agrave;'   => '&#224;',
		'&aacute;'   => '&#225;',
		'&acirc;'    => '&#226;',
		'&atilde;'   => '&#227;',
		'&auml;'     => '&#228;',
		'&aring;'    => '&#229;',
		'&aelig;'    => '&#230;',
		'&ccedil;'   => '&#231;',
		'&egrave;'   => '&#232;',
		'&eacute;'   => '&#233;',
		'&ecirc;'    => '&#234;',
		'&euml;'     => '&#235;',
		'&igrave;'   => '&#236;',
		'&iacute;'   => '&#237;',
		'&icirc;'    => '&#238;',
		'&iuml;'     => '&#239;',
		'&eth;'      => '&#240;',
		'&ntilde;'   => '&#241;',
		'&ograve;'   => '&#242;',
		'&oacute;'   => '&#243;',
		'&ocirc;'    => '&#244;',
		'&otilde;'   => '&#245;',
		'&ouml;'     => '&#246;',
		'&divide;'   => '&#247;',
		'&oslash;'   => '&#248;',
		'&ugrave;'   => '&#249;',
		'&uacute;'   => '&#250;',
		'&ucirc;'    => '&#251;',
		'&uuml;'     => '&#252;',
		'&yacute;'   => '&#253;',
		'&thorn;'    => '&#254;',
		'&yuml;'     => '&#255;',
		'&OElig;'    => '&#338;',
		'&oelig;'    => '&#339;',
		'&Scaron;'   => '&#352;',
		'&scaron;'   => '&#353;',
		'&Yuml;'     => '&#376;',
		'&fnof;'     => '&#402;',
		'&circ;'     => '&#710;',
		'&tilde;'    => '&#732;',
		'&Alpha;'    => '&#913;',
		'&Beta;'     => '&#914;',
		'&Gamma;'    => '&#915;',
		'&Delta;'    => '&#916;',
		'&Epsilon;'  => '&#917;',
		'&Zeta;'     => '&#918;',
		'&Eta;'      => '&#919;',
		'&Theta;'    => '&#920;',
		'&Iota;'     => '&#921;',
		'&Kappa;'    => '&#922;',
		'&Lambda;'   => '&#923;',
		'&Mu;'       => '&#924;',
		'&Nu;'       => '&#925;',
		'&Xi;'       => '&#926;',
		'&Omicron;'  => '&#927;',
		'&Pi;'       => '&#928;',
		'&Rho;'      => '&#929;',
		'&Sigma;'    => '&#931;',
		'&Tau;'      => '&#932;',
		'&Upsilon;'  => '&#933;',
		'&Phi;'      => '&#934;',
		'&Chi;'      => '&#935;',
		'&Psi;'      => '&#936;',
		'&Omega;'    => '&#937;',
		'&alpha;'    => '&#945;',
		'&beta;'     => '&#946;',
		'&gamma;'    => '&#947;',
		'&delta;'    => '&#948;',
		'&epsilon;'  => '&#949;',
		'&zeta;'     => '&#950;',
		'&eta;'      => '&#951;',
		'&theta;'    => '&#952;',
		'&iota;'     => '&#953;',
		'&kappa;'    => '&#954;',
		'&lambda;'   => '&#955;',
		'&mu;'       => '&#956;',
		'&nu;'       => '&#957;',
		'&xi;'       => '&#958;',
		'&omicron;'  => '&#959;',
		'&pi;'       => '&#960;',
		'&rho;'      => '&#961;',
		'&sigmaf;'   => '&#962;',
		'&sigma;'    => '&#963;',
		'&tau;'      => '&#964;',
		'&upsilon;'  => '&#965;',
		'&phi;'      => '&#966;',
		'&chi;'      => '&#967;',
		'&psi;'      => '&#968;',
		'&omega;'    => '&#969;',
		'&thetasym;' => '&#977;',
		'&upsih;'    => '&#978;',
		'&piv;'      => '&#982;',
		'&ensp;'     => '&#8194;',
		'&emsp;'     => '&#8195;',
		'&thinsp;'   => '&#8201;',
		'&zwnj;'     => '&#8204;',
		'&zwj;'      => '&#8205;',
		'&lrm;'      => '&#8206;',
		'&rlm;'      => '&#8207;',
		'&ndash;'    => '&#8211;',
		'&mdash;'    => '&#8212;',
		'&lsquo;'    => '&#8216;',
		'&rsquo;'    => '&#8217;',
		'&sbquo;'    => '&#8218;',
		'&ldquo;'    => '&#8220;',
		'&rdquo;'    => '&#8221;',
		'&bdquo;'    => '&#8222;',
		'&dagger;'   => '&#8224;',
		'&Dagger;'   => '&#8225;',
		'&bull;'     => '&#8226;',
		'&hellip;'   => '&#8230;',
		'&permil;'   => '&#8240;',
		'&prime;'    => '&#8242;',
		'&Prime;'    => '&#8243;',
		'&lsaquo;'   => '&#8249;',
		'&rsaquo;'   => '&#8250;',
		'&oline;'    => '&#8254;',
		'&frasl;'    => '&#8260;',
		'&euro;'     => '&#8364;',
		'&image;'    => '&#8465;',
		'&weierp;'   => '&#8472;',
		'&real;'     => '&#8476;',
		'&trade;'    => '&#8482;',
		'&alefsym;'  => '&#8501;',
		'&crarr;'    => '&#8629;',
		'&lArr;'     => '&#8656;',
		'&uArr;'     => '&#8657;',
		'&rArr;'     => '&#8658;',
		'&dArr;'     => '&#8659;',
		'&hArr;'     => '&#8660;',
		'&forall;'   => '&#8704;',
		'&part;'     => '&#8706;',
		'&exist;'    => '&#8707;',
		'&empty;'    => '&#8709;',
		'&nabla;'    => '&#8711;',
		'&isin;'     => '&#8712;',
		'&notin;'    => '&#8713;',
		'&ni;'       => '&#8715;',
		'&prod;'     => '&#8719;',
		'&sum;'      => '&#8721;',
		'&minus;'    => '&#8722;',
		'&lowast;'   => '&#8727;',
		'&radic;'    => '&#8730;',
		'&prop;'     => '&#8733;',
		'&infin;'    => '&#8734;',
		'&ang;'      => '&#8736;',
		'&and;'      => '&#8743;',
		'&or;'       => '&#8744;',
		'&cap;'      => '&#8745;',
		'&cup;'      => '&#8746;',
		'&int;'      => '&#8747;',
		'&there4;'   => '&#8756;',
		'&sim;'      => '&#8764;',
		'&cong;'     => '&#8773;',
		'&asymp;'    => '&#8776;',
		'&ne;'       => '&#8800;',
		'&equiv;'    => '&#8801;',
		'&le;'       => '&#8804;',
		'&ge;'       => '&#8805;',
		'&sub;'      => '&#8834;',
		'&sup;'      => '&#8835;',
		'&nsub;'     => '&#8836;',
		'&sube;'     => '&#8838;',
		'&supe;'     => '&#8839;',
		'&oplus;'    => '&#8853;',
		'&otimes;'   => '&#8855;',
		'&perp;'     => '&#8869;',
		'&sdot;'     => '&#8901;',
		'&lceil;'    => '&#8968;',
		'&rceil;'    => '&#8969;',
		'&lfloor;'   => '&#8970;',
		'&rfloor;'   => '&#8971;',
		'&lang;'     => '&#9001;',
		'&rang;'     => '&#9002;',
		'&larr;'     => '&#8592;',
		'&uarr;'     => '&#8593;',
		'&rarr;'     => '&#8594;',
		'&darr;'     => '&#8595;',
		'&harr;'     => '&#8596;',
		'&loz;'      => '&#9674;',
		'&spades;'   => '&#9824;',
		'&clubs;'    => '&#9827;',
		'&hearts;'   => '&#9829;',
		'&diams;'    => '&#9830;',
	);

	return str_replace( array_keys( $to_ncr ), array_values( $to_ncr ), $text );
}

/**
 * Formats text for the editor.
 *
 * Generally the browsers treat everything inside a textarea as text, but
 * it is still a good idea to HTML entity encode `<`, `>` and `&` in the content.
 *
 * The filter {@see 'format_for_editor'} is applied here. If `$text` is empty the
 * filter will be applied to an empty string.
 *
 * @since 4.3.0
 *
 * @see _WP_Editors::editor()
 *
 * @param string $text           The text to be formatted.
 * @param string $default_editor The default editor for the current user.
 *                               It is usually either 'html' or 'tinymce'.
 * @return string The formatted text after filter is applied.
 */
function format_for_editor( $text, $default_editor = null ) {
	if ( $text ) {
		$text = htmlspecialchars( $text, ENT_NOQUOTES, get_option( 'blog_charset' ) );
	}

	/**
	 * Filters the text after it is formatted for the editor.
	 *
	 * @since 4.3.0
	 *
	 * @param string $text           The formatted text.
	 * @param string $default_editor The default editor for the current user.
	 *                               It is usually either 'html' or 'tinymce'.
	 */
	return apply_filters( 'format_for_editor', $text, $default_editor );
}

/**
 * Performs a deep string replace operation to ensure the values in $search are no longer present.
 *
 * Repeats the replacement operation until it no longer replaces anything so as to remove "nested" values
 * e.g. $subject = '%0%0%0DDD', $search ='%0D', $result ='' rather than the '%0%0DD' that
 * str_replace would return
 *
 * @since 2.8.1
 * @access private
 *
 * @param string|array $search  The value being searched for, otherwise known as the needle.
 *                              An array may be used to designate multiple needles.
 * @param string       $subject The string being searched and replaced on, otherwise known as the haystack.
 * @return string The string with the replaced values.
 */
function _deep_replace( $search, $subject ) {
	$subject = (string) $subject;

	$count = 1;
	while ( $count ) {
		$subject = str_replace( $search, '', $subject, $count );
	}

	return $subject;
}

/**
 * Escapes data for use in a MySQL query.
 *
 * Usually you should prepare queries using wpdb::prepare().
 * Sometimes, spot-escaping is required or useful. One example
 * is preparing an array for use in an IN clause.
 *
 * NOTE: Since 4.8.3, '%' characters will be replaced with a placeholder string,
 * this prevents certain SQLi attacks from taking place. This change in behavior
 * may cause issues for code that expects the return value of esc_sql() to be useable
 * for other purposes.
 *
 * @since 2.8.0
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param string|array $data Unescaped data.
 * @return string|array Escaped data, in the same type as supplied.
 */
function esc_sql( $data ) {
	global $wpdb;
	return $wpdb->_escape( $data );
}

/**
 * Checks and cleans a URL.
 *
 * A number of characters are removed from the URL. If the URL is for displaying
 * (the default behavior) ampersands are also replaced. The {@see 'clean_url'} filter
 * is applied to the returned cleaned URL.
 *
 * @since 2.8.0
 *
 * @param string   $url       The URL to be cleaned.
 * @param string[] $protocols Optional. An array of acceptable protocols.
 *                            Defaults to return value of wp_allowed_protocols().
 * @param string   $_context  Private. Use sanitize_url() for database usage.
 * @return string The cleaned URL after the {@see 'clean_url'} filter is applied.
 *                An empty string is returned if `$url` specifies a protocol other than
 *                those in `$protocols`, or if `$url` contains an empty string.
 */
function esc_url( $url, $protocols = null, $_context = 'display' ) {
	$original_url = $url;

	if ( '' === $url ) {
		return $url;
	}

	$url = str_replace( ' ', '%20', ltrim( $url ) );
	$url = preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\\x80-\\xff]|i', '', $url );

	if ( '' === $url ) {
		return $url;
	}

	if ( 0 !== stripos( $url, 'mailto:' ) ) {
		$strip = array( '%0d', '%0a', '%0D', '%0A' );
		$url   = _deep_replace( $strip, $url );
	}

	$url = str_replace( ';//', '://', $url );
	/*
	 * If the URL doesn't appear to contain a scheme, we presume
	 * it needs http:// prepended (unless it's a relative link
	 * starting with /, # or ?, or a PHP file).
	 */
	if ( strpos( $url, ':' ) === false && ! in_array( $url[0], array( '/', '#', '?' ), true ) &&
		! preg_match( '/^[a-z0-9-]+?\.php/i', $url ) ) {
		$url = 'http://' . $url;
	}

	// Replace ampersands and single quotes only when displaying.
	if ( 'display' === $_context ) {
		$url = wp_kses_normalize_entities( $url );
		$url = str_replace( '&amp;', '&#038;', $url );
		$url = str_replace( "'", '&#039;', $url );
	}

	if ( ( false !== strpos( $url, '[' ) ) || ( false !== strpos( $url, ']' ) ) ) {

		$parsed = wp_parse_url( $url );
		$front  = '';

		if ( isset( $parsed['scheme'] ) ) {
			$front .= $parsed['scheme'] . '://';
		} elseif ( '/' === $url[0] ) {
			$front .= '//';
		}

		if ( isset( $parsed['user'] ) ) {
			$front .= $parsed['user'];
		}

		if ( isset( $parsed['pass'] ) ) {
			$front .= ':' . $parsed['pass'];
		}

		if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
			$front .= '@';
		}

		if ( isset( $parsed['host'] ) ) {
			$front .= $parsed['host'];
		}

		if ( isset( $parsed['port'] ) ) {
			$front .= ':' . $parsed['port'];
		}

		$end_dirty = str_replace( $front, '', $url );
		$end_clean = str_replace( array( '[', ']' ), array( '%5B', '%5D' ), $end_dirty );
		$url       = str_replace( $end_dirty, $end_clean, $url );

	}

	if ( '/' === $url[0] ) {
		$good_protocol_url = $url;
	} else {
		if ( ! is_array( $protocols ) ) {
			$protocols = wp_allowed_protocols();
		}
		$good_protocol_url = wp_kses_bad_protocol( $url, $protocols );
		if ( strtolower( $good_protocol_url ) != strtolower( $url ) ) {
			return '';
		}
	}

	/**
	 * Filters a string cleaned and escaped for output as a URL.
	 *
	 * @since 2.3.0
	 *
	 * @param string $good_protocol_url The cleaned URL to be returned.
	 * @param string $original_url      The URL prior to cleaning.
	 * @param string $_context          If 'display', replace ampersands and single quotes only.
	 */
	return apply_filters( 'clean_url', $good_protocol_url, $original_url, $_context );
}

/**
 * Sanitizes a URL for database or redirect usage.
 *
 * This function is an alias for sanitize_url().
 *
 * @since 2.8.0
 * @since 6.1.0 Turned into an alias for sanitize_url().
 *
 * @see sanitize_url()
 *
 * @param string   $url       The URL to be cleaned.
 * @param string[] $protocols Optional. An array of acceptable protocols.
 *                            Defaults to return value of wp_allowed_protocols().
 * @return string The cleaned URL after sanitize_url() is run.
 */
function esc_url_raw( $url, $protocols = null ) {
	return sanitize_url( $url, $protocols );
}

/**
 * Sanitizes a URL for database or redirect usage.
 *
 * @since 2.3.1
 * @since 2.8.0 Deprecated in favor of esc_url_raw().
 * @since 5.9.0 Restored (un-deprecated).
 *
 * @see esc_url()
 *
 * @param string   $url       The URL to be cleaned.
 * @param string[] $protocols Optional. An array of acceptable protocols.
 *                            Defaults to return value of wp_allowed_protocols().
 * @return string The cleaned URL after esc_url() is run with the 'db' context.
 */
function sanitize_url( $url, $protocols = null ) {
	return esc_url( $url, $protocols, 'db' );
}

/**
 * Converts entities, while preserving already-encoded entities.
 *
 * @link https://www.php.net/htmlentities Borrowed from the PHP Manual user notes.
 *
 * @since 1.2.2
 *
 * @param string $myHTML The text to be converted.
 * @return string Converted text.
 */
function htmlentities2( $myHTML ) {
	$translation_table              = get_html_translation_table( HTML_ENTITIES, ENT_QUOTES );
	$translation_table[ chr( 38 ) ] = '&';
	return preg_replace( '/&(?![A-Za-z]{0,4}\w{2,3};|#[0-9]{2,3};)/', '&amp;', strtr( $myHTML, $translation_table ) );
}

/**
 * Escapes single quotes, `"`, `<`, `>`, `&`, and fixes line endings.
 *
 * Escapes text strings for echoing in JS. It is intended to be used for inline JS
 * (in a tag attribute, for example `onclick="..."`). Note that the strings have to
 * be in single quotes. The {@see 'js_escape'} filter is also applied here.
 *
 * @since 2.8.0
 *
 * @param string $text The text to be escaped.
 * @return string Escaped text.
 */
function esc_js( $text ) {
	$safe_text = wp_check_invalid_utf8( $text );
	$safe_text = _wp_specialchars( $safe_text, ENT_COMPAT );
	$safe_text = preg_replace( '/&#(x)?0*(?(1)27|39);?/i', "'", stripslashes( $safe_text ) );
	$safe_text = str_replace( "\r", '', $safe_text );
	$safe_text = str_replace( "\n", '\\n', addslashes( $safe_text ) );
	/**
	 * Filters a string cleaned and escaped for output in JavaScript.
	 *
	 * Text passed to esc_js() is stripped of invalid or special characters,
	 * and properly slashed for output.
	 *
	 * @since 2.0.6
	 *
	 * @param string $safe_text The text after it has been escaped.
	 * @param string $text      The text prior to being escaped.
	 */
	return apply_filters( 'js_escape', $safe_text, $text );
}

/**
 * Escaping for HTML blocks.
 *
 * @since 2.8.0
 *
 * @param string $text
 * @return string
 */
function esc_html( $text ) {
	$safe_text = wp_check_invalid_utf8( $text );
	$safe_text = _wp_specialchars( $safe_text, ENT_QUOTES );
	/**
	 * Filters a string cleaned and escaped for output in HTML.
	 *
	 * Text passed to esc_html() is stripped of invalid or special characters
	 * before output.
	 *
	 * @since 2.8.0
	 *
	 * @param string $safe_text The text after it has been escaped.
	 * @param string $text      The text prior to being escaped.
	 */
	return apply_filters( 'esc_html', $safe_text, $text );
}

/**
 * Escaping for HTML attributes.
 *
 * @since 2.8.0
 *
 * @param string $text
 * @return string
 */
function esc_attr( $text ) {
	$safe_text = wp_check_invalid_utf8( $text );
	$safe_text = _wp_specialchars( $safe_text, ENT_QUOTES );
	/**
	 * Filters a string cleaned and escaped for output in an HTML attribute.
	 *
	 * Text passed to esc_attr() is stripped of invalid or special characters
	 * before output.
	 *
	 * @since 2.0.6
	 *
	 * @param string $safe_text The text after it has been escaped.
	 * @param string $text      The text prior to being escaped.
	 */
	return apply_filters( 'attribute_escape', $safe_text, $text );
}

/**
 * Escaping for textarea values.
 *
 * @since 3.1.0
 *
 * @param string $text
 * @return string
 */
function esc_textarea( $text ) {
	$safe_text = htmlspecialchars( $text, ENT_QUOTES, get_option( 'blog_charset' ) );
	/**
	 * Filters a string cleaned and escaped for output in a textarea element.
	 *
	 * @since 3.1.0
	 *
	 * @param string $safe_text The text after it has been escaped.
	 * @param string $text      The text prior to being escaped.
	 */
	return apply_filters( 'esc_textarea', $safe_text, $text );
}

/**
 * Escaping for XML blocks.
 *
 * @since 5.5.0
 *
 * @param string $text Text to escape.
 * @return string Escaped text.
 */
function esc_xml( $text ) {
	$safe_text = wp_check_invalid_utf8( $text );

	$cdata_regex = '\<\!\[CDATA\[.*?\]\]\>';
	$regex       = <<<EOF
/
	(?=.*?{$cdata_regex})                 # lookahead that will match anything followed by a CDATA Section
	(?<non_cdata_followed_by_cdata>(.*?)) # the "anything" matched by the lookahead
	(?<cdata>({$cdata_regex}))            # the CDATA Section matched by the lookahead

|	                                      # alternative

	(?<non_cdata>(.*))                    # non-CDATA Section
/sx
EOF;

	$safe_text = (string) preg_replace_callback(
		$regex,
		static function( $matches ) {
			if ( ! isset( $matches[0] ) ) {
				return '';
			}

			if ( isset( $matches['non_cdata'] ) ) {
				// escape HTML entities in the non-CDATA Section.
				return _wp_specialchars( $matches['non_cdata'], ENT_XML1 );
			}

			// Return the CDATA Section unchanged, escape HTML entities in the rest.
			return _wp_specialchars( $matches['non_cdata_followed_by_cdata'], ENT_XML1 ) . $matches['cdata'];
		},
		$safe_text
	);

	/**
	 * Filters a string cleaned and escaped for output in XML.
	 *
	 * Text passed to esc_xml() is stripped of invalid or special characters
	 * before output. HTML named character references are converted to their
	 * equivalent code points.
	 *
	 * @since 5.5.0
	 *
	 * @param string $safe_text The text after it has been escaped.
	 * @param string $text      The text prior to being escaped.
	 */
	return apply_filters( 'esc_xml', $safe_text, $text );
}

/**
 * Escapes an HTML tag name.
 *
 * @since 2.5.0
 *
 * @param string $tag_name
 * @return string
 */
function tag_escape( $tag_name ) {
	$safe_tag = strtolower( preg_replace( '/[^a-zA-Z0-9_:]/', '', $tag_name ) );
	/**
	 * Filters a string cleaned and escaped for output as an HTML tag.
	 *
	 * @since 2.8.0
	 *
	 * @param string $safe_tag The tag name after it has been escaped.
	 * @param string $tag_name The text before it was escaped.
	 */
	return apply_filters( 'tag_escape', $safe_tag, $tag_name );
}

/**
 * Converts full URL paths to absolute paths.
 *
 * Removes the http or https protocols and the domain. Keeps the path '/' at the
 * beginning, so it isn't a true relative link, but from the web root base.
 *
 * @since 2.1.0
 * @since 4.1.0 Support was added for relative URLs.
 *
 * @param string $link Full URL path.
 * @return string Absolute path.
 */
function wp_make_link_relative( $link ) {
	return preg_replace( '|^(https?:)?//[^/]+(/?.*)|i', '$2', $link );
}

/**
 * Sanitizes various option values based on the nature of the option.
 *
 * This is basically a switch statement which will pass $value through a number
 * of functions depending on the $option.
 *
 * @since 2.0.5
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param string $option The name of the option.
 * @param string $value  The unsanitized value.
 * @return string Sanitized value.
 */
function sanitize_option( $option, $value ) {
	global $wpdb;

	$original_value = $value;
	$error          = null;

	switch ( $option ) {
		case 'admin_email':
		case 'new_admin_email':
			$value = $wpdb->strip_invalid_text_for_column( $wpdb->options, 'option_value', $value );
			if ( is_wp_error( $value ) ) {
				$error = $value->get_error_message();
			} else {
				$value = sanitize_email( $value );
				if ( ! is_email( $value ) ) {
					$error = __( 'The email address entered did not appear to be a valid email address. Please enter a valid email address.' );
				}
			}
			break;

		case 'thumbnail_size_w':
		case 'thumbnail_size_h':
		case 'medium_size_w':
		case 'medium_size_h':
		case 'medium_large_size_w':
		case 'medium_large_size_h':
		case 'large_size_w':
		case 'large_size_h':
		case 'mailserver_port':
		case 'comment_max_links':
		case 'page_on_front':
		case 'page_for_posts':
		case 'rss_excerpt_length':
		case 'default_category':
		case 'default_email_category':
		case 'default_link_category':
		case 'close_comments_days_old':
		case 'comments_per_page':
		case 'thread_comments_depth':
		case 'users_can_register':
		case 'start_of_week':
		case 'site_icon':
		case 'fileupload_maxk':
			$value = absint( $value );
			break;

		case 'posts_per_page':
		case 'posts_per_rss':
			$value = (int) $value;
			if ( empty( $value ) ) {
				$value = 1;
			}
			if ( $value < -1 ) {
				$value = abs( $value );
			}
			break;

		case 'default_ping_status':
		case 'default_comment_status':
			// Options that if not there have 0 value but need to be something like "closed".
			if ( '0' == $value || '' === $value ) {
				$value = 'closed';
			}
			break;

		case 'blogdescription':
		case 'blogname':
			$value = $wpdb->strip_invalid_text_for_column( $wpdb->options, 'option_value', $value );
			if ( $value !== $original_value ) {
				$value = $wpdb->strip_invalid_text_for_column( $wpdb->options, 'option_value', $original_value );
			}

			if ( is_wp_error( $value ) ) {
				$error = $value->get_error_message();
			} else {
				$value = esc_html( $value );
			}
			break;

		case 'blog_charset':
			$value = preg_replace( '/[^a-zA-Z0-9_-]/', '', $value ); // Strips slashes.
			break;

		case 'blog_public':
			// This is the value if the settings checkbox is not checked on POST. Don't rely on this.
			if ( null === $value ) {
				$value = 1;
			} else {
				$value = (int) $value;
			}
			break;

		case 'date_format':
		case 'time_format':
		case 'mailserver_url':
		case 'mailserver_login':
		case 'mailserver_pass':
		case 'upload_path':
			$value = $wpdb->strip_invalid_text_for_column( $wpdb->options, 'option_value', $value );
			if ( is_wp_error( $value ) ) {
				$error = $value->get_error_message();
			} else {
				$value = strip_tags( $value );
				$value = wp_kses_data( $value );
			}
			break;

		case 'ping_sites':
			$value = explode( "\n", $value );
			$value = array_filter( array_map( 'trim', $value ) );
			$value = array_filter( array_map( 'sanitize_url', $value ) );
			$value = implode( "\n", $value );
			break;

		case 'gmt_offset':
			$value = preg_replace( '/[^0-9:.-]/', '', $value ); // Strips slashes.
			break;

		case 'siteurl':
			$value = $wpdb->strip_invalid_text_for_column( $wpdb->options, 'option_value', $value );
			if ( is_wp_error( $value ) ) {
				$error = $value->get_error_message();
			} else {
				if ( preg_match( '#http(s?)://(.+)#i', $value ) ) {
					$value = sanitize_url( $value );
				} else {
					$error = __( 'The WordPress address you entered did not appear to be a valid URL. Please enter a valid URL.' );
				}
			}
			break;

		case 'home':
			$value = $wpdb->strip_invalid_text_for_column( $wpdb->options, 'option_value', $value );
			if ( is_wp_error( $value ) ) {
				$error = $value->get_error_message();
			} else {
				if ( preg_match( '#http(s?)://(.+)#i', $value ) ) {
					$value = sanitize_url( $value );
				} else {
					$error = __( 'The Site address you entered did not appear to be a valid URL. Please enter a valid URL.' );
				}
			}
			break;

		case 'WPLANG':
			$allowed = get_available_languages();
			if ( ! is_multisite() && defined( 'WPLANG' ) && '' !== WPLANG && 'en_US' !== WPLANG ) {
				$allowed[] = WPLANG;
			}
			if ( ! in_array( $value, $allowed, true ) && ! empty( $value ) ) {
				$value = get_option( $option );
			}
			break;

		case 'illegal_names':
			$value = $wpdb->strip_invalid_text_for_column( $wpdb->options, 'option_value', $value );
			if ( is_wp_error( $value ) ) {
				$error = $value->get_error_message();
			} else {
				if ( ! is_array( $value ) ) {
					$value = explode( ' ', $value );
				}

				$value = array_values( array_filter( array_map( 'trim', $value ) ) );

				if ( ! $value ) {
					$value = '';
				}
			}
			break;

		case 'limited_email_domains':
		case 'banned_email_domains':
			$value = $wpdb->strip_invalid_text_for_column( $wpdb->options, 'option_value', $value );
			if ( is_wp_error( $value ) ) {
				$error = $value->get_error_message();
			} else {
				if ( ! is_array( $value ) ) {
					$value = explode( "\n", $value );
				}

				$domains = array_values( array_filter( array_map( 'trim', $value ) ) );
				$value   = array();

				foreach ( $domains as $domain ) {
					if ( ! preg_match( '/(--|\.\.)/', $domain ) && preg_match( '|^([a-zA-Z0-9-\.])+$|', $domain ) ) {
						$value[] = $domain;
					}
				}
				if ( ! $value ) {
					$value = '';
				}
			}
			break;

		case 'timezone_string':
			$allowed_zones = timezone_identifiers_list( DateTimeZone::ALL_WITH_BC );
			if ( ! in_array( $value, $allowed_zones, true ) && ! empty( $value ) ) {
				$error = __( 'The timezone you have entered is not valid. Please select a valid timezone.' );
			}
			break;

		case 'permalink_structure':
		case 'category_base':
		case 'tag_base':
			$value = $wpdb->strip_invalid_text_for_column( $wpdb->options, 'option_value', $value );
			if ( is_wp_error( $value ) ) {
				$error = $value->get_error_message();
			} else {
				$value = sanitize_url( $value );
				$value = str_replace( 'http://', '', $value );
			}

			if ( 'permalink_structure' === $option && null === $error
				&& '' !== $value && ! preg_match( '/%[^\/%]+%/', $value )
			) {
				$error = sprintf(
					/* translators: %s: Documentation URL. */
					__( 'A structure tag is required when using custom permalinks. <a href="%s">Learn more</a>' ),
					__( 'https://wordpress.org/support/article/using-permalinks/#choosing-your-permalink-structure' )
				);
			}
			break;

		case 'default_role':
			if ( ! get_role( $value ) && get_role( 'subscriber' ) ) {
				$value = 'subscriber';
			}
			break;

		case 'moderation_keys':
		case 'disallowed_keys':
			$value = $wpdb->strip_invalid_text_for_column( $wpdb->options, 'option_value', $value );
			if ( is_wp_error( $value ) ) {
				$error = $value->get_error_message();
			} else {
				$value = explode( "\n", $value );
				$value = array_filter( array_map( 'trim', $value ) );
				$value = array_unique( $value );
				$value = implode( "\n", $value );
			}
			break;
	}

	if ( null !== $error ) {
		if ( '' === $error && is_wp_error( $value ) ) {
			/* translators: 1: Option name, 2: Error code. */
			$error = sprintf( __( 'Could not sanitize the %1$s option. Error code: %2$s' ), $option, $value->get_error_code() );
		}

		$value = get_option( $option );
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( $option, "invalid_{$option}", $error );
		}
	}

	/**
	 * Filters an option value following sanitization.
	 *
	 * @since 2.3.0
	 * @since 4.3.0 Added the `$original_value` parameter.
	 *
	 * @param string $value          The sanitized option value.
	 * @param string $option         The option name.
	 * @param string $original_value The original value passed to the function.
	 */
	return apply_filters( "sanitize_option_{$option}", $value, $option, $original_value );
}

/**
 * Maps a function to all non-iterable elements of an array or an object.
 *
 * This is similar to `array_walk_recursive()` but acts upon objects too.
 *
 * @since 4.4.0
 *
 * @param mixed    $value    The array, object, or scalar.
 * @param callable $callback The function to map onto $value.
 * @return mixed The value with the callback applied to all non-arrays and non-objects inside it.
 */
function map_deep( $value, $callback ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $index => $item ) {
			$value[ $index ] = map_deep( $item, $callback );
		}
	} elseif ( is_object( $value ) ) {
		$object_vars = get_object_vars( $value );
		foreach ( $object_vars as $property_name => $property_value ) {
			$value->$property_name = map_deep( $property_value, $callback );
		}
	} else {
		$value = call_user_func( $callback, $value );
	}

	return $value;
}

/**
 * Parses a string into variables to be stored in an array.
 *
 * @since 2.2.1
 *
 * @param string $input_string The string to be parsed.
 * @param array  $result       Variables will be stored in this array.
 */
function wp_parse_str( $input_string, &$result ) {
	parse_str( (string) $input_string, $result );

	/**
	 * Filters the array of variables derived from a parsed string.
	 *
	 * @since 2.2.1
	 *
	 * @param array $result The array populated with variables.
	 */
	$result = apply_filters( 'wp_parse_str', $result );
}

/**
 * Converts lone less than signs.
 *
 * KSES already converts lone greater than signs.
 *
 * @since 2.3.0
 *
 * @param string $content Text to be converted.
 * @return string Converted text.
 */
function wp_pre_kses_less_than( $content ) {
	return preg_replace_callback( '%<[^>]*?((?=<)|>|$)%', 'wp_pre_kses_less_than_callback', $content );
}

/**
 * Callback function used by preg_replace.
 *
 * @since 2.3.0
 *
 * @param string[] $matches Populated by matches to preg_replace.
 * @return string The text returned after esc_html if needed.
 */
function wp_pre_kses_less_than_callback( $matches ) {
	if ( false === strpos( $matches[0], '>' ) ) {
		return esc_html( $matches[0] );
	}
	return $matches[0];
}

/**
 * WordPress implementation of PHP sprintf() with filters.
 *
 * @since 2.5.0
 * @since 5.3.0 Formalized the existing and already documented `...$args` parameter
 *              by adding it to the function signature.
 *
 * @link https://www.php.net/sprintf
 *
 * @param string $pattern The string which formatted args are inserted.
 * @param mixed  ...$args Arguments to be formatted into the $pattern string.
 * @return string The formatted string.
 */
function wp_sprintf( $pattern, ...$args ) {
	$len       = strlen( $pattern );
	$start     = 0;
	$result    = '';
	$arg_index = 0;
	while ( $len > $start ) {
		// Last character: append and break.
		if ( strlen( $pattern ) - 1 == $start ) {
			$result .= substr( $pattern, -1 );
			break;
		}

		// Literal %: append and continue.
		if ( '%%' === substr( $pattern, $start, 2 ) ) {
			$start  += 2;
			$result .= '%';
			continue;
		}

		// Get fragment before next %.
		$end = strpos( $pattern, '%', $start + 1 );
		if ( false === $end ) {
			$end = $len;
		}
		$fragment = substr( $pattern, $start, $end - $start );

		// Fragment has a specifier.
		if ( '%' === $pattern[ $start ] ) {
			// Find numbered arguments or take the next one in order.
			if ( preg_match( '/^%(\d+)\$/', $fragment, $matches ) ) {
				$index    = $matches[1] - 1; // 0-based array vs 1-based sprintf() arguments.
				$arg      = isset( $args[ $index ] ) ? $args[ $index ] : '';
				$fragment = str_replace( "%{$matches[1]}$", '%', $fragment );
			} else {
				$arg = isset( $args[ $arg_index ] ) ? $args[ $arg_index ] : '';
				++$arg_index;
			}

			/**
			 * Filters a fragment from the pattern passed to wp_sprintf().
			 *
			 * If the fragment is unchanged, then sprintf() will be run on the fragment.
			 *
			 * @since 2.5.0
			 *
			 * @param string $fragment A fragment from the pattern.
			 * @param string $arg      The argument.
			 */
			$_fragment = apply_filters( 'wp_sprintf', $fragment, $arg );
			if ( $_fragment != $fragment ) {
				$fragment = $_fragment;
			} else {
				$fragment = sprintf( $fragment, (string) $arg );
			}
		}

		// Append to result and move to next fragment.
		$result .= $fragment;
		$start   = $end;
	}

	return $result;
}

/**
 * Localizes list items before the rest of the content.
 *
 * The '%l' must be at the first characters can then contain the rest of the
 * content. The list items will have ', ', ', and', and ' and ' added depending
 * on the amount of list items in the $args parameter.
 *
 * @since 2.5.0
 *
 * @param string $pattern Content containing '%l' at the beginning.
 * @param array  $args    List items to prepend to the content and replace '%l'.
 * @return string Localized list items and rest of the content.
 */
function wp_sprintf_l( $pattern, $args ) {
	// Not a match.
	if ( '%l' !== substr( $pattern, 0, 2 ) ) {
		return $pattern;
	}

	// Nothing to work with.
	if ( empty( $args ) ) {
		return '';
	}

	/**
	 * Filters the translated delimiters used by wp_sprintf_l().
	 * Placeholders (%s) are included to assist translators and then
	 * removed before the array of strings reaches the filter.
	 *
	 * Please note: Ampersands and entities should be avoided here.
	 *
	 * @since 2.5.0
	 *
	 * @param array $delimiters An array of translated delimiters.
	 */
	$l = apply_filters(
		'wp_sprintf_l',
		array(
			/* translators: Used to join items in a list with more than 2 items. */
			'between'          => sprintf( __( '%1$s, %2$s' ), '', '' ),
			/* translators: Used to join last two items in a list with more than 2 times. */
			'between_last_two' => sprintf( __( '%1$s, and %2$s' ), '', '' ),
			/* translators: Used to join items in a list with only 2 items. */
			'between_only_two' => sprintf( __( '%1$s and %2$s' ), '', '' ),
		)
	);

	$args   = (array) $args;
	$result = array_shift( $args );
	if ( count( $args ) == 1 ) {
		$result .= $l['between_only_two'] . array_shift( $args );
	}

	// Loop when more than two args.
	$i = count( $args );
	while ( $i ) {
		$arg = array_shift( $args );
		$i--;
		if ( 0 == $i ) {
			$result .= $l['between_last_two'] . $arg;
		} else {
			$result .= $l['between'] . $arg;
		}
	}

	return $result . substr( $pattern, 2 );
}

/**
 * Safely extracts not more than the first $count characters from HTML string.
 *
 * UTF-8, tags and entities safe prefix extraction. Entities inside will *NOT*
 * be counted as one character. For example &amp; will be counted as 4, &lt; as
 * 3, etc.
 *
 * @since 2.5.0
 *
 * @param string $str   String to get the excerpt from.
 * @param int    $count Maximum number of characters to take.
 * @param string $more  Optional. What to append if $str needs to be trimmed. Defaults to empty string.
 * @return string The excerpt.
 */
function wp_html_excerpt( $str, $count, $more = null ) {
	if ( null === $more ) {
		$more = '';
	}

	$str     = wp_strip_all_tags( $str, true );
	$excerpt = mb_substr( $str, 0, $count );

	// Remove part of an entity at the end.
	$excerpt = preg_replace( '/&[^;\s]{0,6}$/', '', $excerpt );
	if ( $str != $excerpt ) {
		$excerpt = trim( $excerpt ) . $more;
	}

	return $excerpt;
}

/**
 * Adds a base URL to relative links in passed content.
 *
 * By default it supports the 'src' and 'href' attributes. However this can be
 * changed via the 3rd param.
 *
 * @since 2.7.0
 *
 * @global string $_links_add_base
 *
 * @param string $content String to search for links in.
 * @param string $base    The base URL to prefix to links.
 * @param array  $attrs   The attributes which should be processed.
 * @return string The processed content.
 */
function links_add_base_url( $content, $base, $attrs = array( 'src', 'href' ) ) {
	global $_links_add_base;
	$_links_add_base = $base;
	$attrs           = implode( '|', (array) $attrs );
	return preg_replace_callback( "!($attrs)=(['\"])(.+?)\\2!i", '_links_add_base', $content );
}

/**
 * Callback to add a base URL to relative links in passed content.
 *
 * @since 2.7.0
 * @access private
 *
 * @global string $_links_add_base
 *
 * @param string $m The matched link.
 * @return string The processed link.
 */
function _links_add_base( $m ) {
	global $_links_add_base;
	// 1 = attribute name  2 = quotation mark  3 = URL.
	return $m[1] . '=' . $m[2] .
		( preg_match( '#^(\w{1,20}):#', $m[3], $protocol ) && in_array( $protocol[1], wp_allowed_protocols(), true ) ?
			$m[3] :
			WP_Http::make_absolute_url( $m[3], $_links_add_base )
		)
		. $m[2];
}

/**
 * Adds a Target attribute to all links in passed content.
 *
 * This function by default only applies to `<a>` tags, however this can be
 * modified by the 3rd param.
 *
 * *NOTE:* Any current target attributed will be stripped and replaced.
 *
 * @since 2.7.0
 *
 * @global string $_links_add_target
 *
 * @param string   $content String to search for links in.
 * @param string   $target  The Target to add to the links.
 * @param string[] $tags    An array of tags to apply to.
 * @return string The processed content.
 */
function links_add_target( $content, $target = '_blank', $tags = array( 'a' ) ) {
	global $_links_add_target;
	$_links_add_target = $target;
	$tags              = implode( '|', (array) $tags );
	return preg_replace_callback( "!<($tags)((\s[^>]*)?)>!i", '_links_add_target', $content );
}

/**
 * Callback to add a target attribute to all links in passed content.
 *
 * @since 2.7.0
 * @access private
 *
 * @global string $_links_add_target
 *
 * @param string $m The matched link.
 * @return string The processed link.
 */
function _links_add_target( $m ) {
	global $_links_add_target;
	$tag  = $m[1];
	$link = preg_replace( '|( target=([\'"])(.*?)\2)|i', '', $m[2] );
	return '<' . $tag . $link . ' target="' . esc_attr( $_links_add_target ) . '">';
}

/**
 * Normalizes EOL characters and strips duplicate whitespace.
 *
 * @since 2.7.0
 *
 * @param string $str The string to normalize.
 * @return string The normalized string.
 */
function normalize_whitespace( $str ) {
	$str = trim( $str );
	$str = str_replace( "\r", "\n", $str );
	$str = preg_replace( array( '/\n+/', '/[ \t]+/' ), array( "\n", ' ' ), $str );
	return $str;
}

/**
 * Properly strips all HTML tags including script and style
 *
 * This differs from strip_tags() because it removes the contents of
 * the `<script>` and `<style>` tags. E.g. `strip_tags( '<script>something</script>' )`
 * will return 'something'. wp_strip_all_tags will return ''
 *
 * @since 2.9.0
 *
 * @param string $text          String containing HTML tags
 * @param bool   $remove_breaks Optional. Whether to remove left over line breaks and white space chars
 * @return string The processed string.
 */
function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
	$text = strip_tags( $text );

	if ( $remove_breaks ) {
		$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
	}

	return trim( $text );
}

/**
 * Sanitizes a string from user input or from the database.
 *
 * - Checks for invalid UTF-8,
 * - Converts single `<` characters to entities
 * - Strips all tags
 * - Removes line breaks, tabs, and extra whitespace
 * - Strips octets
 *
 * @since 2.9.0
 *
 * @see sanitize_textarea_field()
 * @see wp_check_invalid_utf8()
 * @see wp_strip_all_tags()
 *
 * @param string $str String to sanitize.
 * @return string Sanitized string.
 */
function sanitize_text_field( $str ) {
	$filtered = _sanitize_text_fields( $str, false );

	/**
	 * Filters a sanitized text field string.
	 *
	 * @since 2.9.0
	 *
	 * @param string $filtered The sanitized string.
	 * @param string $str      The string prior to being sanitized.
	 */
	return apply_filters( 'sanitize_text_field', $filtered, $str );
}

/**
 * Sanitizes a multiline string from user input or from the database.
 *
 * The function is like sanitize_text_field(), but preserves
 * new lines (\n) and other whitespace, which are legitimate
 * input in textarea elements.
 *
 * @see sanitize_text_field()
 *
 * @since 4.7.0
 *
 * @param string $str String to sanitize.
 * @return string Sanitized string.
 */
function sanitize_textarea_field( $str ) {
	$filtered = _sanitize_text_fields( $str, true );

	/**
	 * Filters a sanitized textarea field string.
	 *
	 * @since 4.7.0
	 *
	 * @param string $filtered The sanitized string.
	 * @param string $str      The string prior to being sanitized.
	 */
	return apply_filters( 'sanitize_textarea_field', $filtered, $str );
}

/**
 * Internal helper function to sanitize a string from user input or from the database.
 *
 * @since 4.7.0
 * @access private
 *
 * @param string $str           String to sanitize.
 * @param bool   $keep_newlines Optional. Whether to keep newlines. Default: false.
 * @return string Sanitized string.
 */
function _sanitize_text_fields( $str, $keep_newlines = false ) {
	if ( is_object( $str ) || is_array( $str ) ) {
		return '';
	}

	$str = (string) $str;

	$filtered = wp_check_invalid_utf8( $str );

	if ( strpos( $filtered, '<' ) !== false ) {
		$filtered = wp_pre_kses_less_than( $filtered );
		// This will strip extra whitespace for us.
		$filtered = wp_strip_all_tags( $filtered, false );

		// Use HTML entities in a special case to make sure no later
		// newline stripping stage could lead to a functional tag.
		$filtered = str_replace( "<\n", "&lt;\n", $filtered );
	}

	if ( ! $keep_newlines ) {
		$filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered );
	}
	$filtered = trim( $filtered );

	$found = false;
	while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
		$filtered = str_replace( $match[0], '', $filtered );
		$found    = true;
	}

	if ( $found ) {
		// Strip out the whitespace that may now exist after removing the octets.
		$filtered = trim( preg_replace( '/ +/', ' ', $filtered ) );
	}

	return $filtered;
}

/**
 * i18n-friendly version of basename().
 *
 * @since 3.1.0
 *
 * @param string $path   A path.
 * @param string $suffix If the filename ends in suffix this will also be cut off.
 * @return string
 */
function wp_basename( $path, $suffix = '' ) {
	return urldecode( basename( str_replace( array( '%2F', '%5C' ), '/', urlencode( $path ) ), $suffix ) );
}

// phpcs:disable WordPress.WP.CapitalPDangit.Misspelled, WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- 8-)
/**
 * Forever eliminate "Wordpress" from the planet (or at least the little bit we can influence).
 *
 * Violating our coding standards for a good function name.
 *
 * @since 3.0.0
 *
 * @param string $text The text to be modified.
 * @return string The modified text.
 */
function capital_P_dangit( $text ) {
	// Simple replacement for titles.
	$current_filter = current_filter();
	if ( 'the_title' === $current_filter || 'wp_title' === $current_filter ) {
		return str_replace( 'Wordpress', 'WordPress', $text );
	}
	// Still here? Use the more judicious replacement.
	static $dblq = false;
	if ( false === $dblq ) {
		$dblq = _x( '&#8220;', 'opening curly double quote' );
	}
	return str_replace(
		array( ' Wordpress', '&#8216;Wordpress', $dblq . 'Wordpress', '>Wordpress', '(Wordpress' ),
		array( ' WordPress', '&#8216;WordPress', $dblq . 'WordPress', '>WordPress', '(WordPress' ),
		$text
	);
}
// phpcs:enable

/**
 * Sanitizes a mime type
 *
 * @since 3.1.3
 *
 * @param string $mime_type Mime type.
 * @return string Sanitized mime type.
 */
function sanitize_mime_type( $mime_type ) {
	$sani_mime_type = preg_replace( '/[^-+*.a-zA-Z0-9\/]/', '', $mime_type );
	/**
	 * Filters a mime type following sanitization.
	 *
	 * @since 3.1.3
	 *
	 * @param string $sani_mime_type The sanitized mime type.
	 * @param string $mime_type      The mime type prior to sanitization.
	 */
	return apply_filters( 'sanitize_mime_type', $sani_mime_type, $mime_type );
}

/**
 * Sanitizes space or carriage return separated URLs that are used to send trackbacks.
 *
 * @since 3.4.0
 *
 * @param string $to_ping Space or carriage return separated URLs
 * @return string URLs starting with the http or https protocol, separated by a carriage return.
 */
function sanitize_trackback_urls( $to_ping ) {
	$urls_to_ping = preg_split( '/[\r\n\t ]/', trim( $to_ping ), -1, PREG_SPLIT_NO_EMPTY );
	foreach ( $urls_to_ping as $k => $url ) {
		if ( ! preg_match( '#^https?://.#i', $url ) ) {
			unset( $urls_to_ping[ $k ] );
		}
	}
	$urls_to_ping = array_map( 'sanitize_url', $urls_to_ping );
	$urls_to_ping = implode( "\n", $urls_to_ping );
	/**
	 * Filters a list of trackback URLs following sanitization.
	 *
	 * The string returned here consists of a space or carriage return-delimited list
	 * of trackback URLs.
	 *
	 * @since 3.4.0
	 *
	 * @param string $urls_to_ping Sanitized space or carriage return separated URLs.
	 * @param string $to_ping      Space or carriage return separated URLs before sanitization.
	 */
	return apply_filters( 'sanitize_trackback_urls', $urls_to_ping, $to_ping );
}

/**
 * Adds slashes to a string or recursively adds slashes to strings within an array.
 *
 * This should be used when preparing data for core API that expects slashed data.
 * This should not be used to escape data going directly into an SQL query.
 *
 * @since 3.6.0
 * @since 5.5.0 Non-string values are left untouched.
 *
 * @param string|array $value String or array of data to slash.
 * @return string|array Slashed `$value`, in the same type as supplied.
 */
function wp_slash( $value ) {
	if ( is_array( $value ) ) {
		$value = array_map( 'wp_slash', $value );
	}

	if ( is_string( $value ) ) {
		return addslashes( $value );
	}

	return $value;
}

/**
 * Removes slashes from a string or recursively removes slashes from strings within an array.
 *
 * This should be used to remove slashes from data passed to core API that
 * expects data to be unslashed.
 *
 * @since 3.6.0
 *
 * @param string|array $value String or array of data to unslash.
 * @return string|array Unslashed `$value`, in the same type as supplied.
 */
function wp_unslash( $value ) {
	return stripslashes_deep( $value );
}

/**
 * Extracts and returns the first URL from passed content.
 *
 * @since 3.6.0
 *
 * @param string $content A string which might contain a URL.
 * @return string|false The found URL.
 */
function get_url_in_content( $content ) {
	if ( empty( $content ) ) {
		return false;
	}

	if ( preg_match( '/<a\s[^>]*?href=([\'"])(.+?)\1/is', $content, $matches ) ) {
		return sanitize_url( $matches[2] );
	}

	return false;
}

/**
 * Returns the regexp for common whitespace characters.
 *
 * By default, spaces include new lines, tabs, nbsp entities, and the UTF-8 nbsp.
 * This is designed to replace the PCRE \s sequence. In ticket #22692, that
 * sequence was found to be unreliable due to random inclusion of the A0 byte.
 *
 * @since 4.0.0
 *
 * @return string The spaces regexp.
 */
function wp_spaces_regexp() {
	static $spaces = '';

	if ( empty( $spaces ) ) {
		/**
		 * Filters the regexp for common whitespace characters.
		 *
		 * This string is substituted for the \s sequence as needed in regular
		 * expressions. For websites not written in English, different characters
		 * may represent whitespace. For websites not encoded in UTF-8, the 0xC2 0xA0
		 * sequence may not be in use.
		 *
		 * @since 4.0.0
		 *
		 * @param string $spaces Regexp pattern for matching common whitespace characters.
		 */
		$spaces = apply_filters( 'wp_spaces_regexp', '[\r\n\t ]|\xC2\xA0|&nbsp;' );
	}

	return $spaces;
}

/**
 * Shortens a URL, to be used as link text.
 *
 * @since 1.2.0
 * @since 4.4.0 Moved to wp-includes/formatting.php from wp-admin/includes/misc.php and added $length param.
 *
 * @param string $url    URL to shorten.
 * @param int    $length Optional. Maximum length of the shortened URL. Default 35 characters.
 * @return string Shortened URL.
 */
function url_shorten( $url, $length = 35 ) {
	$stripped  = str_replace( array( 'https://', 'http://', 'www.' ), '', $url );
	$short_url = untrailingslashit( $stripped );

	if ( strlen( $short_url ) > $length ) {
		$short_url = substr( $short_url, 0, $length - 3 ) . '&hellip;';
	}
	return $short_url;
}

/**
 * Sanitizes a hex color.
 *
 * Returns either '', a 3 or 6 digit hex color (with #), or nothing.
 * For sanitizing values without a #, see sanitize_hex_color_no_hash().
 *
 * @since 3.4.0
 *
 * @param string $color
 * @return string|void
 */
function sanitize_hex_color( $color ) {
	if ( '' === $color ) {
		return '';
	}

	// 3 or 6 hex digits, or the empty string.
	if ( preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', $color ) ) {
		return $color;
	}
}

/**
 * Sanitizes a hex color without a hash. Use sanitize_hex_color() when possible.
 *
 * Saving hex colors without a hash puts the burden of adding the hash on the
 * UI, which makes it difficult to use or upgrade to other color types such as
 * rgba, hsl, rgb, and HTML color names.
 *
 * Returns either '', a 3 or 6 digit hex color (without a #), or null.
 *
 * @since 3.4.0
 *
 * @param string $color
 * @return string|null
 */
function sanitize_hex_color_no_hash( $color ) {
	$color = ltrim( $color, '#' );

	if ( '' === $color ) {
		return '';
	}

	return sanitize_hex_color( '#' . $color ) ? $color : null;
}

/**
 * Ensures that any hex color is properly hashed.
 * Otherwise, returns value untouched.
 *
 * This method should only be necessary if using sanitize_hex_color_no_hash().
 *
 * @since 3.4.0
 *
 * @param string $color
 * @return string
 */
function maybe_hash_hex_color( $color ) {
	$unhashed = sanitize_hex_color_no_hash( $color );
	if ( $unhashed ) {
		return '#' . $unhashed;
	}

	return $color;
}
