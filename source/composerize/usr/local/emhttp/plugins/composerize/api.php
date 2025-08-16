<?php
/**
 * api.php - Handles the installation of a Docker Compose stack.
 * This script is now designed to handle POST requests from the plugin UI.
 */

// --- Dependencies ---
// Use a relative path for reliability.
require_once __DIR__ . '/include/api_helpers.php';

// --- Main Execution ---
// This script should only be called via POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo "Error: This script only accepts POST requests.";
    exit;
}

// --- Input Processing ---
$name    = $_POST['name'] ?? null;
$compose = $_POST['compose'] ?? null;
$force   = filter_var($_POST['force'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

// Sanitize the name to be a valid directory name.
$sanitizedName = $name ? preg_replace('/[^a-zA-Z0-9_-]/', '', $name) : null;

// --- Validation and Installation ---
if (empty($sanitizedName) || empty($compose) || !isValidYaml($compose)) {
    http_response_code(400); // Bad Request
    echo "Error: Invalid or missing data received. Please select a template.";
    exit;
}

try {
    $status = installCompose($sanitizedName, $compose, $force);
    if ($status) {
        // Success
        echo "Stack '{$sanitizedName}' installed successfully!";
    } else {
        // The stack already exists, and force was false.
        http_response_code(409); // Conflict
        echo "Stack '{$sanitizedName}' already exists. Installation aborted.";
    }
} catch (Exception $e) {
    // An error occurred during file operations.
    http_response_code(500); // Internal Server Error
    error_log("Composerize Plugin Error: " . $e->getMessage()); // Log the detailed error for the admin
    echo "An error occurred: " . htmlspecialchars($e->getMessage());
}
