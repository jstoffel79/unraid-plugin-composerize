<?php

/**
 * composerize.php - Helper functions for the Composerize Unraid Plugin UI page.
 */

// --- Dependencies ---
require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php';
require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php';

/**
 * Manually parses a Docker template XML and builds a 'docker run' command.
 * This version is optimized for compatibility with the composerize JS library.
 */
function buildDockerRunCommand(SimpleXMLElement $xml): ?string
{
    if (!isset($xml->Name) || !isset($xml->Repository)) {
        return null;
    }
    $parts = [];
    $parts[] = 'docker run';
    
    // Add detached mode (required for most containers)
    $parts[] = '-d';
    
    // Container name - use simple quoting
    $name = trim((string)$xml->Name);
    if (!empty($name)) {
        $parts[] = '--name ' . escapeshellarg($name);
    }
    // Network configuration
    if (isset($xml->Network)) {
        $network = trim((string)$xml->Network);
        if (!empty($network) && $network !== 'bridge') {
            $parts[] = '--network ' . escapeshellarg($network);
        }
    }
    // Privileged mode
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
                        // Clean container port - remove protocol specifications for composerize
                        $containerPortClean = preg_replace('/\/[a-zA-Z]+$/', '', $containerPort);
                        $parts[] = '-p ' . escapeshellarg($hostPort . ':' . $containerPortClean);
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
                    $name = trim((string)$attributes['Target']);
                    if (!empty($name)) {
                        $parts[] = '-e ' . escapeshellarg($name . '=' . $value);
                    }
                    break;
            }
        }
    }
    // Extra parameters - add them carefully
    if (isset($xml->ExtraParams)) {
        $extra = trim((string)$xml->ExtraParams);
        if (!empty($extra)) {
            // Only add extra params if they don't look malformed
            if (preg_match('/^[\w\s\-=:\/\.]*$/', $extra)) {
                $parts[] = $extra;
            }
        }
    }
    // Repository/image name
    $repository = trim((string)$xml->Repository);
    if (!empty($repository)) {
        $parts[] = escapeshellarg($repository);
    }
    $command = implode(' ', $parts);
    
    // Log the command for debugging
    error_log("Composerize Plugin: Generated command: {$command}");
    
    return $command;
}
/**
 * Minimal version for maximum composerize compatibility
 */
function buildDockerRunCommandMinimal(SimpleXMLElement $xml): ?string
{
    if (!isset($xml->Name) || !isset($xml->Repository)) {
        return null;
    }
    $cmd = 'docker run -d';
    
    // Container name
    $name = trim((string)$xml->Name);
    if (!empty($name)) {
        $cmd .= ' --name ' . escapeshellarg($name);
    }
    // Only add the most essential elements for testing
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
                        // Remove any protocol suffix and ensure clean format
                        $containerPort = preg_replace('/\/[a-zA-Z]+$/', '', $containerPort);
                        $cmd .= ' -p ' . escapeshellarg($hostPort . ':' . $containerPort);
                    }
                    break;
                case 'Variable':
                    $name = trim((string)$attributes['Target']);
                    if (!empty($name)) {
                        $cmd .= ' -e ' . escapeshellarg($name . '=' . $value);
                    }
                    break;
                case 'Path':
                    $hostPath = $value;
                    $containerPath = trim((string)$attributes['Target']);
                    if (!empty($hostPath) && !empty($containerPath)) {
                        $cmd .= ' -v ' . escapeshellarg($hostPath . ':' . $containerPath);
                    }
                    break;
            }
        }
    }
    // Repository/image
    $repository = trim((string)$xml->Repository);
    if (!empty($repository)) {
        $cmd .= ' ' . escapeshellarg($repository);
    }
    // Log for debugging
    error_log("Composerize Plugin: Minimal command: {$cmd}");
    
    return $cmd;
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
