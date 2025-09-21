<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$url = $_GET['url'] ?? null;

if (empty($url)) {
    http_response_code(403);
    exit;
}

$client = new MovieDbProxy($url);
$response = $client->fetch();

if ($response === null) {
    http_response_code(500);
    exit;
}

$jsonData = json_decode($response, true);

if (json_last_error() === JSON_ERROR_NONE) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo $response;
}
