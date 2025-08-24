<?php
declare(strict_types=1);

/**
 * api_helpers.php - Lean helper functions specifically for the API endpoint.
 */

// --- Constants ---
define('COMPOSE_DIRECTORY', '/boot/config/plugins/compose.manager/projects/');
// Max directory name length to avoid filesystem surprises
define('COMPOSE_MAX_NAME_LEN', 80);

/**
 * Return a safe, normalized stack directory path or throw.
 */
function stack_dir(string $name): string {
    $dir = rtrim(COMPOSE_DIRECTORY, '/').'/'.$name;
    return $dir;
}

/**
 * Validates a given string to see if it's a non-empty YAML string.
 */
function isValidYaml(?string $yamlString): bool
{
    return !empty($yamlString);
}

/**
 * Validate the compose file by asking docker compose to parse it.
 * Returns true if the config parses cleanly.
 */
function validateWithDockerCompose(string $composePath): bool
{
    $cmd = sprintf("/usr/bin/docker compose -f %s config --quiet 2>&1", escapeshellarg($composePath));
    exec($cmd, $out, $code);
    error_log("Composerize Trace: docker compose validation rc={$code} out=" . implode("\\n", $out));
    return $code === 0;
}

/**
 * Installs a Docker Compose stack to the disk with detailed error handling and verification.
 */
function installCompose(string $name, string $compose, bool $force): bool
{
    error_log("Composerize Trace: Starting installCompose for stack '{$name}'.");

    if (strlen($name) > COMPOSE_MAX_NAME_LEN) {
        throw new Exception("Stack name too long (max ".COMPOSE_MAX_NAME_LEN." characters).");
    }
    if ($name === '.' || $name === '..') {
        throw new Exception("Invalid stack name.");
    }

    $composeProjectDirectory = stack_dir($name);
    $composeYamlFilePath = $composeProjectDirectory . '/docker-compose.yml';
    $composeNameFilePath = $composeProjectDirectory . '/name';

    error_log("Composerize Trace: Target project directory: {$composeProjectDirectory}");

    if (!$force && (file_exists($composeProjectDirectory) || file_exists($composeYamlFilePath))) {
        error_log("Composerize Trace: Stack '{$name}' already exists and force is false. Aborting.");
        return false;
    }

    if (!is_dir($composeProjectDirectory)) {
        error_log("Composerize Trace: Directory does not exist. Attempting to create...");
        if (!@mkdir($composeProjectDirectory, 0775, true)) {
            $error = error_get_last();
            throw new Exception("Failed to create project directory at ".COMPOSE_DIRECTORY.". OS Error: " . ($error['message'] ?? 'Unknown error'));
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
    $nameWritten = file_put_contents($composeNameFilePath, $name."\\n", LOCK_EX);
    if ($nameWritten === false) {
        $error = error_get_last();
        throw new Exception("Failed to write 'name' file. Could not write to {$composeProjectDirectory}. OS Error: " . ($error['message'] ?? 'Unknown error'));
    }

    error_log("Composerize Trace: Attempting to write YAML file atomically: {$composeYamlFilePath}");
    $tmp = $composeYamlFilePath.'.tmp';
    $fh = @fopen($tmp, 'cb'); // create+binary
    if (!$fh) {
        $error = error_get_last();
        throw new Exception("Failed to open temp compose file for writing: ".($error['message'] ?? 'Unknown error'));
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        throw new Exception("Failed to lock temp compose file for writing.");
    }
    $bytes = fwrite($fh, $compose);
    fflush($fh);
    // Best-effort fsync
    if (function_exists('posix_fsync')) { @posix_fsync($fh); }
    fclose($fh);
    if ($bytes === false) {
        $error = error_get_last();
        throw new Exception("Failed to write compose YAML: ".($error['message'] ?? 'Unknown error'));
    }
    if (!@rename($tmp, $composeYamlFilePath)) {
        @unlink($tmp);
        throw new Exception("Failed to finalize compose YAML (rename).");
    }

    // Validate with docker compose (best-effort)
    if (!validateWithDockerCompose($composeYamlFilePath)) {
        throw new Exception("docker-compose.yml failed validation (docker compose config).");
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
