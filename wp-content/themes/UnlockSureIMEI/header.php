<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1" />
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<header class="site-header" style="background:#fff; border-bottom:1px solid #eee;">
  <div class="container" style="max-width:1140px;margin:0 auto;padding:12px 20px;display:flex;align-items:center;gap:16px;">
    <a href="<?php echo esc_url(home_url('/')); ?>" class="logo" style="font-weight:700;color:#1A1A2E;text-decoration:none;">UnlockSure</a>
    <?php wp_nav_menu(['theme_location'=>'primary','container'=>false]); ?>
  </div>
</header>
<main class="site-main" style="max-width:1140px;margin:24px auto;padding:0 20px;">
