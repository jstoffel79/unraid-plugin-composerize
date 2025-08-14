<?php
/**
 * DEBUGGING VERSION
 * This script is simplified to test if the API endpoint is reachable and executable.
 * It bypasses all helper files and logic.
 */

function send_json_response(int $statusCode, array $data): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// For this test, we are not including any other files.
// require_once '/usr/local/emhttp/plugins/composerize/include/api_helpers.php';

// Immediately send a success response to confirm the script is running.
send_json_response(200, [
    'success' => true,
    'message' => 'Success12! This is a test response from the simplified api.php file.',
]);
