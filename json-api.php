<?php
/*
Plugin Name: MobPress
Plugin URI: http://www.mob.com/downloadDetail/MobPress/server
Description: WordPress移动化解决方案，免费提供WordPress插件、App生成打包以及7 * 24小时技术支持服务，强力助推站长开拓移动市场
Version: 1.0.0
Author: Mob
Author URI: http://mob.com
*/

$dir = wpsdkDir();
@include_once "$dir/singletons/api.php";
@include_once "$dir/singletons/query.php";
@include_once "$dir/singletons/introspector.php";
@include_once "$dir/singletons/response.php";
@include_once "$dir/models/post.php";
@include_once "$dir/models/comment.php";
@include_once "$dir/models/category.php";
@include_once "$dir/models/tag.php";
@include_once "$dir/models/author.php";
@include_once "$dir/models/attachment.php";

function wpsdkInit() {
  global $wpsdk_api;
  if (phpversion() < 5) {
    add_action('admin_notices', 'wpsdkPhpVersionWarning');
    return;
  }
  if (!class_exists('WPSDK')) {
    add_action('admin_notices', 'wpsdkClassWarning');
    return;
  }
  add_filter('rewrite_rules_array', 'wpsdkRewrites');
  $wpsdk_api = new WPSDK();
}

function wpsdkPhpVersionWarning() {
  echo "<div id=\"json-api-warning\" class=\"updated fade\"><p>Sorry, JSON API requires PHP version 5.0 or greater.</p></div>";
}

function wpsdkClassWarning() {
  echo "<div id=\"json-api-warning\" class=\"updated fade\"><p>Oops, WPSDK class not found. If you've defined a WPSDK_DIR constant, double check that the path is correct.</p></div>";
}

function wpsdkActivation() {
  // Add the rewrite rule on activation
  global $wp_rewrite;
  add_filter('rewrite_rules_array', 'wpsdkRewrites');
  $wp_rewrite->flush_rules();
}

function wpsdkDeactivation() {
  // Remove the rewrite rule on deactivation
  global $wp_rewrite;
  $wp_rewrite->flush_rules();
}

function wpsdkRewrites($wp_rules) {
  $base = get_option('wpsdk_base', 'api');
  if (empty($base)) {
    return $wp_rules;
  }
  $wpsdk_api_rules = array(
    "$base\$" => 'index.php?json=info',
    "$base/(.+)\$" => 'index.php?json=$matches[1]'
  );
  return array_merge($wpsdk_api_rules, $wp_rules);
}

function wpsdkDir() {
  if (defined('WPSDK_DIR') && file_exists(WPSDK_DIR)) {
    return WPSDK_DIR;
  } else {
    return dirname(__FILE__);
  }
}

// Add initialization and activation hooks
add_action('init', 'wpsdkInit');
register_activation_hook("$dir/json-api.php", 'wpsdkActivation');
register_deactivation_hook("$dir/json-api.php", 'wpsdkDeactivation');

?>
