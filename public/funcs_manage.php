<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/exception.php';
require_once __DIR__ . '/funcs_common.php';

/**
 * Executes user login credential verification and on success assigns session variables.
 */
function funcs_manage_login(array $account, string $password): bool {
  // reject if passwords do not match
  if (funcs_common_verify_password($password, $account['password']) !== TRUE) {
    return false;
  }

  // set session variables
  $_SESSION['mb_username'] = $account['username'];
  $_SESSION['mb_role'] = $account['role'];

  return true;
}

/**
 * Verifies user session variables and returns true if user is logged in.
 */
function funcs_manage_is_logged_in(): bool {
  return isset($_SESSION['mb_username']) && isset($_SESSION['mb_role']);
}

/**
 * Destroys user session variables.
 */
function funcs_manage_logout(): bool {
  // destroy session variables and return success code
  return session_unset();
}

/**
 * Imports data from another MySQL/MariaDB database.
 */
function funcs_manage_import(array $params): string {
  $inserted = 0;

  // handle each table type separately
  switch ($params['table_type']) {
    case MB_IMPORT_TINYIB_ACCOUNTS:
      // execute import
      $inserted = insert_import_accounts_tinyib($params, $params['table_name']);
      break;
    case MB_IMPORT_TINYIB_POSTS:
      // validate params
      if (!array_key_exists($params['board_id'], MB_BOARDS)) {
        return "Target BOARD id '{$params['board_id']}' not found";
      }

      // execute import
      $inserted = insert_import_posts_tinyib($params, $params['table_name'], $params['board_id']);
      break;
    default:
      return "Unsupported table_type '{$params['table_type']}'";
  }

  return "Inserted {$inserted} rows from target database '{$params['db_name']}' table '{$params['table_name']}' successfully";
}

/**
 * Rebuilds all data in the database.
 */
function funcs_manage_rebuild(array $params): string {
  // get board config
  $board_cfg = funcs_common_get_board_cfg($params['board_id']);

  // select posts
  $posts = select_rebuild_posts($params['board_id']);

  // rebuild each post
  $processed = 0;
  $total = count($posts);
  foreach ($posts as &$post) {
    // process fields
    $name = $post['name'] !== '' ? $post['name'] : $board_cfg['anonymous'];
    $email = $post['email'];
    $message = $post['message'];
    
    // do extra cleanup for imported data because of raw HTML
    if ($post['imported']) {
      $name = funcs_common_clean_field($name);
      $email = funcs_common_clean_field($email);
      $message = strip_tags($message);
      $message = htmlspecialchars_decode($message, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401);
    }

    // render nameblock and message
    $nameblock = funcs_post_render_nameblock($name, $post['tripcode'], $email, $post['role'], $post['timestamp']);
    $message = funcs_post_render_message($params['board_id'], $message, $board_cfg['truncate']);

    // update post
    $rebuild_post = [
      'post_id' => $post['post_id'],
      'board_id' => $post['board_id'],
      'message_rendered' => $message['rendered'],
      'message_truncated' => $message['truncated'],
      'nameblock' => $nameblock
    ];
    if (!update_rebuild_post($rebuild_post)) {
      return "Failed to rebuild post /{$post['board_id']}/{$post['post_id']}/, processed {$processed}/{$total}";
    }

    $processed++;
  }

  return "Rebuilt all posts on board /{$board_cfg['id']}/, processed {$processed}/{$total}";
}

/**
 * Returns user details which user is allowed to view.
 */
function funcs_manage_list_account_details(): array {
  $account_details = [];
  if (funcs_common_get_role() == MB_ROLE_SUPERADMIN) {
    $account_details = list_account_details();
  }
  return $account_details;
}