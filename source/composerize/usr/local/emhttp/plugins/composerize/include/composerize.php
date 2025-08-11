<?php
declare(strict_types=1);

/**
 * composerize.php - Helper functions for the Composerize Unraid Plugin.
 * NOTE: The 'declare(strict_types=1);' statement MUST be the very first
 * line after the opening <?php tag. No comments or blank lines can come before it.
 */

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
 * into an array of 'docker run' commands. This function includes a workaround
 * for a common fatal error in Unraid's xmlToCommand function.
 */
function getDockerTemplateList(): array
{
    $dockerTemplates = [];
    $files = glob(DOCKER_TEMPLATE_DIRECTORY . '*.xml');

    if ($files === false) {
        error_log('Composerize Plugin: Failed to read Docker template directory: ' . DOCKER_TEMPLATE_DIRECTORY);
        return [];
    }

    foreach ($files as $file) {
        try {
            // Pre-process the XML to prevent fatal errors in xmlToCommand()
            $xmlContent = file_get_contents($file);
            if ($xmlContent === false) {
                error_log("Composerize Plugin: Failed to read template file: {$file}");
                continue;
            }

            // The key_exists() error in Helpers.php happens when the <Network> tag is missing.
            // We can fix this by adding a default if it's not present.
            if (strpos($xmlContent, '<Network>') === false) {
                // Inject a default Network tag. This is the most common cause of the crash.
                $xmlContent = str_replace('</Container>', "  <Network>Bridge</Network>\n</Container>", $xmlContent);
                
                // Create a temporary file to pass to the Unraid function
                $tempFile = tempnam(sys_get_temp_dir(), 'composerize-');
                file_put_contents($tempFile, $xmlContent);
                $fileToProcess = $tempFile;
            } else {
                $fileToProcess = $file;
            }

            $info = xmlToCommand($fileToProcess, false);

            // Clean up the temporary file if it was created
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
            // This will catch any other unexpected errors.
            error_log("Composerize Plugin: Skipped incompatible template {$file}. Error: " . $t->getMessage());
        }
    }

    ksort($dockerTemplates);
    return $dockerTemplates;
}
