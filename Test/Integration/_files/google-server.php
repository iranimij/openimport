<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

// phpcs:ignoreFile -- router for PHP's built-in web server, started by the Google integration tests and the E2E job

declare(strict_types=1);

/*
 * A small stand-in for Google's token endpoint and the Drive API v3, for PHP's built-in web server. The state (files,
 * keys, upload sessions) lives in the JSON file named by OPENIMPORT_GOOGLE_STATE; the test reads and writes it too.
 *
 * - POST /token: jwt-bearer (the assertion must be signed with state.public_key), authorization_code (code
 *   "good-code" and a verifier whose S256 challenge is state.challenge) and refresh_token ("refresh-1").
 * - /drive/v3/files: list ("'<id>' in parents", name, mimeType, trashed), get (alt=media), export, create folder,
 *   update (addParents/removeParents, trashed), /drive/v3/about.
 * - /upload/drive/v3/files: resumable uploads (create or update), finished with PUT /upload-session/<id>.
 */

$stateFile = (string) getenv('OPENIMPORT_GOOGLE_STATE');
$state = json_decode((string) file_get_contents($stateFile), true) ?: [];
$state += ['files' => [], 'sessions' => [], 'requests' => []];
$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string) file_get_contents('php://input');
$state['requests'][] = $method . ' ' . $_SERVER['REQUEST_URI'];

function answer(int $status, array $data, array $headers = []): void
{
    global $state, $stateFile;
    file_put_contents($stateFile, json_encode($state));
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($data);
    exit;
}

function b64url(string $data): string
{
    return (string) base64_decode(strtr($data, '-_', '+/'));
}

if ($path === '/token' && $method === 'POST') {
    parse_str($body, $form);
    if (($form['grant_type'] ?? '') === 'urn:ietf:params:oauth:grant-type:jwt-bearer') {
        [$header, $claims, $signature] = array_pad(explode('.', (string) ($form['assertion'] ?? '')), 3, '');
        $ok = openssl_verify($header . '.' . $claims, b64url($signature), (string) $state['public_key'], OPENSSL_ALGO_SHA256) === 1;
        $data = json_decode(b64url($claims), true) ?: [];
        if (!$ok || ($data['scope'] ?? '') !== 'https://www.googleapis.com/auth/drive' || ($data['exp'] ?? 0) < time()) {
            answer(400, ['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.']);
        }
        answer(200, ['access_token' => 'sa-token', 'expires_in' => 3599, 'token_type' => 'Bearer']);
    }
    if (($form['grant_type'] ?? '') === 'authorization_code') {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', (string) ($form['code_verifier'] ?? ''), true)), '+/', '-_'), '=');
        if (($form['code'] ?? '') !== 'good-code' || $challenge !== ($state['challenge'] ?? '')
            || ($form['client_secret'] ?? '') !== 'client-secret'
        ) {
            answer(400, ['error' => 'invalid_grant', 'error_description' => 'Bad Request']);
        }
        answer(200, ['access_token' => 'user-token', 'refresh_token' => 'refresh-1', 'expires_in' => 3599]);
    }
    if (($form['grant_type'] ?? '') === 'refresh_token' && ($form['refresh_token'] ?? '') === 'refresh-1') {
        answer(200, ['access_token' => 'user-token', 'expires_in' => 3599]);
    }
    answer(400, ['error' => 'invalid_grant']);
}

$token = (string) preg_replace('/^Bearer /', '', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
if (!in_array($token, ['sa-token', 'user-token'], true)) {
    answer(401, ['error' => ['code' => 401, 'message' => 'Request had invalid authentication credentials.']]);
}

if ($path === '/drive/v3/about') {
    answer(200, ['user' => ['emailAddress' => 'shop@example.com']]);
}

if ($path === '/drive/v3/files' && $method === 'GET') {
    $q = (string) ($_GET['q'] ?? '');
    preg_match("/'([^']+)' in parents/", $q, $parent);
    preg_match("/name = '((?:[^'\\\\]|\\\\.)*)'/", $q, $name);
    preg_match("/mimeType = '([^']+)'/", $q, $mime);
    $found = [];
    foreach ($state['files'] as $id => $file) {
        if (($parent && !in_array($parent[1], $file['parents'], true)) || !empty($file['trashed'])
            || ($name && $file['name'] !== stripslashes($name[1])) || ($mime && $file['mimeType'] !== $mime[1])
        ) {
            continue;
        }
        $found[] = ['id' => $id, 'name' => $file['name'], 'mimeType' => $file['mimeType'], 'modifiedTime' => $file['modifiedTime']];
    }
    answer(200, ['files' => $found]);
}

if ($path === '/drive/v3/files' && $method === 'POST') {
    $meta = json_decode($body, true) ?: [];
    $id = 'folder' . str_pad((string) count($state['files']), 22, '0', STR_PAD_LEFT);
    $state['files'][$id] = ['name' => $meta['name'], 'mimeType' => $meta['mimeType'], 'parents' => $meta['parents'],
        'modifiedTime' => gmdate('Y-m-d\TH:i:s.000\Z'), 'content' => ''];
    answer(200, ['id' => $id]);
}

if (preg_match('#^/drive/v3/files/([^/]+)(/export)?$#', $path, $match)) {
    $id = $match[1];
    if (!isset($state['files'][$id])) {
        answer(404, ['error' => ['code' => 404, 'message' => 'File not found: ' . $id . '.']]);
    }
    if ($method === 'PATCH') {
        $file = &$state['files'][$id];
        if (isset($_GET['addParents'])) {
            $file['parents'] = array_values(array_diff($file['parents'], [$_GET['removeParents'] ?? '']));
            $file['parents'][] = $_GET['addParents'];
        }
        $meta = json_decode($body, true) ?: [];
        if (!empty($meta['trashed'])) {
            $file['trashed'] = true;
        }
        answer(200, ['id' => $id]);
    }
    if (!empty($match[2])) {
        if ($state['files'][$id]['mimeType'] !== 'application/vnd.google-apps.spreadsheet') {
            answer(403, ['error' => ['code' => 403, 'message' => 'Export only supports Docs Editors files.']]);
        }
        file_put_contents($stateFile, json_encode($state));
        header('Content-Type: ' . ($_GET['mimeType'] ?? ''));
        echo 'XLSX:' . $state['files'][$id]['content'];
        exit;
    }
    file_put_contents($stateFile, json_encode($state));
    header('Content-Type: application/octet-stream');
    echo $state['files'][$id]['content'];
    exit;
}

if (preg_match('#^/upload/drive/v3/files(?:/([^/]+))?$#', $path, $match) && ($_GET['uploadType'] ?? '') === 'resumable') {
    $session = 'session' . count($state['sessions']);
    $state['sessions'][$session] = ['update' => $match[1] ?? null, 'meta' => json_decode($body, true) ?: []];
    $port = $_SERVER['SERVER_PORT'];
    answer(200, [], ['Location' => 'http://127.0.0.1:' . $port . '/upload-session/' . $session]);
}

if (preg_match('#^/upload-session/(.+)$#', $path, $match) && $method === 'PUT') {
    $session = $state['sessions'][$match[1]] ?? null;
    if ($session === null) {
        answer(404, ['error' => ['code' => 404, 'message' => 'No upload session.']]);
    }
    $id = $session['update'] ?? ('file' . str_pad((string) count($state['files']), 24, '0', STR_PAD_LEFT));
    $existing = $state['files'][$id] ?? null;
    $state['files'][$id] = [
        'name' => $existing['name'] ?? $session['meta']['name'],
        'mimeType' => $existing['mimeType'] ?? ($session['meta']['mimeType'] ?? 'text/csv'),
        'parents' => $existing['parents'] ?? $session['meta']['parents'],
        'modifiedTime' => gmdate('Y-m-d\TH:i:s.000\Z'),
        'content' => $body,
    ];
    answer(200, ['id' => $id]);
}

answer(404, ['error' => ['code' => 404, 'message' => 'Unknown address ' . $path]]);
