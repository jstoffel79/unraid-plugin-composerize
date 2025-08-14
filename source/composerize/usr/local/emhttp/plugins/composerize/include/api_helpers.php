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
