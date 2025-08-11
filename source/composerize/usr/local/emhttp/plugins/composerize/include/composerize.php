<?php
declare(strict_types=1);

/**
 * composerize.php - Helper functions for the Composerize Unraid Plugin.
 */

// --- Constants ---
define('COMPOSE_DIRECTORY', '/boot/config/plugins/compose.manager/projects/');

// --- Dependencies ---
require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php';

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
 * Manually parses a Docker template XML and builds a 'docker run' command.
 * This is more robust than relying on Unraid's internal functions.
 */
function buildDockerRunCommand(SimpleXMLElement $xml): ?string
{
    if (!isset($xml->Name) || !isset($xml->Repository)) {
        return null;
    }

    $command = ['docker run'];
    $command[] = '--name=' . escapeshellarg((string)$xml->Name);

    if (isset($xml->Network) && (string)$xml->Network !== 'bridge') {
        $command[] = '--net=' . escapeshellarg((string)$xml->Network);
    }

    if (isset($xml->Privileged) && strtolower((string)$xml->Privileged) === 'true') {
        $command[] = '--privileged';
    }

    if (isset($xml->Restart)) {
        $command[] = '--restart=' . escapeshellarg((string)$xml->Restart);
    }
    
    if (isset($xml->ExtraParams)) {
        $command[] = (string)$xml->ExtraParams;
    }

    // Process all Config tags (Ports, Paths, Variables, etc.)
    if (isset($xml->Config)) {
        foreach ($xml->Config as $config) {
            $attributes = $config->attributes();
            $type = isset($attributes['Type']) ? (string)$attributes['Type'] : '';
            $value = (string)$config;

            // Use the Default value from the attribute if the main value is empty
            if ($value === '' && isset($attributes['Default'])) {
                $value = (string)$attributes['Default'];
            }

            switch ($type) {
                case 'Port':
                    $hostPort = $value;
                    $containerPort = (string)$attributes['Target'];
                    if (!empty($hostPort) && !empty($containerPort)) {
                        $command[] = '-p ' . escapeshellarg($hostPort . ':' . $containerPort);
                    }
                    break;

                case 'Path':
                    $hostPath = $value;
                    $containerPath = (string)$attributes['Target'];
                    if (!empty($hostPath) && !empty($containerPath)) {
                        $command[] = '-v ' . escapeshellarg($hostPath . ':' . $containerPath);
                    }
                    break;
                
                case 'Variable':
                    $name = (string)$attributes['Target'];
                    if (isset($name)) {
                        // Only add environment variables that have a value.
                        if ($value !== '') {
                            $command[] = '-e ' . escapeshellarg($name . '=' . $value);
                        }
                    }
                    break;
            }
        }
    }

    $command[] = escapeshellarg((string)$xml->Repository);
    
    return implode(' ', array_filter($command));
}


/**
 * Gets a list of templates for currently running Docker containers.
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
                $command = buildDockerRunCommand($xml);
                if ($command) {
                    $dockerTemplates[$templateName] = $command;
                } else {
                    error_log("Composerize Plugin: Failed to build command for template: {$file}");
                }
            }
        } catch (Throwable $t) {
            error_log("Composerize Plugin: Skipped template due to an unexpected error {$file}. Error: " . $t->getMessage());
        }
    }

    ksort($dockerTemplates);
    return $dockerTemplates;
}
