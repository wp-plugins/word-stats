<?php
/*
Plugin Name: Word Stats
Plugin URI: http://bestseller.franontanaya.com/?p=101
Description: A suite of word counters, keyword counters and readability analysis displays for your blog.
Author: Fran Ontanaya
Version: 3.1.0
Author URI: http://www.franontanaya.com

Copyright (C) 2010 Fran Ontanaya
contacto@franontanaya.com
http://bestseller.franontanaya.com/?p=101

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.

Thanks to Allan Ellegaard for testing and input.
*/

/* # Activate premium.
-------------------------------------------------------------- */
// No special checks. This is open source, you could hack around it easily (if your time is less valuable than €2).
if ( $_GET[ 'word-stats-action' ] == 'basic' ) { update_option( 'word_stats_premium', 0 ); }
if ( $_GET[ 'word-stats-action' ] == 'alternative') {	update_option( 'word_stats_premium', 1 ); }
if ( $_GET[ 'word-stats-action' ] == 'payment') {	update_option( 'word_stats_premium', 2 ); }
if ( $_GET[ 'word-stats-action' ] == 'donation' ) { update_option( 'word_stats_premium', 3 ); }

/* # Word Counts
-------------------------------------------------------------- */
load_plugin_textdomain( 'word-stats', '/wp-content/plugins/word-stats/languages/', 'word-stats/languages/' );

/* # Basic string tools class
-------------------------------------------------------------- */
require_once( 'basic-string-tools.php' );

/* # Check version. Perform upgrades.
-------------------------------------------------------------- */
// Note: version_compare is the PHP for checking versions.

// pre 3.1.0 versions have no word_stats_version
if ( !get_option( 'word_stats_version' ) ) {

	// fix inconsistent naming for some options
	if ( get_option( 'ws-premium' ) ) {
		update_option( 'word_stats_premium', get_option( 'ws-premium' ) );
		delete_option( 'ws-premium' );
	}
	if ( get_option( 'ws-total-counts-cache' ) ) {
		update_option( 'word_stats_total_counts_cache', get_option( 'ws-total-counts-cache' ) );
		delete_option( 'ws-total-counts-cache' );
	}
	if ( get_option( 'ws-monthly-counts-cache' ) ) {
		update_option( 'word_stats_monthly_counts_cache', get_option( 'ws-monthly-counts-cache' ) );
		delete_option( 'ws-monthly-counts-cache' );
	}

	// convert ignored keywords list to regular expressions
	$keywords_to_upgrade = explode( "\n", str_replace( "\r", '', get_option( 'word_stats_ignore_keywords' ) ) );
	if ( count( $keywords_to_upgrade ) ) {
		for ( $i = 0; $i < count( $keywords_to_upgrade ); $i++ ) {
			$keywords_to_upgrade[ $i ] =  '^' . $keywords_to_upgrade[ $i ] . '$';
		}
		$i = null;
		update_option( 'word_stats_ignore_keywords', implode( "\n", $keywords_to_upgrade ) );
	}
	update_option( 'word_stats_version', '3.1.0' );
}


//  Deprecated option
if ( get_option( 'ws-counts-cache' ) ) { delete_option( 'ws-counts-cache' ); }

/* # Functions to count and cache total words
-------------------------------------------------------------- */
class word_stats_counts {

	// Code to select posts according to the count unpublished option
	public function ws_get_posts( $post_type_name ) {
		global $wpdb;
		if ( get_option( 'word_stats_count_unpublished' ) ) {
			$query = "SELECT * FROM $wpdb->posts WHERE post_type = '" . $post_type_name . "' ORDER BY ID DESC";

			// post_status => any is necessary to count drafts and post pending review
			/*$posts = get_posts( array(
				'numberposts' => -1,
				'post_type' => array( $post_type->name ),
				'post_status' => 'any'
			));*/
		} else {
			$query = "SELECT * FROM $wpdb->posts WHERE post_status = 'publish' AND post_type = '" . $post_type_name . "' ORDER BY ID DESC";
			/*$posts = get_posts( array(
				'numberposts' => -1,
				'post_type' => array( $post_type->name ),
			));*/
		}
		$posts = $wpdb->get_results( $query, OBJECT );
		return $posts;
	}

	// Count words from all post types and cache the output
	public function cache_word_counts() {
		global $wp_post_types;
		$total_count = 0;
		$cache = '';
		$author_count = array();
		$total_num = 0;
		foreach( $wp_post_types as $post_type ) {
			if ( $post_type->name != 'attachment' && $post_type->name != 'nav_menu_item' && $post_type->name != 'revision' ) {
				$total_count = 0;
				$posts = word_stats_counts::ws_get_posts( $post_type->name );
				foreach( $posts as $post ) {
					$word_count = bst_count_words( $post->post_content );
					$total_count += $word_count;
					// Multidimensional array, stores monthly words per author
					$author_count[ $post->post_author ][ $post_type->name ][ substr( $post->post_date, 0, 7 ) ] += $word_count;
				}
				$num = number_format_i18n( $total_count );

				// This adds the word count for each post type to the stats portion of the Right Now box
				$text = __( 'Words', 'word-stats' ) . ' (' . $post_type->name . ')';
				$cache = $cache . "::opentag::{$num}::separator::{$text}::closetag::";
				$total_num += $total_count;
			}
		}

		$text = __( 'Total words', 'word-stats' );
		$total_num =  number_format_i18n( $total_num );
		$cache = $cache . "::totalopentag::{$total_num}::separator::{$text}::closetag::";
		update_option( 'word_stats_total_counts_cache', $cache );
		update_option( 'word_stats_monthly_counts_cache', $author_count );
		return $cache;
	}

	// Output the cached word counts with the proper HTML tags
	public function get_word_counts( $mode ) {
		$cached = get_option( 'word_stats_total_counts_cache' );
		//echo $cached;
		if ( !$cached ) {
			$cached = word_stats_counts::cache_word_counts();
		}
		if ( $mode == 'table' ) {
			$cached = str_replace( '::opentag::', '<tr><td class="first b"><a>', $cached );
			$cached = str_replace( '::totalopentag::', '<tr><td class="first b word-stats-dash-total"><a>', $cached );
			$cached = str_replace( '::separator::', '</a></td><td class="t"><a>', $cached );
			$cached = str_replace( '::closetag::', '</a></td></tr>', $cached );
		} else {
			$cached = str_replace( '::opentag::', '<li class="word-stats-count">', $cached );
			$cached = str_replace( '::totalopentag::', '<li class="word-stats-count word-stats-list-total">', $cached );
			$cached = str_replace( '::separator::', ' ', $cached );
			$cached = str_replace( '::closetag::', '</li>', $cached );
		}
		return $cached;
	}

	public function total_word_counts() {
		echo word_stats_counts::get_word_counts( 'table' );
	}

	// Shortcode to output word counts
	public function word_counts_sc( $atts = null, $content = null ) {
		return '<ul class="word-stats-counts">' . word_stats_counts::get_word_counts( 'list' ) . '</ul>';
	}
}

include ( 'word-counts-widget.php' );

// Hook the functions
if ( get_option( 'word_stats_totals' ) || get_option( 'word_stats_totals' ) == '' ) {
	add_action( 'save_post', array( 'word_stats_counts', 'cache_word_counts' ) );
	add_action( 'right_now_content_table_end', array( 'word_stats_counts', 'total_word_counts' ) );
	add_action( 'widgets_init', create_function( '', 'return register_widget( "widget_ws_word_counts" );' ) );
}
add_shortcode( 'wordcounts', array( 'word_stats_counts', 'word_counts_sc' ) );

/* # Live post stats
-------------------------------------------------------------- */
// Display post legibility
class word_stats_readability {

	public function live_stats() {
		global $post;
		bst_js_string_tools();

		echo '
		<script type="text/javascript">
			function wsRefreshStats() {
				var statusInfo = document.getElementById( "post-status-info" );
				var allText = document.getElementById("content").value;
				allText = bstHtmlStripper( allText );
				var totalCharacters = 0;
				var totalWords = 0;
				var totalSentences = 0;
				var wordArray = new Array();
				var stats = new Array();
				var temp = "";
				if ( allText ) {
					totalCharacters = allText.length;
					stats = bstSplitText( allText );
					allText = stats[ "text" ];
					totalAlphanumeric = stats[ "alphanumeric"].length;
					totalSentences = stats[ "sentences" ].length;
					totalWords = stats[ "words" ].length;
					wordArray = stats[ "words"].slice( 0 ); /* array copy kludge */
					delete stats;
				}
				if ( totalWords > 0 && totalSentences > 0 ) {
					var charsPerWord = ( totalAlphanumeric / totalWords );
					charsPerWord = charsPerWord.toFixed( 0 );
					var charsPerSentence = ( totalAlphanumeric / totalSentences );
					charsPerSentence = charsPerSentence.toFixed( 0 );
					var wordsPerSentence = ( totalWords / totalSentences );
					wordsPerSentence = wordsPerSentence.toFixed( 0 );

					/* Automated Readability Index */
					var ARI = 4.71 * ( totalAlphanumeric / totalWords ) + 0.5 * ( totalWords / totalSentences ) - 21.43;
					var ARItext;
					ARI = ARI.toFixed( 1 );
					if ( ARI < 8 ) { ARItext = \'<span style="color: #0c0;">\' + ARI + "</span>"; }
					if ( ARI > 7.9 && ARI < 12 ) { ARItext = \'<span style="color: #aa0;">\' + ARI + "</span>"; }
					if ( ARI > 11.9 && ARI < 16 ) { ARItext = \'<span style="color: #c60;">\' + ARI + "</span>"; }
					if ( ARI > 15.9 && ARI < 20 ) { ARItext = \'<span style="color: #c00;">\' + ARI + "</span>"; }
					if ( ARI > 19.9 ) { ARItext = \'<span style="color: #a0a;">\' + ARI + "</span>"; }

					/* Coleman-Liau Index */
					var CLI = 5.88 * ( totalAlphanumeric / totalWords ) - 29.6 * ( totalSentences / totalWords ) - 15.8;
					var CLItext;
					CLI = CLI.toFixed( 1 );
					if ( CLI < 8 ) { CLItext = \'<span style="color: #0c0;">\' + CLI + "</span>"; }
					if ( CLI > 7.9 && CLI < 12 ) { CLItext = \'<span style="color: #aa0;">\' + CLI + "</span>"; }
					if ( CLI > 11.9 && CLI < 16 ) { CLItext = \'<span style="color: #c60;">\' + CLI + "</span>"; }
					if ( CLI > 15.9 && CLI < 20 ) { CLItext = \'<span style="color: #c00;">\' + CLI + "</span>"; }
					if ( CLI > 19.9 ) { CLItext = \'<span style="color: #a0a;">\' + CLI + "</span>"; }

					/* LIX */
					var LIXlongwords = 0;
					for (var i = 0; i < wordArray.length; i=i+1 ) {
						if ( wordArray[ i ].length > 6 ) { LIXlongwords = LIXlongwords + 1; }
					}
					temp = allText.split( /[,;\.\(\:]/ );
					var LIX = totalWords / temp.length + ( LIXlongwords * 100 ) / totalWords;
					var LIXtext;
					LIX = LIX.toFixed( 1 );
					if ( LIX < 30 ) { LIXtext = \'<span style="color: #0c0;">\' + LIX + "</span>"; }
					if ( LIX > 29.9 && LIX < 40 ) { LIXtext = \'<span style="color: #aa0;">\' + LIX + "</span>"; }
					if ( LIX > 39.9 && LIX < 50 ) { LIXtext = \'<span style="color: #c60;">\' + LIX + "</span>"; }
					if ( LIX > 49.9 && LIX < 60 ) { LIXtext = \'<span style="color: #c00;">\' + LIX + "</span>"; }
					if ( LIX > 59.9 ) { LIXtext = \'<span style="color: #a0a;">\' + LIX + "</span>"; }

					temp = "";';
					if ( get_option( 'word_stats_show_keywords' ) || get_option( 'word_stats_show_keywords' ) == '' ) {

						echo '
						/* Find keywords */
						var wordHash = new Array;
						var topCount = 0;
						 var ignKeywords = "' , strtolower( str_replace( "\r", '', str_replace( "\n", '::', get_option( 'word_stats_ignore_keywords' ) ) ) ), '";
						ignKeywords = ignKeywords.split( "::" );
						for (var i = 0; i < wordArray.length; i = i + 1) {
							wordArray[i] = wordArray[i].toLowerCase();

							/* if ( ignKeywords.indexOf( wordArray[i] ) == "-1" ) { */

							if ( !bstMatchRegArray( ignKeywords, wordArray[i] ) ) {
								if ( wordArray[i].length > 3 ) {
									if ( !wordHash[ wordArray[i] ] ) { wordHash[ wordArray[i] ] = 0; }
									wordHash[ wordArray[i] ] = wordHash[ wordArray[i] ] + 1;
									if ( wordHash[ wordArray[i] ] > topCount ) { topCount = wordHash[ wordArray[i] ]; }
								}
							}
						}';

						// Add tags. Note $post has been declared global above.
						if ( get_option( 'word_stats_add_tags' ) && get_the_tags( $post->ID ) ) {
							echo '/* Add last saved tags */', "\n";
							foreach ( get_the_tags( $post->ID ) as $tag ) {
								$tag->name = strtolower( esc_attr( $tag->name ) );
								if ( strlen( $tag->name ) > 3 ) {
									echo '
											if ( !wordHash[ "', $tag->name, '" ] ) { wordHash[ "', $tag->name, '" ] = 0; }
											wordHash[ "', $tag->name, '" ] = wordHash[ "', $tag->name, '" ] + 1;
											if ( wordHash[ "', $tag->name, '" ] > topCount ) { topCount = wordHash[ "', $tag->name, '" ]; }
									';
								}
							}
						}

						echo '
						/* Relevant keywords must have at least three appareances and half the appareances of the top keyword */
						for ( var j in wordHash ) {
							if ( wordHash[j] >= topCount/5 && wordHash[j] > 2 ) {
								if ( wordHash[j] == topCount ) {
									temp = temp + \'<span style="font-weight:bold; color:#0c0;">\' + j + " (" + wordHash[j] + ")</span> ";
								} else if ( wordHash[j] > topCount / 1.5 ) {
									temp = temp + \'<span style="color:#3c0;">\' + j + " (" + wordHash[j] + ")</span> ";
								} else {
									temp = temp + j + " (" + wordHash[j] + ") ";
								}
							}
						}
						if ( temp == "" ) {
							temp = "<br><strong>', esc_attr( __( 'Keywords:', 'word-stats' ) ), '</strong><br>', esc_attr( __( 'No relevant keywords.', 'word-stats' ) ), '";
						} else {
							temp = "<br><strong>', esc_attr( __( 'Keywords:', 'word-stats' ) ), '</strong><br>" + temp;
						}';
					}
					echo 'if ( statusInfo.innerHTML.indexOf( "edit-word-stats" ) < 1 ) {
							statusInfo.innerHTML = statusInfo.innerHTML + "<tbody><tr><td id=\'edit-word-stats\' style=\'padding-left:7px; padding-bottom:4px;\' colspan=\'2\'><strong>', esc_attr( __( 'Readability:', 'word-stats' ) ), '</strong><br><a title=\'Automated Readability Index\'>ARI<a>: " + ARItext + "&nbsp; <a title=\'Coleman-Liau Index\'>CLI</a>: " + CLItext + "&nbsp; <a title=\'Läsbarhetsindex\'>LIX</a>: " + LIXtext ';
					if ( get_option( 'word_stats_averages' ) || get_option( 'word_stats_averages' ) == null ) {
						echo '+ "<br>" + totalCharacters + " ', esc_attr( __( 'characters', 'word-stats' ) ),
							'; " + totalAlphanumeric + " ', esc_attr( __( 'alphanumeric characters', 'word-stats' ) ),
							'; " + totalWords + " ', esc_attr( __( 'words', 'word-stats' ) ),
							'; " + totalSentences + " ', esc_attr( __( 'sentences', 'word-stats' ) ),
							'.<br>" + charsPerWord + " ', esc_attr( __( 'characters per word', 'word-stats' ) ),
							'; " + charsPerSentence + " ', esc_attr( __( 'characters per sentence', 'word-stats' ) ),
							'; " + wordsPerSentence + " ', esc_attr( __( 'words per sentence', 'word-stats' ) ), '."';
					}
					echo ' + temp + "</td></tr></tbody>";
						} else {
						 	document.getElementById( "edit-word-stats").innerHTML = "<strong>', esc_attr( __( 'Readability:', 'word-stats' ) ), '</strong><br><a title=\'Automated Readability Index\'>ARI<a>: " + ARItext + "&nbsp; <a title=\'Coleman-Liau Index\'>CLI</a>: " + CLItext + "&nbsp; <a title=\'Läsbarhetsindex\'>LIX</a>: " + LIXtext ';
					if ( get_option( 'word_stats_averages' ) || get_option( 'word_stats_averages' ) == null ) {
						echo '+ "<br>" + totalCharacters + " ', esc_attr( __( 'characters', 'word-stats' ) ),
							'; " + totalAlphanumeric + " ', esc_attr( __( 'alphanumeric characters', 'word-stats' ) ),
							'; " + totalWords + " ', esc_attr( __( 'words', 'word-stats' ) ),
							'; " + totalSentences + " ', esc_attr( __( 'sentences', 'word-stats' ) ),
							'.<br>" + charsPerWord + " ', esc_attr( __( 'characters per word', 'word-stats' ) ),
							'; " + charsPerSentence + " ', esc_attr( __( 'characters per sentence', 'word-stats' ) ),
							'; " + wordsPerSentence + " ', esc_attr( __( 'words per sentence', 'word-stats' ) ), '."';
					}
					echo ' + temp;
						} ';

					// Replace WordPress' word count
					if ( true || get_option( 'word_stats_replace_word_count' ) || get_option( 'word_stats_replace_word_count' ) == null ) {
						echo '
						if ( document.getElementById( "wp-word-count") != null ) { /* WP 3.2 */
							document.getElementById( "wp-word-count").innerHTML = "' . __( 'Word count:' ) . ' " + totalWords + " <small>' . __( '(Word Stats plugin)', 'word-stats' ) . '</small>";
						}
						if ( document.getElementById( "word-count") != null ) { /* WP 3.0 */
							document.getElementById( "word-count").innerHTML = totalWords;
						}';
					}
				echo '}
			}

			var statsTime = setInterval( "wsRefreshStats()", 10000 );
			wsRefreshStats();

		</script>';
	}

	/* # Static post stats
	-------------------------------------------------------------- */
	// Calculate stats the PHP way and store them.
	public function cache_stats( $id = null ) {
		if ( !$id ) {
			global $post;
			if ( !$post->ID ) {
				return null;
			}
			$id = $post->ID;
		}
		$thepost = get_post( $id );

		// Strip tags
		$allText = bst_html_stripper( $thepost->post_content );

		// Count
		if ( $allText ) {
			$stats = bst_split_text( $allText );
			$totalAlphanumeric = mb_strlen( $stats[ 'alphanumeric' ] );
			$totalSentences = count( $stats[ 'sentences' ] );
			$totalWords = count( $stats[ 'words' ] );
			$wordArray = $stats[ 'words' ];
			$allText = $stats[ 'text' ];
			// Do the calcs if we aren't going to divide by zero
			if ( $totalWords > 0 && $totalSentences > 0 ) {
				$charsPerWord = intval( $totalAlphanumeric / $totalWords );
				$charsPerSentence = intval( $totalAlphanumeric / $totalSentences );
				$wordsPerSentence = intval( $totalWords / $totalSentences );
				// Automated Readability Index
				$ARI = round( 4.71 * ( $totalAlphanumeric / $totalWords ) + 0.5 * ( $totalWords / $totalSentences ) - 21.43, 1);

				// Coleman-Liau Index
				$CLI = round( 5.88 * ( $totalAlphanumeric / $totalWords ) - 29.6 * ( $totalSentences / $totalWords ) - 15.8, 1);

				// LIX
				$LIXlongwords = 0;
				for ($i = 0; $i < count( $wordArray ); $i = $i + 1 ) {
					if ( mb_strlen( $wordArray[ $i ] ) > 6 ) { $LIXlongwords++; }
				}
				$temp = preg_split( '/[,;\.\(\:]/', $allText );
				$LIX = round( $totalWords / count( $temp ) + ( $LIXlongwords * 100 ) / $totalWords, 1) ;
			} else {
				$ARI = '0';
				$CLI = '0';
				$LIX = '0';
			}
		} else {
			$ARI = '0';
			$CLI = '0';
			$LIX = '0';
		}

		// Create/update the post meta fields for readability
		update_post_meta( $id, 'readability_ARI', $ARI );
		update_post_meta( $id, 'readability_CLI', $CLI );
		update_post_meta( $id, 'readability_LIX', $LIX );
	}

	/* # Add a column to the post management list
	-------------------------------------------------------------- */
	public function add_posts_list_column( $defaults ) {
		 $defaults[ 'readability' ] = __( 'R.I.', 'word-stats' );
		 return $defaults;
	}

	public function calc_ws_index( $ARI, $CLI, $LIX ) {
		// Translate as Basic / Intermediate / Advanced
		return ( floatval( $ARI ) + floatval( $CLI ) + ( ( floatval( $LIX ) - 10 ) / 2 ) ) / 3;
	}

	public function create_posts_list_column( $name ) {
		global $post;
		if ( $name == 'readability' ) {
			$ARI = get_post_meta( $post->ID, 'readability_ARI', true );
			$CLI = get_post_meta( $post->ID, 'readability_CLI', true );
			$LIX = get_post_meta( $post->ID, 'readability_LIX', true );

			if ( !$ARI ) {
				// If there is no data or the post is blank
				echo '<span style="color:#999;">--</span>';
			} else {
				// Trying to aggregate the indexes in a meaningful way.
				$r_avg = word_stats_readability::calc_ws_index( $ARI, $CLI, $LIX );
				if ( $r_avg < 8 ) { echo '<span style="color: #0c0;">', round( $r_avg, 1 ), '</span>'; }
				if ( $r_avg >= 8 && $r_avg < 12 ) { echo '<span style="color: #aa0;">', round( $r_avg, 1 ), '</span>'; }
				if ( $r_avg >= 12 && $r_avg < 16 ) { echo '<span style="color: #c60;">', round( $r_avg, 1 ), '</span>'; }
				if ( $r_avg >= 16 && $r_avg < 20 ) { echo '<span style="color: #c00;">', round( $r_avg, 1 ), '</span>'; }
				if ( $r_avg >= 20 ) { echo '<span style="color: #a0a;">', round( $r_avg, 1 ), '</span>'; }
			}
		}
	}

	// Load style for the column
	public function style_column() {
		wp_register_style( 'word-stats-css', plugins_url() . '/word-stats/css/word-stats.css' );
		wp_enqueue_style( 'word-stats-css' );
	}
} // end class word_stats_readability

// Hook live stats. Load only when editing a post
if ( $_GET[ 'action' ] == 'edit' || !strpos( $_SERVER[ 'SCRIPT_FILENAME' ], 'post-new.php' ) === false ) {
	add_action( 'admin_footer', array( 'word_stats_readability' , 'live_stats' ) );
}

// Hook cached stats
add_action( 'save_post', array( 'word_stats_readability', 'cache_stats' ) );

// Hook custom column
if ( get_option( 'word_stats_RI_Column' ) || get_option( 'word_stats_RI_Column' ) == '' ) {
	add_filter( 'manage_posts_columns', array( 'word_stats_readability', 'add_posts_list_column' ) );
	add_action( 'manage_posts_custom_column', array( 'word_stats_readability', 'create_posts_list_column' ) );
	add_action( 'admin_init', array( 'word_stats_readability', 'style_column' ) );
}

/* § Construct the options page
-------------------------------------------------------------- */
// create custom plugin settings menu
class word_stats_admin {
	function register_settings() {
		//register our settings
		register_setting( 'word-stats-settings-group', 'word_stats_RI_column' );
		register_setting( 'word-stats-settings-group', 'word_stats_totals' );
		register_setting( 'word-stats-settings-group', 'word_stats_replace_word_count' );
		register_setting( 'word-stats-settings-group', 'word_stats_averages' );
		register_setting( 'word-stats-settings-group', 'word_stats_show_keywords' );
		register_setting( 'word-stats-settings-group', 'word_stats_ignore_keywords' );
		register_setting( 'word-stats-settings-group', 'word_stats_add_tags' );
		register_setting( 'word-stats-settings-group', 'word_stats_count_unpublished' );
	}

	function settings_page() {
		// Default values
		$opt_RI_column = ( get_option( 'word_stats_RI_column' ) === null ) ? 1 : get_option( 'word_stats_RI_column' );
		$opt_totals = ( get_option( 'word_stats_totals' )  === null ) ?  1 : get_option( 'word_stats_totals' );
		$opt_replace_wc = ( get_option( 'word_stats_replace_word_count' ) === null ) ? 1 : get_option( 'word_stats_replace_word_count') ;
		$opt_averages = ( get_option( 'word_stats_averages' )  === null ) ? 1 : get_option( 'word_stats_averages' );
		$opt_show_keywords = ( get_option( 'word_stats_show_keywords' )  === null ) ? 1 : get_option( 'word_stats_show_keywords' );
		$opt_add_tags = ( get_option( 'word_stats_add_tags' )  === null ) ? 1 : get_option( 'word_stats_add_tags' );
		$opt_count_unpublished = ( get_option( 'word_stats_count_unpublished' )  === null ) ? 1 : get_option( 'word_stats_count_unpublished' );

		$opt_ignore_keywords = get_option( 'word_stats_ignore_keywords' );

		echo '
	<div class="wrap">
		<h2>' , __( 'Word Stats Options', 'word-stats' ), '</h2>
		<form method="post" action="options.php">
			 ', settings_fields( 'word-stats-settings-group' ), '
 			<h3>', __( 'Readabilty', 'word-stats' ), '</h3>
			<p>
				<input type="hidden" name="word_stats_RI_column" value="0" />
				<input type="checkbox" name="word_stats_RI_column" value="1" '; if ( $opt_RI_column ) { echo 'checked="checked"'; } echo ' /> ',
				__( 'Display aggregate readability column in the manage posts list.', 'word-stats' ), '
			</p>
			<h3>', __( 'Total word counts', 'word-stats' ), '</h3>
			<p>
				<input type="hidden" name="word_stats_totals" value="0" />
				<input type="checkbox" name="word_stats_totals" value="1" ';  if ( $opt_totals ) { echo 'checked="checked"'; } echo ' /> ',
				__( 'Enable total word counts (dashboard, widget and shortcode).', 'word-stats' ), ' ',
				__( 'This may slow down post saving in large blogs.', 'word-stats' ), '
			</p>
			<p>
				<input type="hidden" name="word_stats_count_unpublished" value="0" />
				<input type="checkbox" name="word_stats_count_unpublished" value="1" ';  if ( $opt_count_unpublished ) { echo 'checked="checked"'; } echo ' /> ',
				__( 'Count words from drafts and posts pending review.', 'word-stats' ),  '
			</p>
			<h3>', __( 'Live stats', 'word-stats' ), '</h3>
			<p>
				<input type="hidden" name="word_stats_replace_word_count" value="0" />
				<input type="checkbox" name="word_stats_replace_word_count" value="1" '; if ( $opt_replace_wc ) { echo 'checked="checked"';  } echo ' /> ',
				__( 'Replace WordPress live word count with Word Stats word count.', 'word-stats' ), '
			</p>
			<p>
				<input type="hidden" name="word_stats_averages" value="0" />
				<input type="checkbox" name="word_stats_averages" value="1" '; if ( $opt_averages ) { echo 'checked="checked"'; } echo ' /> ',
				__( 'Display live character/word/sentence averages.', 'word-stats' ), '
			</p>
			<p>
				<input type="hidden" name="word_stats_show_keywords" value="0" />
				<input type="checkbox" name="word_stats_show_keywords" value="1" '; if ( $opt_show_keywords ) { echo 'checked="checked"'; } echo ' /> ',
				__( 'Display live keyword count.', 'word-stats' ), '
			</p>
			<h3>', __( 'Keywords', 'word-stats' ), '</h3>
			<p>
				<input type="hidden" name="word_stats_add_tags" value="0" />
				<input type="checkbox" name="word_stats_add_tags" value="1" '; if ( $opt_add_tags ) { echo 'checked="checked"'; } echo ' /> ',
				__( 'Add the last saved tags to the keyword count.', 'word-stats' ), '
			</p>

			<h4 style="padding: 0;margin: 0;">Ignore these keywords:</h4>
			<p>
				', sprintf( __( 'One %1$sregular expression%2$s per line, without slashes.', 'word-stats' ), '<a href="https://developer.mozilla.org/en/JavaScript/Guide/Regular_Expressions">', '</a>' ),  '<br />
				 <small><em>', __( 'Example: ^apples?$ = good, /^apples?$/ = bad.', 'word-stats' ), '</em></small><br />


<div style="float: left; margin-right: 20px;">
				<textarea name="word_stats_ignore_keywords" cols="40" rows="25">', esc_attr( strip_tags( $opt_ignore_keywords ) ) ,'</textarea></div>

				<div style="float: left;">

				<strong>', __( 'Writing basic regular expressions:', 'word-stats' ), '</strong><br /><br /><ul><li>',
				__( '^ matches the beggining of the word.', 'word-stats' ), ' <br /><small><em>', __( 'Example: "^where" matches "wherever" but not "anywhere".', 'word-stats' ), '</em></small></li><li>',
				__( '$ matches the end of the word.', 'word-stats' ), ' <br /><small><em>', __( 'Example: "where$" matches "anywhere" but not "wherever".', 'word-stats' ), '</em></small></li><li>',
				__( '^keyword$ matches only the whole keyword.', 'word-stats' ), ' <br /><small><em>', __( 'Example: "^where$" matches "where" but not "anywhere" or "wherever".', 'word-stats' ), '</em></small></li><li>',
				__( '? matches the previous character zero or one time.', 'word-stats' ), ' <br /><small><em>', __( 'Example: "^apples?$" matches "apple" and "apples".', 'word-stats' ), '</em></small></li><li>',
				__( '* matches the previous character zero or more times.', 'word-stats' ), ' <br /><small><em>', __( 'Example: "^10*$" matches "1", "10", "1000000", etc.', 'word-stats' ), '</em></small></li><li>',
				__( '+ matches the previous character one or more times.', 'word-stats' ), ' <br /><small><em>', __( 'Example: "^10+$" matches "10", "1000000", etc., but not "1".', 'word-stats' ), '</em></small></li><li>',
				__( '[] matches any of the characters between the brackets.', 'word-stats' ), ' <br /><small><em>', __( 'Example: "^take[ns]?$" matches "take", "taken" and "takes".', 'word-stats' ), '</em></small></li></ul>',

				'</div><br style="clear:both;">',

			'</p>
			<p class="submit">
				<input type="submit" class="button-primary" value="' ,__( 'Save Changes' ), '" />
			</p>
		</form>
	</div>';
	}

	// Analyze the posts database and output the data set for the stats page
	public function load_report_stats( $author_graph, $period_start, $period_end ) {
		global $user_ID, $current_user, $wp_post_types, $wpdb;

		// $report contains all the data needed to render the stats page
		$report[ 'total_keywords' ] = $report[ 'recent_posts_rows' ] = array();
		$report[ 'totals_readability' ][ 0 ] = $report[ 'totals_readability' ][ 1 ] = $report[ 'totals_readability' ][ 2 ] = 0;
		$cur_author = get_userdata( $author_graph );

		// Validate dates
		$period_start = date( 'Y-m-d', strtotime( $period_start ) );
		$period_end = date( 'Y-m-d', strtotime( $period_end ) );
		/* For next version
		$can_write_csv = is_writable( plugins_url( 'word-stats/csv' ) );
		if ( $can_write_csv ) {
			// CSV file to export the data
			$csv_file = 'word-stats/csv/' . $period_start . '_' . $period_end;
			if ( get_option( 'word_stats_count_unpublished' ) ) { $csv_file .= '_all'; } else { $csv_file .= '_published'; }
			$csv_file .= '.csv';
			$csv_handle = fopen( plugins_url( $csv_file ), 'w');
		}
		*/

		// Loop requested posts by type
		$ignore = explode( "\n", preg_replace('/\r\n|\r/', "\n", get_option( 'word_stats_ignore_keywords' ) ) );

		$report[ 'type_count' ][ 'custom' ] = 0;
		foreach( $wp_post_types as $post_type ) {
			$report[ 'type_count' ][ $post_type->name ] = 0;

			// Load only content and custom post types
			if ( $post_type->name != 'attachment' && $post_type->name != 'nav_menu_item' && $post_type->name != 'revision' ) {
				$rp_table_row = 0;

				// Load the posts
				$query = "SELECT * FROM $wpdb->posts WHERE ";
				if ( !get_option( 'word_stats_count_unpublished' ) ) {
					$query .= "post_status = 'publish' AND ";
				}
				$query .= " post_type = '" . $post_type->name . "' AND post_date BETWEEN '" . $period_start . "' AND '" . $period_end . "' ORDER BY post_date DESC";
				$posts = $wpdb->get_results( $query, OBJECT );

				foreach( $posts as $post ) {

					// Fetch keywords
					$keywords = bst_keywords( $post->post_content, $ignore, 999, 0 );

					// Aggregate the keywords
					if ( $keywords && is_array( $keywords ) ) {
						foreach( $keywords as $key=>$value ) {
							if( $report[ 'total_keywords' ][ $key ] === null ) { $report[ 'total_keywords' ][ $key ] = 0; }
							$report[ 'total_keywords' ][ $key ] += $value;
						}
					}

					if ( $post->post_author == $author_graph ) {

						// Counts per type. Custom post types are aggregated.
						if ( $post_type->name != 'post' && $post_type->name != 'page' ) {
							$report[ 'type_count' ][ 'custom' ]++;
						} else {
							$report[ 'type_count' ][ $post_type->name ]++;
						}

						// Get the readability index
						$ARI = get_post_meta( $post->ID, 'readability_ARI', true ); $CLI = get_post_meta( $post->ID, 'readability_CLI', true ); $LIX = get_post_meta( $post->ID, 'readability_LIX', true );

						// Calculate stats if they aren't cached
						if ( !$ARI  || !$CLI || !$LIX ) {
							word_stats_readability::cache_stats( $post->ID );
							$ARI = get_post_meta( $post->ID, 'readability_ARI', true ); $CLI = get_post_meta( $post->ID, 'readability_CLI', true ); $LIX = get_post_meta( $post->ID, 'readability_LIX', true );
						}

						if ( $ARI  && $CLI && $LIX ) {
							$ws_index_n = word_stats_readability::calc_ws_index( $ARI, $CLI, $LIX );
							// Aggregate levels in 3 tiers like Google does (Basic, Intermediate, Advanced)
							if ( $ws_index_n < 9) { $ws_index = 0; } elseif ( $ws_index_n < 16 ) { $ws_index = 1; } else { $ws_index = 2; }
							$report[ 'totals_readability' ][ $ws_index ]++;
						}
					}

					// Count post words
					$post_word_count = bst_count_words( $post->post_content );

					// Count words per author. Group per month.
					$post_month = mysql2date( 'Y-m', $post->post_date );
					//if ( !$report[ 'author_count' ][ $post->post_author ][ $post_type->name ][ $post_month ] ) { $report[ 'author_count' ][ $post->post_author ][ $post_type->name ][ $post_month ] = 0; }
					$report[ 'author_count' ][ $post->post_author ][ $post_type->name ][ $post_month ] += $post_word_count;
					$report[ 'author_count_total' ][ $post->post_author ] += $post_word_count;
					$report[ 'all_total_words' ] += $post_word_count;

					// Store rows for the recent posts table
					if ( $post->post_author == $author_graph ) {
						$post_link = ( current_user_can( 'edit_post', $post->ID ) ) ? '<a href=\'' . get_edit_post_link( $post->ID ) . '\'>' . htmlentities( $post->post_title, null, 'utf-8' ) . '</a>' : htmlentities( $post->post_title, null, 'utf-8' );
						$row = '<td class=\'ws-table-title\'> ' . $post_link . '</td><td class=\'ws-table-date\'>' . mysql2date('Y-m-d', $post->post_date ) . '</td><td class=\'ws-table-count\'>' . number_format( $post_word_count ) . '</td><td class=\'ws-table-index\'>' . round( $ws_index_n ) . '</td>';
						$report[ 'recent_posts_rows' ][ $post_type->name ][ $rp_table_row ] = $row;
						$rp_table_row++;
					}
					/* For next version
					if ( $can_write_csv ) {
						// Store CSV data
						$csv = array( $post->ID, $post->title, $post->post_date, $post_word_count, $ws_index_n, implode( ' ', array_keys( $keywords ) ) );
						fputcsv( $csv_handle, $csv );
					}
					*/
				}
			}
		}

		/* if ( $can_write_csv ) { fclose( $csv_handle ); } */

		// Sort keywords by frequency, descending
		asort( $report[ 'total_keywords' ] );
		$report[ 'total_keywords' ] = array_reverse( $report[ 'total_keywords' ], true );

		// Sort timeline
		if ( count( $report[ 'author_count' ][ $author_graph ][ 'post' ] ) ) { ksort( $report[ 'author_count' ][ $author_graph ][ 'post' ] ); }
		if ( count( $report[ 'author_count' ][ $author_graph ][ 'page' ] ) ) { ksort( $report[ 'author_count' ][ $author_graph ][ 'page' ] ); }
		if ( count( $report[ 'author_count' ][ $author_graph ][ 'custom' ] ) ) { ksort( $report[ 'author_count' ][ $author_graph ][ 'custom' ] ); }

		return $report;
	}

	public function ws_reports_page() {
		global $user_ID;
		global $current_user;
		global $wp_post_types;
		global $wpdb;

		if( $_GET[ 'view-all' ] ) { $period_start = '1900-01-01'; } else { $period_start = $_GET[ 'period-start' ] ? $_GET[ 'period-start' ] : date( 'Y-m-d', time() - 15552000 ); }
		if( $_GET[ 'view-all' ] ) { $period_end = date( 'Y-m-d' ); } else { $period_end = $_GET[ 'period-end' ] ? $_GET[ 'period-end' ] : date( 'Y-m-d' ); }



		if ( $_GET[ 'author-tab' ] ) {
			$author_graph = intval( $_GET[ 'author-tab' ] );
		} else {
			$author_graph = $user_ID;
		}
		$report = word_stats_admin::load_report_stats( $author_graph, $period_start, $period_end );

		if ( $report ) {

			// Get oldest date
			if( $_GET[ 'view-all' ] ) {
				$period_start = date( 'Y-m-d', min( bst_Ym_to_unix( bst_array_first( $report[ 'author_count' ][ $author_graph ][ 'post' ] ) ), bst_Ym_to_unix( bst_array_first( $report[ 'author_count' ][ $author_graph ][ 'page' ] ) ), bst_Ym_to_unix( bst_array_first( $report[ 'author_count' ][ $author_graph ][ 'custom' ] ) ) ) );
			}

			// Using WordPress built in jQuery, jQuery UI. Using jQuery UI datepicker 1.7.3 for WP < 3.1 compatibility
			// Load jQuery Flot scripts to draw the graphs
			echo '<!--[if lte IE 8]>';
			$src = plugins_url( 'word-stats/js/excanvas.min.js' );
			echo '<script type="text/javascript" src="' , $src, '"></script>';
			echo '<![endif]-->';
			$scripts = array(
				plugins_url( 'word-stats/js/ui/ui.datepicker.min.js' ),
				plugins_url( 'word-stats/js/flot/jquery.flot.js' ),
				plugins_url( 'word-stats/js/flot/jquery.flot.resize.js' ),
				plugins_url( 'word-stats/js/flot/jquery.flot.pie.js' )
			);
			foreach ( $scripts as $script ) {
				echo '<script type="text/javascript" src="' , $script, '"></script>', "\n";
			}

			include( 'graph_options.php' );

			echo '<div class="wrap ws-wrap">';

			if( $_GET[ 'word-stats-action' ] == 'payment' ) {
				$ws_message = __( 'Thanks for your contribution!' , 'word-stats' ) . ' ' . __( 'Word Stats Plugin is now upgraded to Premium!', 'word-stats' );
			}
			if( $_GET[ 'word-stats-action' ] == 'alternative' ) {
				$ws_message = __( 'Word Stats Plugin is now upgraded to Premium!', 'word-stats' ) . ' ' . __( 'Please, use the donation button to express your support.' , 'word-stats' );
			}
			if( $_GET[ 'word-stats-action' ] == 'donation' ) {
				$ws_message = __( 'Thanks for your contribution!' , 'word-stats' ) . ' ' . __( 'With your support we can bring you even more premium features!', 'word-stats' );
			}

			if( $ws_message ) {
				echo '<div id="ws-message">', $ws_message, '</div>';
			}

			if( !get_option( 'word_stats_premium' ) ) {
				include ( 'premium.php' );
			}

			echo '<br />';

			$i = 0;
			if ( $_GET[ 'author-tab' ] ) {
				$author_graph = intval( $_GET[ 'author-tab' ] );
			} else {
				$author_graph = $user_ID;
			}
			// Links to author tabs and collect stats from all authors
			echo '<div id="ws-forms-wrapper">
			<form id="authors-form" name="select-author" action="index.php" method="get">
				<input type="hidden" name="page" value="word-stats-graphs" />',
				__( 'View author:', 'word-stats' ), ' <select name="author-tab" id="authors-list">';

			foreach ( $report[ 'author_count' ] as $id=>$post_type ) {
				// Admin and Editor can view all stats, Author and Contributor can view only their stats.
				$this_author = get_userdata( $id );
			 	echo '<option class="author-graph-option" value="', $id, '"', ( $author_graph == $id ) ? ' selected="selected" ' : '', '>', $this_author->nickname, '</option>';
			}
			echo '</select>

			', __( 'Period:', 'word-stats' ), ' <input type="text" name="period-start" id="period-start" value="', $period_start, '" /> - <input type="text" name="period-end" id="period-end" value="', $period_end, '" />
<input type="checkbox" id="view-all" name="view-all"', ( $_GET[ 'view-all' ] ) ? ' checked="checked" ' : '', ' /> ', __( 'all time', 'word-stats' ), '

<input id="ws-period-submit" type="submit" name="ws-submit" value="', __( 'View', 'word-stats' ), '" />
		</form>
			<script type="text/javascript">
				jQuery("#authors-list").change( function() { jQuery( "#authors-form").submit(); } );
				jQuery("#period-start").datepicker( { dateFormat: "yy-mm-dd" } );
				jQuery("#period-end").datepicker( { dateFormat: "yy-mm-dd" } );
			</script>';

			include( 'donate.php' );
			echo '</div>';

			// Load stats for the currently selected author
			$cur_author = get_userdata( $author_graph );
			echo '<br style="clear:both" />';
			echo
			'<div id="ws-graph-wrapper">
				<div id="ws-headlines">
					<div style="float:left;">
						<h2 id="ws-total">', number_format( $report[ 'author_count_total' ][ $author_graph ] ), '</h2>
						<p id="ws-total-period">',
						 sprintf( __( '%1$swords%2$s between %3$s and %4$s', 'word-stats' ), '<strong>', '</strong> ', $period_start, $period_end ), '</p>
					</div>
					<div id="ws-meters">
						<table>
							<tr><td  class="pt-meter pt-meter-label">', __( 'Posts', 'word-stats' ), '</td><td class="pt-meter" id="pt-meter-post"></td></tr>
							<tr><td class="pt-meter pt-meter-label">', __( 'Pages', 'word-stats' ), '</td><td class="pt-meter" id="pt-meter-page"></td></tr>
							<tr><td class="pt-meter pt-meter-label">', __( 'Custom', 'word-stats' ), '</td><td class="pt-meter" id="pt-meter-custom"></td></tr>
							<tr><td class="pt-meter pt-meter-label">', __( 'All', 'word-stats' ), ' </td><td class="pt-meter" id="pt-meter-all"></td></tr>
						</table>
					</div>
					<br style="clear:both" />

					<div id="ws-graph-index-wrap">
						<div id="ws-graph-index-pc" title="', __( 'Readability level', 'word-stats' ), '"></div>
					</div>
					<div id="ws-graph-total-wrap">
						<div id="ws-graph-total-pc"  title="', __( 'Share of total words', 'word-stats' ), '"></div>
					</div>

				</div>

				<div id="ws-graph-keywords-wrap">
					<h3 class="ws-header ws-header-keywords">', __( 'Keywords', 'word-stats') . '</h3>
					<div id="ws-graph-keywords"></div>
				</div>

				<br style="clear:both" />
				<div id="ws-graph-timeline" width="258" height="390"></div>

				<br style="clear:both;" />';

			// Timeline tooltip
			echo '<script type="text/javascript" src="', plugins_url( 'word-stats/js/timeline-tooltip.js' ), '"></script>', "\n";

			echo '<script type="text/javascript">', "\n";

			// Words per Month
			$series = '[ ';
			$z = 0;
			if ( count( $report[ 'author_count' ][ $author_graph ] ) ) {
				foreach ( $report[ 'author_count' ][ $author_graph ] as $type=>$months ) {

					if ( $z ) { $series .= ', '; }
					$z++;
					$series .= '{ label: "' . $type . '", data: d' . $z . ' }';
					$comma = false;
					foreach ( $months as $month=>$count ) {
						$total_per_type[ $type ] += $count;
						//$month = str_replace( '-', '-01-', $month);
						$month .= '-01';
						$month = strtotime( $month ) * 1000;
						// ToDo: Zero months with no words
						if ( $comma ) { $data[ $z ] .= ', '; }
						$data[ $z ] .= "[ $month, $count ]"; // Add a data point to the series
						$comma = true;
					}
					echo "var d$z = [",  $data[ $z ], "];\n"; // Create the data array for each post type
				}
			} else {
				$series .= '{ label: "' . __( 'No data', 'word-stats' ). '", data: 100, color: "#666" }';
			}
			$series .= ' ]';
			echo "jQuery.plot(jQuery(\"#ws-graph-timeline\"), $series, ", ws_graph_options( 'timeline' ), ");\n";

			// Percentage of each post type. We counted the totals in the loop for the main chart.
			$series = '[ ';
			$z = 0;
			$comma = false;
			foreach ( $report[ 'type_count' ] as $type=>$count ) {
				$total_sum += $count;
			}
			if ( count( $total_per_type ) ) {
				foreach ( $total_per_type as $type=>$count ) {
					$z++;
					if ( $comma ) { $series .= ', '; }
					$series .= '{ label: "' . $type . '", data: ' . $count . ' }';
					$comma = true;
				}
			} else {
				$series .= '{ label: "' . __( 'No data', 'word-stats' ) . '", data: 100, color: "#666" }';
			}

			$series .= ' ]';
			echo "jQuery.plot(jQuery(\"#ws-graph-total-pc\"), $series,", ws_graph_options( 'type' ), " );\n";

			$series = '[ ';
			$z = 0;
			$comma = false;
			if ( array_sum( $report[ 'totals_readability' ] ) ) {
				foreach ( $report[ 'totals_readability' ] as $index=>$count ) {
						$z++;
						if ( $comma ) { $series .= ', '; }
						switch( $index ) {
							case 0: $label = __( 'Basic', 'word-stats' ); $color = '#19c'; break;
							case 1: $label = __( 'Intermediate', 'word-stats' ); $color = '#1c9'; break;
							case 2: $label = __( 'Advanced', 'word-stats' ); $color = '#c36'; break;
						}
						$series .= '{ label: "' . $label . '", data: ' . $count . ', color: \'' . $color . '\' }';
						$comma = true;
				}
			} else {
				$series .= '{ label: "' . __( 'No data', 'word-stats' ) . '", data: 100, color: "#666" }';
			}
			$series .= ' ]';
			echo "jQuery.plot(jQuery(\"#ws-graph-index-pc\"), $series,", ws_graph_options( 'readability' ), " );\n";


			// Keywords.
			$series = '[ { label: "keywords", data: kw1, color: \'#38c\' } ]';
			$comma = false;
			$z = 0;
			if( count( $report[ 'total_keywords' ] ) ) {
				foreach ( $report[ 'total_keywords' ] as $key=>$value ) {
						if ( $comma ) { $kw_data .= ', '; $kw_ticks .= ', '; $var_kw_ticks .= ', '; }
						$kw_data .= "[  $value, $z ]";
						$var_kw_ticks .= '"' . $key . '"';
						$comma = true;
						$z++;
						if ( $z == 20 ) { break; }
				}
			}
			// Fill the blanks
			if ( $z < 20 ) {
				for( $i = 1; 20 - $z; $i++ ) {
					if ( $comma ) { $kw_data .= ', '; $kw_ticks .= ', '; $var_kw_ticks .= ', '; }
					$kw_data .= "[  0, $z ]";
					$var_kw_ticks .= '""';
					$comma = true;
					$z++;
				}
			}

			echo "var kw1 = [",  $kw_data, "];\n"; // Create the data array for each post type
			echo "var kw_ticks = new Array(",  $var_kw_ticks, ");\n"; // Create the data array for each post type

			echo "	jQuery.plot(jQuery(\"#ws-graph-keywords\"), $series,", ws_graph_options( 'keywords' ), " );\n;";

			// Post type counts
			$bar_max_width = 125;
			$total_posts =  $report[ 'type_count' ][ 'post' ];
			$total_pages =  $report[ 'type_count' ][ 'page' ];
			$total_custom = $report[ 'type_count' ][ 'custom' ];
			$total_all_types = $total_posts + $total_pages + $total_custom;

			if ( !$total_all_types ) { $total_all_types = 1; }
			echo '	jQuery("#pt-meter-post").html("<div class=\'pt-meter-bar pt-meter-post-bar\' style=\'width:', $bar_max_width * ( $total_posts / $total_all_types ) + 1, 'px\'></div> ', $total_posts, '");';
			echo '	jQuery("#pt-meter-page").html("<div class=\'pt-meter-bar pt-meter-page-bar\' style=\'width:', $bar_max_width * ( $total_pages / $total_all_types ) + 1, 'px\'></div> ', $total_pages, '");';
			echo '	jQuery("#pt-meter-custom").html("<div class=\'pt-meter-bar pt-meter-custom-bar\'  style=\'width:', $bar_max_width * ( $total_custom / $total_all_types ) + 1, 'px\'></div> ', $total_custom, '");';
			echo '	jQuery("#pt-meter-all").html("<div class=\'pt-meter-bar pt-meter-all-bar\' style=\'width:', $bar_max_width + 1, 'px\'></div> ', $total_posts + $total_pages + $total_custom, '");';

			echo '</script>

				<div id="ws-recent-entries-wrap">
				<h3 class="ws-header">', __( 'The Most Recent Entries', 'word-stats' ), '</h3>
					<div id="ws-tables">
						<table id="ws-recent-entries">';

			// Recent posts table
			if ( count( $report[ 'recent_posts_rows' ] ) ) {
				foreach ( $report[ 'recent_posts_rows' ] as $type=>$rows) {
					// Clip rows array
					if ( count( $rows ) > 15 ) { $rows = array_slice( $rows, 0, 15 ); }
					echo "<tr><td class='ws-table-block'>";
					switch( $type ) {
						case 'post': _e( 'post', 'word-stats' ); break;
						case 'page': _e( 'page', 'word-stats' ); break;
						default: echo $type; break;
					}
					echo '</td><td class="ws-table-block">', __( 'date', 'word-stats' ), '</td><td class="ws-table-block ws-table-count">', __( 'words', 'word-stats' ), '</td><td class="ws-table-block ws-table-index">', __( 'readability', 'word-stats' ), '</td></tr>';
					$even = false;
					foreach( $rows as $row ) {
						echo '<tr', ( $even ) ? '' : ' class="ws-row-even" ', '>';
						$even = !$even;
						echo $row, '</tr>';
					}
				}
			} else {
				echo "<tr><td class='ws-row-even'>", __( 'No data', 'word-stats' ), '</td></tr>';
			}
			echo '</table>
					</div>
				</div>
			</div>';
			echo '<br style="clear:both;"></div>'; // End wrap
		} else {
			_e( 'Sorry, word counting failed for an unknown reason.', 'word-stats' );
		}
	}
}

function word_stats_report_init() {
		// wp_register_style( 'ws-google-font', 'http://fonts.googleapis.com/css?family=News+Cycle' );
		wp_register_style( 'ws-reports-page', plugins_url() . '/word-stats/css/reports-page.css' );
		wp_register_style( 'ws-jquery-ui', plugins_url() . '/word-stats/js/ui/jquery-ui-1.7.3.custom.css' );
}

function word_stats_report_styles() {
		// wp_enqueue_style( 'ws-google-font' );
		wp_enqueue_style( 'ws-reports-page' );
		wp_enqueue_style( 'ws-jquery-ui' );
}
function word_stats_create_menu() {
	add_action( 'admin_init', array( 'word_stats_admin', 'register_settings' ) );
	add_options_page( 'Word Stats Plugin Settings', 'Word Stats', 'manage_options', 'word-stats-options', array( 'word_stats_admin', 'settings_page' ) );
	if ( get_option( 'word_stats_totals' ) || get_option( 'word_stats_totals' ) == '' ) {
		$page = add_submenu_page( 'index.php', 'Word Stats Plugin Stats', 'Word Stats', 'edit_posts', 'word-stats-graphs', array( 'word_stats_admin', 'ws_reports_page' ) );
		// Load styles for the reports page
		add_action( 'admin_print_styles-' . $page, 'word_stats_report_styles' );
	}
}
add_action( 'admin_init', 'word_stats_report_init' );
add_action( 'admin_menu', 'word_stats_create_menu' );
?>
