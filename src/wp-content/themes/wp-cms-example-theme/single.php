<?php
get_header();

while ( have_posts() ) {
	the_post();
	get_template_part( 'template-parts/post-content', get_post_type() );
}

get_sidebar();
get_footer();
