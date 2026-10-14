<?php
if (!defined('ABSPATH')) { exit; }
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#FFFDF5">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>家庭財務管理 · WUMETAX Finance</title>
    <?php wp_head(); ?>
</head>
<body <?php body_class('wff-standalone'); ?>>
<?php wp_body_open(); ?>
<div id="wff-app" role="main">
    <noscript>此財務管理介面需要啟用 JavaScript。</noscript>
    <p class="wff-loading">載入家庭財務管理中…</p>
</div>
<?php wp_footer(); ?>
</body>
</html>
