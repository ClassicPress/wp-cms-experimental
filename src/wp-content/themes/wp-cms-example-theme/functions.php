<?php

namespace wpcms\example_theme;

// Let WP CMS manage the document title
add_theme_support( 'title-tag' );

// Enable support for Post Thumbnails on posts and pages
add_theme_support( 'post-thumbnails' );

// Include primary navigation menu
function setup_navigation() {
	register_nav_menus( array(
		'primary-menu' => 'Primary Menu',
	) );
}
add_action( 'init', 'wpcms\example_theme\setup_navigation' );

// Enqueue scripts and styles
function enqueue_stuff() {
	wp_enqueue_style( 'general-style', get_stylesheet_uri() );
	wp_enqueue_script( 'global-js', get_template_directory_uri() . '/assets/js/global.js', array(), '1.0.0', true );
}
add_action( 'wp_enqueue_scripts', 'wpcms\example_theme\enqueue_stuff' );
