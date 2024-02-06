<?php

use WPGatsby\Admin\Settings;

/**
 
 * @package Swace

 */

/*
 
Plugin Name: Swace Gatsby Redirects
 
Plugin URI: https://swace.se
 
Description: A plugin for adding redirects that get picked up by gatsby on build.
 
Version: 1.0.5
 
Author: Adam Elvander, Johan Isaksson
 
License: GPLv2 or later
 
Text Domain: swace
 
*/

function get_options_table()
{
  global $wpdb;
  return $wpdb->prefix . "options";
}

function save_db_backup($redirects)
{
  global $wpdb;
  $wp_options = get_options_table();
  $query = $wpdb->prepare("SELECT option_id FROM {$wp_options} WHERE option_name = %s", array( 'redirects' ));
  $res = $wpdb->get_results($query);
  $id = isset($res[0]) && isset($res[0]->option_id) ? $res[0]->option_id : null;
  if ($id) {
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
  return $res;
}

/**
 * Used to programmatically add a redirect
 */
function add_redirect($fromPath, $toPath) 
{
  $filePath = get_redirects_file_path();
  $string = file_get_contents($filePath);
  $json = json_decode($string, true);

  $faulty = is_faulty_redirect($fromPath, $toPath, $json);
  if ($faulty) {
    return false;
  }

  $pair = array(
    'fromPath' => $fromPath,
    'toPath' => $toPath
  );
  
  $json[] = $pair;
  
  $fp = fopen($filePath, 'w');
  fwrite($fp, json_encode($json, JSON_UNESCAPED_SLASHES));
  fclose($fp);
  
  save_db_backup($json);
  
  return true;
}

function redirect_page_content()
{
  $error_text = "";
  $filePath = get_redirects_file_path();
  if ($_POST && count($_POST) > 0) {
    $redirects = [];
    $pair = [];
    foreach ($_POST as $i => $path) {
      if (strpos($i, 'fromPath') === 0) {
        $pair['fromPath'] = $_POST[$i];
      }
      if (strpos($i, 'toPath') === 0) {
        $pair['toPath'] = $_POST[$i];
        $error = is_faulty_redirect($pair['fromPath'], $pair['toPath'], $redirects);
        if ($error) {
          $error_text .= $error;
          continue;
        }
        $redirects[] = $pair;
      }
    }
    $fp = fopen($filePath, 'w');
    $json = json_encode($redirects, JSON_UNESCAPED_SLASHES);
    fwrite($fp, $json);
    fclose($fp);
    save_db_backup($json);
    $webhook = Settings::prefix_get_option('builds_api_webhook', 'wpgatsby_settings', false);
    $args = apply_filters('gatsby_trigger_dispatch_args', [], $webhook);
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

  if ($_FILES && $_FILES["redirect-file-input"] && $_FILES["redirect-file-input"]['size']  && $_FILES['redirect-file-input']['error'] == 0) {
    $redirect_file = $_FILES["redirect-file-input"];
    if ($redirect_file) {
      $tmpName = $redirect_file['tmp_name'];
      $csvArray = array_map('str_getcsv', file($tmpName));
  
  
      if($csvArray) {
        foreach ($csvArray as $i => $csvRow) {
          $valuePair = explode(";", $csvRow[0]);
          $fromPath = $valuePair[0];
          $toPath = $valuePair[1];
    
          $error = is_faulty_redirect($fromPath, $toPath, $json);
    
          if ($error) {
            $error_text .= $error;
            continue;
          }
    
          $validPair = array(
            'fromPath'  => $fromPath,
            'toPath'    => $toPath,
            'fromFile'  => true
          );
          array_push($json, $validPair);
        }
      }
    }
  }

  ?>
  <div>
    <form method="post" enctype="multipart/form-data">
      <div class="form-header">
        <h1>
          Manage Redirects
        </h1>
        <input type="submit" value="Save" />
      </div>
      <div class="redirect-wrapper">
        <div class="controls-wrapper">
          <button type="button" class="add" id="addButton">Add new redirect</button>
          <input type="hidden" name="MAX_FILE_SIZE" value="30000" />
          <div class="file-upload-wrapper">
            <label for="redirect-file-input">Upload csv</label>
            <input type="file" name="redirect-file-input" id="redirect-file-input" accept=".csv">
          </div>
        </div>
        <p class="redirect-info"><span>Redirects imported by file</span> must be submitted again in order get stored correctly</p>
        <ul id="redirectList">
          <li class="redirect-header">
            <h3>FROM</h3>
            <h3 class="to">TO</h3>
          </li>
          <?php
            foreach ($json as $i => $link) {
              ?>
            <li class="redirect <?= !empty($link['fromFile']) ? "imported" : "" ?>">
              <input value="<?php echo $link["fromPath"] ?>" name="fromPath-<?php echo $i ?>"><input value="<?php echo $link["toPath"] ?>" name="toPath-<?php echo $i ?>">
              <span class="remove">❌</span>
            </li>
          <?php
            }
            ?>
        </ul>
      </div>
    </form>
  </div>
  <p><?php echo $error_text ?></p>
<?php
}


function get_redirects_file_path()
{
  return ABSPATH . "/redirects.json";
}

function is_faulty_redirect($fromPath, $toPath, $redirects)
{
  if ((empty($fromPath) || empty($toPath))) {
    return ("<br><br>Missing value in pair - from: " . $fromPath . " to: " . $toPath);
  }

  if ($fromPath === $toPath) {
    return ("<br><br>Potential loop - Pair: " . $fromPath . " to: " . $toPath . " attempts to redirect to same page");
  }

  foreach ($redirects as $redirect) {
    if ($redirect['fromPath'] === $fromPath) {
      return ("<br><br>Pair with from: " . $fromPath . " to: " . $toPath . " already has redirect to " . $redirect['toPath']);
    }

    if ($redirect['fromPath'] === $toPath && $redirect['toPath'] === $fromPath) {
      return ("<br><br>Potential loop - Pair with from: " . $fromPath . " to: " . $toPath . " already has redirect in opposite direction");
    }
  }

  return "";
}

function load_redirects_wp_admin_scripts($hook)
{
  if ($hook != 'toplevel_page_redirects') {
    return;
  }
  wp_enqueue_style('redirects_wp_admin_css', plugin_dir_url(__FILE__) . '/redirects.css');
  wp_enqueue_script('redirects', plugin_dir_url(__FILE__) . '/redirects.js', array('jquery'));
}
add_action('admin_enqueue_scripts', 'load_redirects_wp_admin_scripts');

function add_redirects_menu_item()
{
  add_menu_page(
    'Redirects',
    'Redirects',
    'edit_theme_options',
    'redirects.php',
    'redirect_page_content',
    'dashicons-admin-links',
    69
  );
}
add_action('admin_menu', 'add_redirects_menu_item');
