<?php
/**
 * Installs a Docker Compose stack on Unraid.
 *
 * This script handles a POST request to create and install a new stack.
 * It's designed for Unraid 7+ and uses modern PHP practices.
 *
 * @param string name     The name for the new stack (alphanumeric, dashes, underscores).
 * @param string compose  The Docker Compose YAML configuration.
 * @param bool   [force]  If true, overwrites an existing stack with the same name.
 */

// Use strict types for better code quality, a feature well-supported in modern PHP.
declare(strict_types=1);

// --- Configuration and Helpers ---

// Define a constant for the plugin's root directory for clarity and easy maintenance.
define('PLUGIN_ROOT', '/usr/local/emhttp/plugins/composerize');

// A helper function to send consistent, structured JSON responses.
function send_json_response(int $statusCode, array $data): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// Include necessary backend functions. Make sure this file is also updated.
// The 'require_once' is safer as it will cause a fatal error if the file is missing.
require_once PLUGIN_ROOT . '/include/composerize.php';


// --- Main Execution ---

// Ensure the script is called via a POST request.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(405, ['error' => 'Method Not Allowed. Only POST is accepted.']);
}

// --- Input Processing and Validation ---

// Safely get and sanitize inputs using modern PHP features.
$name    = $_POST['name'] ?? null;
$compose = $_POST['compose'] ?? null;
// Use filter_var for robust boolean conversion.
$force   = filter_var($_POST['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

// Sanitize the stack name to prevent security issues like directory traversal.
// Allows only letters, numbers, underscores, and hyphens.
$sanitizedName = $name ? preg_replace('/[^a-zA-Z0-9_-]/', '', $name) : null;

if (empty($sanitizedName)) {
    send_json_response(400, ['error' => 'Invalid or missing stack name.']);
}

if ($sanitizedName !== $name) {
    send_json_response(400, ['error' => 'Stack name contains invalid characters.']);
}

if (empty($compose) || !isValidYaml($compose)) { // Assuming isValidYaml() exists in composerize.php
    send_json_response(400, ['error' => 'Invalid or missing Docker Compose YAML.']);
}

// --- Installation ---

try {
    // The core logic of the installation.
    $status = installCompose($sanitizedName, $compose, $force);

    if ($status) {
        send_json_response(200, [
            'success' => true,
            'message' => "Stack '{$sanitizedName}' installed successfully.",
            'force'   => $force,
        ]);
    } else {
        // This assumes installCompose returns false on a "soft" failure (e.g., stack exists and force=false).
        send_json_response(409, [
            'success' => false,
            'error'   => "Stack '{$sanitizedName}' already exists. Use 'force' to overwrite.",
            'force'   => $force,
        ]);
    }
} catch (Exception $e) {
    // Catch any exceptions thrown during the installation process for robust error handling.
    // This is crucial for debugging issues within the installCompose function.
    send_json_response(500, [
        'success' => false,
        'error'   => "An internal error occurred: " . $e->getMessage(),
    ]);
}
