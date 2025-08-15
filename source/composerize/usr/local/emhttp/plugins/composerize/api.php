<?php
/**
 * api.php - Handles the installation of a Docker Compose stack.
 * This is now a standard PHP script called by Unraid's script runner.
 */

// Since this script is run directly by Unraid's handler, we don't need strict_types.

// --- Configuration and Helpers ---
define('PLUGIN_ROOT', '/usr/local/emhttp/plugins/composerize');

// This function is no longer needed as we won't be sending JSON back directly.
// Instead, we'll rely on the Unraid GUI's standard "Done" page.
require_once PLUGIN_ROOT . '/include/api_helpers.php';

// --- Main Execution ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "Error: This script only accepts POST requests.";
    exit;
}

// --- Input Processing and Validation ---
$name    = $_POST['name'] ?? null;
$compose = $_POST['compose'] ?? null;
$force   = filter_var($_POST['force'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

$sanitizedName = $name ? preg_replace('/[^a-zA-Z0-9_-]/', '', $name) : null;

if (empty($sanitizedName)) {
    echo "Error: Invalid or missing stack name.";
    exit;
}
if ($sanitizedName !== $name) {
    echo "Error: Stack name contains invalid characters.";
    exit;
}
if (empty($compose) || !isValidYaml($compose)) {
    echo "Error: Invalid or missing Docker Compose YAML.";
    exit;
}

// --- Installation ---
try {
    installCompose($sanitizedName, $compose, $force);
    echo "Stack '{$sanitizedName}' installed successfully!";
} catch (Exception $e) {
    echo "An internal error occurred: " . htmlspecialchars($e->getMessage());
}
