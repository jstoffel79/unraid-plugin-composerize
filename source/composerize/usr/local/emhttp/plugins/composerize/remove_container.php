<?php
declare(strict_types=1);

/**
 * remove_container.php - Stops and forcefully removes a Docker container.
 */

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Basic CSRF protection
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { 
    http_response_code(403); 
    exit('Invalid CSRF token'); 
}

$containerName = $_POST['container_name'] ?? '';

if (empty($containerName)) {
    http_response_code(400);
    exit('Container name not specified.');
}

// Sanitize the name to prevent command injection
$sanitizedName = escapeshellarg($containerName);
$timeout = 10; // 10-second timeout for graceful stop

error_log("Composerize Trace: Attempting to stop container '{$containerName}' with timeout {$timeout}s.");
// Stop the container with a timeout
exec("/usr/bin/docker stop -t {$timeout} {$sanitizedName} 2>&1", $stopOutput, $stopCode);

// Regardless of stop success, attempt to remove it forcefully
error_log("Composerize Trace: Attempting to forcefully remove container '{$containerName}'.");
exec("/usr/bin/docker rm -f {$sanitizedName} 2>&1", $removeOutput, $removeCode);

if ($removeCode === 0) {
    echo "Successfully removed container '{$containerName}'.";
} else {
    http_response_code(500);
    // Combine output for a more informative error message
    $fullOutput = array_merge($stopOutput, $removeOutput);
    $errorMessage = implode("\n", $fullOutput);
    error_log("Composerize Trace: Failed to remove container '{$containerName}'. Output: {$errorMessage}");
    exit("Failed to remove container '{$containerName}'.\n" . htmlspecialchars($errorMessage));
}
