<?php
/**
 * api.php - Handles the installation of a Docker Compose stack.
 * This script is now designed to be called by Unraid's openBox() function
 * and will output plain text status messages.
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
 * Installs a Docker Compose stack to the disk.
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
            throw new Exception("Failed to create project directory. Check permissions for: " . COMPOSE_DIRECTORY);
        }
    }

    if (file_put_contents($composeNameFilePath, $name) === false) {
        throw new Exception("Failed to write 'name' file. Check permissions for: {$composeProjectDirectory}");
    }

    if (file_put_contents($composeYamlFilePath, $compose) === false) {
        throw new Exception("Failed to write 'docker-compose.yml' file. Check permissions for: {$composeProjectDirectory}");
    }

    return true;
}


// --- Main Execution ---
// This script can be called with GET (by openBox) or POST (by the form submission).
// We only process the installation logic if we receive POST data.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // For the initial GET request from openBox, just show a waiting message.
    echo "Waiting for form data...";
    exit;
}

// --- Input Processing ---
$name    = $_POST['name'] ?? null;
$compose = $_POST['compose'] ?? null;
$force   = filter_var($_POST['force'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

$sanitizedName = $name ? preg_replace('/[^a-zA-Z0-9_-]/', '', $name) : null;

// --- Validation and Installation ---
if (empty($sanitizedName) || empty($compose) || !isValidYaml($compose)) {
    echo "Error: Invalid or missing data received.";
    exit;
}

try {
    $status = installCompose($sanitizedName, $compose, $force);
    if ($status) {
        echo "Stack '{$sanitizedName}' installed successfully!";
    } else {
        echo "Stack '{$sanitizedName}' already exists. Installation aborted.";
    }
} catch (Exception $e) {
    echo "An error occurred: " . htmlspecialchars($e->getMessage());
}
