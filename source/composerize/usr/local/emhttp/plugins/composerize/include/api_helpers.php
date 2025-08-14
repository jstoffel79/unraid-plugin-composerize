<?php
// FILE: source/composerize/usr/local/emhttp/plugins/composerize/include/api_helpers.php
// This is a NEW file.
declare(strict_types=1);

/**
 * api_helpers.php - Lean helper functions specifically for the API endpoint.
 */

// --- Constants ---
define('COMPOSE_DIRECTORY', '/boot/config/plugins/compose.manager/projects/');

/**
 * Validates a given string to see if it's a non-empty YAML string.
 */
function isValidYaml(?string $yamlString): bool
{
    return !empty($yamlString);
}

/**
 * Installs a Docker Compose stack to the disk with detailed error handling.
 */
function installCompose(string $name, string $compose, bool $force): bool
{
    $composeProjectDirectory = COMPOSE_DIRECTORY . $name;
    $composeYamlFilePath = $composeProjectDirectory . '/docker-compose.yml';
    $composeNameFilePath = $composeProjectDirectory . '/name';

    if (!$force && (file_exists($composeProjectDirectory) || file_exists($composeYamlFilePath))) {
        return false;
    }

    if (!is_dir($composeProjectDirectory)) {
        if (!@mkdir($composeProjectDirectory, 0755, true)) {
            $error = error_get_last();
            throw new Exception("Failed to create project directory. Check permissions for: " . COMPOSE_DIRECTORY . ". OS Error: " . ($error['message'] ?? 'Unknown error'));
        }
    }

    $nameWritten = file_put_contents($composeNameFilePath, $name);
    if ($nameWritten === false) {
        $error = error_get_last();
        throw new Exception("Failed to write 'name' file. Check permissions for: {$composeProjectDirectory}. OS Error: " . ($error['message'] ?? 'Unknown error'));
    }

    $yamlWritten = file_put_contents($composeYamlFilePath, $compose);
    if ($yamlWritten === false) {
        $error = error_get_last();
        throw new Exception("Failed to write 'docker-compose.yml' file. Check permissions for: {$composeProjectDirectory}. OS Error: " . ($error['message'] ?? 'Unknown error'));
    }

    return true;
}

// --- DIVIDER ---

// FILE: source/composerize/usr/local/emhttp/plugins/composerize/api.php
// This file should be UPDATED.
?>
<?php
declare(strict_types=1);

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

// Use the new, lean helper file that doesn't load problematic dependencies.
require_once PLUGIN_ROOT . '/include/api_helpers.php';

// --- Main Execution ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(405, ['error' => 'Method Not Allowed. Only POST is accepted.']);
}

// --- Input Processing and Validation ---
$name    = $_POST['name'] ?? null;
$compose = $_POST['compose'] ?? null;
$force   = filter_var($_POST['force'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

$sanitizedName = $name ? preg_replace('/[^a-zA-Z0-9_-]/', '', $name) : null;

if (empty($sanitizedName)) {
    send_json_response(400, ['error' => 'Invalid or missing stack name.']);
}
if ($sanitizedName !== $name) {
    send_json_response(400, ['error' => 'Stack name contains invalid characters.']);
}
if (empty($compose) || !isValidYaml($compose)) {
    send_json_response(400, ['error' => 'Invalid or missing Docker Compose YAML.']);
}

// --- Installation ---
try {
    $status = installCompose($sanitizedName, $compose, $force);

    if ($status) {
        send_json_response(200, [
            'success' => true,
            'message' => "Stack '{$sanitizedName}' installed successfully.",
            'force'   => $force,
        ]);
    } else {
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
