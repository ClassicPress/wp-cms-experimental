<?php

namespace wpcms\very_simple_theme;

// Let WP CMS manage the document title
add_theme_support( 'title-tag' );

// Enqueue scripts and styles
function enqueue_stuff() {
	wp_enqueue_style( 'theme-style', get_stylesheet_uri() );
}
add_action( 'wp_enqueue_scripts', 'wpcms\very_simple_theme\enqueue_stuff' );
