<?php

/**
 * composerize.php - Helper functions for the Composerize Unraid Plugin UI page.
 */

// --- Dependencies ---
require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php';
require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php';

/**
 * Manually parses a Docker template XML and builds a 'docker run' command.
 * This version uses escapeshellarg() for security and compatibility.
 */
function buildDockerRunCommand(SimpleXMLElement $xml): ?string
{
    if (!isset($xml->Name) || !isset($xml->Repository)) {
        return null;
    }

    $parts = ['docker run'];
    
    // Add detached mode by default
    $parts[] = '-d';

    // Container name
    $name = trim((string)$xml->Name);
    if (!empty($name)) {
        $parts[] = '--name ' . escapeshellarg($name);
    }

    // Network
    if (isset($xml->Network) && (string)$xml->Network !== 'bridge') {
        $network = trim((string)$xml->Network);
        if (!empty($network)) {
            $parts[] = '--net ' . escapeshellarg($network);
        }
    }

    // Privileged
    if (isset($xml->Privileged) && strtolower(trim((string)$xml->Privileged)) === 'true') {
        $parts[] = '--privileged';
    }

    // Process Config elements
    if (isset($xml->Config)) {
        foreach ($xml->Config as $config) {
            $attributes = $config->attributes();
            $type = isset($attributes['Type']) ? trim((string)$attributes['Type']) : '';
            $value = trim((string)$config);

            if ($value === '' && isset($attributes['Default'])) {
                $value = trim((string)$attributes['Default']);
            }
            
            if ($value === '') continue;

            switch ($type) {
                case 'Port':
                    $hostPort = $value;
                    $containerPort = trim((string)$attributes['Target']);
                    if (!empty($hostPort) && !empty($containerPort)) {
                        $parts[] = '-p ' . escapeshellarg($hostPort . ':' . $containerPort);
                    }
                    break;

                case 'Path':
                    $hostPath = $value;
                    $containerPath = trim((string)$attributes['Target']);
                    if (!empty($hostPath) && !empty($containerPath)) {
                        $parts[] = '-v ' . escapeshellarg($hostPath . ':' . $containerPath);
                    }
                    break;
                
                case 'Variable':
                    $nameAttr = trim((string)$attributes['Target']);
                    if (!empty($nameAttr)) {
                        $parts[] = '-e ' . escapeshellarg($nameAttr . '=' . $value);
                    }
                    break;
            }
        }
    }

    // Extra parameters
    if (isset($xml->ExtraParams)) {
        $extra = trim((string)$xml->ExtraParams);
        if (!empty($extra)) {
            $parts[] = $extra;
        }
    }

    // Repository/image
    $repository = trim((string)$xml->Repository);
    if (!empty($repository)) {
        $parts[] = escapeshellarg($repository);
    }

    return implode(' ', $parts);
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
