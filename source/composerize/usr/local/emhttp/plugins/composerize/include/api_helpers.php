<?php
/**
 * api_helpers.php - DEBUGGING VERSION
 * This version contains a dummy installCompose function to test the API call chain
 * without touching the filesystem.
 */

/**
 * Validates a given string to see if it's a non-empty YAML string.
 */
function isValidYaml(?string $yamlString): bool
{
    return !empty($yamlString);
}

/**
 * DUMMY installCompose function for debugging.
 * This function does NOT write any files. It only logs that it was called.
 */
function installCompose(string $name, string $compose, bool $force): bool
{
    // Log a success message to the main Unraid system log.
    error_log("Composerize Trace: Dummy installCompose() executed successfully for stack '{$name}'.");
    
    // Return true to make the UI show a success message.
    return true;
}
