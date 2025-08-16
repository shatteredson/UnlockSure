<?php
/**
 * Plugin Name: UnlockSure IMEI Check
 * Description: Renders the UnlockSure IMEI check tool via shortcode [unlocksure_imei_check].
 * Version: 0.1.0
 * Author: UnlockSure
 */

if (!defined('ABSPATH')) exit;

define('US_IMEI_VER', '0.1.0');
define('US_IMEI_URL', plugin_dir_url(__FILE__));
define('US_IMEI_PATH', plugin_dir_path(__FILE__));

add_action('wp_enqueue_scripts', function(){
  // CSS/JS enqueue (scoped)
  wp_enqueue_style('unlocksure-imei', US_IMEI_URL.'assets/unlocksure-imei.css', [], US_IMEI_VER);
  wp_enqueue_script('unlocksure-imei', US_IMEI_URL.'assets/unlocksure-imei.js', ['jquery'], US_IMEI_VER, true);
  // Optional: pass AJAX endpoint or nonces here if needed
  wp_localize_script('unlocksure-imei', 'US_IMEI', [
    'ajax_url' => admin_url('admin-ajax.php'),
    'nonce'    => wp_create_nonce('us_imei_nonce'),
  ]);
});

// Shortcode to render IMEI block
add_shortcode('unlocksure_imei_check', function($atts, $content=null){
  ob_start();
  $view = US_IMEI_PATH.'partials/unlocksure-imei.php';
  if (file_exists($view)) {
    include $view;
  } else {
    echo '<div class="us-imei-error">IMEI component not found.</div>';
  }
  return ob_get_clean();
});
