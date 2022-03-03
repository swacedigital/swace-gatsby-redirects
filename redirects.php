<?php

use WPGatsby\Admin\Settings;

/**
 
* @package Swace

*/
 
/*
 
Plugin Name: Swace Gatsby Redirects
 
Plugin URI: https://swace.se
 
Description: A plugin for adding redirects that get picked up by gatsby on build.
 
Version: 1.0.1
 
Author: Adam Elvander
 
Author URI: https://github.com/adel0559
 
License: GPLv2 or later
 
Text Domain: swace
 
*/

function get_options_table() {
  global $wpdb;
  return $wpdb->prefix . "options";
}

function save_db_backup($redirects) {
  global $wpdb;
  $wp_options = get_options_table();
  $sql = <<<SQL
    SELECT option_id FROM {$wp_options} WHERE option_name = 'redirects'
  SQL;
  $res = $wpdb->get_results($wpdb->prepare($sql));
  $id = isset($res[0]) && isset($res[0]->option_id) ? $res[0]->option_id : null;
  if($id) {
    $sql = <<<SQL
      REPLACE INTO {$wp_options} (option_id, option_name, option_value, autoload)
      VALUES (%s, "redirects", %s, "no")
    SQL;
    $wpdb->query($wpdb->prepare($sql, $id, $redirects));
  } else {
    $sql = <<<SQL
      INSERT INTO {$wp_options} (option_name, option_value, autoload)
      VALUES ("redirects", %s, "no")
    SQL;
    $wpdb->query($wpdb->prepare($sql, $redirects));
  }
  return $result;
}

function redirect_page_content() {
  $filePath = get_redirects_file_path();
  if($_POST && count($_POST) > 0) {
    $redirects = [];
    $pair = [];
    foreach($_POST as $i => $path) {
      if(strpos($i, 'fromPath') === 0) { $pair['fromPath'] = $_POST[$i]; }
      if(strpos($i, 'toPath') === 0) {
        $pair['toPath'] = $_POST[$i];
        $redirects[] = $pair;
      }
    }
    $fp = fopen($filePath, 'w');
    $json = json_encode($redirects, JSON_UNESCAPED_SLASHES);
    fwrite($fp, $json);
    fclose($fp);
    save_db_backup($json);
    $webhook = Settings::prefix_get_option( 'builds_api_webhook', 'wpgatsby_settings', false );
    $args = apply_filters( 'gatsby_trigger_dispatch_args', [], $webhook );
    wp_safe_remote_post(
      $webhook,
      ['headers' => [
          'Content-Type'  => 'application/json',
          'User-Agent' => 'CMS'
      ]]
    );
  }

  $string = file_get_contents($filePath);
  $json = json_decode($string, true);
  ?>
      <div>
          <h1>
              Manage Redirects
          </h1>
          <div class="redirect-wrapper">
              <button class="add" id="addButton">Add new redirect</button>
              <form method="post">
                  <input type="submit" value="Save Redirects"/>
                  <ul id="redirectList">
                      <li class="redirect-header"><h3>FROM</h3><h3 class="to">TO</h3></li>
                      <?php
                          foreach($json as $i => $link) {
                              ?>
                              <li class="redirect">
                                  <input value="<?php echo $link["fromPath"]?>" name="fromPath-<?php echo $i ?>"><input value="<?php echo $link["toPath"]?>" name="toPath-<?php echo $i ?>">
                                  <span class="remove">❌</span>
                              </li>
                              <?php
                          }
                      ?>
                  </ul>
              </form>
          </div>
      </div>
  <?php
}

function get_redirects_file_path() {
  return ABSPATH."/redirects.json";
}

function load_custom_wp_admin_scripts($hook) {
  if( $hook != 'toplevel_page_redirects' ) {
    return;
  }
  wp_enqueue_style( 'custom_wp_admin_css', plugin_dir_url( __FILE__ ). '/redirects.css');
  wp_enqueue_script( 'redirects', plugin_dir_url( __FILE__ ). '/redirects.js', array( 'jquery' ) );
}
add_action( 'admin_enqueue_scripts', 'load_custom_wp_admin_scripts' );

function add_menu_item() {
  add_menu_page(
    'Redirects',
    'Redirects',
    'edit_theme_options',
    'redirects.php',
    'redirect_page_content',
    'dashicons-links',
    69
  );
}
add_action('admin_menu', 'add_menu_item');