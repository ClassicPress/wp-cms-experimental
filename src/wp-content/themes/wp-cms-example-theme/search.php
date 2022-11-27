<?php
/**
 * The template for displaying search results
 */

get_header();

if ( have_posts() ) { ?>
	<h1>Results for: <?php echo get_search_query(); ?></h1>
	<?php
	while ( have_posts() ) {
		the_post();
		get_template_part( 'template-parts/listed-post', 'search' );
	}
} else {
	?>
	<p>Sorry, but nothing matched your search query.</p>
	<?php
}

get_sidebar();
get_footer();
