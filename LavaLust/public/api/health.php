<?php
header('Content-Type: application/json; charset=utf-8');
$allowed_origins = [
	getenv('FRONTEND_URL') ?: 'http://localhost:5173',
	'http://localhost:5173',
	'http://localhost:5174',
];
$request_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$cors_origin = in_array($request_origin, $allowed_origins, true)
	? $request_origin
	: $allowed_origins[0];
header('Access-Control-Allow-Origin: ' . $cors_origin);
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(204);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
	header('Allow: GET, OPTIONS');
	http_response_code(405);
	echo json_encode(['error' => 'Method not allowed']);
	exit;
}

echo json_encode([
	'status' => 'ok',
	'service' => 'esped-resort-api',
	'timestamp' => gmdate('c'),
]);
