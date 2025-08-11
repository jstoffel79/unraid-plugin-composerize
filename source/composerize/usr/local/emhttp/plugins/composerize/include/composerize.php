<?php
declare(strict_types=1);

/**
 * composerize.php - Helper functions for the Composerize Unraid Plugin.
 * NOTE: This is a debugging version to inspect the container array structure.
 */

// --- Constants ---
define('COMPOSE_DIRECTORY', '/boot/config/plugins/compose.manager/projects/');

// --- Dependencies ---
require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php';
require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php';

function isValidYaml(?string $yamlString): bool { return !empty($yamlString); }

function installCompose(string $name, string $compose, bool $force): bool {
    $composeProjectDirectory = COMPOSE_DIRECTORY . $name;
    $composeYamlFilePath = $composeProjectDirectory . '/docker-compose.yml';
    $composeNameFilePath = $composeProjectDirectory . '/name';
    if (!$force && (file_exists($composeProjectDirectory) || file_exists($composeYamlFilePath))) { return false; }
    if (!is_dir($composeProjectDirectory) && !@mkdir($composeProjectDirectory, 0755, true)) { throw new Exception("Failed to create project directory: {$composeProjectDirectory}"); }
    $nameWritten = file_put_contents($composeNameFilePath, $name);
    $yamlWritten = file_put_contents($composeYamlFilePath, $compose);
    if ($nameWritten === false || $yamlWritten === false) { throw new Exception("Failed to write compose files to disk for stack: {$name}"); }
    return true;
}

/**
 * DEBUGGING VERSION
 * This function will log the raw container array from DockerClient to the system log.
 */
function getDockerTemplateList(): array
{
    $dockerClient = new DockerClient();
    $containers = $dockerClient->getDockerContainers();

    // --- DEBUGGING ---
    // Log the entire structure of the containers array to the main system log.
    error_log("Composerize Debug - Containers Array: " . print_r($containers, true));
    // --- END DEBUGGING ---

    $dockerTemplates = [];
    $filesToProcess = [];

    foreach ($containers as $container) {
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
            
            $command = str_replace('/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/docker create', 'docker run', $info[0]);
            $name = $info[1];

            $dockerTemplates[$name] = $command;
        } catch (Throwable $t) {
            error_log("Composerize Plugin: Skipped incompatible template {$file}. Error: " . $t->getMessage());
        }
    }

    ksort($dockerTemplates);
    return $dockerTemplates;
}
