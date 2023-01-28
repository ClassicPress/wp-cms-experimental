<?php
get_header();

while ( have_posts() ) {
	the_post();
	get_template_part( 'template-parts/post-content' );
}

get_sidebar();
get_footer();
