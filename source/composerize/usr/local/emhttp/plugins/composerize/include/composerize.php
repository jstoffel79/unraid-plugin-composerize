<?php

/**
 * composerize.php - Helper functions for the Composerize Unraid Plugin UI page.
 */

// --- Dependencies ---
require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php';
require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php';

/**
 * Helper to quote a value for a command line argument if it contains spaces.
 */
function quoteValue(string $value): string
{
    // If the value contains spaces and is not already quoted, wrap it in double quotes.
    if (strpos($value, ' ') !== false && substr($value, 0, 1) !== '"') {
        return '"' . $value . '"';
    }
    return $value;
}


/**
 * Manually parses a Docker template XML and builds a 'docker run' command.
 * This version avoids escapeshellarg() to be compatible with the composerize JS library.
 */
function buildDockerRunCommand(SimpleXMLElement $xml): ?string
{
    if (!isset($xml->Name) || !isset($xml->Repository)) {
        return null;
    }

    $command = ['docker run'];
    $command[] = '--name=' . quoteValue((string)$xml->Name);

    if (isset($xml->Network) && (string)$xml->Network !== 'bridge') {
        $command[] = '--net=' . quoteValue((string)$xml->Network);
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
                        $command[] = '-p ' . quoteValue($hostPort . ':' . $containerPort);
                    }
                    break;

                case 'Path':
                    $hostPath = $value;
                    $containerPath = (string)$attributes['Target'];
                    if (!empty($hostPath) && !empty($containerPath)) {
                        $command[] = '-v ' . quoteValue($hostPath . ':' . $containerPath);
                    }
                    break;
                
                case 'Variable':
                    $name = (string)$attributes['Target'];
                    if (isset($name) && $value !== '') {
                        $command[] = '-e ' . quoteValue($name . '=' . $value);
                    }
                    break;
            }
        }
    }

    $command[] = quoteValue((string)$xml->Repository);
    
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
