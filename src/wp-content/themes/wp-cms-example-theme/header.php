<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>

<a class="screen-reader-text" href="#main-content">Skip to content</a>

<?php
if ( ! is_admin_bar_showing() ) {
	?>
	<aside id="top-bar">
		<span class="version">WP CMS v<?php bloginfo( 'version' ); ?></span>
	</aside>
	<?php
}
?>

<div id="resizable-area">
	<header id="navigation">
		<h1 class="site-title"><a href="<?php echo esc_url( home_url() ); ?>"><?php bloginfo( 'name' ); ?></a></h1>
		<h2 class="site-description"><?php bloginfo( 'description' ); ?></h2>
		<hr>
		<nav id="main-menu">
			<?php
			wp_nav_menu( array(
					'theme_location' => 'primary-menu',
					'menu_id'        => 'primary-menu',
			) );
			?>
		</nav>
		<div id="handle"></div>
	</header>
	<div id="editor">
		<aside id="line-numbers"></aside>
		<main id="main-content">
