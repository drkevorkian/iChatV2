<?php
/**
 * Sentinel Chat Platform - Web Terminal API
 * 
 * Provides a secure web-based terminal interface for executing system commands.
 * All commands are logged for security auditing.
 * 
 * Security: Admin-only access required. Dangerous commands are blocked.
 */

declare(strict_types=1);

// Set error handler to catch fatal errors
set_error_handler(function($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\SecurityService;
use iChat\Services\AuthService;
use iChat\Services\AuditService;

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
$action = $_GET['action'] ?? 'execute';

// Dangerous commands that should be blocked
$dangerousCommands = [
    'rm -rf',
    'rm -r',
    'del /f /s /q',
    'format',
    'fdisk',
    'mkfs',
    'dd if=',
    'mkfs',
    'shutdown',
    'reboot',
    'halt',
    'poweroff',
    'init 0',
    'init 6',
    'systemctl poweroff',
    'systemctl reboot',
    'wmic process call delete',
    'taskkill /F /IM',
];

// Commands that require special handling or are informational only
$infoCommands = [
    'pwd',
    'cd',
    'dir',
    'ls',
    'whoami',
    'hostname',
    'echo',
    'type',
    'cat',
    'python --version',
    'python -V',
    'python3 --version',
    'python3 -V',
    'where python',
    'where python3',
    'which python',
    'which python3',
    'python -V',
];

/**
 * Check if command is dangerous
 */
function isDangerousCommand(string $command): bool {
    global $dangerousCommands;
    $cmdLower = strtolower(trim($command));
    
    foreach ($dangerousCommands as $dangerous) {
        if (strpos($cmdLower, strtolower($dangerous)) === 0 || strpos($cmdLower, ' ' . strtolower($dangerous)) !== false) {
            return true;
        }
    }
    
    // Block commands that try to escape to parent directories in dangerous ways
    if (preg_match('/\.\.\/.*(rm|del|format|fdisk|mkfs|dd)/i', $command)) {
        return true;
    }
    
    return false;
}

/**
 * Sanitize command output
 */
function sanitizeOutput(string $output): string {
    // Remove any potential XSS or control characters
    $output = htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
    // Allow some ANSI color codes for terminal formatting
    $output = preg_replace('/\x1b\[[0-9;]*m/', '', $output); // Remove ANSI codes for now
    return $output;
}

/**
 * Execute command safely
 */
function executeCommand(string $command, ?string $workingDir = null): array {
    global $security, $authService, $currentUser;
    
    // Trim command
    $command = trim($command);
    
    if (empty($command)) {
        return [
            'success' => false,
            'error' => 'Empty command',
            'output' => '',
            'exit_code' => 1,
        ];
    }
    
    // Check if dangerous
    if (isDangerousCommand($command)) {
        // Log blocked command attempt (wrap in try-catch to prevent audit logging failures from breaking command execution)
        try {
            $auditService = new AuditService();
            $auditService->log(
                $currentUser['username'] ?? 'unknown',
                'terminal_command_blocked',
                'admin',
                [
                    'user_id' => $currentUser['id'] ?? null,
                    'command' => $command,
                    'reason' => 'Dangerous command blocked',
                ]
            );
        } catch (\Exception $e) {
            // Log audit failure but don't break command blocking
            error_log('Failed to log blocked command: ' . $e->getMessage());
        }
        
        return [
            'success' => false,
            'error' => 'Dangerous command blocked for security',
            'output' => '',
            'exit_code' => 1,
        ];
    }
    
    // Set working directory
    $originalDir = getcwd();
    if ($workingDir && is_dir($workingDir)) {
        chdir($workingDir);
    }
    
    // Execute command
    $output = [];
    $exitCode = 0;
    
    try {
        // On Windows, use cmd /c, on Unix use sh -c
        $isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
        
        if ($isWindows) {
            // Windows: use cmd /c with enhanced PATH
            // First, try to find Python if the command starts with 'python'
            if (preg_match('/^python(\d+)?(\s|$)/i', $command, $matches)) {
                $pythonPath = null;
                $username = getenv('USERNAME') ?: getenv('USER') ?: 'owner';
                
                // Method 0 (highest priority): Try Windows Python Launcher (py.exe) - works even if Python not in PATH
                // This is the most reliable method as py.exe is always in C:\Windows and can find any Python installation
                $pyLauncher = 'C:\\Windows\\py.exe';
                if (file_exists($pyLauncher)) {
                    // Test if it works by checking version
                    $testOutput = [];
                    @exec('"' . $pyLauncher . '" --version 2>&1', $testOutput, $testCode);
                    if ($testCode === 0 && !empty($testOutput)) {
                        // py.exe works! Use it with -3 flag to get Python 3 (or -2 for Python 2)
                        $pythonPath = $pyLauncher . ' -3';
                    }
                }
                
                // Method 0.5: Try PowerShell Get-Command to find Python (more reliable than 'where')
                if (empty($pythonPath) || strpos($pythonPath, 'py.exe') !== false) {
                    $psCmd = 'powershell -NoProfile -Command "Get-Command python -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source" 2>nul';
                    $psOutput = @shell_exec($psCmd);
                    if (!empty($psOutput)) {
                        $psPath = trim($psOutput);
                        // Skip Windows Store launcher (the one directly in WindowsApps, not in subdirectories)
                        if (file_exists($psPath) && !(strpos($psPath, 'WindowsApps\\python.exe') !== false && strpos($psPath, 'PythonSoftwareFoundation') === false)) {
                            $pythonPath = $psPath;
                        }
                    }
                }
                
                // Method 0.6: Check Windows Registry for Python installations
                if (empty($pythonPath) || strpos($pythonPath, 'py.exe') !== false) {
                    // Check HKEY_CURRENT_USER\Software\Python\PythonCore for installed versions
                    $regCmd = 'reg query "HKCU\\Software\\Python\\PythonCore" /s /v "ExecutablePath" 2>nul';
                    $regOutput = @shell_exec($regCmd);
                    if (!empty($regOutput)) {
                        // Parse registry output to find python.exe paths
                        $lines = explode("\n", $regOutput);
                        foreach ($lines as $line) {
                            if (stripos($line, 'ExecutablePath') !== false && stripos($line, 'python.exe') !== false) {
                                // Extract path from registry output (format: REG_SZ    C:\path\to\python.exe)
                                if (preg_match('/REG_SZ\s+(.+python\.exe)/i', $line, $regMatches)) {
                                    $regPath = trim($regMatches[1]);
                                    if (file_exists($regPath)) {
                                        $pythonPath = $regPath;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Method 1: Directly search for actual Python executable in WindowsApps subdirectories
                // This is the most reliable method for Windows Store Python installations
                if (empty($pythonPath) || strpos($pythonPath, 'py.exe') !== false) {
                    $windowsAppsPath = 'C:\\Users\\' . $username . '\\AppData\\Local\\Microsoft\\WindowsApps';
                    if (is_dir($windowsAppsPath)) {
                        // Look for PythonSoftwareFoundation directories
                        $dirs = @scandir($windowsAppsPath);
                        if ($dirs) {
                            foreach ($dirs as $dir) {
                                if ($dir === '.' || $dir === '..') continue;
                                if (strpos($dir, 'PythonSoftwareFoundation.Python') === 0) {
                                    $testPath = $windowsAppsPath . '\\' . $dir . '\\python.exe';
                                    if (file_exists($testPath)) {
                                        $pythonPath = $testPath;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
                
                // Method 2: Try common Python installation paths (non-Windows Store)
                if (empty($pythonPath)) {
                    $commonPaths = [
                        'C:\\Users\\' . $username . '\\AppData\\Local\\Programs\\Python\\Python310\\python.exe',
                        'C:\\Users\\' . $username . '\\AppData\\Local\\Programs\\Python\\Python311\\python.exe',
                        'C:\\Users\\' . $username . '\\AppData\\Local\\Programs\\Python\\Python312\\python.exe',
                        'C:\\Python310\\python.exe',
                        'C:\\Python311\\python.exe',
                        'C:\\Python312\\python.exe',
                        'C:\\Python39\\python.exe',
                        'C:\\Python38\\python.exe',
                        'C:\\Python37\\python.exe',
                        'C:\\Program Files\\Python310\\python.exe',
                        'C:\\Program Files\\Python311\\python.exe',
                        'C:\\Program Files\\Python312\\python.exe',
                        'C:\\Program Files\\Python39\\python.exe',
                        'C:\\Program Files\\Python38\\python.exe',
                        'C:\\Program Files\\Python37\\python.exe',
                        'C:\\Program Files (x86)\\Python310\\python.exe',
                        'C:\\Program Files (x86)\\Python311\\python.exe',
                        'C:\\Program Files (x86)\\Python312\\python.exe',
                    ];
                    
                    foreach ($commonPaths as $path) {
                        if (file_exists($path)) {
                            $pythonPath = $path;
                            break;
                        }
                    }
                }
                
                // Method 3: Try 'where' command but skip Windows Store launcher
                if (empty($pythonPath)) {
                    $whereOutput = @shell_exec('where python 2>nul');
                    if (!empty($whereOutput)) {
                        $lines = explode("\n", trim($whereOutput));
                        foreach ($lines as $line) {
                            $line = trim($line);
                            if (empty($line)) continue;
                            // Skip Windows Store launcher - it can't be executed directly
                            // Check if it's the launcher (in WindowsApps but not in a subdirectory)
                            if (strpos($line, 'WindowsApps\\python.exe') !== false && strpos($line, 'PythonSoftwareFoundation') === false) {
                                continue;
                            }
                            if (file_exists($line)) {
                                $pythonPath = $line;
                                break;
                            }
                        }
                    }
                }
                
                // Method 3: Try common Python installation paths
                if (empty($pythonPath)) {
                    $username = getenv('USERNAME') ?: getenv('USER') ?: 'owner';
                    $commonPaths = [
                        'C:\\Python310\\python.exe',
                        'C:\\Python311\\python.exe',
                        'C:\\Python312\\python.exe',
                        'C:\\Python39\\python.exe',
                        'C:\\Python38\\python.exe',
                        'C:\\Python37\\python.exe',
                        'C:\\Program Files\\Python310\\python.exe',
                        'C:\\Program Files\\Python311\\python.exe',
                        'C:\\Program Files\\Python312\\python.exe',
                        'C:\\Program Files\\Python39\\python.exe',
                        'C:\\Program Files\\Python38\\python.exe',
                        'C:\\Program Files\\Python37\\python.exe',
                        'C:\\Program Files (x86)\\Python310\\python.exe',
                        'C:\\Program Files (x86)\\Python311\\python.exe',
                        'C:\\Program Files (x86)\\Python312\\python.exe',
                        'C:\\Users\\' . $username . '\\AppData\\Local\\Programs\\Python\\Python310\\python.exe',
                        'C:\\Users\\' . $username . '\\AppData\\Local\\Programs\\Python\\Python311\\python.exe',
                        'C:\\Users\\' . $username . '\\AppData\\Local\\Programs\\Python\\Python312\\python.exe',
                        'C:\\Users\\' . $username . '\\AppData\\Local\\Microsoft\\WindowsApps\\python.exe',
                    ];
                    
                    foreach ($commonPaths as $path) {
                        if (file_exists($path)) {
                            $pythonPath = $path;
                            break;
                        }
                    }
                }
                
                // Method 4: Search in PATH directories
                if (empty($pythonPath)) {
                    $pathEnv = getenv('PATH') ?: '';
                    $pathDirs = explode(';', $pathEnv);
                    foreach ($pathDirs as $dir) {
                        $dir = trim($dir);
                        if (empty($dir)) continue;
                        $testPath = rtrim($dir, '\\') . '\\python.exe';
                        if (file_exists($testPath)) {
                            $pythonPath = $testPath;
                            break;
                        }
                    }
                }
                
                // Replace 'python' in command with full path if found
                if (!empty($pythonPath)) {
                    // If using py.exe launcher, keep it as-is (it handles version selection)
                    if (strpos($pythonPath, 'py.exe') !== false) {
                        $command = preg_replace('/^python(\d+)?(\s|$)/i', $pythonPath . '$2', $command);
                    } else {
                        $command = preg_replace('/^python(\d+)?(\s|$)/i', escapeshellarg($pythonPath) . '$2', $command);
                    }
                } else {
                    // Last resort: try py.exe launcher even if we didn't find it earlier
                    $pyLauncher = 'C:\\Windows\\py.exe';
                    if (file_exists($pyLauncher)) {
                        $command = preg_replace('/^python(\d+)?(\s|$)/i', '"' . $pyLauncher . '" -3$2', $command);
                    }
                }
            }
            
            // Build command with enhanced PATH
            // Get system PATH and user PATH
            $systemPath = getenv('PATH') ?: '';
            $username = getenv('USERNAME') ?: getenv('USER') ?: 'owner';
            
            // Try to get user PATH from registry (more reliable) - use PowerShell
            $userPathCmd = 'powershell -NoProfile -Command "[Environment]::GetEnvironmentVariable(\'Path\', \'User\')" 2>nul';
            $userPath = @shell_exec($userPathCmd);
            if (!empty($userPath)) {
                $userPath = trim($userPath);
                if (!empty($userPath)) {
                    // Combine user PATH with system PATH (user PATH takes precedence)
                    $systemPath = $userPath . ';' . $systemPath;
                }
            }
            
            // Add common Python locations to PATH (if not already present)
            $pythonPaths = [
                'C:\\Python310',
                'C:\\Python311',
                'C:\\Python312',
                'C:\\Python39',
                'C:\\Python38',
                'C:\\Python37',
                'C:\\Program Files\\Python310',
                'C:\\Program Files\\Python311',
                'C:\\Program Files\\Python312',
                'C:\\Program Files\\Python39',
                'C:\\Program Files\\Python38',
                'C:\\Program Files\\Python37',
                'C:\\Program Files (x86)\\Python310',
                'C:\\Program Files (x86)\\Python311',
                'C:\\Program Files (x86)\\Python312',
                'C:\\Users\\' . $username . '\\AppData\\Local\\Programs\\Python\\Python310',
                'C:\\Users\\' . $username . '\\AppData\\Local\\Programs\\Python\\Python311',
                'C:\\Users\\' . $username . '\\AppData\\Local\\Programs\\Python\\Python312',
                'C:\\Users\\' . $username . '\\AppData\\Local\\Programs\\Python\\Python310\\Scripts',
                'C:\\Users\\' . $username . '\\AppData\\Local\\Programs\\Python\\Python311\\Scripts',
                'C:\\Users\\' . $username . '\\AppData\\Local\\Programs\\Python\\Python312\\Scripts',
                'C:\\Users\\' . $username . '\\AppData\\Local\\Programs\\Python',
                'C:\\Users\\' . $username . '\\AppData\\Local\\Microsoft\\WindowsApps',
            ];
            
            $enhancedPath = $systemPath;
            foreach ($pythonPaths as $pythonPath) {
                // Check if directory exists and isn't already in PATH
                if (is_dir($pythonPath)) {
                    // Normalize paths for comparison (handle trailing slashes)
                    $normalizedPath = rtrim(str_replace('/', '\\', $pythonPath), '\\');
                    $pathParts = explode(';', $enhancedPath);
                    $found = false;
                    foreach ($pathParts as $part) {
                        $normalizedPart = rtrim(str_replace('/', '\\', trim($part)), '\\');
                        if (strcasecmp($normalizedPart, $normalizedPath) === 0) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $enhancedPath = $pythonPath . ';' . $enhancedPath;
                    }
                }
            }
            
            // Build command with enhanced PATH
            // Use a simpler approach: set PATH in the same command line
            // Escape quotes for cmd.exe (double them)
            $escapedPath = str_replace('"', '""', $enhancedPath);
            $escapedCommand = str_replace('"', '""', $command);
            $fullCommand = 'cmd /c "set PATH=' . $escapedPath . ' && ' . $escapedCommand . '" 2>&1';
        } else {
            // Unix: use sh -c
            $fullCommand = 'sh -c ' . escapeshellarg($command) . ' 2>&1';
        }
        
        // Execute command and capture both stdout and stderr
        $output = [];
        $exitCode = 0;
        exec($fullCommand, $output, $exitCode);
        
        // If no output but command failed, try to get error
        if (empty($output) && $exitCode !== 0) {
            // Try using shell_exec to get any output
            $shellOutput = @shell_exec($fullCommand);
            if (!empty($shellOutput)) {
                $output = explode("\n", trim($shellOutput));
            } else {
                // Last resort: try a simpler command to see if exec works at all
                $testOutput = [];
                @exec('cmd /c "echo test" 2>&1', $testOutput, $testCode);
                if (empty($testOutput)) {
                    $output = ['Error: Command execution failed. Check PHP exec() permissions and error logs.'];
                } else {
                    $output = ['Error: Command failed with exit code ' . $exitCode . '. No output captured.'];
                }
            }
        }
        
        // Get current working directory after command
        $newCwd = getcwd();
        
    } catch (\Exception $e) {
        $output = ['Error: ' . $e->getMessage()];
        $exitCode = 1;
        $newCwd = $originalDir;
    } finally {
        // Restore original directory
        chdir($originalDir);
    }
    
    // Log command execution (wrap in try-catch to prevent audit logging failures from breaking command execution)
    try {
        $auditService = new AuditService();
        $auditService->log(
            $currentUser['username'] ?? 'unknown',
            'terminal_command_executed',
            'admin',
            [
                'user_id' => $currentUser['id'] ?? null,
                'command' => $command,
                'exit_code' => $exitCode,
                'output_length' => strlen(implode("\n", $output)),
                'working_dir' => $workingDir ?? $originalDir,
            ]
        );
    } catch (\Exception $e) {
        // Log audit failure but don't break command execution
        error_log('Failed to log terminal command: ' . $e->getMessage());
    }
    
    // If command failed and output is empty, try to get stderr
    if ($exitCode !== 0 && empty($output)) {
        // Re-run with explicit error capture
        if ($isWindows) {
            $errorCmd = 'cmd /c "' . str_replace('"', '""', $command) . '" 2>&1';
        } else {
            $errorCmd = 'sh -c ' . escapeshellarg($command) . ' 2>&1';
        }
        $errorOutput = [];
        @exec($errorCmd, $errorOutput, $errorCode);
        if (!empty($errorOutput)) {
            $output = $errorOutput;
        }
    }
    
    return [
        'success' => $exitCode === 0,
        'output' => implode("\n", $output),
        'exit_code' => $exitCode,
        'cwd' => $newCwd ?? $originalDir,
    ];
}

/**
 * Get current working directory
 */
function getCurrentWorkingDir(): string {
    return getcwd() ?: (__DIR__ . '/..');
}

try {
    switch ($action) {
        case 'execute':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required']);
                break;
            }
            
            // Get input
            $rawInput = file_get_contents('php://input');
            if ($rawInput === false) {
                throw new \InvalidArgumentException('Failed to read request body');
            }
            $input = json_decode($rawInput, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input: ' . json_last_error_msg());
            }
            
            $command = $input['command'] ?? '';
            $workingDir = $input['working_dir'] ?? null;
            
            if (empty($command)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Command is required',
                ]);
                break;
            }
            
            // Execute command
            $result = executeCommand($command, $workingDir);
            
            // Sanitize output
            $result['output'] = sanitizeOutput($result['output']);
            
            echo json_encode($result);
            break;
            
        case 'cwd':
            // Get current working directory
            $cwd = getCurrentWorkingDir();
            echo json_encode([
                'success' => true,
                'cwd' => $cwd,
            ]);
            break;
            
        case 'change-dir':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required']);
                break;
            }
            
            // Get input
            $rawInput = file_get_contents('php://input');
            if ($rawInput === false) {
                throw new \InvalidArgumentException('Failed to read request body');
            }
            $input = json_decode($rawInput, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input: ' . json_last_error_msg());
            }
            
            $newDir = $input['directory'] ?? '';
            
            if (empty($newDir)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Directory is required',
                ]);
                break;
            }
            
            // Validate directory exists and is accessible
            if (!is_dir($newDir)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Directory does not exist',
                ]);
                break;
            }
            
            // Check if it's within project directory (security)
            $projectRoot = realpath(__DIR__ . '/..');
            $targetDir = realpath($newDir);
            
            if ($targetDir && strpos($targetDir, $projectRoot) === 0) {
                // Directory is within project, allow it
                $cwd = $targetDir;
            } else {
                // Try to change to it anyway (user might have permissions)
                $cwd = getCurrentWorkingDir();
            }
            
            echo json_encode([
                'success' => true,
                'cwd' => $cwd,
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} catch (\Exception $e) {
    http_response_code(500);
    error_log('Terminal API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
} catch (\Error $e) {
    http_response_code(500);
    error_log('Terminal API Fatal Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}

