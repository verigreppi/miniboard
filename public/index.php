<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$app->get('/{board_id}/', function (Request $request, Response $response, array $args) {
  // validate get
  $validated_get = validate_get($args);
  if (isset($validated_get['error'])) {
    $response->getBody()->write('Error: ' . $validated_get['error']);
    $response = $response->withStatus(500);
    return $response;
  }

  // get board config
  $board_cfg = MB_BOARDS[$args['board_id']];

  // get threads
  $threads = select_posts($args['board_id'], 0, true, 0, 10);
  
  // get replies
  foreach ($threads as $key => $thread) {
    $threads[$key]['replies'] = select_posts($args['board_id'], $thread['id'], false, 0, 4);
  }

  $renderer = new PhpRenderer('templates/', [
    'board' => $board_cfg,
    'threads' => $threads
  ]);
  return $renderer->render($response, 'board.phtml');
});

$app->get('/{board_id}/{thread_id}/', function (Request $request, Response $response, array $args) {
  // validate get
  $validated_get = validate_get($args);
  if (isset($validated_get['error'])) {
    $response->getBody()->write('Error: ' . $validated_get['error']);
    $response = $response->withStatus(500);
    return $response;
  }

  // get board config
  $board_cfg = MB_BOARDS[$args['board_id']];

  // get thread
  $thread = select_post($args['board_id'], $args['thread_id']);
  
  // get replies
  $replies = select_posts($args['board_id'], $args['thread_id'], false, 0, 1000);

  $renderer = new PhpRenderer('templates/', [
    'board' => $board_cfg,
    'thread' => $thread,
    'replies' => $replies
  ]);
  return $renderer->render($response, 'thread.phtml');
});

$app->post('/{board_id}/', function(Request $request, Response $response, array $args) {
  return handle_postform($request, $response, $args);
});

$app->post('/{board_id}/{thread_id}/', function(Request $request, Response $response, array $args) {
  return handle_postform($request, $response, $args);
});

function handle_postform(Request $request, Response $response, array $args) : Response {
  // parse request body
  $params = (array) $request->getParsedBody();
  $file = $request->getUploadedFiles()['file'];

  // validate post
  $validated_post = validate_post($args, $params);
  if (isset($validated_post['error'])) {
    $response->getBody()->write('Post validation error: ' . $validated_post['error']);
    $response = $response->withStatus(500);
    return $response;
  }

  // upload file
  $uploaded_file = upload_file($file);
  if (isset($uploaded_file['error'])) {
    $response->getBody()->write('File upload error: ' . $uploaded_file['error']);
    $response = $response->withStatus(500);
    return $response;
  }

  // create post
  $created_post = create_post($args, $params, $uploaded_file);

  // insert post
  $inserted_post_id = insert_post($created_post);

  // bump thread
  $bumped_thread = bump_thread($created_post['board'], $created_post['parent']);

  $response->getBody()->write('form keys: ' . implode(',', array_keys($params)) . '<br>');
  $response->getBody()->write('file keys: ' . implode(',', array_keys($uploaded_file)) . '<br>');
  $response->getBody()->write('post keys: ' . implode(',', array_keys($created_post)) . '<br>');
  $response->getBody()->write('inserted post: ' . $inserted_post_id);
  return $response;
}

$app->run();
