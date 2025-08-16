<?php
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
 * Installs a Docker Compose stack to the disk with detailed error handling and verification.
 */
function installCompose(string $name, string $compose, bool $force): bool
{
    error_log("Composerize Trace: Starting installCompose for stack '{$name}'.");
    
    $composeProjectDirectory = COMPOSE_DIRECTORY . $name;
    $composeYamlFilePath = $composeProjectDirectory . '/docker-compose.yml';
    $composeNameFilePath = $composeProjectDirectory . '/name';

    error_log("Composerize Trace: Target project directory: {$composeProjectDirectory}");

    if (!$force && (file_exists($composeProjectDirectory) || file_exists($composeYamlFilePath))) {
        error_log("Composerize Trace: Stack '{$name}' already exists and force is false. Aborting.");
        return false;
    }

    if (!is_dir($composeProjectDirectory)) {
        error_log("Composerize Trace: Directory does not exist. Attempting to create...");
        if (!@mkdir($composeProjectDirectory, 0755, true)) {
            $error = error_get_last();
            throw new Exception("Failed to create project directory. Check permissions for: " . COMPOSE_DIRECTORY . ". OS Error: " . ($error['message'] ?? 'Unknown error'));
        }
        // VERIFICATION STEP
        if (is_dir($composeProjectDirectory)) {
            error_log("Composerize Trace: SUCCESS - Directory created successfully.");
        } else {
            error_log("Composerize Trace: FAILURE - Directory was not created, despite no immediate error.");
            throw new Exception("Failed to create project directory due to a silent filesystem issue.");
        }
    }

    error_log("Composerize Trace: Attempting to write name file: {$composeNameFilePath}");
    $nameWritten = file_put_contents($composeNameFilePath, $name);
    if ($nameWritten === false) {
        $error = error_get_last();
        throw new Exception("Failed to write 'name' file. Check permissions for: {$composeProjectDirectory}. OS Error: " . ($error['message'] ?? 'Unknown error'));
    }
    // VERIFICATION STEP
    if (file_exists($composeNameFilePath)) {
        error_log("Composerize Trace: SUCCESS - Name file written successfully.");
    } else {
        error_log("Composerize Trace: FAILURE - Name file was not written, despite no immediate error.");
        throw new Exception("Failed to write 'name' file due to a silent filesystem issue.");
    }


    error_log("Composerize Trace: Attempting to write YAML file: {$composeYamlFilePath}");
    $yamlWritten = file_put_contents($composeYamlFilePath, $compose);
    if ($yamlWritten === false) {
        $error = error_get_last();
        throw new Exception("Failed to write 'docker-compose.yml' file. Check permissions for: {$composeProjectDirectory}. OS Error: " . ($error['message'] ?? 'Unknown error'));
    }
    // VERIFICATION STEP
    if (file_exists($composeYamlFilePath)) {
        error_log("Composerize Trace: SUCCESS - YAML file written successfully.");
    } else {
        error_log("Composerize Trace: FAILURE - YAML file was not written, despite no immediate error.");
        throw new Exception("Failed to write 'docker-compose.yml' file due to a silent filesystem issue.");
    }

    return true;
}
