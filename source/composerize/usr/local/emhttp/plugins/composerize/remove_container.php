<?php
/**
 * remove_container.php - Handles Docker container removal
 */

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Error: This script only accepts POST requests.";
    exit;
}

// Get container name from POST data
$containerName = $_POST['container_name'] ?? null;

if (empty($containerName)) {
    http_response_code(400);
    echo "Error: Container name is required.";
    exit;
}

// Sanitize container name
$containerName = preg_replace('/[^a-zA-Z0-9_-]/', '', $containerName);

if (empty($containerName)) {
    http_response_code(400);
    echo "Error: Invalid container name.";
    exit;
}

/**
 * Safely execute Docker commands
 */
function executeDockerCommand(string $command): array
{
    $output = [];
    $returnCode = 0;
    
    // Execute command and capture output
    exec($command . ' 2>&1', $output, $returnCode);
    
    return [
        'success' => $returnCode === 0,
        'output' => implode("\n", $output),
        'return_code' => $returnCode
    ];
}

/**
 * Check if container exists and is running
 */
function getContainerStatus(string $containerName): array
{
    $result = executeDockerCommand("docker inspect --format='{{.State.Status}}' " . escapeshellarg($containerName));
    
    if (!$result['success']) {
        return ['exists' => false, 'running' => false, 'status' => 'not_found'];
    }
    
    $status = trim($result['output']);
    return [
        'exists' => true,
        'running' => $status === 'running',
        'status' => $status
    ];
}

/**
 * Stop and remove a Docker container
 */
function removeContainer(string $containerName): array
{
    $status = getContainerStatus($containerName);
    
    if (!$status['exists']) {
        return ['success' => false, 'message' => "Container '{$containerName}' does not exist."];
    }
    
    $steps = [];
    
    // Stop container if running
    if ($status['running']) {
        $steps[] = "Stopping container '{$containerName}'...";
        $result = executeDockerCommand("docker stop " . escapeshellarg($containerName));
        
        if (!$result['success']) {
            return [
                'success' => false, 
                'message' => "Failed to stop container '{$containerName}': " . $result['output'],
                'steps' => $steps
            ];
        }
        $steps[] = "Container stopped successfully.";
    } else {
        $steps[] = "Container '{$containerName}' is already stopped.";
    }
    
    // Remove container
    $steps[] = "Removing container '{$containerName}'...";
    $result = executeDockerCommand("docker rm " . escapeshellarg($containerName));
    
    if (!$result['success']) {
        return [
            'success' => false,
            'message' => "Failed to remove container '{$containerName}': " . $result['output'],
            'steps' => $steps
        ];
    }
    
    $steps[] = "Container removed successfully.";
    
    return [
        'success' => true,
        'message' => "Container '{$containerName}' has been stopped and removed successfully.",
        'steps' => $steps
    ];
}

try {
    error_log("Composerize: Attempting to remove container '{$containerName}'");
    
    $result = removeContainer($containerName);
    
    if ($result['success']) {
        http_response_code(200);
        echo $result['message'];
        error_log("Composerize: Successfully removed container '{$containerName}'");
    } else {
        http_response_code(400);
        echo $result['message'];
        error_log("Composerize: Failed to remove container '{$containerName}': " . $result['message']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    $errorMsg = "An error occurred while removing container: " . htmlspecialchars($e->getMessage());
    echo $errorMsg;
    error_log("Composerize: Exception while removing container '{$containerName}': " . $e->getMessage());
}
