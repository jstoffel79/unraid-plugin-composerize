<?php
/**
 * DEBUGGING VERSION - HEARTBEAT TEST
 * This script is simplified to its absolute minimum to test if the Unraid
 * web server can execute this file at all. It has no dependencies.
 */

// This is the very first line of executable code.
// If this log appears, we know the script is running.
error_log("Composerize Trace: API Heartbeat - api.php script was executed.");

// A simple function to send a JSON response.
function send_json_response(int $statusCode, array $data): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// Immediately send a success response to confirm the script ran.
send_json_response(200, [
    'success' => true,
    'message' => 'API Heartbeat Successful. The api.php file is executable.',
]);
