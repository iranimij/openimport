<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

// phpcs:ignoreFile -- router for PHP's built-in web server, started by the REST API integration tests

declare(strict_types=1);

/*
 * A small REST API with five records ("P-1" … "P-5") behind every kind of paging, three kinds of login, a token
 * endpoint (client credentials) and an endpoint that receives exports. State (counters, what was received) lives in
 * the JSON file named by OPENIMPORT_REST_STATE.
 *
 * Login: header "X-Api-Key: key-1", Basic "user:pass", or Bearer "cc-token" from POST /token (client "cid"/"csecret").
 */

$stateFile = (string) getenv('OPENIMPORT_REST_STATE');
$state = json_decode((string) file_get_contents($stateFile), true) ?: [];
$state += ['received' => [], 'slow' => 0, 'requests' => []];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$state['requests'][] = $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'];
$base = 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'];
$records = [];
for ($i = 1; $i <= 5; $i++) {
    $records[] = ['sku' => 'P-' . $i, 'name' => 'Product ' . $i, 'stock' => ['qty' => $i * 10]];
}

function answer(int $status, $data, array $headers = []): void
{
    global $state, $stateFile;
    file_put_contents($stateFile, json_encode($state));
    header('Content-Type: application/json');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($path === '/token') {
    parse_str((string) file_get_contents('php://input'), $form);
    if (($form['grant_type'] ?? '') === 'client_credentials' && ($form['client_id'] ?? '') === 'cid'
        && ($form['client_secret'] ?? '') === 'csecret'
    ) {
        answer(200, ['access_token' => 'cc-token', 'expires_in' => 3600, 'token_type' => 'Bearer']);
    }
    answer(401, ['error' => 'invalid_client']);
}

$authorized = ($_SERVER['HTTP_X_API_KEY'] ?? '') === 'key-1'
    || ($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Basic ' . base64_encode('user:pass')
    || ($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Bearer cc-token';
if (!$authorized) {
    answer(401, ['message' => 'Unauthorized']);
}

$page = static fn (int $offset, int $size): array => array_slice($records, $offset, $size);
switch ($path) {
    case '/':
    case '/v1':
        answer(200, ['name' => 'Test API']);
    case '/v1/products':
        $number = max(1, (int) ($_GET['page'] ?? 1));
        $size = (int) ($_GET['per_page'] ?? 2);
        answer(200, ['data' => ['items' => $page(($number - 1) * $size, $size)], 'status' => $_GET['status'] ?? '']);
    case '/v1/offset':
        answer(200, $page((int) ($_GET['offset'] ?? 0), (int) ($_GET['limit'] ?? 2)));
    case '/v1/cursor':
        $offset = (int) ($_GET['cursor'] ?? 0);
        answer(200, ['results' => $page($offset, 2), 'meta' => ['next_cursor' => $offset + 2 < 5 ? (string) ($offset + 2) : null]]);
    case '/v1/next':
        $offset = (int) ($_GET['p'] ?? 0);
        answer(200, ['items' => $page($offset, 2), 'links' => ['next' => $offset + 2 < 5 ? $base . '/v1/next?p=' . ($offset + 2) : null]]);
    case '/v1/link':
        $offset = (int) ($_GET['p'] ?? 0);
        $headers = $offset + 2 < 5 ? ['Link' => '<' . $base . '/v1/link?p=' . ($offset + 2) . '>; rel="next"'] : [];
        answer(200, $page($offset, 2), $headers);
    case '/v1/forever':
        answer(200, ['items' => [['sku' => 'X']], 'links' => ['next' => $base . '/v1/forever']]);
    case '/v1/slow':
        $state['slow']++;
        if ($state['slow'] <= 2) {
            answer(429, ['message' => 'Too many requests'], ['Retry-After' => '1']);
        }
        answer(200, $page(0, 5));
    case '/v1/import':
        $state['received'][] = [
            'method' => $_SERVER['REQUEST_METHOD'],
            'type' => $_SERVER['CONTENT_TYPE'] ?? '',
            'body' => (string) file_get_contents('php://input'),
        ];
        answer(201, ['ok' => true]);
}
answer(404, ['message' => 'Not found']);
