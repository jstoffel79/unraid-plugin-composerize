<?php
declare(strict_types=1);

/**
 * composerize.php - Helper functions for the Composerize Unraid Plugin.
 * NOTE: The 'declare(strict_types=1);' statement MUST be the very first
 * line after the opening <?php tag. No comments or blank lines can come before it.
 */

// --- Constants ---
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
 * Gets a list of templates for currently running Docker containers.
 * This function queries the Docker client, filters for running containers
 * that have a template, and then extracts the run command.
 */
function getDockerTemplateList(): array
{
    $dockerTemplates = [];
    $dockerClient = new DockerClient();
    $containers = $dockerClient->getDockerContainers();
    $filesToProcess = [];

    // First, collect all unique template files from running containers, regardless of source.
    foreach ($containers as $container) {
        // Check if the container is running and has a valid template file associated with it.
        if ($container['Running'] && isset($container['Template']) && file_exists($container['Template'])) {
            if (!in_array($container['Template'], $filesToProcess)) {
                $filesToProcess[] = $container['Template'];
            }
        }
    }

    if (empty($filesToProcess)) {
        error_log('Composerize Plugin: No running containers with associated template files were found.');
        return [];
    }

    // Now, process only the files for running containers
    foreach ($filesToProcess as $file) {
        try {
            $xmlContent = file_get_contents($file);
            if ($xmlContent === false) {
                error_log("Composerize Plugin: Failed to read template file: {$file}");
                continue;
            }

            if (strpos($xmlContent, '<Network>') === false) {
                $xmlContent = str_replace('</Container>', "  <Network>Bridge</Network>\n</Container>", $xmlContent);
                $tempFile = tempnam(sys_get_temp_dir(), 'composerize-');
                file_put_contents($tempFile, $xmlContent);
                $fileToProcess = $tempFile;
            } else {
                $fileToProcess = $file;
            }

            $info = xmlToCommand($fileToProcess, false);

            if (isset($tempFile)) {
                unlink($tempFile);
                unset($tempFile);
            }

            if (!is_array($info) || empty($info[0]) || empty($info[1])) {
                error_log("Composerize Plugin: Skipped template with invalid format: {$file}");
                continue;
            }
            
            $command = str_replace(
                '/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/docker create',
                'docker run',
                $info[0]
            );
            $name = $info[1];

            $dockerTemplates[$name] = $command;
        } catch (Throwable $t) {
            error_log("Composerize Plugin: Skipped incompatible template {$file}. Error: " . $t->getMessage());
        }
    }

    ksort($dockerTemplates);
    return $dockerTemplates;
}
