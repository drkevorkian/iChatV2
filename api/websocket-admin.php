<?php
/**
 * Sentinel Chat Platform - WebSocket Server Admin API
 * 
 * Handles WebSocket server management: status, start, stop, restart, logs.
 * 
 * Security: Admin-only access required.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\SecurityService;
use iChat\Services\AuthService;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication and admin/owner role
$authService = new AuthService();
$currentUser = $authService->getCurrentUser();

$userRole = $currentUser['role'] ?? '';
if (!$currentUser || !in_array($userRole, ['administrator', 'owner', 'trusted_admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin access required']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? 'status';

// WebSocket server configuration
$websocketConfig = \iChat\Config::getInstance();
// Default to port 8420 (Node.js default) instead of 8080
$websocketUrl = $websocketConfig->get('websocket.url') ?: 'ws://localhost:8420';
$websocketPort = 8420; // Changed default from 8080 to 8420
$websocketHost = 'localhost';

// Parse URL if provided
if (!empty($websocketUrl) && is_string($websocketUrl)) {
    $parsed = parse_url($websocketUrl);
    if ($parsed !== false) {
        $websocketPort = isset($parsed['port']) ? (int)$parsed['port'] : 8420; // Changed default from 8080 to 8420
        $websocketHost = isset($parsed['host']) ? $parsed['host'] : 'localhost';
    }
}

// Fallback to config values if available
if ($websocketConfig->get('websocket.port')) {
    $websocketPort = (int)$websocketConfig->get('websocket.port');
}
if ($websocketConfig->get('websocket.host')) {
    $websocketHost = $websocketConfig->get('websocket.host');
}

$serverScript = __DIR__ . '/../websocket-server.js';
$logFile = __DIR__ . '/../logs/websocket.log';
$pidFile = __DIR__ . '/../logs/websocket.pid';

// Python server configuration
$pythonScript = __DIR__ . '/../websocket-server-python.py';
$pythonLogFile = __DIR__ . '/../logs/websocket-python.log';
$pythonPidFile = __DIR__ . '/../logs/websocket-python.pid';
$pythonPort = 4291;

try {
    switch ($action) {
        case 'status':
            // Check if server is running
            $status = checkWebSocketStatus($websocketHost, $websocketPort, $pidFile);
            
            // Determine which port the server is actually running on
            // checkWebSocketStatus already checks both ports, so if running, it found the right port
            $actualPort = $websocketPort;
            if ($status['running']) {
                // Check if server is on port 8420 (Node.js default) instead of configured port
                $port8420Check = @fsockopen($websocketHost, 8420, $errno, $errstr, 0.1);
                if ($port8420Check) {
                    fclose($port8420Check);
                    $actualPort = 8420; // Server is running on Node.js default port
                }
            }
            
            // If server is running, try to fetch stats from it (but don't fail if it doesn't respond)
            $serverStats = null;
            if ($status['running']) {
                try {
                    // Try port 8420 first (Node.js default), then configured port
                    $serverStats = @fetchWebSocketServerStats($websocketHost, 8420);
                    if (!$serverStats && $actualPort != 8420) {
                        // Try the configured port as fallback
                        $serverStats = @fetchWebSocketServerStats($websocketHost, $websocketPort);
                    }
                } catch (\Exception $e) {
                    // Silently fail - server might be starting up or stats endpoint not available
                    error_log('Failed to fetch WebSocket stats: ' . $e->getMessage());
                }
            }
            
            echo json_encode([
                'success' => true,
                'status' => $status['running'] ? 'running' : 'stopped',
                'pid' => $status['pid'] ?? null,
                'port' => $actualPort, // Report actual port server is running on
                'host' => $websocketHost,
                'uptime' => $serverStats['uptime'] ?? $status['uptime'] ?? null,
                'connections' => $serverStats['total_connections'] ?? $status['connections'] ?? 0,
                'connected_users' => $serverStats['connected_users'] ?? 0,
                'active_rooms' => $serverStats['active_rooms'] ?? 0,
                'users' => $serverStats['users'] ?? [],
                'rooms' => $serverStats['rooms'] ?? [],
                'stats' => $serverStats, // Full stats object
                'running' => $status['running'] // Add explicit running flag for UI
            ]);
            break;
            
        case 'start':
            // Start WebSocket server
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('POST method required');
            }
            
            $result = startWebSocketServer($serverScript, $logFile, $pidFile);
            echo json_encode([
                'success' => $result['success'],
                'message' => $result['message'],
                'pid' => $result['pid'] ?? null
            ]);
            break;
            
        case 'stop':
            // Stop WebSocket server
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('POST method required');
            }
            
            $result = stopWebSocketServer($pidFile);
            echo json_encode([
                'success' => $result['success'],
                'message' => $result['message']
            ]);
            break;
            
        case 'restart':
            // Restart WebSocket server (truly non-blocking - returns immediately)
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('POST method required');
            }
            
            // Get old PID before stopping
            // SECURITY: Check file exists before reading to prevent errors
            $oldPid = null;
            if (file_exists($pidFile) && is_readable($pidFile)) {
                $pidContent = @file_get_contents($pidFile);
                if ($pidContent !== false) {
                    $oldPid = (int)trim($pidContent);
                }
            }
            
            // Trigger restart in background (non-blocking)
            $restartResult = restartWebSocketServerNonBlocking($serverScript, $logFile, $pidFile, $websocketHost, $websocketPort);
            
            echo json_encode([
                'success' => $restartResult['success'],
                'message' => $restartResult['message'],
                'old_pid' => $oldPid,
                'polling_required' => true // Always poll - restart happens in background
            ]);
            break;
            
        case 'logs':
            // Get server logs (Python or Node.js)
            $serverType = $_GET['server'] ?? 'node';
            $targetLogFile = ($serverType === 'python') ? $pythonLogFile : $logFile;
            
            if ($method === 'POST' && isset($_GET['clear']) && $_GET['clear'] === '1') {
                // Clear logs
                if (file_exists($targetLogFile)) {
                    file_put_contents($targetLogFile, '');
                }
                echo json_encode([
                    'success' => true,
                    'message' => ucfirst($serverType) . ' server logs cleared'
                ]);
            } else {
                // Get logs
                $lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 100;
                $logs = getWebSocketLogs($targetLogFile, $lines);
                echo json_encode([
                    'success' => true,
                    'logs' => $logs,
                    'server' => $serverType
                ]);
            }
            break;
            
        case 'python-status':
            // Check Python server status
            $pythonStatus = checkPythonServerStatus($pythonPort, $pythonPidFile);
            
            // Try to fetch stats from Python server (same way as Node.js)
            $pythonStats = null;
            if ($pythonStatus['running']) {
                try {
                    $pythonStats = @fetchPythonServerStats('localhost', $pythonPort);
                } catch (\Exception $e) {
                    // Silently fail - server might be starting up or stats endpoint not available
                    error_log('Failed to fetch Python stats: ' . $e->getMessage());
                }
            }
            
            // Extract stats from Python server response (same structure as Node.js)
            $statsData = $pythonStats['stats'] ?? null;
            $pythonServerData = $statsData['python_server'] ?? null;
            $nodeServerData = $statsData['node_server'] ?? null;
            
            echo json_encode([
                'success' => true,
                'status' => $pythonStatus['running'] ? 'running' : 'stopped',
                'pid' => $pythonStatus['pid'] ?? $pythonServerData['pid'] ?? null,
                'port' => $pythonPort,
                'uptime' => $pythonServerData['uptime'] ?? $pythonStatus['uptime'] ?? null,
                'node_restarts' => $pythonServerData['node_restarts'] ?? $nodeServerData['restart_count'] ?? 0,
                'connected_clients' => $pythonServerData['connected_clients'] ?? 0,
                'active_rooms' => $pythonServerData['active_rooms'] ?? 0,
                'node_running' => $nodeServerData['running'] ?? false,
                'node_pid' => $nodeServerData['pid'] ?? null,
                'node_port' => $nodeServerData['port'] ?? null,
                'stats' => $statsData, // Full stats object (same as Node.js)
                'running' => $pythonStatus['running'] // Add explicit running flag for UI
            ]);
            break;
            
        case 'python-start':
            // Start Python server
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('POST method required');
            }
            
            $result = startPythonServer($pythonScript, $pythonLogFile, $pythonPidFile);
            echo json_encode([
                'success' => $result['success'],
                'message' => $result['message'],
                'pid' => $result['pid'] ?? null
            ]);
            break;
            
        case 'python-stop':
            // Stop Python server (but NOT Node.js)
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('POST method required');
            }
            
            $result = stopPythonServer($pythonPidFile);
            echo json_encode([
                'success' => $result['success'],
                'message' => $result['message']
            ]);
            break;
            
        case 'python-restart':
            // Restart Python server (truly non-blocking - returns immediately)
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('POST method required');
            }
            
            $oldPid = null;
            if (file_exists($pythonPidFile)) {
                $oldPid = (int)trim(file_get_contents($pythonPidFile));
            }
            
            // Trigger restart in background (non-blocking)
            $restartResult = restartPythonServerNonBlocking($pythonScript, $pythonLogFile, $pythonPidFile, $pythonPort);
            
            echo json_encode([
                'success' => $restartResult['success'],
                'message' => $restartResult['message'],
                'old_pid' => $oldPid,
                'polling_required' => true // Always poll - restart happens in background
            ]);
            break;
            
        default:
            throw new \InvalidArgumentException('Invalid action');
    }
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}

/**
 * Check if WebSocket server is running
 * Priority: Port check → Find PID by port → Compare with stored PID → Update if different
 */
function checkWebSocketStatus(string $host, int $port, string $pidFile): array {
    $result = [
        'running' => false,
        'pid' => null,
        'uptime' => null,
        'connections' => 0
    ];
    
    // STEP 1: Check if configured port OR Node.js default port (8420) is listening
    // Check port 8420 first (Node.js default) since that's where the server actually runs
    $portsToCheck = [];
    if ($port != 8420) {
        $portsToCheck[] = 8420; // Check Node.js default port first
    }
    $portsToCheck[] = $port; // Then check configured port
    
    $listeningPort = null;
    $foundPid = null;
    
    foreach ($portsToCheck as $checkPort) {
        // Step 1: Use netstat -abn for fast port checking (super fast as user requested)
        // Step 2: If port is LISTENING, use netstat -ano to get PID
        if (PHP_OS_FAMILY === 'Windows') {
            // Fast check: is port LISTENING?
            $portCheck = shell_exec("netstat -abn | findstr \":{$checkPort}\"");
            if (!empty($portCheck) && strpos($portCheck, 'LISTENING') !== false) {
                $listeningPort = $checkPort;
                
                // Now get PID using -ano (we need PID anyway)
                $pidCheck = shell_exec("netstat -ano | findstr \":{$checkPort}\" | findstr \"LISTENING\"");
                if (!empty($pidCheck)) {
                    // Extract PID from netstat -ano output
                    // Format: TCP    0.0.0.0:8420           0.0.0.0:0              LISTENING       18848
                    preg_match('/LISTENING\s+(\d+)/', $pidCheck, $matches);
                    $foundPid = isset($matches[1]) ? (int)$matches[1] : null;
                    
                    if ($foundPid && isProcessRunning($foundPid)) {
                        break; // Found valid PID, stop checking
                    }
                }
            }
        } else {
            // Linux: use lsof to find PID by port
            $portCheck = shell_exec("lsof -ti:{$checkPort} 2>/dev/null");
            if (!empty($portCheck)) {
                $listeningPort = $checkPort;
                $foundPid = (int)trim($portCheck);
                if ($foundPid && isProcessRunning($foundPid)) {
                    break; // Found valid PID, stop checking
                }
            }
        }
    }
    
    if (!$listeningPort || !$foundPid || !isProcessRunning($foundPid)) {
        // No port listening or PID invalid - server is not running
        $result['running'] = false;
        $result['pid'] = null;
        
        // SECURITY: Check file exists and is readable before reading
        // Clean up stale PID file if it exists
        if (file_exists($pidFile) && is_readable($pidFile)) {
            $pidContent = @file_get_contents($pidFile);
            if ($pidContent !== false) {
                $storedPid = (int)trim($pidContent);
                if ($storedPid > 0 && !isProcessRunning($storedPid)) {
                    error_log("WebSocket server not running (no ports listening), cleaning up stale PID file (PID: {$storedPid})");
                    @unlink($pidFile);
                }
            }
        }
        return $result;
    }
    
    // Port is listening and PID is valid - server is running
    $result['running'] = true;
    
    // SECURITY: Check file exists and is readable before reading
    // STEP 2: Compare with stored PID and update if different
    $storedPid = null;
    if (file_exists($pidFile) && is_readable($pidFile)) {
        $pidContent = @file_get_contents($pidFile);
        if ($pidContent !== false) {
            $storedPid = (int)trim($pidContent);
        }
    }
    
    if ($storedPid !== $foundPid) {
        // PID changed (or file didn't exist) - update it
        error_log("WebSocket PID detected: stored={$storedPid}, found={$foundPid} on port {$listeningPort}, updating PID file");
        file_put_contents($pidFile, $foundPid);
        $result['pid'] = $foundPid;
    } else {
        // PID matches - use it
        $result['pid'] = $foundPid;
    }
    
    // STEP 3: Calculate uptime for the current PID
    if (PHP_OS_FAMILY === 'Windows') {
        $psCommand = "Get-Process -Id {$foundPid} -ErrorAction SilentlyContinue | Select-Object -ExpandProperty StartTime";
        $output = [];
        exec("powershell -Command \"$psCommand\"", $output);
        if (!empty($output) && !empty($output[0])) {
            try {
                $startTime = new \DateTime($output[0]);
                $now = new \DateTime();
                $diff = $now->diff($startTime);
                $totalSeconds = ($diff->days * 86400) + ($diff->h * 3600) + ($diff->i * 60) + $diff->s;
                $result['uptime'] = formatUptime($totalSeconds);
            } catch (\Exception $e) {
                $result['uptime'] = 'Unknown';
            }
        } else {
            $result['uptime'] = 'Unknown';
        }
    } else {
        // Linux: use /proc to get process start time
        $procFile = "/proc/{$foundPid}/stat";
        if (file_exists($procFile)) {
            $stat = file_get_contents($procFile);
            if ($stat) {
                $parts = explode(' ', $stat);
                if (isset($parts[21])) {
                    $startTime = (int)$parts[21];
                    $uptime = time() - $startTime;
                    $result['uptime'] = formatUptime($uptime);
                } else {
                    $result['uptime'] = 'Unknown';
                }
            } else {
                $result['uptime'] = 'Unknown';
            }
        } else {
            $result['uptime'] = 'Unknown';
        }
    }
    
    return $result;
}

/**
 * Check if a process is running
 */
function isProcessRunning(int $pid): bool {
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows: use tasklist
        $output = [];
        exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output);
        foreach ($output as $line) {
            if (strpos($line, (string)$pid) !== false) {
                return true;
            }
        }
        return false;
    } else {
        // Unix/Linux: use kill -0
        return posix_kill($pid, 0);
    }
}

/**
 * Start WebSocket server
 */
function startWebSocketServer(string $script, string $logFile, string $pidFile): array {
    global $websocketHost, $websocketPort;
    
    // Check if port is already in use (check both configured port and Node.js default port 8420)
    $portsToCheck = [$websocketPort];
    // Also check port 8420 if Node.js might be using it
    if ($websocketPort != 8420) {
        $portsToCheck[] = 8420;
    }
    
    foreach ($portsToCheck as $checkPort) {
        if (PHP_OS_FAMILY === 'Windows') {
            // Step 1: Fast check with netstat -abn
            $portCheck = shell_exec("netstat -abn | findstr \":{$checkPort}\"");
            if (!empty($portCheck) && strpos($portCheck, 'LISTENING') !== false) {
                // Step 2: Get PID using -ano
                $pidCheck = shell_exec("netstat -ano | findstr \":{$checkPort}\" | findstr \"LISTENING\"");
                if (!empty($pidCheck)) {
                    // Extract PID from netstat -ano output
                    preg_match('/LISTENING\s+(\d+)/', $pidCheck, $matches);
                    $portPid = isset($matches[1]) ? (int)$matches[1] : null;
                    
                    if ($portPid) {
                        // Check if it's our process (from PID file)
                        if (file_exists($pidFile)) {
                            $existingPid = (int)trim(file_get_contents($pidFile));
                            if ($existingPid == $portPid && isProcessRunning($existingPid)) {
                                return [
                                    'success' => false,
                                    'message' => 'WebSocket server is already running on port ' . $checkPort . ' (PID: ' . $existingPid . ')'
                                ];
                            }
                        }
                        
                        // Port is in use by another process
                        return [
                            'success' => false,
                            'message' => 'Port ' . $checkPort . ' is already in use by process ' . $portPid . '. Please stop it first or change the port.'
                        ];
                    }
                }
            }
        } else {
            // Unix/Linux: use lsof or netstat
            $portCheck = shell_exec("lsof -i :{$checkPort} 2>/dev/null | grep LISTEN");
            if (!empty($portCheck)) {
                preg_match('/\s+(\d+)\s+/', $portCheck, $matches);
                $portPid = isset($matches[1]) ? (int)$matches[1] : null;
                
                if ($portPid) {
                    if (file_exists($pidFile)) {
                        $existingPid = (int)trim(file_get_contents($pidFile));
                        if ($existingPid == $portPid && isProcessRunning($existingPid)) {
                            return [
                                'success' => false,
                                'message' => 'WebSocket server is already running on port ' . $checkPort . ' (PID: ' . $existingPid . ')'
                            ];
                        }
                    }
                    return [
                        'success' => false,
                        'message' => 'Port ' . $checkPort . ' is already in use by process ' . $portPid . '. Please stop it first or change the port.'
                    ];
                }
            }
        }
    }
    
    // SECURITY: Check file exists and is readable before reading
    // Check if already running (by PID file)
    if (file_exists($pidFile) && is_readable($pidFile)) {
        $pidContent = @file_get_contents($pidFile);
        if ($pidContent !== false) {
            $pid = (int)trim($pidContent);
            if ($pid > 0 && isProcessRunning($pid)) {
                return [
                    'success' => false,
                    'message' => 'WebSocket server is already running (PID: ' . $pid . ')'
                ];
            }
        }
        // Clean up stale PID file
        @unlink($pidFile);
    }
    
    
    // Ensure log directory exists
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    // Start server in background
    $nodePath = findNodePath();
    $command = escapeshellarg($script);
    $logPath = escapeshellarg($logFile);
    
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows: use PowerShell to start process and capture PID
        $nodePathEscaped = escapeshellarg($nodePath);
        $scriptEscaped = escapeshellarg($script);
        $logPathEscaped = escapeshellarg($logFile);
        
        // Ensure log directory exists
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        // Use PowerShell to start process in background
        // Create a temporary PowerShell script file to avoid escaping issues
        $psScriptFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'start_websocket_' . uniqid() . '.ps1';
        
        // Build PowerShell script content - use absolute paths
        $nodePathAbs = realpath($nodePath) ?: $nodePath;
        $scriptAbs = realpath($script) ?: $script;
        $logPathAbs = $logFile; // Use the log file path as-is
        
        // Ensure log directory exists
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $psScriptContent = "\$ErrorActionPreference = 'Stop'\n";
        $psScriptContent .= "try {\n";
        // Use Start-Process with separate log files, then merge them
        // PowerShell doesn't allow redirecting both stdout and stderr to the same file
        $errLogFile = $logPathAbs . '.err';
        $psScriptContent .= "    \$proc = Start-Process -FilePath '" . str_replace("'", "''", $nodePathAbs) . "' -ArgumentList '" . str_replace("'", "''", $scriptAbs) . "' -WindowStyle Hidden -PassThru -RedirectStandardOutput '" . str_replace("'", "''", $logPathAbs) . "' -RedirectStandardError '" . str_replace("'", "''", $errLogFile) . "'\n";
        $psScriptContent .= "    if (\$proc) {\n";
        $psScriptContent .= "        [Console]::Out.WriteLine(\$proc.Id)\n";
        $psScriptContent .= "        # Merge stderr into stdout log file in background\n";
        $psScriptContent .= "        Start-Job -ScriptBlock { param(\$errFile, \$logFile) Start-Sleep -Seconds 1; if (Test-Path \$errFile) { Get-Content \$errFile | Add-Content \$logFile; Remove-Item \$errFile -ErrorAction SilentlyContinue } } -ArgumentList '" . str_replace("'", "''", $errLogFile) . "', '" . str_replace("'", "''", $logPathAbs) . "' | Out-Null\n";
        $psScriptContent .= "    } else {\n";
        $psScriptContent .= "        [Console]::Error.WriteLine('Failed to start process')\n";
        $psScriptContent .= "        exit 1\n";
        $psScriptContent .= "    }\n";
        $psScriptContent .= "} catch {\n";
        $psScriptContent .= "    [Console]::Error.WriteLine(\$_.Exception.Message)\n";
        $psScriptContent .= "    exit 1\n";
        $psScriptContent .= "}\n";
        
        file_put_contents($psScriptFile, $psScriptContent);
        
        // Execute PowerShell script - capture both stdout and stderr
        $cmd = "powershell -ExecutionPolicy Bypass -NoProfile -File " . escapeshellarg($psScriptFile) . " 2>&1";
        
        $output = [];
        $returnVar = 0;
        exec($cmd, $output, $returnVar);
        
        // Clean up temp script
        @unlink($psScriptFile);
        
        // Log the command output for debugging
        error_log("WebSocket start command: " . $cmd);
        error_log("WebSocket start command output: " . json_encode($output));
        error_log("WebSocket start return code: " . $returnVar);
        if (file_exists($psScriptFile)) {
            error_log("PowerShell script content: " . file_get_contents($psScriptFile));
        }
        
        // PowerShell returns PID on first line
        $pid = null;
        if (!empty($output)) {
            foreach ($output as $line) {
                $line = trim($line);
                // Remove any PowerShell formatting/prefixes
                $line = preg_replace('/^[^0-9]*/', '', $line);
                if (is_numeric($line)) {
                    $pid = (int)$line;
                    break;
                }
            }
        }
        
        // Wait a moment for server to start (reduced from 3 to 2 seconds)
        sleep(2);
        
        // Verify it started by checking if process is running
        $processRunning = false;
        if ($pid) {
            $processRunning = isProcessRunning($pid);
        }
        
        // Also check if port is listening (alternative verification)
        // Check port 8420 first (Node.js default), then configured port
        $portListening = false;
        global $websocketHost, $websocketPort;
        $portsToCheck = [8420]; // Check Node.js default port first
        if ($websocketPort != 8420) {
            $portsToCheck[] = $websocketPort; // Then check configured port
        }
        foreach ($portsToCheck as $checkPort) {
            $connection = @fsockopen($websocketHost, $checkPort, $errno, $errstr, 0.1); // Reduced timeout to 0.1s
            if ($connection) {
                $portListening = true;
                fclose($connection);
                break; // Found listening port, stop checking
            }
        }
        
        // If process is running OR port is listening, consider it successful
        if ($processRunning || $portListening) {
            // If we have a PID and process is running, use it
            if ($pid && $processRunning) {
                file_put_contents($pidFile, $pid);
                return [
                    'success' => true,
                    'message' => 'WebSocket server started successfully (PID: ' . $pid . ')',
                    'pid' => $pid
                ];
            }
            
            // If port is listening but we don't have a valid PID, try to find it by port
            if ($portListening) {
                // Find PID by port on Windows
                if (PHP_OS_FAMILY === 'Windows') {
                    // Step 1: Fast check with netstat -abn
                    $portCheck = shell_exec("netstat -abn | findstr \":{$websocketPort}\"");
                    if (!empty($portCheck) && strpos($portCheck, 'LISTENING') !== false) {
                        // Step 2: Get PID using -ano
                        $pidCheck = shell_exec("netstat -ano | findstr \":{$websocketPort}\" | findstr \"LISTENING\"");
                        if (!empty($pidCheck)) {
                            // Extract PID from netstat -ano output
                            preg_match('/LISTENING\s+(\d+)/', $pidCheck, $matches);
                            $foundPid = isset($matches[1]) ? (int)$matches[1] : null;
                            if ($foundPid && isProcessRunning($foundPid)) {
                                file_put_contents($pidFile, $foundPid);
                                return [
                                    'success' => true,
                                    'message' => 'WebSocket server started successfully (PID: ' . $foundPid . ', found by port)',
                                    'pid' => $foundPid
                                ];
                            }
                        }
                    }
                }
                
                // If we still don't have a PID but port is listening, use the original PID
                if ($pid) {
                    file_put_contents($pidFile, $pid);
                    return [
                        'success' => true,
                        'message' => 'WebSocket server started successfully (PID: ' . $pid . ', port verified)',
                        'pid' => $pid
                    ];
                }
            }
        }
        
        // Check log file for errors (including stderr file)
        $logError = '';
        if (file_exists($logFile)) {
            $logContent = file_get_contents($logFile);
            if (!empty($logContent)) {
                $logError = ' Log: ' . substr($logContent, -500);
            }
        }
        
        // Check for stderr log file
        $errLogFile = $logFile . '.err';
        if (file_exists($errLogFile)) {
            $errContent = file_get_contents($errLogFile);
            if (!empty($errContent)) {
                $logError .= ' Error: ' . substr($errContent, -500);
            }
        }
        
        // If we get here, starting failed
        return [
            'success' => false,
            'message' => 'Failed to start WebSocket server. PID: ' . ($pid ?? 'none') . ' (process running: ' . ($processRunning ? 'yes' : 'no') . ', port listening: ' . ($portListening ? 'yes' : 'no') . '). Return code: ' . $returnVar . '.' . $logError
        ];
    } else {
        // Unix/Linux: use nohup or similar
        $cmd = "nohup {$nodePath} {$command} >> {$logPath} 2>&1 & echo $!";
        $output = [];
        exec($cmd, $output);
        $pid = isset($output[0]) ? (int)trim($output[0]) : null;
        
        // Wait a moment for server to start
        sleep(2);
        
        // Verify it started
        if ($pid && isProcessRunning($pid)) {
            file_put_contents($pidFile, $pid);
            return [
                'success' => true,
                'message' => 'WebSocket server started successfully (PID: ' . $pid . ')',
                'pid' => $pid
            ];
        }
    }
    
    // If we get here, starting failed
    $errorDetails = '';
    if (file_exists($logFile)) {
        $logContent = file_get_contents($logFile);
        if (!empty($logContent)) {
            $lastLines = array_slice(explode("\n", $logContent), -5);
            $errorDetails = ' Last log entries: ' . implode(' | ', $lastLines);
        }
    }
    
    return [
        'success' => false,
        'message' => 'Failed to start WebSocket server. Check logs for details.' . $errorDetails
    ];
}

/**
 * Stop WebSocket server
 */
function stopWebSocketServer(string $pidFile): array {
    // SECURITY: Check file exists and is readable before reading
    if (!file_exists($pidFile) || !is_readable($pidFile)) {
        return [
            'success' => false,
            'message' => 'WebSocket server is not running (no PID file found)'
        ];
    }
    
    $pidContent = @file_get_contents($pidFile);
    if ($pidContent === false) {
        return [
            'success' => false,
            'message' => 'Failed to read PID file'
        ];
    }
    
    $pid = (int)trim($pidContent);
    if ($pid <= 0) {
        return [
            'success' => false,
            'message' => 'Invalid PID file'
        ];
    }
    
    if (!isProcessRunning($pid)) {
        @unlink($pidFile);
        return [
            'success' => false,
            'message' => 'WebSocket server process not found (may have already stopped)'
        ];
    }
    
    // Kill process
    if (PHP_OS_FAMILY === 'Windows') {
        exec("taskkill /PID {$pid} /F 2>NUL", $output);
    } else {
        posix_kill($pid, SIGTERM);
        // Wait a moment
        sleep(1);
        // Force kill if still running
        if (isProcessRunning($pid)) {
            posix_kill($pid, SIGKILL);
        }
    }
    
    // Clean up PID file
    @unlink($pidFile);
    
    // Verify it stopped
    sleep(1);
    if (!isProcessRunning($pid)) {
        return [
            'success' => true,
            'message' => 'WebSocket server stopped successfully'
        ];
    }
    
    return [
        'success' => false,
        'message' => 'Failed to stop WebSocket server'
    ];
}

/**
 * Get WebSocket server logs
 */
function getWebSocketLogs(string $logFile, int $lines = 100): array {
    // SECURITY: Check file exists and is readable before reading
    if (!file_exists($logFile) || !is_readable($logFile)) {
        return [];
    }
    
    // Read last N lines with error checking
    $content = @file_get_contents($logFile);
    if ($content === false) {
        return [];
    }
    
    $allLines = explode("\n", $content);
    $allLines = array_filter($allLines, function($line) {
        return trim($line) !== '';
    });
    
    $recentLines = array_slice($allLines, -$lines);
    return array_values($recentLines);
}

/**
 * Find Node.js executable path
 */
function findNodePath(): string {
    // Try local installation first (iChat root/nodejs/node.exe)
    $localPath = __DIR__ . '/../nodejs/node.exe';
    if (file_exists($localPath)) {
        return $localPath;
    }
    
    // Try common locations
    $paths = ['node', 'nodejs'];
    
    if (PHP_OS_FAMILY === 'Windows') {
        $paths[] = 'C:\\Program Files\\nodejs\\node.exe';
        $paths[] = 'C:\\Program Files (x86)\\nodejs\\node.exe';
    }
    
    foreach ($paths as $path) {
        $output = [];
        exec("{$path} --version 2>&1", $output, $return);
        if ($return === 0) {
            return $path;
        }
    }
    
    // Default fallback
    return 'node';
}

/**
 * Fetch server statistics from WebSocket server HTTP endpoint
 */
function fetchWebSocketServerStats(string $host, int $port): ?array {
    // Quick port check first - if port isn't open, don't try HTTP request
    $connection = @fsockopen($host, $port, $errno, $errstr, 0.1);
    if (!$connection) {
        return null; // Server not running, don't try HTTP request
    }
    fclose($connection);
    
    $url = "http://{$host}:{$port}/stats";
    
    // Use stream context with very short timeout to prevent blocking
    $context = stream_context_create([
        'http' => [
            'timeout' => 0.5, // 0.5 second timeout (very short to prevent blocking)
            'method' => 'GET',
            'header' => 'Connection: close\r\n',
            'ignore_errors' => true // Don't throw errors on HTTP failures
        ]
    ]);
    
    // Suppress all warnings and errors completely
    $oldErrorReporting = error_reporting(0);
    $response = file_get_contents($url, false, $context);
    error_reporting($oldErrorReporting);
    
    if ($response === false || empty($response)) {
        return null;
    }
    
    $data = @json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['success']) || !$data['success']) {
        return null;
    }
    
    return $data['stats'] ?? null;
}

/**
 * Format uptime in human-readable format
 */
function formatUptime(int $seconds): string {
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    
    $parts = [];
    if ($days > 0) $parts[] = $days . 'd';
    if ($hours > 0) $parts[] = $hours . 'h';
    if ($minutes > 0) $parts[] = $minutes . 'm';
    if ($secs > 0 || empty($parts)) $parts[] = $secs . 's';
    
    return implode(' ', $parts);
}

/**
 * Check Python server status
 */
function checkPythonServerStatus(int $port, string $pidFile): array {
    $result = [
        'running' => false,
        'pid' => null,
        'uptime' => null
    ];
    
    // Step 1: Use netstat -abn for fast port checking (super fast as user requested)
    // Step 2: If port is LISTENING, use netstat -ano to get PID
    $foundPid = null;
    if (PHP_OS_FAMILY === 'Windows') {
        // Fast check: is port LISTENING?
        $portCheck = shell_exec("netstat -abn | findstr \":{$port}\"");
        if (!empty($portCheck) && strpos($portCheck, 'LISTENING') !== false) {
            // Port is listening - now get PID using -ano
            $pidCheck = shell_exec("netstat -ano | findstr \":{$port}\" | findstr \"LISTENING\"");
            if (!empty($pidCheck)) {
                // Extract PID from netstat -ano output
                // Format: TCP    0.0.0.0:4291           0.0.0.0:0              LISTENING       1234
                preg_match('/LISTENING\s+(\d+)/', $pidCheck, $matches);
                $foundPid = isset($matches[1]) ? (int)$matches[1] : null;
            }
        }
    } else {
        $portCheck = shell_exec("lsof -ti:{$port} 2>/dev/null");
        if (!empty($portCheck)) {
            $foundPid = (int)trim($portCheck);
        }
    }
    
    if (!$foundPid || !isProcessRunning($foundPid)) {
        // Port not listening or PID invalid
        if (file_exists($pidFile)) {
            $storedPid = (int)trim(file_get_contents($pidFile));
            if ($storedPid > 0 && !isProcessRunning($storedPid)) {
                @unlink($pidFile);
            }
        }
        return $result;
    }
    
    // Port is listening and PID is valid
    $result['running'] = true;
    
    // Update PID file
    $storedPid = null;
    if (file_exists($pidFile)) {
        $storedPid = (int)trim(file_get_contents($pidFile));
    }
    
    if ($storedPid !== $foundPid) {
        file_put_contents($pidFile, $foundPid);
        $result['pid'] = $foundPid;
    } else {
        $result['pid'] = $foundPid;
    }
    
    // Calculate uptime
    if (PHP_OS_FAMILY === 'Windows') {
        $psCommand = "Get-Process -Id {$foundPid} -ErrorAction SilentlyContinue | Select-Object -ExpandProperty StartTime";
        $output = [];
        exec("powershell -Command \"$psCommand\"", $output);
        if (!empty($output) && !empty($output[0])) {
            try {
                $startTime = new \DateTime($output[0]);
                $now = new \DateTime();
                $diff = $now->diff($startTime);
                $totalSeconds = ($diff->days * 86400) + ($diff->h * 3600) + ($diff->i * 60) + $diff->s;
                $result['uptime'] = formatUptime($totalSeconds);
            } catch (\Exception $e) {
                $result['uptime'] = 'Unknown';
            }
        }
    }
    
    return $result;
}

/**
 * Start Python server
 */
function startPythonServer(string $script, string $logFile, string $pidFile): array {
    global $pythonPort;
    
    // Check if already running
    $status = checkPythonServerStatus($pythonPort, $pidFile);
    if ($status['running']) {
        return [
            'success' => false,
            'message' => 'Python server is already running (PID: ' . $status['pid'] . ')'
        ];
    }
    
    // Check if port is in use
    if (PHP_OS_FAMILY === 'Windows') {
        // Step 1: Fast check with netstat -abn
        $portCheck = shell_exec("netstat -abn | findstr \":{$pythonPort}\"");
        if (!empty($portCheck) && strpos($portCheck, 'LISTENING') !== false) {
            // Step 2: Get PID using -ano
            $pidCheck = shell_exec("netstat -ano | findstr \":{$pythonPort}\" | findstr \"LISTENING\"");
            if (!empty($pidCheck)) {
                // Extract PID from netstat -ano output
                preg_match('/LISTENING\s+(\d+)/', $pidCheck, $matches);
                $portPid = isset($matches[1]) ? (int)$matches[1] : null;
                
                if ($portPid) {
                // Check if it's our process (from PID file)
                if (file_exists($pidFile)) {
                    $existingPid = (int)trim(file_get_contents($pidFile));
                    if ($existingPid == $portPid && isProcessRunning($existingPid)) {
                        return [
                            'success' => false,
                            'message' => 'Python server is already running on port ' . $pythonPort . ' (PID: ' . $existingPid . ')'
                        ];
                    }
                }
                
                return [
                    'success' => false,
                    'message' => 'Port ' . $pythonPort . ' is already in use by process ' . $portPid . '. Please stop it first or change the port.'
                ];
                }
            }
        }
    } else {
        // Unix/Linux: use lsof
        $portCheck = shell_exec("lsof -ti:{$pythonPort} 2>/dev/null");
        if (!empty($portCheck)) {
            $portPid = (int)trim($portCheck);
            if ($portPid) {
                return [
                    'success' => false,
                    'message' => 'Port ' . $pythonPort . ' is already in use by process ' . $portPid . '. Please stop it first or change the port.'
                ];
            }
        }
    }
    
    // Find Python executable
    $pythonPath = findPythonPath();
    
    // Verify Python executable exists and can run
    $testOutput = [];
    $testReturn = 0;
    exec("\"$pythonPath\" --version 2>&1", $testOutput, $testReturn);
    if ($testReturn !== 0) {
        error_log("Python path test failed: $pythonPath, return code: $testReturn, output: " . implode("\n", $testOutput));
        return [
            'success' => false,
            'message' => "Python executable not found or not working. Path: $pythonPath. Error: " . implode("\n", $testOutput)
        ];
    }
    
    // Verify script file exists
    if (!file_exists($script)) {
        return [
            'success' => false,
            'message' => "Python script not found: $script"
        ];
    }
    
    // Ensure log directory exists
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows: Use Start-Process with -PassThru to get PID immediately
        // Similar to Unix 'nohup ... & echo $!' approach
        $pythonPathAbs = realpath($pythonPath) ?: $pythonPath;
        $scriptAbs = realpath($script) ?: $script;
        $logPathAbs = $logFile;
        $errLogPath = $logFile . '.err';
        
        // Build PowerShell command - similar to Unix approach
        // Start-Process with -PassThru returns process object immediately (like echo $!)
        // -WindowStyle Hidden = runs in background (like &)
        // Redirect output to log files
        $psCommand = sprintf(
            'powershell -Command "$proc = Start-Process -FilePath %s -ArgumentList %s -WindowStyle Hidden -PassThru -RedirectStandardOutput %s -RedirectStandardError %s; if ($proc) { Write-Output $proc.Id } else { exit 1 }"',
            escapeshellarg($pythonPathAbs),
            escapeshellarg($scriptAbs),
            escapeshellarg($logPathAbs),
            escapeshellarg($errLogPath)
        );
        
        // Log the command for debugging
        error_log("Python start command: $psCommand");
        error_log("Python path: $pythonPathAbs");
        error_log("Python script: $scriptAbs");
        error_log("Log file: $logPathAbs");
        
        // Execute command with timeout using proc_open to prevent hanging
        $descriptorspec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w']   // stderr
        ];
        
        $process = proc_open($psCommand, $descriptorspec, $pipes);
        
        if (!is_resource($process)) {
            return [
                'success' => false,
                'message' => 'Failed to start PowerShell process'
            ];
        }
        
        // Set non-blocking mode
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        // Read output with timeout (5 seconds max)
        $output = [];
        $startTime = time();
        $timeout = 5;
        
        while (true) {
            $status = proc_get_status($process);
            
            // Check if process has terminated
            if ($status['running'] === false) {
                break;
            }
            
            // Check timeout
            if (time() - $startTime > $timeout) {
                proc_terminate($process);
                proc_close($process);
                return [
                    'success' => false,
                    'message' => 'PowerShell command timed out after ' . $timeout . ' seconds'
                ];
            }
            
            // Read from stdout
            $line = fgets($pipes[1]);
            if ($line !== false) {
                $output[] = trim($line);
            }
            
            // Read from stderr
            $errorLine = fgets($pipes[2]);
            if ($errorLine !== false) {
                error_log("PowerShell stderr: " . trim($errorLine));
            }
            
            // Small delay to prevent CPU spinning
            usleep(100000); // 0.1 second
        }
        
        // Close pipes
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        // Get return code
        $returnVar = proc_close($process);
        
        // Log output for debugging
        error_log("Python start command output: " . json_encode($output));
        error_log("Python start return code: $returnVar");
        
        if ($returnVar !== 0) {
            $errorMsg = !empty($output) ? implode("\n", $output) : "PowerShell returned error code: $returnVar";
            // Check log files for additional error info
            $logError = '';
            if (file_exists($logFile)) {
                $logContent = file_get_contents($logFile);
                if (!empty($logContent)) {
                    $logError = ' Log: ' . substr($logContent, -500);
                }
            }
            if (file_exists($errLogPath)) {
                $errContent = file_get_contents($errLogPath);
                if (!empty($errContent)) {
                    $logError .= ' Error: ' . substr($errContent, -500);
                }
            }
            return [
                'success' => false,
                'message' => "Failed to start Python server. Error: $errorMsg" . $logError
            ];
        }
        
        if (empty($output)) {
            // Check log file for errors
            $logError = '';
            if (file_exists($logFile)) {
                $logContent = file_get_contents($logFile);
                if (!empty($logContent)) {
                    $logError = ' Log: ' . substr($logContent, -500);
                }
            }
            if (file_exists($errLogPath)) {
                $errContent = file_get_contents($errLogPath);
                if (!empty($errContent)) {
                    $logError .= ' Error: ' . substr($errContent, -500);
                }
            }
            return [
                'success' => false,
                'message' => 'Failed to start Python server (no PID returned).' . $logError
            ];
        }
        
        // Extract PID from output (first numeric value, like Unix echo $!)
        $pid = null;
        foreach ($output as $line) {
            $line = trim($line);
            // Remove any PowerShell formatting/prefixes
            $line = preg_replace('/^[^0-9]*/', '', $line);
            if (is_numeric($line)) {
                $pid = (int)$line;
                break;
            }
        }
        
        if (!$pid || $pid <= 0) {
            $logError = '';
            if (file_exists($logFile)) {
                $logContent = file_get_contents($logFile);
                if (!empty($logContent)) {
                    $logError = ' Log: ' . substr($logContent, -500);
                }
            }
            if (file_exists($errLogPath)) {
                $errContent = file_get_contents($errLogPath);
                if (!empty($errContent)) {
                    $logError .= ' Error: ' . substr($errContent, -500);
                }
            }
            return [
                'success' => false,
                'message' => 'Failed to start Python server (invalid PID). Output: ' . implode("\n", $output) . $logError
            ];
        }
        
        // Merge stderr into main log file (in background, non-blocking)
        $mergeCommand = sprintf(
            'powershell -Command "Start-Job -ScriptBlock { Start-Sleep -Seconds 1; if (Test-Path %s) { Get-Content %s | Add-Content %s; Remove-Item %s -ErrorAction SilentlyContinue } } | Out-Null"',
            escapeshellarg($errLogPath),
            escapeshellarg($errLogPath),
            escapeshellarg($logPathAbs),
            escapeshellarg($errLogPath)
        );
        exec($mergeCommand . ' 2>&1'); // Run in background, don't wait
        
        // Wait a moment for server to start
        sleep(2);
        
        // Verify it started
        if (!isProcessRunning($pid)) {
            $logError = '';
            if (file_exists($logFile)) {
                $logContent = file_get_contents($logFile);
                if (!empty($logContent)) {
                    $logError = ' Log: ' . substr($logContent, -500);
                }
            }
            if (file_exists($errLogPath)) {
                $errContent = file_get_contents($errLogPath);
                if (!empty($errContent)) {
                    $logError .= ' Error: ' . substr($errContent, -500);
                }
            }
            return [
                'success' => false,
                'message' => "Python server process started but immediately exited (PID: $pid)." . $logError
            ];
        }
        
        file_put_contents($pidFile, $pid);
        
        return [
            'success' => true,
            'message' => "Python server started successfully (PID: {$pid})",
            'pid' => $pid
        ];
    } else {
        // Unix/Linux: Use nohup approach (like the user's suggestion)
        // nohup → survives even if parent session ends
        // > /dev/null 2>&1 → discard output (prevents PHP from hanging)
        // & → run in background
        // echo $! → immediately print the PID of the background process
        $command = sprintf(
            'nohup %s %s >> %s 2>&1 & echo $!',
            escapeshellarg($pythonPath),
            escapeshellarg($script),
            escapeshellarg($logFile)
        );
        
        // Execute and capture output (the PID will be in $output[0])
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);
        
        if ($returnVar === 0 && !empty($output)) {
            $pid = (int)trim($output[0]);
            
            if ($pid <= 0) {
                return [
                    'success' => false,
                    'message' => 'Failed to start Python server (invalid PID). Output: ' . implode("\n", $output)
                ];
            }
            
            // Wait a moment for server to start
            sleep(2);
            
            // Verify it started
            if (!isProcessRunning($pid)) {
                $logError = '';
                if (file_exists($logFile)) {
                    $logContent = file_get_contents($logFile);
                    if (!empty($logContent)) {
                        $logError = ' Log: ' . substr($logContent, -500);
                    }
                }
                return [
                    'success' => false,
                    'message' => "Python server process started but immediately exited (PID: $pid)." . $logError
                ];
            }
            
            file_put_contents($pidFile, $pid);
            
            return [
                'success' => true,
                'message' => "Python server started successfully (PID: {$pid})",
                'pid' => $pid
            ];
        } else {
            $logError = '';
            if (file_exists($logFile)) {
                $logContent = file_get_contents($logFile);
                if (!empty($logContent)) {
                    $logError = ' Log: ' . substr($logContent, -500);
                }
            }
            return [
                'success' => false,
                'message' => "Failed to start Python server. Return code: $returnVar. Output: " . implode("\n", $output) . $logError
            ];
        }
    }
}

/**
 * Stop Python server (but NOT Node.js)
 */
function stopPythonServer(string $pidFile): array {
    if (!file_exists($pidFile)) {
        return [
            'success' => false,
            'message' => 'Python server PID file not found'
        ];
    }
    
    $pid = (int)trim(file_get_contents($pidFile));
    if ($pid <= 0) {
        @unlink($pidFile);
        return [
            'success' => false,
            'message' => 'Invalid PID in PID file'
        ];
    }
    
    if (!isProcessRunning($pid)) {
        @unlink($pidFile);
        return [
            'success' => false,
            'message' => 'Python server process not found (may have already stopped)'
        ];
    }
    
    // Kill Python process (this will NOT kill Node.js child process)
    // On Windows, we need to kill only the Python process, not its children
    if (PHP_OS_FAMILY === 'Windows') {
        // Use taskkill WITHOUT /T flag to kill only the Python process (not children)
        exec("taskkill /PID {$pid} /F 2>NUL", $output);
    } else {
        // Unix: Send SIGTERM to Python process only
        if (function_exists('posix_kill')) {
            posix_kill($pid, SIGTERM);
            sleep(1);
            if (isProcessRunning($pid)) {
                posix_kill($pid, SIGKILL);
            }
        }
    }
    
    // Clean up PID file
    @unlink($pidFile);
    
    // Verify it stopped
    sleep(1);
    if (!isProcessRunning($pid)) {
        return [
            'success' => true,
            'message' => 'Python server stopped successfully (Node.js server continues running)'
        ];
    }
    
    return [
        'success' => false,
        'message' => 'Failed to stop Python server'
    ];
}

/**
 * Fetch Python server stats
 */
function fetchPythonServerStats(string $host, int $port): ?array {
    // Skip the fsockopen test - it's unreliable and generates warnings
    // Just try to fetch the stats directly
    $url = "http://{$host}:{$port}/stats";
    $context = stream_context_create([
        'http' => [
            'timeout' => 1.0, // Increased timeout slightly
            'method' => 'GET',
            'header' => 'Connection: close\r\n',
            'ignore_errors' => true
        ]
    ]);
    
    // Suppress all warnings/errors for this operation
    $oldErrorReporting = error_reporting(0);
    $oldDisplayErrors = ini_get('display_errors');
    ini_set('display_errors', '0');
    
    $response = @file_get_contents($url, false, $context);
    
    // Restore error reporting
    error_reporting($oldErrorReporting);
    ini_set('display_errors', $oldDisplayErrors);
    
    if ($response === false || empty($response)) {
        return null;
    }
    
    $data = @json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['success']) || !$data['success']) {
        return null;
    }
    
    // Python server returns stats in 'stats' key, same as Node.js
    return $data;
}

/**
 * Find Python executable path
 */
function findPythonPath(): string {
    // Try common Python paths
    $paths = [
        'py', // Windows Python launcher (most reliable on Windows)
        'python3',
        'python',
        __DIR__ . '/../python/python.exe', // If Python is bundled
        'C:\\Python39\\python.exe',
        'C:\\Python310\\python.exe',
        'C:\\Python311\\python.exe',
        'C:\\Python312\\python.exe',
        'C:\\Python313\\python.exe'
    ];
    
    foreach ($paths as $path) {
        if (PHP_OS_FAMILY === 'Windows') {
            // Test if Python can run
            $output = [];
            $returnVar = 0;
            exec("\"$path\" --version 2>&1", $output, $returnVar);
            if ($returnVar === 0 && !empty($output)) {
                // Python works - try to get full path
                $fullPathOutput = [];
                exec("where $path 2>NUL", $fullPathOutput);
                if (!empty($fullPathOutput) && file_exists($fullPathOutput[0])) {
                    error_log("Found Python at: " . $fullPathOutput[0]);
                    return $fullPathOutput[0];
                }
                // If where doesn't work, return the command as-is (it's in PATH)
                error_log("Found Python command: $path");
                return $path;
            }
        } else {
            $output = [];
            exec("which $path 2>/dev/null", $output);
            if (!empty($output) && file_exists($output[0])) {
                return $output[0];
            }
        }
    }
    
    // Fallback
    $fallback = PHP_OS_FAMILY === 'Windows' ? 'py' : 'python3';
    error_log("Using fallback Python command: $fallback");
    return $fallback;
}

/**
 * Restart WebSocket server non-blocking (Windows-compatible)
 * Spawns restart in background and returns immediately
 */
function restartWebSocketServerNonBlocking(string $script, string $logFile, string $pidFile, string $host, int $port): array {
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows: Use PowerShell Start-Job to run restart in background
        $nodePath = findNodePath();
        $nodePathAbs = realpath($nodePath) ?: $nodePath;
        $scriptAbs = realpath($script) ?: $script;
        $logPathAbs = $logFile;
        $pidFileAbs = realpath($pidFile) ?: $pidFile;
        
        // Create PowerShell script that does stop + start in background
        $psScript = sprintf(
            'Start-Job -ScriptBlock {
                param($NodePath, $Script, $LogFile, $PidFile, $Host, $Port)
                
                # Stop server (non-blocking)
                if (Test-Path $PidFile) {
                    $pid = Get-Content $PidFile -ErrorAction SilentlyContinue
                    if ($pid) {
                        Stop-Process -Id $pid -Force -ErrorAction SilentlyContinue
                        Start-Sleep -Milliseconds 500
                    }
                    Remove-Item $PidFile -ErrorAction SilentlyContinue
                }
                
                # Small delay for port to free up
                Start-Sleep -Milliseconds 800
                
                # Start server (non-blocking)
                $proc = Start-Process -FilePath $NodePath -ArgumentList $Script -WindowStyle Hidden -PassThru -RedirectStandardOutput $LogFile -RedirectStandardError ($LogFile + ".err")
                if ($proc) {
                    Set-Content -Path $PidFile -Value $proc.Id
                }
            } -ArgumentList %s, %s, %s, %s, %s, %d | Out-Null',
            escapeshellarg($nodePathAbs),
            escapeshellarg($scriptAbs),
            escapeshellarg($logPathAbs),
            escapeshellarg($pidFileAbs),
            escapeshellarg($host),
            $port
        );
        
        // Execute PowerShell command in background (truly non-blocking using VBScript)
        // Write PowerShell script to temp file
        $tempPsScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'restart_ws_' . uniqid() . '.ps1';
        file_put_contents($tempPsScript, $psScript);
        
        // Create VBScript wrapper that runs PowerShell without waiting (bWaitOnReturn = False)
        $tempVbsScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'restart_ws_' . uniqid() . '.vbs';
        $vbsContent = sprintf(
            "Set WshShell = CreateObject(\"WScript.Shell\")\r\nWshShell.Run \"powershell.exe -ExecutionPolicy Bypass -NoProfile -File %s\", 0, False\r\nSet WshShell = Nothing",
            str_replace('\\', '\\\\', $tempPsScript)
        );
        file_put_contents($tempVbsScript, $vbsContent);
        
        // Execute VBScript using wscript (returns immediately, doesn't wait)
        @exec('wscript.exe ' . escapeshellarg($tempVbsScript) . ' > NUL 2>&1');
        
        // Clean up temp scripts after delay (in background)
        @exec("powershell -Command \"Start-Sleep -Seconds 10; Remove-Item " . escapeshellarg($tempPsScript) . ", " . escapeshellarg($tempVbsScript) . " -ErrorAction SilentlyContinue\" > NUL 2>&1 &");
        
        return [
            'success' => true,
            'message' => 'WebSocket server restart initiated in background. Status will update shortly.'
        ];
    } else {
        // Unix/Linux: Use nohup with background execution
        $nodePath = findNodePath();
        $scriptAbs = realpath($script) ?: $script;
        $logPathAbs = $logFile;
        $pidFileAbs = realpath($pidFile) ?: $pidFile;
        
        // Create shell script for restart
        $restartScript = sprintf(
            '#!/bin/bash
            # Stop server
            if [ -f %s ]; then
                PID=$(cat %s)
                kill -TERM $PID 2>/dev/null
                sleep 0.8
                kill -9 $PID 2>/dev/null
                rm -f %s
            fi
            
            # Start server
            sleep 0.5
            nohup %s %s >> %s 2>&1 &
            echo $! > %s',
            escapeshellarg($pidFileAbs),
            escapeshellarg($pidFileAbs),
            escapeshellarg($pidFileAbs),
            escapeshellarg($nodePath),
            escapeshellarg($scriptAbs),
            escapeshellarg($logPathAbs),
            escapeshellarg($pidFileAbs)
        );
        
        // Write temp script
        $tempScript = sys_get_temp_dir() . '/restart_ws_' . uniqid() . '.sh';
        file_put_contents($tempScript, $restartScript);
        chmod($tempScript, 0755);
        
        // Execute in background
        exec("nohup bash " . escapeshellarg($tempScript) . " > /dev/null 2>&1 &");
        
        // Clean up temp script after a delay (in background)
        exec("(sleep 5; rm -f " . escapeshellarg($tempScript) . ") > /dev/null 2>&1 &");
        
        return [
            'success' => true,
            'message' => 'WebSocket server restart initiated in background. Status will update shortly.'
        ];
    }
}

/**
 * Restart Python server non-blocking (Windows-compatible)
 * Spawns restart in background and returns immediately
 */
function restartPythonServerNonBlocking(string $script, string $logFile, string $pidFile, int $port): array {
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows: Use PowerShell Start-Job to run restart in background
        $pythonPath = findPythonPath();
        $pythonPathAbs = realpath($pythonPath) ?: $pythonPath;
        $scriptAbs = realpath($script) ?: $script;
        $logPathAbs = $logFile;
        $pidFileAbs = realpath($pidFile) ?: $pidFile;
        
        // Create PowerShell script that does stop + start in background
        $psScript = sprintf(
            'Start-Job -ScriptBlock {
                param($PythonPath, $Script, $LogFile, $PidFile, $Port)
                
                # Stop server (non-blocking)
                if (Test-Path $PidFile) {
                    $pid = Get-Content $PidFile -ErrorAction SilentlyContinue
                    if ($pid) {
                        Stop-Process -Id $pid -Force -ErrorAction SilentlyContinue
                        Start-Sleep -Milliseconds 500
                    }
                    Remove-Item $PidFile -ErrorAction SilentlyContinue
                }
                
                # Small delay for port to free up
                Start-Sleep -Milliseconds 800
                
                # Start server (non-blocking)
                $errLogFile = $LogFile + ".err"
                $proc = Start-Process -FilePath $PythonPath -ArgumentList $Script -WindowStyle Hidden -PassThru -RedirectStandardOutput $LogFile -RedirectStandardError $errLogFile
                if ($proc) {
                    Set-Content -Path $PidFile -Value $proc.Id
                    # Merge stderr into main log in background
                    Start-Job -ScriptBlock { param($errFile, $logFile) Start-Sleep -Seconds 1; if (Test-Path $errFile) { Get-Content $errFile | Add-Content $logFile; Remove-Item $errFile -ErrorAction SilentlyContinue } } -ArgumentList $errLogFile, $LogFile | Out-Null
                }
            } -ArgumentList %s, %s, %s, %s, %d | Out-Null',
            escapeshellarg($pythonPathAbs),
            escapeshellarg($scriptAbs),
            escapeshellarg($logPathAbs),
            escapeshellarg($pidFileAbs),
            $port
        );
        
        // Execute PowerShell command in background (truly non-blocking using VBScript)
        // Write PowerShell script to temp file
        $tempPsScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'restart_python_' . uniqid() . '.ps1';
        file_put_contents($tempPsScript, $psScript);
        
        // Create VBScript wrapper that runs PowerShell without waiting (bWaitOnReturn = False)
        $tempVbsScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'restart_python_' . uniqid() . '.vbs';
        $vbsContent = sprintf(
            "Set WshShell = CreateObject(\"WScript.Shell\")\r\nWshShell.Run \"powershell.exe -ExecutionPolicy Bypass -NoProfile -File %s\", 0, False\r\nSet WshShell = Nothing",
            str_replace('\\', '\\\\', $tempPsScript)
        );
        file_put_contents($tempVbsScript, $vbsContent);
        
        // Execute VBScript using wscript (returns immediately, doesn't wait)
        @exec('wscript.exe ' . escapeshellarg($tempVbsScript) . ' > NUL 2>&1');
        
        // Clean up temp scripts after delay (in background)
        @exec("powershell -Command \"Start-Sleep -Seconds 10; Remove-Item " . escapeshellarg($tempPsScript) . ", " . escapeshellarg($tempVbsScript) . " -ErrorAction SilentlyContinue\" > NUL 2>&1 &");
        
        return [
            'success' => true,
            'message' => 'Python server restart initiated in background. Status will update shortly.'
        ];
    } else {
        // Unix/Linux: Use nohup with background execution
        $pythonPath = findPythonPath();
        $scriptAbs = realpath($script) ?: $script;
        $logPathAbs = $logFile;
        $pidFileAbs = realpath($pidFile) ?: $pidFile;
        
        // Create shell script for restart
        $restartScript = sprintf(
            '#!/bin/bash
            # Stop server
            if [ -f %s ]; then
                PID=$(cat %s)
                kill -TERM $PID 2>/dev/null
                sleep 0.8
                kill -9 $PID 2>/dev/null
                rm -f %s
            fi
            
            # Start server
            sleep 0.5
            nohup %s %s >> %s 2>&1 &
            echo $! > %s',
            escapeshellarg($pidFileAbs),
            escapeshellarg($pidFileAbs),
            escapeshellarg($pidFileAbs),
            escapeshellarg($pythonPath),
            escapeshellarg($scriptAbs),
            escapeshellarg($logPathAbs),
            escapeshellarg($pidFileAbs)
        );
        
        // Write temp script
        $tempScript = sys_get_temp_dir() . '/restart_python_' . uniqid() . '.sh';
        file_put_contents($tempScript, $restartScript);
        chmod($tempScript, 0755);
        
        // Execute in background
        exec("nohup bash " . escapeshellarg($tempScript) . " > /dev/null 2>&1 &");
        
        // Clean up temp script after a delay (in background)
        exec("(sleep 5; rm -f " . escapeshellarg($tempScript) . ") > /dev/null 2>&1 &");
        
        return [
            'success' => true,
            'message' => 'Python server restart initiated in background. Status will update shortly.'
        ];
    }
}

