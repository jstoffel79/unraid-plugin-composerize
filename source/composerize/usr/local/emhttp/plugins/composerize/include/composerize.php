<?php
/**
 * composerize.php - Helper functions for the Composerize Unraid Plugin.
 */

declare(strict_types=1);

// --- Constants ---
define('DOCKER_TEMPLATE_DIRECTORY', '/boot/config/plugins/dockerMan/templates-user/');
define('COMPOSE_DIRECTORY', '/boot/config/plugins/compose.manager/projects/');

// --- Dependencies ---
require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php';
require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php';

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
