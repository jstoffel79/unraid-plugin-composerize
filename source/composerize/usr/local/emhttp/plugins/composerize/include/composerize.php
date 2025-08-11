<?php
declare(strict_types=1);

/**
 * composerize.php - Helper functions for the Composerize Unraid Plugin.
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
 * This function is more robust and cross-references running container names with available templates.
 */
function getDockerTemplateList(): array
{
    $dockerTemplates = [];
    $dockerClient = new DockerClient();
    $containers = $dockerClient->getDockerContainers();
    $runningContainerNames = [];

    // 1. Get a list of all running container names.
    foreach ($containers as $container) {
        if (!empty($container['Running'])) {
            $runningContainerNames[] = $container['Name'];
        }
    }

    if (empty($runningContainerNames)) {
        error_log('Composerize Plugin: No running containers found.');
        return [];
    }

    // 2. Scan both user and default template directories.
    $userTemplates = glob('/boot/config/plugins/dockerMan/templates-user/*.xml');
    $defaultTemplates = glob('/boot/config/plugins/dockerMan/templates/*.xml');
    $allTemplateFiles = array_merge($userTemplates ?: [], $defaultTemplates ?: []);

    if (empty($allTemplateFiles)) {
        error_log('Composerize Plugin: No template files found in user or default directories.');
        return [];
    }

    // 3. Process each template file and check if it matches a running container.
    foreach ($allTemplateFiles as $file) {
        try {
            $xml = @simplexml_load_file($file);
            if ($xml === false || !isset($xml->Name)) {
                error_log("Composerize Plugin: Skipping malformed template file: {$file}");
                continue; 
            }
            $templateName = (string)$xml->Name;

            if (in_array($templateName, $runningContainerNames)) {
                // This template is for a running container, so we process it.
                $xmlContent = file_get_contents($file);
                if ($xmlContent === false) continue;

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
                    error_log("Composerize Plugin: Skipped template with invalid format after processing: {$file}");
                    continue;
                }
                
                $command = str_replace(
                    '/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/docker create',
                    'docker run',
                    $info[0]
                );
                $name = $info[1];

                $dockerTemplates[$name] = $command;
            }
        } catch (Throwable $t) {
            error_log("Composerize Plugin: Skipped incompatible template {$file}. Error: " . $t->getMessage());
        }
    }

    ksort($dockerTemplates);
    return $dockerTemplates;
}
