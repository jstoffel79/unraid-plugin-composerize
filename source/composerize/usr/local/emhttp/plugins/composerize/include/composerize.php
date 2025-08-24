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
    // Trim the value first to handle extraneous whitespace.
    $trimmedValue = trim($value);
    // If the value contains spaces and is not already quoted, wrap it in double quotes.
    if (strpos($trimmedValue, ' ') !== false && substr($trimmedValue, 0, 1) !== '"' && substr($trimmedValue, 0, 1) !== "'") {
        return '"' . $trimmedValue . '"';
    }
    return $trimmedValue;
}


/**
 * Manually parses a Docker template XML and builds a 'docker run' command.
 * This version is more robust, trimming all inputs and separating flags from values
 * to ensure compatibility with the composerize JS library.
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

    if (isset($xml->Privileged) && strtolower(trim((string)$xml->Privileged)) === 'true') {
        $command[] = '--privileged';
    }
    
    if (isset($xml->ExtraParams)) {
        $extra = trim((string)$xml->ExtraParams);
        if (!empty($extra)) {
            $command[] = $extra;
        }
    }

    if (isset($xml->Config)) {
        foreach ($xml->Config as $config) {
            $attributes = $config->attributes();
            $type = isset($attributes['Type']) ? trim((string)$attributes['Type']) : '';
            $value = trim((string)$config);

            if ($value === '' && isset($attributes['Default'])) {
                $value = trim((string)$attributes['Default']);
            }
            
            // Skip any parameters that are ultimately empty.
            if ($value === '') continue;

            switch ($type) {
                case 'Port':
                    $hostPort = $value;
                    $containerPort = trim((string)$attributes['Target']);
                    if (!empty($hostPort) && !empty($containerPort)) {
                        $command[] = '-p';
                        $command[] = quoteValue($hostPort . ':' . $containerPort);
                    }
                    break;

                case 'Path':
                    $hostPath = $value;
                    $containerPath = trim((string)$attributes['Target']);
                    if (!empty($hostPath) && !empty($containerPath)) {
                        $command[] = '-v';
                        $command[] = quoteValue($hostPath . ':' . $containerPath);
                    }
                    break;
                
                case 'Variable':
                    $name = trim((string)$attributes['Target']);
                    if (!empty($name)) {
                        $command[] = '-e';
                        $command[] = quoteValue($name . '=' . $value);
                    }
                    break;
            }
        }
    }

    $command[] = quoteValue((string)$xml->Repository);
    
    // Filter out any empty elements that might have crept in before joining.
    $filteredCommand = array_filter($command, function($part) {
        return trim($part) !== '';
    });

    return implode(' ', $filteredCommand);
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
