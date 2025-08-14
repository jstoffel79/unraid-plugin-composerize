<?php
// This is the very first line to execute. If this log appears, the script is running.
error_log("Composerize Trace: api.php script started.");

/**
 * Installs a Docker Compose stack on Unraid.
 * This script handles a POST request to create and install a new stack.
 */

// --- Configuration and Helpers ---
define('PLUGIN_ROOT', '/usr/local/emhttp/plugins/composerize');

function send_json_response(int $statusCode, array $data): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

require_once PLUGIN_ROOT . '/include/api_helpers.php';

// --- Main Execution ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("Composerize Trace: API called with incorrect method: " . $_SERVER['REQUEST_METHOD']);
    send_json_response(405, ['error' => 'Method Not Allowed. Only POST is accepted.']);
}

error_log("Composerize Trace: API received POST request.");

// --- Input Processing and Validation ---
$name    = $_POST['name'] ?? null;
$compose = $_POST['compose'] ?? null;
$force   = filter_var($_POST['force'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

error_log("Composerize Trace: Received data - Name: [{$name}], Force: [" . ($force ? 'true' : 'false') . "]");

$sanitizedName = $name ? preg_replace('/[^a-zA-Z0-9_-]/', '', $name) : null;

if (empty($sanitizedName)) {
    error_log("Composerize Trace: Validation failed - Empty or missing stack name.");
    send_json_response(400, ['error' => 'Invalid or missing stack name.']);
}
if ($sanitizedName !== $name) {
    error_log("Composerize Trace: Validation failed - Stack name contains invalid characters.");
    send_json_response(400, ['error' => 'Stack name contains invalid characters.']);
}
if (empty($compose) || !isValidYaml($compose)) {
    error_log("Composerize Trace: Validation failed - Invalid or missing YAML.");
    send_json_response(400, ['error' => 'Invalid or missing Docker Compose YAML.']);
}

error_log("Composerize Trace: Input validation passed for stack '{$sanitizedName}'.");

// --- Installation ---
try {
    error_log("Composerize Trace: Calling installCompose() for stack '{$sanitizedName}'.");
    $status = installCompose($sanitizedName, $compose, $force);

    if ($status) {
        error_log("Composerize Trace: installCompose() succeeded for stack '{$sanitizedName}'.");
        send_json_response(200, [
            'success' => true,
            'message' => "Stack '{$sanitizedName}' installed successfully.",
            'force'   => $force,
        ]);
    } else {
        error_log("Composerize Trace: installCompose() returned false (stack likely exists) for stack '{$sanitizedName}'.");
        send_json_response(409, [
            'success' => false,
            'error'   => "Stack '{$sanitizedName}' already exists. Use 'force' to overwrite.",
            'force'   => $force,
        ]);
    }
} catch (Exception $e) {
    error_log("Composerize Plugin API Error: " . $e->getMessage());
    send_json_response(500, [
        'success' => false,
        'error'   => "An internal error occurred: " . $e->getMessage(),
    ]);
}
