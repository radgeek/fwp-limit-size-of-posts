<?php
/*
Plugin Name: FWP+: Limit size of posts
Plugin URI: http://projects.radgeek.com/fwp-limit-size-of-posts/
Description: enables you to track and limit the size of incoming syndicated posts from FeedWordPress by word count, character count, or sentence count.
Version: 2018.0128 
Author: Charles Johnson
Author URI: http://radgeek.com/
License: GPL
*/

class FWPLimitSizeOfPosts {
	private $mb;
	private $myCharset;

	public function __construct () {
		$this->name = strtolower(get_class($this));
		$this->myCharset = get_bloginfo('charset');
		
		// Carefully support multibyte languages
		if (extension_loaded('mbstring') and function_exists('mb_list_encodings')) :
			$this->mb = in_array($this->myCharset, mb_list_encodings());
		endif;

		add_filter(
		/*hook=*/ 'the_content',
		/*function=*/ array($this, 'the_content'),
		/*priority=*/ 10010,
		/*arguments=*/ 1
		);

		add_filter(
		/*hook=*/ 'the_content_rss',
		/*function=*/ array($this, 'the_content'),
		/*priority=*/ 10010,
		/*arguments=*/ 1
		);

		add_filter(
		/*hook=*/ 'syndicated_item_content',
		/*function=*/ array($this, 'syndicated_item_content'),
		/*priority=*/ 10010,
		/*arguments=*/ 2
		);

		add_filter(
		/*hook=*/ 'syndicated_item_excerpt',
		/*function=*/ array($this, 'syndicated_item_excerpt'),
		/*priority=*/ 10010,
		/*arguments=*/ 2
		);
		
		add_filter(
		/*hook=*/ 'syndicated_post',
		/*function=*/ array($this, 'syndicated_post'),
		/*priority=*/ 10010,
		/*arguments=*/ 2
		);
		
		add_action(
		/*hook=*/ 'feedwordpress_admin_page_posts_meta_boxes',
		/*function=*/ array($this, 'add_settings_box'),
		/*priority=*/ 100,
		/*arguments=*/ 1
		);
		
		add_action(
		/*hook=*/ 'feedwordpress_admin_page_posts_save',
		/*function=*/ array($this, 'save_settings'),
		/*priority=*/ 100,
		/*arguments=*/ 2
		);
	} /* FWPLimitSizeOfPosts constructor */

	// Carefully support multibyte languages (fallback to normal functions if not available)
	protected function is_mb () { return $this->mb; }
	protected function charset () { return $this->myCharset; }
	
	protected function substr ($str, $start, $length = null) {
		$length = (is_null($length) ? $this->strlen($str) : $length);
		$str = ($this->is_mb()
			? mb_substr($str, $start, $length, $this->charset())
			: substr($str, $start, $length));
		return $str;
	} /* FWPLimitSizeOfPosts::substr() */
	
	protected function strlen ($str) {
		if ($this->is_mb()) :
			return mb_strlen($str, $this->charset());
		else :
			return strlen($str);
		endif;
	} /* FWPLimitSizeOfPosts::strlen() */

	public function filter ($text, $params = array()) {
		global $id, $post;
		
		$arg = wp_parse_args($params, array(
		'sentences' => NULL,
		'words' => NULL,
		'characters' => NULL,
		'breaks' => false,
		'insert break' => false,
		'allowed tags' => NULL,
		'finish word' => false,
		'ellipsis' => NULL,
		'keep images' => false,
		));
		
		$sentencesMax = $arg['sentences'];
		$wordsMax = $arg['words'];
		$charsMax = $arg['characters'];
		$breaks = !!$arg['breaks'];
		$insertBreak = !!$arg['insert break'];
		$allowed_tags = $arg['allowed tags'];
		$finish_word = !!$arg['finish word'];
		
		if (!is_null($arg['ellipsis'])) :
			$ellipsis = $arg['ellipsis'];
		else:
			$ellipsis = ($arg['insert break'] ? "<!--more-->" : '...');
		endif;
		$ellipsis = apply_filters('feedwordpress_limit_size_of_posts_ellipsis', $ellipsis);
		
		$originalText = $text;

		if ($breaks) :
			$gen = new SyndicatedPostGenerator($params['post']);

			$moreMarks = array(
				'http://www.sixapart.com/movabletype/' => array('(<div id=["\']more["\']>)', '$1'),
				'http://www.blogger.com' => array('<a name=["\']more["\'](></a>|\s*/>)', ''),
				'http://wordpress.org/' => array('<span id=["\']more-[0-9]+["\'](></span>|\s*/>)', ''),
				'LiveJournal / LiveJournal.com' => array('<a name=["\']cutid[0-9]+["\'](></a>|\s*/>)', ''),
			);
			foreach ($moreMarks as $url => $rewrite) :
				$gotIt = $gen->generated_by(NULL, $url);
				if ($gotIt or is_null($gotIt)) :
					if ($insertBreak) :
						// Keep remainder
						$pattern = $rewrite[0];
						$replacement = $ellipsis.$rewrite[1];
					else :
						// Cut it off
						$pattern = $rewrite[0] . '.*$'; // Eat it all to the end of the string
						$replacement = $ellipsis;
					endif;

					// Search for HTML artifact of jump tag
					$text = preg_replace(
						"\007".$pattern."\007"."i",
						$replacement,
						$text
					);
				endif;
			endforeach;
		endif;
		
		if ($originalText == $text) :
			// From the default wp_trim_excerpt():
			// Some kind of precaution against malformed CDATA in RSS feeds I suppose
			$text = str_replace(']]>', ']]&gt;', $text);
			
			if (!is_null($allowed_tags)) :
				$text = strip_tags($text, $allowed_tags);
			endif;
	
			$sentencesOK = (is_null($sentencesMax) OR ($sentencesMax > count(preg_split(':([.?!]|</p>|</li>):i', $text, -1))));
			$wordsOK = (is_null($wordsMax) OR ($wordsMax > count(preg_split('/[\s]+/', strip_tags($text), -1))));
			$charsOK = (is_null($charsMax) OR ($charsMax > $this->strlen(strip_tags($text))));
			if ($sentencesOK and $wordsOK and $charsOK) :
				return $text;
			else :
				// Break string into "words" based on
				// (1) whitespace, or
				// (2) tags
				// Hence "un<em>frakking</em>believable" will
				// be treated as 3 words, not as 1 word. You
				// might refine this, if it is really important,
				// by keeping a list of "word-breaking" tags
				// (e.g. <br/>, <p>/</p>, <div>/</div>, etc.)
				// and non-word-breaking tags (e.g. <em>/</em>,
				// etc.).
				//
				// Tags do not count towards either words or characters;
				// Whitespace chunks count towards characters, not words
	
				$text_bits = preg_split(
					'/([\s]+|[.?!]+|<[^>]+>)/',
					$text,
					-1,
					PREG_SPLIT_DELIM_CAPTURE
				);
	
				$sentencesN = 0;
				$wordsN = 0;
				$charsN = 0;
				$length = 0;
				$text = ''; $rest = '';
				$prefixDone = false;
				foreach ($text_bits as $chunk) :
					if ($prefixDone) :
						$rest .= $chunk;
					else :
						// This is a tag, or whitespace.
						if (preg_match('/^<[^>]+>$/s', $chunk)) :
							// Closer tags might break a sentence
							if (preg_match('!(</p>|</li>)!i', $chunk)) :
								$sentencesN += 1;
							endif;
						elseif (strlen(trim($chunk)) == 0) :
							$charsN += $this->strlen($chunk);
						elseif (preg_match('/[.?!]+/', $chunk)) :
							$charsN += $this->strlen($chunk);
							$sentencesN += 1;
						else :
							$charsN += $this->strlen($chunk);
							$wordsN += 1;
						endif;
		
						if (!is_null($wordsMax) and ($wordsN > $wordsMax)) :
							$prefixDone = true;
						else :
							$text .= $chunk;
			
							if (!is_null($charsMax) and ($charsN > $charsMax)) :
								$length += ($this->strlen($chunk) - ($charsN - $charsMax));
								$prefixDone = true;
							elseif (!is_null($sentencesMax) and ($sentencesN >= $sentencesMax)) :
								$length += ($this->strlen($chunk));
								$prefixDone = true;
							else :
								$length += $this->strlen($chunk);
							endif;
						endif;
					endif;
				endforeach;
	
				if ($charsN > $charsMax and !$finish_word) :
					// Break it off right there!
					$text = $this->substr($text, 0, $length);
					$rest = $this->substr($text, $length).$rest;
				endif;
	
				$wordsLimited = (!is_null($wordsMax) AND ($wordsN > $wordsMax));
				$charsLimited = (!is_null($charsMax) AND ($charsN > $charsMax));
				$sentencesLimited = (!is_null($sentencesMax) AND ($sentencesN >= $sentencesMax));

				$img = '';
				if ($wordsLimited OR $charsLimited OR $sentencesLimited) :
 					if ($arg['keep images']) :

						$imgs = array();
						$matches = preg_match_all(
							'/<img \s+ [^>]* src= [^>]+ >/ix',
							$rest,
							$imgs
						);
						
						if ($matches > 0) :
							$openTag = '<div class="post-limited-image">';
							$img = $openTag
								.implode(
									"</div>\n$openTag",
									$imgs[0]
								).'</div>';
						endif;

					endif;
				else :
					$ellipsis = '';
				endif;
	
				if ($insertBreak) :
					$text = $text . $img . $ellipsis . $rest;
				else :
					$text = force_balance_tags($text . $ellipsis) . $img;
				endif;
			endif;
		else :
			$text = force_balance_tags($text);
		endif;
		return $text;
	} /* FWPLimitSizeOfPosts::filter() */

	public function the_content ($content) {
		global $post;

		if (is_object($post)) :
			$oPost = new FeedWordPressLocalPost($post);
			if ($oPost->is_syndicated()) :
				$link = $oPost->feed();

				if ('syndicated_item_content'==$link->setting($this->name.' apply size limits', $this->name.'_apply_size_limits')) :
					return $content;
				endif;
				
				$rule = $link->setting('limit size of posts', $this->name.'_limit_size_of_posts', NULL);
				// Rules are stored in the format array('metric' => $count, 'metric' => $count);
				// 'metric' is the metric to be limited (characters|words)
				// $count is a numeric value indicating the maximum to limit it to
				// NULL indicates that no limiting rules have been stored.
		
				if (is_string($rule)) : $rule = unserialize($rule); endif;

				$haveRule = is_array($rule);
				if ($haveRule) :
					$rule['post'] = $post;
					$content = $this->filter($content, $rule);
				endif;
			endif;
		endif;
		return $content;
	} /* FWPLimitSizeOfPosts::the_content () */

	public function syndicated_item_content ($content, $post) {
		$link = $post->link;

		if ('syndicated_item_content'!=$link->setting($this->name.' apply size limits', $this->name.'_apply_size_limits')) :
			return $content;
		endif;

		$rule = $link->setting('limit size of posts', $this->name.'_limit_size_of_posts', NULL);
		// Rules are stored in the format array('metric' => $count, 'metric' => $count);
		// 'metric' is the metric to be limited (characters|words)
		// $count is a numeric value indicating the maximum to limit it to
		// NULL indicates that no limiting rules have been stored.
		
		if (is_string($rule)) : $rule = unserialize($rule); endif;

		$haveRule = is_array($rule);
		if ($haveRule) :
			$rule['post'] = $post;
			$content = $this->filter($content, $rule);
		endif;
		return $content;
	} /* FWPLimitSizeOfPosts::syndicated_item_content() */

	public function syndicated_item_excerpt ($excerpt, $post) {
		$link = $post->link;

		if ('syndicated_item_content'!=$link->setting($this->name.' apply size limits', $this->name.'_apply_size_limits')) :
			return $content;
		endif;

		$rule = $link->setting('limit size of posts', $this->name.'_limit_size_of_posts', NULL);
		// Rules are stored in the format array('metric' => $count, 'metric' => $count);
		// 'metric' is the metric to be limited (characters|words)
		// $count is a numeric value indicating the maximum to limit it to
		// NULL indicates that no limiting rules have been stored.
		
		if (is_string($rule)) : $rule = unserialize($rule); endif;

		$filtered = is_array($rule);
		if ($filtered) :
			// Force FWP to generate an excerpt from the filtered text.
			$excerpt = NULL;
		endif;
		return $excerpt;
	} /* FWPLimitSizeOfPosts::syndicated_item_excerpt() */
	
	public function syndicated_post ($aPost, $oPost) {
		$aLengths = $this->count_lengths($oPost->content()); // get original HTML content from before filtering, etc.
		foreach ($aLengths as $units => $measure) :
			$metaKey = 'fwplsop count '.$units;
			if (!isset($aPost['meta'][$metaKey]) ):
				$aPost['meta'][$metaKey] = array();
			endif;
			$aPost['meta'][$metaKey][] = $measure;
		endforeach;
		return $aPost;
	} /* FWPLimitSizeOfPosts::syndicated_post() */

	public function count_lengths($text) {
		$text_bits = preg_split(
					'/([\s]+|[.?!]+|<[^>]+>)/',
					$text,
					-1,
					PREG_SPLIT_DELIM_CAPTURE
		);

		$sentencesN = 0;
		$wordsN = 0;
		$charsN = 0;
		$length = 0;
		foreach ($text_bits as $chunk) :
			// This is a tag, or whitespace.
			if (preg_match('/^<[^>]+>$/s', $chunk)) :
				// Closer tags might break a sentence
				if (preg_match('!(</p>|</li>)!i', $chunk)) :
					$sentencesN += 1;
				endif;
			elseif (strlen(trim($chunk)) == 0) :
				$charsN += $this->strlen($chunk);
			elseif (preg_match('/[.?!]+/', $chunk)) :
				$charsN += $this->strlen($chunk);
				$sentencesN += 1;
			else :
				$charsN += $this->strlen($chunk);
				$wordsN += 1;
			endif;
		endforeach;

		return array("words" => $wordsN, "characters" => $charsN, "sentences" => $sentencesN);
	} /* FWPLimitSizeOfPosts::count_lengths() */

	public function add_settings_box ($page) {
		add_meta_box(
			/*id=*/ "feedwordpress_{$this->name}_box",
			/*title=*/ __("Limit size of posts"),
			/*callback=*/ array(&$this, 'display_settings'),
			/*page=*/ $page->meta_box_context(),
			/*context=*/ $page->meta_box_context()
		);
	} /* FWPLimitSizeOfPosts::add_settings_box() */

	protected function limit_objects () {
		return array(
			'words' => 300 /*=default limit*/,
			'sentences' => 10 /*=default limit*/,
			'characters' => 1000 /*=default limit*/,
			'breaks' => 'unchecked' /*=default limit*/,
			'keep images' => 'unchecked' /*=default limit*/,
		);
	}

	public function display_settings ($page, $box = NULL) {
		$global_rule = get_option("feedwordpress_{$this->name}_limit_size_of_posts", NULL);
		if ($page->for_feed_settings()) :
			$rule = $page->link->setting('limit size of posts', NULL, NULL);
		else :
			$rule = $global_rule;
		endif;
		$thesePosts = $page->these_posts_phrase();	

		if (is_string($global_rule)) : $global_rule = unserialize($global_rule); endif;
	
		if (is_string($rule)) : $rule = unserialize($rule); endif;

		foreach ($this->limit_objects() as $thingy => $default) :
			$inputThingy = str_replace(' ', '_', $thingy);
			$checked[$thingy] = ((isset($rule[$thingy]) and !is_null($rule[$thingy])) ? 'checked="checked"' : '');
			if (is_numeric($default)) :
				$input[$thingy] = '<input type="number"
				min="0" step="1"
				size="4"
				name="'.$this->name.'_'.$inputThingy.'_limit" value="'.(isset($rule[$thingy]) ? $rule[$thingy] : $default).'" />';
			else :
				$input[$thingy] = '<input type="hidden" name="'.$this->name.'_'.$inputThingy.'_limit" value="1" />';
			endif;
		endforeach;
		
		$selector = array();
			
		$wordsSet = isset($rule['words']);
		$sentsSet = isset($rule['sentences']);
		$charsSet = isset($rule['characters']);
		$breaksSet = isset($rule['breaks']);
		
		$insertBreaks = ((isset($rule['insert break']) and $rule['insert break']) ? 'break' : 'yes');

		if (!($wordsSet or $charsSet or $sentsSet or $breaksSet)) : $selected = 'no';
		else : $selected = $insertBreaks;
		endif;
		if ($page->for_feed_settings()) :
			if (is_null($rule)) : $selected = 'default'; endif;
		endif;

		$selector[] = '<ul>';
		
		$magicId = $this->name.'-limit-controls';
		foreach (array('default', 'yes', 'no', 'break') as $response) :
			$boxId[$response] = $this->name.'-limit-'.$response;
			$input[$response] = '<input type="radio" onclick="'.$this->name.'_display_limit_controls();" id="'.$boxId[$response].'" name="'.$this->name.'_limits" value="'.$response.'" '.($selected==$response?' checked="checked"':'').' />';
		endforeach;

		if ($page->for_feed_settings()) :
			$siteWide = array();
			if (is_null($global_rule)) :
				$siteWide[] = 'no limit';
			else:
				if (isset($global_rule['words'])) : $siteWide[] = $global_rule['words'].' words'; endif;
				if (isset($global_rule['sentences'])) : $siteWide[] = $global_rule['words'].' sentences'; endif;
				if (isset($global_rule['characters'])) : $siteWide[] = $global_rule['characters'].' characters'; endif;
				if (isset($global_rule['breaks'])) : $siteWide[] = 'at breaks from the original post'; endif;
			endif;
			
			$selector[] = '<li><label>'.$input['default'].' '.sprintf(__("Use site-wide settings for $thesePosts (currently: %s)"), implode(' / ',$siteWide)).'</label></li>';
		endif;
		$selector[] = '<li><label>'.$input['no'].' '.__("Do not limit the size of $thesePosts").'</label></li>';
		$selector[] = '<li><label>'.$input['yes'].' '.__("Cut off $thesePosts after a certain length").'</label></li>';
		$selector[] = '<li><label>'.$input['break'].' '.__("Insert a \"Read More...\" break into $thesePosts after a certain length").'</label><br/>(only works when limits are applied on Import, not on Display)</li>';
		$selector[] = '</ul>';

		$fasl_options = array(
			'the_content' => 'when posts are displayed on the website',
			'syndicated_item_content' => 'when posts are first imported into the database',
		);
		$fasl_params = array(
			'setting-default' => 'default',
			'global-setting-default' => 'the_content',
			'labels' => array(
				'the_content' => __('when posts are displayed'),
				'syndicated_item_content' => __('when posts are first imported into the database'),
			),
			'default-input-value' => 'default',
		);
		?>
		<table class="edit-form narrow">
		<tbody>
		<tr>
		<th scope="row"><?php _e('Apply size limits:'); ?></th>
		<td><?php
		$page->setting_radio_control(
			$this->name . ' apply size limits', $this->name . '_apply_size_limits',
			$fasl_options, $fasl_params
		);
		?></td></tr>

		<tr>
		<th scope="row"><?php _e('Limit post size:'); ?></th>
		<td><?php print implode("\n", $selector); ?></td>
		</tr>
		</tbody>
		</table>
		
		<table class="edit-form narrow" id="<?php print $this->name; ?>-limit-controls">
		<tbody>
		<tr><th scope="row"><?php _e('Word count:'); ?></th>
		<td><input type="checkbox" <?php print $checked['words']; ?> name="<?php print $this->name; ?>_limit_words" value="yes" /> <?php printf(__("Limit $thesePosts to no more than %s words"), $input['words']); ?></td>
		</tr>

		<tr><th scope="row"><?php _e('Sentences:'); ?></th>
		<td><input type="checkbox" <?php print $checked['sentences']; ?> name="<?php print $this->name; ?>_limit_sentences" value="yes" /> <?php printf(__("Limit $thesePosts to no more than %s sentences"), $input['sentences']); ?>
		</tr>
		
		<tr><th scope="row"><?php _e('Characters:'); ?></th>
		<td><input type="checkbox" <?php print $checked['characters']; ?> name="<?php print $this->name; ?>_limit_characters" value="yes" /> <?php printf(__("Limit $thesePosts to no more than %s characters"), $input['characters']); ?></td>
		</tr>

		<tr><th scope="row"><?php _e('At Breaks:'); ?></th>
		<td><label><input type="checkbox" <?php print $checked['breaks']; ?> name="<?php print $this->name; ?>_limit_breaks" value="yes" /> <?php printf(__("Use \"Read More...\" break-points from the original source post instead, if available. %s"), $input['breaks']); ?></label></td>
		</tr>

		<tr><th scope="row"><?php _e('Keep Images:'); ?></th>
		<td><label><input type="checkbox" <?php print $checked['keep images']; ?> name="<?php print $this->name; ?>_limit_keep_images" value="yes" /> <?php printf(__("Keep images in post even if they occur after the limit or cut-off point. %s"), $input['keep images']); ?></label></td></tr>
		</tbody>
		</table>
		
		<script type="text/javascript">
		function <?php print $this->name; ?>_display_limit_controls (init) {
			if ('undefined' != typeof(jQuery('#<?php print $this->name; ?>-limit-yes:checked, #<?php print $this->name; ?>-limit-break:checked').val())) {
				var val = 600;
				if (init) { val = 0; }
				jQuery('#<?php print $this->name; ?>-limit-controls').show(val);
			} else {
				jQuery('#<?php print $this->name; ?>-limit-controls').hide();
			}
		}
		function <?php print $this->name; ?>_display_limit_controls_init () {
			<?php print $this->name; ?>_display_limit_controls(/*init=*/ true);
		}
		
		jQuery(document).ready( <?php print $this->name; ?>_display_limit_controls_init );
		</script>
		<?php
	} /* FWPLimitSizeOfPosts::display_settings() */
	
	public function save_settings ($params, $page) {
		if (isset($params['save']) or isset($params['submit'])) :
			if (isset($params[$this->name.'_apply_size_limits'])) :
				$page->update_setting($this->name.' apply size limits', $params[$this->name.'_apply_size_limits']);
			endif;

			if (isset($params[$this->name.'_limits'])) :
				$rule = array();
	
				switch ($params[$this->name.'_limits']) :
				case 'default' :
					$rule = NULL;
					break;
				case 'no' :
					// allows feeds to override a default limiting
					// policy with a no-limiting policy
					$rule = array ( 'limits' => 'none' );
					break;
				case 'break' :
					$rule['insert break'] = true;
					# | Continue on below...
					# v
				case 'yes' :
				default :
					foreach ($this->limit_objects() as $thingy => $default) :
						$inputThingy = str_replace(' ', '_', $thingy);
						$iname = $this->name.'_limit_'.$inputThingy;
						if (isset($params[$iname])
						and ($params[$iname]=='yes')
						and (isset($params[$this->name.'_'.$inputThingy.'_limit']))) :
							$rule[$thingy] = (int) $params[$this->name.'_'.$inputThingy.'_limit'];
						endif;
					endforeach;
				endswitch;
				
				// Now let's write it.
				if ($page->for_feed_settings()) :
					if (!is_null($rule)) :
						$page->link->settings['limit size of posts'] = serialize($rule);
					else :
						unset($page->link->settings['limit size of posts']);
					endif;
					$page->link->save_settings(/*reload=*/ true);
				else :
					update_option("feedwordpress_{$this->name}_limit_size_of_posts", $rule);
				endif;
			endif;
		endif;
	} /* FWPLimitSizeOfPosts::save_settings() */

} /* class FWPLimitSizeOfPosts */

class SyndicatedPostGenerator {
	protected $post;
	protected $link;
	
	public function __construct ($post) {
		if (is_object($post) and is_a($post, 'SyndicatedPost')) :
			$this->post = $post;
			$this->link = $post->link;
		else :
			// FIXME: This doesn't really work. Could we do something
			// with FeedWordPressLocalPost? Or should we just throw
			// an exception, or...?
			$this->post = new SyndicatedPost($post);
			$this->link = $this->post->link;
		endif;
	} /* SyndicatedPostGenerator constructor */
	
	public function generated_by ($name, $url, $version = NULL) {
		$ret = NULL;
		if (method_exists($this->link, 'generated_by')) :
			$ret = $this->link->generated_by($name, $url, $version);
		else :
			$inspect = array(
				'url' => array(
				'/feed/atom:generator/@url',
				'/feed/atom:generator/@uri',
				'/feed/rss:generator',
				'/feed/admin:generatorAgent/@rdf:resource',
				),
				'name' => array(
				'/feed/atom:generator',
				),
				'version' => array(
				'/feed/atom:generator/@version',
				),
			);
			$found = array(); $final = array();
			foreach ($inspect as $item => $set) :
				$found[$item] = array();
				foreach ($set as $q) :
					$found[$item] = array_merge($found[$item], $this->post->query($q));
				endforeach;
				
				if (count($found[$item]) > 0) :
					$final[$item] = implode(" ", $found[$item]);
				else :
					$final[$item] = NULL;
				endif;
			endforeach;
			
			if (is_null($final['version'])
			and !is_null($final['url'])
			and preg_match('|^(.*)\?v=(.*)$|i', $final['url'], $ref)) :
				$final['url'] = $ref[1];
				$final['version'] = $ref[2];
			endif;
			
			if (!is_null($name)) :
				if ($final['name'] == $name) :
					$ret = true;
				elseif (!is_null($final['name'])) :
					$ret = false;
				endif;
			endif;

			if (!is_null($url)) :
				if ($final['url'] == $url) :
					$ret = true;
				elseif (!is_null($final['url'])) :
					$ret = false;
				endif;
			endif;
		endif;
		return $ret;
	} /* SyndicatedPostGenerator::generated_by () */
} /* class SyndicatedPostGenerator */

global $fwpPostSizeLimiter;
$fwpPostSizeLimiter = new FWPLimitSizeOfPosts;

