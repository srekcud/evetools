<?php

declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
[, $status, $device] = explode('/', $path, 3) + [null, '500', 'unknown'];

file_put_contents(
    sprintf('%s/%s.json', getenv('PUSH_SERVICE_RECORD_DIR'), $device),
    json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'path' => $path,
        'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
        'body' => base64_encode((string) file_get_contents('php://input')),
    ], JSON_THROW_ON_ERROR),
);

http_response_code((int) $status);
