<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

// phpcs:ignoreFile -- router for PHP's built-in web server, started by the Dropbox and OneDrive integration tests

declare(strict_types=1);

/*
 * A stand-in for Dropbox (API v2, under /dropbox) and Microsoft Graph with its token endpoint (under /ms), for PHP's
 * built-in web server. Files live in the JSON file named by OPENIMPORT_CLOUD_STATE: "files" => path => content,
 * "folders" => [paths]; the test reads and writes it too. Tokens: code "good-code" (with a verifier whose S256
 * challenge is state.challenge) or refresh tokens "refresh-dbx" / "refresh-ms".
 */

$stateFile = (string) getenv('OPENIMPORT_CLOUD_STATE');
$state = json_decode((string) file_get_contents($stateFile), true) ?: [];
$state += ['files' => [], 'folders' => [], 'uploads' => [], 'requests' => []];
$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$body = (string) file_get_contents('php://input');
$state['requests'][] = $method . ' ' . $_SERVER['REQUEST_URI'];
$base = 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'];

function save(): void
{
    global $state, $stateFile;
    file_put_contents($stateFile, json_encode($state));
}

function answer(int $status, $data, array $headers = []): void
{
    save();
    header('Content-Type: application/json');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function raw(int $status, string $content): void
{
    save();
    http_response_code($status);
    header('Content-Type: application/octet-stream');
    echo $content;
    exit;
}

function norm(string $path): string
{
    return trim($path, '/');
}

function children(string $folder): array
{
    global $state;
    $folder = norm($folder);
    $found = [];
    foreach ($state['files'] as $file => $content) {
        if (norm(dirname('/' . $file)) === $folder) {
            $found[] = ['file', basename($file)];
        }
    }
    foreach ($state['folders'] as $sub) {
        if ($sub !== $folder && norm(dirname('/' . $sub)) === $folder) {
            $found[] = ['folder', basename($sub)];
        }
    }

    return $found;
}

function folderExists(string $folder): bool
{
    global $state;
    $folder = norm($folder);

    return $folder === '' || in_array($folder, $state['folders'], true);
}

function token(string $grant, array $form, string $refresh, string $access): void
{
    global $state;
    if (($form['grant_type'] ?? '') === 'authorization_code') {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', (string) ($form['code_verifier'] ?? ''), true)), '+/', '-_'), '=');
        if (($form['code'] ?? '') !== 'good-code' || $challenge !== ($state['challenge'] ?? '')
            || ($form['client_secret'] ?? '') !== 'client-secret'
        ) {
            answer(400, ['error' => 'invalid_grant', 'error_description' => 'code doesn\'t exist or has expired']);
        }
        answer(200, ['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => 3600]);
    }
    if (($form['grant_type'] ?? '') === 'refresh_token' && ($form['refresh_token'] ?? '') === $refresh) {
        answer(200, ['access_token' => $access, 'expires_in' => 3600]);
    }
    answer(400, ['error' => 'invalid_grant']);
}

$auth = (string) preg_replace('/^Bearer /', '', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));

// ---- Consent page (browser tests): remembers the PKCE challenge and sends the browser back with the code ----
if ($path === '/dropbox/oauth2/authorize' || preg_match('#^/ms/login/[^/]+/oauth2/v2\.0/authorize$#', $path)) {
    $query = $_GET;
    if (($query['response_type'] ?? '') !== 'code' || ($query['code_challenge_method'] ?? '') !== 'S256'
        || empty($query['client_id']) || empty($query['state']) || empty($query['redirect_uri'])
    ) {
        raw(400, 'Bad authorization request: ' . json_encode($query));
    }
    $state['challenge'] = (string) $query['code_challenge'];
    save();
    $back = $query['redirect_uri'] . (str_contains((string) $query['redirect_uri'], '?') ? '&' : '?')
        . http_build_query(['code' => 'good-code', 'state' => $query['state']]);
    header('Location: ' . $back);
    http_response_code(302);
    exit;
}

// ---- Dropbox ----
if ($path === '/dropbox/oauth2/token') {
    parse_str($body, $form);
    token('dropbox', $form, 'refresh-dbx', 'dbx-token');
}
if (str_starts_with($path, '/dropbox/')) {
    if ($auth !== 'dbx-token') {
        answer(401, ['error_summary' => 'invalid_access_token/']);
    }
    $arg = json_decode((string) ($_SERVER['HTTP_DROPBOX_API_ARG'] ?? '{}'), true) ?: [];
    $json = json_decode($body, true) ?: [];
    switch ($path) {
        case '/dropbox/2/users/get_current_account':
            answer(200, ['email' => 'shop@example.com']);
        case '/dropbox/2/files/list_folder':
            if (!folderExists((string) $json['path'])) {
                answer(409, ['error_summary' => 'path/not_found/..']);
            }
            $entries = [];
            foreach (children((string) $json['path']) as [$tag, $name]) {
                $entries[] = ['.tag' => $tag, 'name' => $name, 'server_modified' => '2026-10-20T10:00:00Z'];
            }
            answer(200, ['entries' => $entries, 'has_more' => false, 'cursor' => 'c']);
        case '/dropbox/2/files/create_folder_v2':
            if (folderExists((string) $json['path'])) {
                answer(409, ['error_summary' => 'path/conflict/folder/..']);
            }
            $state['folders'][] = norm((string) $json['path']);
            answer(200, ['metadata' => ['name' => basename((string) $json['path'])]]);
        case '/dropbox/2/files/move_v2':
            $from = norm((string) $json['from_path']);
            if (!isset($state['files'][$from])) {
                answer(409, ['error_summary' => 'from_lookup/not_found/..']);
            }
            if (!folderExists(dirname('/' . norm((string) $json['to_path'])))) {
                answer(409, ['error_summary' => 'to/no_write_permission/..']);
            }
            $state['files'][norm((string) $json['to_path'])] = $state['files'][$from];
            unset($state['files'][$from]);
            answer(200, ['metadata' => []]);
        case '/dropbox/2/files/delete_v2':
            unset($state['files'][norm((string) $json['path'])]);
            answer(200, ['metadata' => []]);
        case '/dropbox/content/2/files/download':
            $file = norm((string) ($arg['path'] ?? ''));
            if (!isset($state['files'][$file])) {
                answer(409, ['error_summary' => 'path/not_found/..']);
            }
            raw(200, $state['files'][$file]);
        case '/dropbox/content/2/files/upload':
            $file = norm((string) ($arg['path'] ?? ''));
            $folder = norm(dirname('/' . $file));
            if ($folder !== '' && !in_array($folder, $state['folders'], true)) {
                $state['folders'][] = $folder;
            }
            $state['files'][$file] = $body;
            answer(200, ['name' => basename($file)]);
    }
    answer(404, ['error_summary' => 'unknown/']);
}

// ---- Microsoft ----
if (preg_match('#^/ms/login/[^/]+/oauth2/v2\.0/token$#', $path)) {
    parse_str($body, $form);
    token('ms', $form, 'refresh-ms', 'ms-token');
}
if (preg_match('#^/ms/download/(.+)$#', $path, $match)) {
    raw(200, $state['files'][$match[1]] ?? '');
}
if (preg_match('#^/ms/upload/(.+)$#', $path, $match) && $method === 'PUT') {
    $file = $match[1];
    preg_match('#bytes (\d+)-(\d+)/(\d+)#', (string) ($_SERVER['HTTP_CONTENT_RANGE'] ?? ''), $range);
    $state['uploads'][$file] = ($state['uploads'][$file] ?? '') . $body;
    $state['requests'][count($state['requests']) - 1] .= ' ' . ($range[0] ?? '');
    if ((int) ($range[2] ?? 0) + 1 === (int) ($range[3] ?? -1)) {
        $state['files'][$file] = $state['uploads'][$file];
        unset($state['uploads'][$file]);
        answer(201, ['name' => basename($file)]);
    }
    answer(202, ['nextExpectedRanges' => [((int) $range[2] + 1) . '-']]);
}
if (str_starts_with($path, '/ms/graph/')) {
    if ($auth !== 'ms-token') {
        answer(401, ['error' => ['code' => 'InvalidAuthenticationToken', 'message' => 'Access token is empty.']]);
    }
    $rest = substr($path, strlen('/ms/graph'));
    if ($rest === '/me') {
        answer(200, ['mail' => 'shop@example.com', 'userPrincipalName' => 'shop@example.onmicrosoft.com']);
    }
    if (preg_match('#^/sites/([^/:]+)(?::/(.+))?$#', $rest, $match)) {
        answer(200, ['id' => 'contoso.sharepoint.com,site-guid,web-guid']);
    }
    if (!preg_match('#^/(?:me|sites/[^/]+)/drive/root(?::/(.*?))?(?::?/(children|content|createUploadSession))?$#', $rest, $match)) {
        answer(404, ['error' => ['code' => 'itemNotFound', 'message' => 'Unknown address ' . $rest]]);
    }
    $item = norm($match[1] ?? '');
    $action = $match[2] ?? '';
    $json = json_decode($body, true) ?: [];
    if ($action === 'children' && $method === 'GET') {
        if (!folderExists($item)) {
            answer(404, ['error' => ['code' => 'itemNotFound', 'message' => 'The resource could not be found.']]);
        }
        $value = [];
        foreach (children($item) as [$tag, $name]) {
            $value[] = ['name' => $name, $tag => new stdClass(), 'lastModifiedDateTime' => '2026-10-20T10:00:00Z'];
        }
        answer(200, ['value' => $value]);
    }
    if ($action === 'children' && $method === 'POST') {
        $folder = norm($item . '/' . $json['name']);
        if (folderExists($folder)) {
            answer(409, ['error' => ['code' => 'nameAlreadyExists', 'message' => 'Name already exists.']]);
        }
        $state['folders'][] = $folder;
        answer(201, ['id' => 'id:' . $folder]);
    }
    if ($action === 'content' && $method === 'GET') {
        if (!isset($state['files'][$item])) {
            answer(404, ['error' => ['code' => 'itemNotFound', 'message' => 'The resource could not be found.']]);
        }
        save();
        http_response_code(302);
        header('Location: ' . $base . '/ms/download/' . $item);
        exit;
    }
    if ($action === 'content' && $method === 'PUT') {
        $state['files'][$item] = $body;
        answer(201, ['id' => 'id:' . $item]);
    }
    if ($action === 'createUploadSession') {
        $state['uploads'][$item] = '';
        answer(200, ['uploadUrl' => $base . '/ms/upload/' . $item]);
    }
    if ($method === 'GET') {
        if (isset($state['files'][$item]) || folderExists($item)) {
            answer(200, ['id' => 'id:' . $item, 'name' => basename($item)]);
        }
        answer(404, ['error' => ['code' => 'itemNotFound', 'message' => 'The resource could not be found.']]);
    }
    if ($method === 'PATCH') {
        $target = norm(substr((string) ($json['parentReference']['id'] ?? ''), 3) . '/' . ($json['name'] ?? basename($item)));
        $state['files'][$target] = $state['files'][$item];
        unset($state['files'][$item]);
        answer(200, ['id' => 'id:' . $target]);
    }
    if ($method === 'DELETE') {
        unset($state['files'][$item]);
        save();
        http_response_code(204);
        exit;
    }
}
answer(404, ['error' => ['code' => 'itemNotFound', 'message' => 'Unknown address ' . $path]]);
