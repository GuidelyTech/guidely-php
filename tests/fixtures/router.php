<?php

// Router for `php -S` used by HttpTransportTest: echoes the request back as JSON, with an ETag.
declare(strict_types=1);

$echo = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'ifNoneMatch' => $_SERVER['HTTP_IF_NONE_MATCH'] ?? null,
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'contentType' => $_SERVER['CONTENT_TYPE'] ?? null,
    'body' => file_get_contents('php://input'),
];
header('ETag: "e1"');
if ($echo['ifNoneMatch'] === '"e1"') {
    http_response_code(304);
    return;
}
http_response_code(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/missing' ? 404 : 200);
header('Content-Type: application/json');
echo json_encode($echo);
