<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/funcs_common.php';
require_once __DIR__ . '/funcs_file.php';
require_once __DIR__ . '/funcs_post.php';
require_once __DIR__ . '/funcs_report.php';
require_once __DIR__ . '/funcs_hide.php';
require_once __DIR__ . '/funcs_manage.php';

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->add(new Middlewares\TrailingSlash(true));
$app->add($session_middleware);

$app->get('/', function (Request $request, Response $response, array $args) {
  $response = $response
    ->withHeader('Location', '/' . MB_BOARDS[array_key_first(MB_BOARDS)]['id'] . '/')
    ->withStatus(303);
  return $response;
});

$app->get('/manage/', function (Request $request, Response $response, array $args) {
  if (!funcs_manage_is_logged_in()) {
    $renderer = new PhpRenderer('templates/', []);
    return $renderer->render($response, 'login.phtml');
  } else {
    // get query params
    $query_params = $request->getQueryParams();
    $query_route = funcs_common_parse_input_str($query_params, 'route', '');
    $query_status = funcs_common_parse_input_str($query_params, 'status', '');
    $account_details = funcs_manage_list_account_details();

    // render page
    $renderer = new PhpRenderer('templates/', [
      'route' => $query_route,
      'status' => $query_status,
      'account_details' => $account_details
    ]);
    return $renderer->render($response, 'manage.phtml');
  }
});

$app->get('/manage/logout/', function (Request $request, Response $response, array $args) {
  $success = funcs_manage_logout();
  if (!$success) {
    throw new ApiException("logout failed to clear PHP session", SC_INTERNAL_ERROR);
  }

  $response = $response
    ->withHeader('Location', '/')
    ->withStatus(303);
  return $response;
});

$app->post('/manage/login/', function (Request $request, Response $response, array $args) {
  return handle_loginform($request, $response, $args);
});

function handle_loginform(Request $request, Response $response, array $args): Response {
  // parse request body
  $params = (array) $request->getParsedBody();

  // validate captcha
  if (MB_CAPTCHA_LOGIN) {
    funcs_common_validate_captcha($params);
  }

  // validate request fields
  funcs_common_validate_fields($params, [
    'username'  => ['required' => true, 'type' => 'string', 'min_len' => 2, 'max_len' => 75],
    'password'  => ['required' => true, 'type' => 'string', 'min_len' => 2, 'max_len' => 256]
  ]);

  // get account
  $account = select_account($params['username']);
  if ($account == null) {
    throw new ApiException("invalid username or password for user '{$params['username']}'", SC_NOT_FOUND);
  }

  // attempt to login
  $login = funcs_manage_login($account, $params['password']);
  if ($login !== true) {
    throw new ApiException("invalid username or password for user '{$params['username']}'", SC_NOT_FOUND);
  }

  // update lastactive
  $account['lastactive'] = time();
  if (update_account($account) !== TRUE) {
    throw new ApiException("failed to update account info for user {$params['username']}", SC_INTERNAL_ERROR);
  }

  $response = $response
    ->withHeader('Location', '/manage/')
    ->withStatus(303);
  return $response;
}

$app->post('/manage/import/', function (Request $request, Response $response, array $args) {
  if (!funcs_manage_is_logged_in()) {
    throw new ApiException('access denied', SC_BAD_REQUEST);
  }

  if ($_SESSION['mb_role'] !== MB_ROLE_SUPERADMIN) {
    throw new ApiException('insufficient permissions', SC_BAD_REQUEST);
  }

  return handle_importform($request, $response, $args);
});

function handle_importform(Request $request, Response $response, array $args): Response {
  // parse request body
  $params = (array) $request->getParsedBody();

  // validate request fields
  funcs_common_validate_fields($params, [
    'db_name'    => ['required' => true, 'type' => 'string'],
    'db_user'    => ['required' => true, 'type' => 'string'],
    'db_pass'    => ['required' => true, 'type' => 'string'],
    'table_name' => ['required' => true, 'type' => 'string'],
    'table_type' => ['required' => true, 'type' => 'string'],
    'board_id'   => ['required' => true, 'type' => 'string']
  ]);

  // execute import
  $status = funcs_manage_import($params);

  $response = $response
    ->withHeader('Location', "/manage/?route=import&status={$status}")
    ->withStatus(303);
  return $response;
}

$app->post('/manage/rebuild/', function (Request $request, Response $response, array $args) {
  if (!funcs_manage_is_logged_in()) {
    throw new ApiException('access denied', SC_BAD_REQUEST);
  }

  if ($_SESSION['mb_role'] !== MB_ROLE_SUPERADMIN) {
    throw new ApiException('insufficient permissions', SC_BAD_REQUEST);
  }

  return handle_rebuildform($request, $response, $args);
});

function handle_rebuildform(Request $request, Response $response, array $args): Response {
  // parse request body
  $params = (array) $request->getParsedBody();

  // validate request fields
  funcs_common_validate_fields($params, [
    'board_id'   => ['required' => true, 'type' => 'string']
  ]);

  // execute import
  $status = funcs_manage_rebuild($params);

  $response = $response
    ->withHeader('Location', "/manage/?route=rebuild&status={$status}")
    ->withStatus(303);
  return $response;
}

$app->get('/{board_id}/{post_id}/report/', function (Request $request, Response $response, array $args) {
  // get board config
  $board_cfg = funcs_common_get_board_cfg($args['board_id']);

  // get post
  $post = select_post($board_cfg['id'], $args['post_id']);
  if ($post == null) {
    throw new ApiException("post with ID /{$board_cfg['id']}/{$args['post_id']} not found", SC_NOT_FOUND);
  }

  $renderer = new PhpRenderer('templates/', [
    'board' => $board_cfg,
    'post' => $post
  ]);
  return $renderer->render($response, 'report.phtml');
});

$app->post('/{board_id}/{post_id}/hide/', function (Request $request, Response $response, array $args) {
  // parse request body
  $params = (array) $request->getParsedBody();

  // get board config
  $board_cfg = funcs_common_get_board_cfg($args['board_id']);

  // toggle hide
  $hide = select_hide(session_id(), $board_cfg['id'], $args['post_id']);
  if ($hide == null) {
    $hide = funcs_hide_create(session_id(), $board_cfg['id'], $args['post_id']);
    insert_hide($hide);
  } else {
    delete_hide($hide);
  }

  $response = $response->withStatus(200);
  return $response;
});

$app->post('/{board_id}/{post_id}/report/', function (Request $request, Response $response, array $args) {
  return handle_reportform($request, $response, $args);
});

function handle_reportform(Request $request, Response $response, array $args): Response {
  // parse request body
  $params = (array) $request->getParsedBody();

  // get board config
  $board_cfg = funcs_common_get_board_cfg($args['board_id']);

  // validate captcha
  if (MB_CAPTCHA_REPORT) {
    funcs_common_validate_captcha($params);
  }

  // validate request fields
  funcs_report_validate_fields($params, MB_REPORT_TYPES);

  // get post
  $post = select_post($board_cfg['id'], $args['post_id']);
  if ($post == null) {
    throw new ApiException("post with ID /{$board_cfg['id']}/{$args['post_id']} not found", SC_NOT_FOUND);
  }

  // create report
  $ip = funcs_common_get_client_remote_address(MB_CLOUDFLARE, $_SERVER);
  $report = funcs_report_create($ip, $board_cfg['id'], $post['post_id'], $params['type'], MB_REPORT_TYPES);

  // insert report
  $inserted_report_id = insert_report($report);

  $response->getBody()->write('Post reported');
  $response = $response->withStatus(200);
  return $response;
}

$app->get('/{board_id}/', function (Request $request, Response $response, array $args) {
  // get board config
  $board_cfg = funcs_common_get_board_cfg($args['board_id']);
  $board_query_id = $board_cfg['type'] !== 'main' ? $board_cfg['id'] : null;
  $board_threads_per_page = $board_cfg['threads_per_page'];
  $board_posts_per_preview = $board_cfg['posts_per_preview'];

  // get query params
  $query_params = $request->getQueryParams();
  $query_page = funcs_common_parse_input_int($query_params, 'page', 0, 0, 1000);

  // get threads
  $threads = select_posts(session_id(), $board_query_id, 0, true, $board_threads_per_page * $query_page, $board_threads_per_page, false);

  // get replies
  foreach ($threads as $key => $thread) {
    $threads[$key]['replies'] = select_posts_preview(session_id(), $thread['board_id'], $thread['post_id'], 0, $board_posts_per_preview);
    $threads[$key]['replies_n'] = count_posts(session_id(), $thread['board_id'], $thread['post_id'], false, false);
  }

  // get thread count
  $threads_n = count_posts(session_id(), $board_query_id, 0, false);

  $renderer = new PhpRenderer('templates/', [
    'board' => $board_cfg,
    'threads' => $threads,
    'page' => $query_page,
    'page_n' => ceil($threads_n / $board_threads_per_page)
  ]);
  return $renderer->render($response, 'board.phtml');
});

$app->get('/{board_id}/hidden/', function (Request $request, Response $response, array $args) {
  // get board config
  $board_cfg = funcs_common_get_board_cfg($args['board_id']);
  $board_query_id = $board_cfg['type'] !== 'main' ? $board_cfg['id'] : null;
  $board_threads_per_page = $board_cfg['threads_per_page'];
  $board_posts_per_preview = $board_cfg['posts_per_preview'];

  // get query params
  $query_params = $request->getQueryParams();
  $query_page = funcs_common_parse_input_int($query_params, 'page', 0, 0, 1000);

  // get threads
  $threads = select_posts(session_id(), $board_query_id, 0, true, $board_threads_per_page * $query_page, $board_threads_per_page, true);

  // do not show replies for hidden threads
  foreach ($threads as $key => $thread) {
    $threads[$key]['replies'] = [];
    $threads[$key]['replies_n'] = count_posts(session_id(), $thread['board_id'], $thread['post_id'], false, false);
  }

  // get thread count
  $threads_n = count_posts(session_id(), $board_query_id, 0, true);

  $renderer = new PhpRenderer('templates/', [
    'board' => $board_cfg,
    'threads' => $threads,
    'page' => $query_page,
    'page_n' => ceil($threads_n / $board_threads_per_page)
  ]);
  return $renderer->render($response, 'hidden.phtml');
});

$app->get('/{board_id}/catalog/', function (Request $request, Response $response, array $args) {
  // get board config
  $board_cfg = funcs_common_get_board_cfg($args['board_id']);
  $board_query_id = $board_cfg['type'] !== 'main' ? $board_cfg['id'] : null;
  $board_threads_per_catalog_page = $board_cfg['threads_per_catalog_page'];

  // get query params
  $query_params = $request->getQueryParams();
  $query_page = funcs_common_parse_input_int($query_params, 'page', 0, 0, 1000);

  // get threads
  $threads = select_posts(session_id(), $board_query_id, 0, true, $board_threads_per_catalog_page * $query_page, $board_threads_per_catalog_page, false);

  // get thread reply counts
  foreach ($threads as $key => $thread) {
    $reply_count = count_posts(session_id(), $thread['board_id'], $thread['post_id'], false);
    if (is_int($reply_count)) {
      $threads[$key]['reply_count'] = $reply_count;
    }
  }

  // get thread count
  $threads_n = count_posts(session_id(), $board_query_id, 0, false);

  $renderer = new PhpRenderer('templates/', [
    'board' => $board_cfg,
    'threads' => $threads,
    'page' => $query_page,
    'page_n' => ceil($threads_n / $board_threads_per_catalog_page)
  ]);
  return $renderer->render($response, 'catalog.phtml');
});

$app->get('/{board_id}/{thread_id}/', function (Request $request, Response $response, array $args) {
  // get board config
  $board_cfg = funcs_common_get_board_cfg($args['board_id']);

  // get thread
  $thread = select_post($board_cfg['id'], $args['thread_id']);
  if ($thread == null) {
    throw new ApiException("thread with ID /{$board_cfg['id']}/{$args['thread_id']} not found", SC_NOT_FOUND);
  } else if ($thread['parent_id'] !== 0) {
    throw new ApiException('not a valid thread', SC_BAD_REQUEST);
  }

  // get replies
  $thread['replies'] = select_posts(session_id(), $thread['board_id'], $thread['post_id'], false, 0, 1000, false);

  $renderer = new PhpRenderer('templates/', [
    'board' => $board_cfg,
    'thread' => $thread
  ]);
  return $renderer->render($response, 'thread.phtml');
});

$app->get('/{board_id}/{thread_id}/{post_id}/', function (Request $request, Response $response, array $args) {
  // get board config
  $board_cfg = funcs_common_get_board_cfg($args['board_id']);

  // get post
  $post = select_post($board_cfg['id'], $args['post_id']);
  if ($post == null || ($post['parent_id'] !== 0 && $post['parent_id'] != $args['thread_id'])) {
    throw new ApiException("post with ID /{$board_cfg['id']}/{$args['thread_id']}/{$args['post_id']} not found", SC_NOT_FOUND);
  }
  $post['replies'] = [];

  $renderer = new PhpRenderer('templates/', [
    'post' => $post
  ]);
  return $renderer->render($response, $post['parent_id'] === 0 ? 'board/post_preview_op.phtml' : 'board/post_preview_reply.phtml');
});

$app->post('/{board_id}/', function (Request $request, Response $response, array $args) {
  return handle_postform($request, $response, $args, 'board');
});

$app->post('/{board_id}/delete/', function (Request $request, Response $response, array $args) {
  return handle_deleteform($request, $response, $args);
});

function handle_deleteform(Request $request, Response $response, array $args): Response {
  // parse request body
  $params = (array) $request->getParsedBody();

  // get board config
  $board_cfg = funcs_common_get_board_cfg($args['board_id']);

  // validate request fields
  funcs_common_validate_fields($params, [
    'password'  => ['required' => true, 'type' => 'string', 'max_len' => 64],
    'delete'    => ['required' => true, 'type' => 'array']
  ]);

  // loop over each post, check pass and mark as deleted
  foreach ($params['delete'] as $val) {
    // parse board id and post id
    $delete_parsed = explode('/', $val);
    $delete_board_id = $delete_parsed[0];
    $delete_post_id = intval($delete_parsed[1]);

    // get post
    $post = select_post($delete_board_id, $delete_post_id);
    if ($post == null || $post['password'] == null) {
      continue;
    }

    // verify password
    if (funcs_common_verify_password($params['password'], $post['password']) !== true) {
      continue;
    }

    // delete post
    if (!delete_post($delete_board_id, $delete_post_id, false)) {
      throw new ApiException("failed to delete post with ID /{$delete_board_id}/{$delete_post_id}", SC_INTERNAL_ERROR);
    }
  }

  $response = $response
    ->withHeader('Location', '/' . $board_cfg['id'] . '/')
    ->withStatus(303);
  return $response;
}

$app->post('/{board_id}/{thread_id}/', function (Request $request, Response $response, array $args) {
  return handle_postform($request, $response, $args, 'thread');
});

function handle_postform(Request $request, Response $response, array $args, string $context): Response {
  // parse request body
  $params = (array) $request->getParsedBody();
  $file = $request->getUploadedFiles()['file'];

  // get board config
  $board_cfg = funcs_common_get_board_cfg($args['board_id']);

  // validate captcha
  if (MB_CAPTCHA_THREAD && $context === 'board' || MB_CAPTCHA_REPLY && $context === 'thread') {
    funcs_common_validate_captcha($params);
  }

  // validate request fields
  funcs_common_validate_fields($params, $board_cfg['fields_post']);

  // if board type is 'main', get target board config
  if ($board_cfg['type'] === 'main') {
    // get board config
    $board_cfg = funcs_common_get_board_cfg($params['board']);

    // do not allow posting directly on boards of type 'main'
    if ($board_cfg['type'] === 'main') {
      throw new ApiException("posting on /{$board_cfg['id']}/ board is disabled", SC_BAD_REQUEST);
    }

    // validate request fields
    funcs_common_validate_fields($params, $board_cfg['fields_post']);
  }

  // get thread if replying
  $thread_id = null;
  if (isset($args['thread_id'])) {
    $parent = select_post($board_cfg['id'], $args['thread_id']);
    if ($parent != null && $parent['parent_id'] > 0) {
      throw new ApiException("thread with ID /{$board_cfg['id']}/{$args['thread_id']} not found", SC_NOT_FOUND);
    } else if ($parent != null) {
      $thread_id = $parent['post_id'];
    }
  }

  // validate request file
  $no_file_ok = $thread_id != null ? true : $board_cfg['nofileok'];
  $file_info = funcs_file_validate_upload($file, $no_file_ok, $board_cfg['mime_ext_types'], $board_cfg['maxkb'] * 1000);

  // validate request message + file
  if (strlen(trim($params['message'])) === 0 && $file_info == null) {
    throw new ApiException('message and file cannot both be null', SC_BAD_REQUEST);
  }

  // check md5 file collisions
  $file_collisions = [];
  if ($file_info != null) {
    $file_collisions = select_files_by_md5($file_info['md5']);
  }

  // upload file
  $file = funcs_file_execute_upload($file, $file_info, $file_collisions, $board_cfg['max_width'], $board_cfg['max_height']);

  // create post
  $ip = funcs_common_get_client_remote_address(MB_CLOUDFLARE, $_SERVER);
  $post = funcs_post_create($ip, $board_cfg, $thread_id, $file_info, $file, $params);

  // insert post
  $inserted_post_id = insert_post($post);

  // bump thread
  $thread_bumped = false;
  if ($post['parent_id'] !== 0 && strtolower($post['email']) !== 'sage') {
    $thread_bumped = bump_thread($post['board_id'], $post['parent_id']);
  }

  // handle noko
  $location_header = '/' . $board_cfg['id'] . '/';
  if (strtolower($post['email']) === 'noko' || $board_cfg['alwaysnoko']) {
    $location_header .= ($post['parent_id'] === 0 ? $inserted_post_id : $post['parent_id']) . '/#' . $post['board_id'] . '-' . $inserted_post_id;
  }

  $response = $response
    ->withHeader('Location', $location_header)
    ->withStatus(303);
  return $response;
}

/**
 * Handle exceptions accordingly, produce sensible error page as a response.
 */
$error_handler = function(
  Request $request,
  Throwable $exception,
  bool $display_error_details,
  bool $log_errors,
  bool $log_error_details,
  ?LoggerInterface $logger = null
) use ($app) {
  if ($logger != null) {
    $logger->error($exception->getMessage());
  }

  // Handle 404 error page
  if ($exception instanceof Slim\Exception\HttpNotFoundException) {
    $response = $app->getResponseFactory()->createResponse(SC_NOT_FOUND);
    $renderer = new PhpRenderer('templates/', [
      'board' => MB_BOARDS[array_key_first(MB_BOARDS)],
      'error_type' => '404',
      'error_message' => 'Not Found'
    ]);
    return $renderer->render($response, 'error.phtml');
  }

  // Handle custom exceptions
  if ($exception instanceof ApiException || $exception instanceof FuncException || $exception instanceof DbException) {
    $response = $app->getResponseFactory()->createResponse($exception->getCode());
    $renderer = new PhpRenderer('templates/', [
      'board' => MB_BOARDS[array_key_first(MB_BOARDS)],
      'error_type' => $exception->getCode() === SC_NOT_FOUND ? '404' : 'Error',
      'error_message' => $exception->getMessage()
    ]);
    return $renderer->render($response, 'error.phtml');
  }
  
  // Handle all other exceptions
  $response = $app->getResponseFactory()->createResponse(SC_INTERNAL_ERROR);
  $renderer = new PhpRenderer('templates/', [
    'board' => MB_BOARDS[array_key_first(MB_BOARDS)],
    'error_type' => 'Critical Error',
    'error_message' => $exception->getMessage()
  ]);
  return $renderer->render($response, 'error.phtml');
};

if (MB_ENV === 'dev') {
  $app->addErrorMiddleware(true, true, true)
    ->setDefaultErrorHandler($error_handler);
} else {
  $app->addErrorMiddleware(false, false, false)
    ->setDefaultErrorHandler($error_handler);
}

$app->run();
