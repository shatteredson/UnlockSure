<?php
// Enqueue brand fonts & base styles
add_action('wp_enqueue_scripts', function() {
  // Google Fonts (swap for local if needed)
  wp_enqueue_style('unlocksure-fonts', 'https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700&family=Open+Sans:wght@400;600&display=swap', [], null);
  wp_enqueue_style('unlocksure-style', get_stylesheet_uri(), [], wp_get_theme()->get('Version'));
});

// WooCommerce support
add_action('after_setup_theme', function(){
  add_theme_support('woocommerce');
});

// Elementor container width (optional)
add_filter('elementor/frontend/container_width', fn($w)=>1140);

// Color palette in Gutenberg (optional)
add_action('after_setup_theme', function(){
  add_theme_support('editor-color-palette', [
    ['name'=>'Blue','slug'=>'us-blue','color'=>'#0073E6'],
    ['name'=>'Navy','slug'=>'us-navy','color'=>'#1A1A2E'],
    ['name'=>'Gray','slug'=>'us-gray','color'=>'#F5F7FA'],
    ['name'=>'Mid Gray','slug'=>'us-mgray','color'=>'#A0A4A8'],
    ['name'=>'Orange','slug'=>'us-orange','color'=>'#FF6F3C'],
    ['name'=>'Error','slug'=>'us-error','color'=>'#E74C3C'],
  ]);
});
