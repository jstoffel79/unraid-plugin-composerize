<?php
declare(strict_types=1);

/**
 * composerize.php - Helper functions for the Composerize Unraid Plugin.
 */

// --- Constants ---
define('COMPOSE_DIRECTORY', '/boot/config/plugins/compose.manager/projects/');

// --- Dependencies ---
require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php';
// We no longer need Helpers.php because we are parsing the XML manually.

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
    error_log("Composerize Trace: Inside installCompose() for stack '{$name}'.");
    
    $composeProjectDirectory = COMPOSE_DIRECTORY . $name;
    $composeYamlFilePath = $composeProjectDirectory . '/docker-compose.yml';
    $composeNameFilePath = $composeProjectDirectory . '/name';

    error_log("Composerize Trace: Project directory path: {$composeProjectDirectory}");

    if (!$force && (file_exists($composeProjectDirectory) || file_exists($composeYamlFilePath))) {
        error_log("Composerize Trace: Stack '{$name}' already exists and force is false. Aborting.");
        return false;
    }

    if (!is_dir($composeProjectDirectory)) {
        error_log("Composerize Trace: Directory does not exist. Attempting to create: {$composeProjectDirectory}");
        if (!@mkdir($composeProjectDirectory, 0755, true)) {
            $error = error_get_last();
            throw new Exception("Failed to create project directory. Check permissions for: " . COMPOSE_DIRECTORY . ". OS Error: " . ($error['message'] ?? 'Unknown error'));
        }
        error_log("Composerize Trace: Directory created successfully.");
    }

    error_log("Composerize Trace: Attempting to write name file: {$composeNameFilePath}");
    $nameWritten = file_put_contents($composeNameFilePath, $name);
    if ($nameWritten === false) {
        $error = error_get_last();
        throw new Exception("Failed to write 'name' file. Check permissions for: {$composeProjectDirectory}. OS Error: " . ($error['message'] ?? 'Unknown error'));
    }
    error_log("Composerize Trace: Name file written successfully.");

    error_log("Composerize Trace: Attempting to write YAML file: {$composeYamlFilePath}");
    $yamlWritten = file_put_contents($composeYamlFilePath, $compose);
    if ($yamlWritten === false) {
        $error = error_get_last();
        throw new Exception("Failed to write 'docker-compose.yml' file. Check permissions for: {$composeProjectDirectory}. OS Error: " . ($error['message'] ?? 'Unknown error'));
    }
    error_log("Composerize Trace: YAML file written successfully.");

    return true;
}

/**
 * Manually parses a Docker template XML and builds a 'docker run' command.
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
    
    if (isset($xml->ExtraParams)) {
        $command[] = (string)$xml->ExtraParams;
    }

    if (isset($xml->Config)) {
        foreach ($xml->Config as $config) {
            $attributes = $config->attributes();
            $type = isset($attributes['Type']) ? (string)$attributes['Type'] : '';
            $value = (string)$config;

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
                    if (isset($name) && $value !== '') {
                        $command[] = '-e ' . escapeshellarg($name . '=' . $value);
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

    foreach ($containers as $container) {
        if (!empty($container['Running'])) {
            $runningContainerNames[] = $container['Name'];
        }
    }

    if (empty($runningContainerNames)) {
        error_log('Composerize Plugin: No running containers found.');
        return [];
    }

    $userTemplates = glob('/boot/config/plugins/dockerMan/templates-user/*.xml');
    $defaultTemplates = glob('/boot/config/plugins/dockerMan/templates/*.xml');
    $allTemplateFiles = array_merge($userTemplates ?: [], $defaultTemplates ?: []);

    if (empty($allTemplateFiles)) {
        error_log('Composerize Plugin: No template files found in user or default directories.');
        return [];
    }

    foreach ($allTemplateFiles as $file) {
        try {
            $xml = @simplexml_load_file($file);
            if ($xml === false || !isset($xml->Name)) {
                error_log("Composerize Plugin: Skipping malformed template file: {$file}");
                continue; 
            }
            $templateName = (string)$xml->Name;

            if (in_array($templateName, $runningContainerNames)) {
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
