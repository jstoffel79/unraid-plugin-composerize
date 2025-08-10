<?php
/**
 * composerize.php - Helper functions for the Composerize Unraid Plugin.
 *
 * This file contains the backend logic for finding Docker templates,
 * converting them to docker run commands, and installing compose files.
 *
 * PHP version 7.4+
 */

// Use strict types for better code quality and error detection.
declare(strict_types=1);

// --- Constants ---
// Define constants for directory paths to avoid magic strings and for easier maintenance.
define('DOCKER_TEMPLATE_DIRECTORY', '/boot/config/plugins/dockerMan/templates-user/');
define('COMPOSE_DIRECTORY', '/boot/config/plugins/compose.manager/projects/');

// It's best practice to include dependencies at the top of the file.
// These files provide the necessary functions from Unraid's Docker Manager.
require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php';
require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php';

/**
 * Validates a given string to see if it's a non-empty YAML string.
 *
 * @param string|null $yamlString The YAML string to validate.
 * @return bool True if the string is not null or empty, false otherwise.
 */
function isValidYaml(?string $yamlString): bool
{
    return !empty($yamlString);
}

/**
 * Installs a Docker Compose stack to the disk.
 *
 * @param string $name    The name of the Docker Compose stack.
 * @param string $compose The Docker Compose YAML configuration as a string.
 * @param bool   $force   If true, any existing stack with the same name will be overwritten.
 *
 * @return bool True on success.
 * @throws Exception if the directory cannot be created or files cannot be written.
 */
function installCompose(string $name, string $compose, bool $force): bool
{
    $composeProjectDirectory = COMPOSE_DIRECTORY . $name;
    $composeYamlFilePath = $composeProjectDirectory . '/docker-compose.yml';
    $composeNameFilePath = $composeProjectDirectory . '/name';

    if (!$force && (file_exists($composeProjectDirectory) || file_exists($composeYamlFilePath))) {
        return false;
    }

    if (!is_dir($composeProjectDirectory) && !@mkdir($composeProjectDirectory, 0755, true)) {
        throw new Exception("Failed to create project directory: {$composeProjectDirectory}");
    }

    $nameWritten = file_put_contents($composeNameFilePath, $name);
    $yamlWritten = file_put_contents($composeYamlFilePath, $compose);

    if ($nameWritten === false || $yamlWritten === false) {
        throw new Exception("Failed to write compose files to disk for stack: {$name}");
    }

    return true;
}

/**
 * Scans the user templates directory for Docker XML templates and converts them
 * into an array of 'docker run' commands.
 *
 * @return array An associative array mapping the template name to its command string.
 */
function getDockerTemplateList(): array
{
    $dockerTemplates = [];
    $files = glob(DOCKER_TEMPLATE_DIRECTORY . '*.xml');

    if ($files === false) {
        error_log('Composerize Plugin: Failed to read Docker template directory.');
        return [];
    }

    foreach ($files as $file) {
        try {
            $info = xmlToCommand($file, false);

            if (!is_array($info) || empty($info[0]) || empty($info[1])) {
                error_log("Composerize Plugin: Failed to parse template file: {$file}");
                continue;
            }
            
            $command = str_replace(
                '/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/docker create',
                'docker run',
                $info[0]
            );
            $name = $info[1];

            $dockerTemplates[$name] = $command;
        } catch (Exception $e) {
            error_log("Composerize Plugin: Error processing template {$file}: " . $e->getMessage());
        }
    }

    ksort($dockerTemplates);
    return $dockerTemplates;
}
