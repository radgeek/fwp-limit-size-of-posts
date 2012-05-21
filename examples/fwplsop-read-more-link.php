<?php
/*
Plugin Name: FWP+: Limit size of posts: Read More link
Plugin URI: http://projects.radgeek.com/fwp-limit-size-of-posts/
Description: sample filter to demonstrate how to change the ellipsis character for FWP+: Limit size of posts. Displays a "Read More" link to the full post on the original website.
Version: 2010.0207 
Author: Charles Johnson
Author URI: http://radgeek.com/
License: GPL
*/

add_filter(
	/*hook=*/ 'feedwordpress_limit_size_of_posts_ellipsis',
	/*function=*/ 'fwp_limit_size_of_posts_read_more_link',
	/*priority=*/ 10,
	/*arguments=*/ 1
);

function fwp_limit_size_of_posts_read_more_link ($ellipsis) {
	global $id, $post;
	$ellipsis = ' <a href="'.htmlspecialchars(get_syndication_permalink($id)).'">(Read more...)</a>';
	return $ellipsis;
}

