<?php
/**
 * Sentinel Chat Platform - Chatbot Bot Admin API
 * 
 * Handles chatbot bot management: status, start, stop, restart, configuration,
 * bot user creation, dependency checking, and log viewing.
 * 
 * Security: Admin-only access required.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\SecurityService;
use iChat\Services\AuthService;
use iChat\Repositories\AuthRepository;
use iChat\Config;

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

// Bot configuration
$botScript = __DIR__ . '/../chatbot-bot.py';
$botLogFile = __DIR__ . '/../logs/chatbot-bot.log';
$botPidFile = __DIR__ . '/../logs/chatbot-bot.pid';
$botConfigFile = __DIR__ . '/../logs/chatbot-config.json';
$requirementsFile = __DIR__ . '/../requirements-bot.txt';

// Ensure logs directory exists
$logsDir = dirname($botLogFile);
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0755, true);
}

/**
 * Check if Python is available
 * Uses the same robust detection methods as the terminal
 */
function checkPythonAvailable(): array {
    $isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    $pythonPath = null;
    $username = getenv('USERNAME') ?: getenv('USER') ?: 'owner';
    
    if ($isWindows) {
        // Method 0 (highest priority): Try Windows Python Launcher (py.exe) - works even if Python not in PATH
        // This is the most reliable method as py.exe is always in C:\Windows and can find any Python installation
        $pyLauncher = 'C:\\Windows\\py.exe';
        if (file_exists($pyLauncher)) {
            // Test if it works by checking version
            $testOutput = [];
            @exec('"' . $pyLauncher . '" --version 2>&1', $testOutput, $testCode);
            if ($testCode === 0 && !empty($testOutput)) {
                // py.exe works! Extract version and use it with -3 flag
                $versionOutput = implode(' ', $testOutput);
                if (preg_match('/(\d+\.\d+[\.\d]*)/', $versionOutput, $matches)) {
                    $version = 'Python ' . $matches[1];
                } else {
                    $version = trim($versionOutput);
                }
                return ['available' => true, 'command' => $pyLauncher . ' -3', 'version' => $version];
            }
        }
        
        // Method 0.5: Try PowerShell Get-Command to find Python (more reliable than 'where')
        $psCmd = 'powershell -NoProfile -Command "Get-Command python -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source" 2>nul';
        $psOutput = @shell_exec($psCmd);
        if (!empty($psOutput)) {
            $psPath = trim($psOutput);
            // Skip Windows Store launcher (the one directly in WindowsApps, not in subdirectories)
            if (file_exists($psPath) && !(strpos($psPath, 'WindowsApps\\python.exe') !== false && strpos($psPath, 'PythonSoftwareFoundation') === false)) {
                $pythonPath = $psPath;
            }
        }
        
        // Method 0.6: Check Windows Registry for Python installations
        if (empty($pythonPath)) {
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
        if (empty($pythonPath)) {
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
        
        // If we found a Python path, get its version
        if (!empty($pythonPath)) {
            $output = @shell_exec('"' . $pythonPath . '" --version 2>&1');
            if (empty($output)) {
                $output = @shell_exec('"' . $pythonPath . '" -V 2>&1');
            }
            if (!empty($output)) {
                $output = trim($output);
                if (preg_match('/(\d+\.\d+[\.\d]*)/', $output, $matches)) {
                    $version = 'Python ' . $matches[1];
                } else {
                    $version = trim($output);
                }
                return ['available' => true, 'command' => $pythonPath, 'version' => $version];
            }
            // If file exists but we can't get version, still return it
            return ['available' => true, 'command' => $pythonPath, 'version' => 'Python (version check failed)'];
        }
        
        // Last resort: try py.exe launcher even if we didn't find it earlier
        $pyLauncher = 'C:\\Windows\\py.exe';
        if (file_exists($pyLauncher)) {
            return ['available' => true, 'command' => $pyLauncher . ' -3', 'version' => 'Python (via launcher)'];
        }
    } else {
        // Unix/Linux: try standard commands
        $commands = ['python3', 'python'];
        foreach ($commands as $cmd) {
            $output = @shell_exec("$cmd --version 2>&1");
            if (empty($output)) {
                $output = @shell_exec("$cmd -V 2>&1");
            }
            if (!empty($output)) {
                $output = trim($output);
                if (stripos($output, 'Python') !== false || preg_match('/\d+\.\d+/', $output)) {
                    if (preg_match('/(\d+\.\d+[\.\d]*)/', $output, $matches)) {
                        $version = 'Python ' . $matches[1];
                    } else {
                        $version = trim($output);
                    }
                    return ['available' => true, 'command' => $cmd, 'version' => $version];
                }
            }
        }
    }
    
    return ['available' => false, 'command' => null, 'version' => null];
}

/**
 * Check if bot process is running
 */
/**
 * Check if a process is running (same as WSS uses)
 */
function isProcessRunning(int $pid): bool {
    if ($pid <= 0) {
        return false;
    }
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows: Use tasklist to check if process exists
        $result = @shell_exec("tasklist /FI \"PID eq $pid\" 2>nul");
        return !empty($result) && strpos($result, (string)$pid) !== false;
    } else {
        // Unix/Linux: Use posix_kill with signal 0 (doesn't kill, just checks)
        return @posix_kill($pid, 0);
    }
}

function checkBotStatus(string $pidFile): array {
    $status = [
        'running' => false,
        'pid' => null,
        'uptime' => null,
    ];
    
    $foundPid = null;
    
    // Method 1: Try to find the bot process by searching for python processes running chatbot-bot.py
    // This is the primary method (same approach as WSS but for command line instead of port)
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows: Search for python processes with chatbot-bot.py in command line
        $processList = @shell_exec('wmic process where "name=\'python.exe\' or name=\'pythonw.exe\'" get ProcessId,CommandLine /format:list 2>nul');
        if ($processList) {
            $lines = explode("\n", $processList);
            $currentPid = null;
            foreach ($lines as $line) {
                $line = trim($line);
                if (strpos($line, 'ProcessId=') === 0) {
                    $currentPid = trim(substr($line, 10));
                } elseif (strpos($line, 'CommandLine=') === 0 && strpos($line, 'chatbot-bot.py') !== false) {
                    if (!empty($currentPid) && is_numeric($currentPid)) {
                        $foundPid = (int)$currentPid;
                        break;
                    }
                }
            }
        }
    } else {
        // Unix/Linux: Use ps to find the process
        $psOutput = @shell_exec("ps aux | grep 'chatbot-bot.py' | grep -v grep");
        if (!empty($psOutput)) {
            // Extract PID from ps output (second column)
            if (preg_match('/^\s*\S+\s+(\d+)/', $psOutput, $matches)) {
                $foundPid = (int)$matches[1];
            }
        }
    }
    
    // Method 2: Fallback to PID file if process search didn't find it
    if (empty($foundPid) && file_exists($pidFile)) {
        $storedPid = (int)trim(@file_get_contents($pidFile));
        if ($storedPid > 0 && isProcessRunning($storedPid)) {
            $foundPid = $storedPid;
        } else {
            // PID file exists but process is not running - clean it up
            @unlink($pidFile);
        }
    }
    
    // Verify the found PID is actually running
    if (empty($foundPid) || !isProcessRunning($foundPid)) {
        return $status;
    }
    
    // Process is running!
    $status['running'] = true;
    $status['pid'] = $foundPid;
    
    // Update PID file if it doesn't match
    if (file_exists($pidFile)) {
        $storedPid = (int)trim(@file_get_contents($pidFile));
        if ($storedPid !== $foundPid) {
            @file_put_contents($pidFile, $foundPid);
        }
    } else {
        @file_put_contents($pidFile, $foundPid);
    }
    
    // Calculate uptime (same method as WSS)
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $psCommand = "Get-Process -Id {$foundPid} -ErrorAction SilentlyContinue | Select-Object -ExpandProperty StartTime";
        $output = [];
        @exec("powershell -Command \"$psCommand\"", $output);
        if (!empty($output) && !empty($output[0])) {
            try {
                $startTime = new \DateTime($output[0]);
                $now = new \DateTime();
                $diff = $now->diff($startTime);
                $totalSeconds = ($diff->days * 86400) + ($diff->h * 3600) + ($diff->i * 60) + $diff->s;
                $status['uptime'] = formatUptime($totalSeconds);
            } catch (\Exception $e) {
                $status['uptime'] = 'Unknown';
            }
        }
    } else {
        // Unix/Linux: Get process start time from /proc
        $stat = @stat("/proc/$foundPid");
        if ($stat && isset($stat['mtime'])) {
            $uptime = time() - $stat['mtime'];
            $status['uptime'] = formatUptime($uptime);
        }
    }
    
    return $status;
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
    if ($days > 0) $parts[] = "{$days}d";
    if ($hours > 0) $parts[] = "{$hours}h";
    if ($minutes > 0) $parts[] = "{$minutes}m";
    if (empty($parts) || $secs > 0) $parts[] = "{$secs}s";
    
    return implode(' ', $parts);
}

/**
 * Check Python dependencies
 */
function checkDependencies(string $requirementsFile): array {
    $dependencies = [];
    
    if (!file_exists($requirementsFile)) {
        return ['installed' => false, 'dependencies' => [], 'error' => 'Requirements file not found'];
    }
    
    $pythonCheck = checkPythonAvailable();
    if (!$pythonCheck['available']) {
        return ['installed' => false, 'dependencies' => [], 'error' => 'Python not available'];
    }
    
    $requirements = file($requirementsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($requirements as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        // Parse package name (remove version specifiers)
        if (preg_match('/^([a-zA-Z0-9_-]+[a-zA-Z0-9_.-]*)/', $line, $matches)) {
            $package = $matches[1];
            
            // Check if installed
            $checkCmd = $pythonCheck['command'] . " -c \"import " . escapeshellarg($package) . "\" 2>&1";
            $result = @shell_exec($checkCmd);
            $installed = ($result === null || trim($result) === '');
            
            $dependencies[] = [
                'name' => $package,
                'required' => $line,
                'installed' => $installed,
            ];
        }
    }
    
    $allInstalled = !empty($dependencies) && array_reduce($dependencies, function($carry, $dep) {
        return $carry && $dep['installed'];
    }, true);
    
    return [
        'installed' => $allInstalled,
        'dependencies' => $dependencies,
        'python' => $pythonCheck,
    ];
}

/**
 * Install Python dependencies
 */
function installDependencies(string $requirementsFile): array {
    $pythonCheck = checkPythonAvailable();
    if (!$pythonCheck['available']) {
        return ['success' => false, 'error' => 'Python not available'];
    }
    
    if (!file_exists($requirementsFile)) {
        return ['success' => false, 'error' => 'Requirements file not found'];
    }
    
    // Install using pip
    $pipCmd = $pythonCheck['command'] . ' -m pip install -r ' . escapeshellarg($requirementsFile) . ' 2>&1';
    $output = @shell_exec($pipCmd);
    
    // Check result
    $success = (strpos($output, 'Successfully installed') !== false || strpos($output, 'Requirement already satisfied') !== false);
    
    return [
        'success' => $success,
        'output' => $output,
        'error' => $success ? null : 'Installation failed',
    ];
}

/**
 * Get bot configuration
 */
function getBotConfig(string $configFile): array {
    if (file_exists($configFile)) {
        $config = @json_decode(file_get_contents($configFile), true);
        if (is_array($config)) {
            return $config;
        }
    }
    
    // Default config
    return [
        'bot_handle' => 'ChatBot',
        'bot_display_name' => 'ChatBot',
        'bot_email' => 'chatbot@sentinel.local',
        'default_room' => 'lobby',
        'response_delay' => 2.0,
        'ai_provider' => 'simple',
        'openai_api_key' => '',
        'ollama_url' => 'http://localhost:11434',
        'ollama_model' => 'llama2',
    ];
}

/**
 * Save bot configuration
 */
function saveBotConfig(string $configFile, array $config): bool {
    return @file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT)) !== false;
}

try {
    switch ($action) {
        case 'status':
            // Get bot status
            $status = checkBotStatus($botPidFile);
            $config = getBotConfig($botConfigFile);
            
            echo json_encode([
                'success' => true,
                'status' => $status,
                'config' => $config,
            ]);
            break;
            
        case 'start':
            // Check if already running
            $status = checkBotStatus($botPidFile);
            if ($status['running']) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Bot is already running',
                ]);
                break;
            }
            
            // Check Python
            $pythonCheck = checkPythonAvailable();
            if (!$pythonCheck['available']) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Python is not available. Please install Python 3.6+',
                ]);
                break;
            }
            
            // Check bot script exists
            if (!file_exists($botScript)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Bot script not found: ' . basename($botScript),
                ]);
                break;
            }
            
            // Quick test: Check if Python can import required modules
            $testImports = ['websockets', 'requests'];
            $missingModules = [];
            foreach ($testImports as $module) {
                $testCmd = $pythonCheck['command'] . ' -c "import ' . escapeshellarg($module) . '" 2>&1';
                $testResult = @shell_exec($testCmd);
                if (!empty($testResult) && (stripos($testResult, 'error') !== false || stripos($testResult, 'No module') !== false)) {
                    $missingModules[] = $module;
                }
            }
            if (!empty($missingModules)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Missing Python dependencies: ' . implode(', ', $missingModules) . '. Please install them using the "Install Dependencies" button.',
                ]);
                break;
            }
            
            // Ensure logs directory exists
            $logsDir = dirname($botLogFile);
            if (!is_dir($logsDir)) {
                @mkdir($logsDir, 0755, true);
            }
            
            // Get config
            $config = getBotConfig($botConfigFile);
            
            // Build command
            $pythonCmd = $pythonCheck['command'];
            $envVars = [];
            $envVars[] = 'BOT_HANDLE=' . escapeshellarg($config['bot_handle'] ?? 'ChatBot');
            $envVars[] = 'BOT_DISPLAY_NAME=' . escapeshellarg($config['bot_display_name'] ?? 'ChatBot');
            $envVars[] = 'BOT_EMAIL=' . escapeshellarg($config['bot_email'] ?? 'chatbot@sentinel.local');
            $envVars[] = 'WS_HOST=localhost';
            $envVars[] = 'WS_PORT=8420';
            $envVars[] = 'API_BASE_URL=' . escapeshellarg((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/iChat/api');
            $envVars[] = 'API_SECRET=' . escapeshellarg(Config::getInstance()->get('api.shared_secret'));
            $envVars[] = 'DEFAULT_ROOM=' . escapeshellarg($config['default_room'] ?? 'lobby');
            $envVars[] = 'RESPONSE_DELAY=' . escapeshellarg((string)($config['response_delay'] ?? 2.0));
            $envVars[] = 'AI_PROVIDER=' . escapeshellarg($config['ai_provider'] ?? 'simple');
            if (!empty($config['openai_api_key'])) {
                $envVars[] = 'OPENAI_API_KEY=' . escapeshellarg($config['openai_api_key']);
            }
            if (!empty($config['ollama_url'])) {
                $envVars[] = 'OLLAMA_URL=' . escapeshellarg($config['ollama_url']);
            }
            if (!empty($config['ollama_model'])) {
                $envVars[] = 'OLLAMA_MODEL=' . escapeshellarg($config['ollama_model']);
            }
            
            // Start bot in background
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                // Windows: Use PowerShell to properly set environment variables and start the process
                $projectRoot = realpath(dirname(__DIR__));
                $botScriptPath = realpath($botScript);
                $botLogPath = realpath(dirname($botLogFile)) . '\\' . basename($botLogFile);
                
                // Ensure paths are Windows-style
                $botScriptPath = str_replace('/', '\\', $botScriptPath);
                $botLogPath = str_replace('/', '\\', $botLogPath);
                $projectRoot = str_replace('/', '\\', $projectRoot);
                
                // Build PowerShell command to set env vars and start Python
                // Use single quotes for PowerShell strings to avoid escaping issues
                $psCommands = [];
                $psCommands[] = '$env:BOT_HANDLE=' . escapeshellarg($config['bot_handle'] ?? 'ChatBot');
                $psCommands[] = '$env:BOT_DISPLAY_NAME=' . escapeshellarg($config['bot_display_name'] ?? 'ChatBot');
                $psCommands[] = '$env:BOT_EMAIL=' . escapeshellarg($config['bot_email'] ?? 'chatbot@sentinel.local');
                $psCommands[] = '$env:WS_HOST="localhost"';
                $psCommands[] = '$env:WS_PORT="8420"';
                $psCommands[] = '$env:API_BASE_URL=' . escapeshellarg((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/iChat/api');
                $psCommands[] = '$env:API_SECRET=' . escapeshellarg(Config::getInstance()->get('api.shared_secret'));
                $psCommands[] = '$env:DEFAULT_ROOM=' . escapeshellarg($config['default_room'] ?? 'lobby');
                $psCommands[] = '$env:RESPONSE_DELAY=' . escapeshellarg((string)($config['response_delay'] ?? 2.0));
                $psCommands[] = '$env:AI_PROVIDER=' . escapeshellarg($config['ai_provider'] ?? 'simple');
                if (!empty($config['openai_api_key'])) {
                    $psCommands[] = '$env:OPENAI_API_KEY=' . escapeshellarg($config['openai_api_key']);
                }
                if (!empty($config['ollama_url'])) {
                    $psCommands[] = '$env:OLLAMA_URL=' . escapeshellarg($config['ollama_url']);
                }
                if (!empty($config['ollama_model'])) {
                    $psCommands[] = '$env:OLLAMA_MODEL=' . escapeshellarg($config['ollama_model']);
                }
                $psCommands[] = 'Set-Location -Path ' . escapeshellarg($projectRoot);
                // Use Start-Process with proper error handling
                $psCommands[] = 'try {';
                $psCommands[] = '    $proc = Start-Process -FilePath ' . escapeshellarg($pythonCmd) . ' -ArgumentList ' . escapeshellarg($botScriptPath) . ' -WorkingDirectory ' . escapeshellarg($projectRoot) . ' -RedirectStandardOutput ' . escapeshellarg($botLogPath) . ' -RedirectStandardError ' . escapeshellarg($botLogPath) . ' -PassThru -WindowStyle Hidden -ErrorAction Stop';
                $psCommands[] = '    if ($proc -and $proc.Id) {';
                $psCommands[] = '        $pidFile = ' . escapeshellarg($botPidFile) . ';';
                $psCommands[] = '        [System.IO.File]::WriteAllText($pidFile, $proc.Id.ToString());';
                $psCommands[] = '        Write-Output $proc.Id';
                $psCommands[] = '    } else {';
                $psCommands[] = '        Write-Output "0"';
                $psCommands[] = '    }';
                $psCommands[] = '} catch {';
                $psCommands[] = '    Write-Output "ERROR: " + $_.Exception.Message';
                $psCommands[] = '    Write-Output "0"';
                $psCommands[] = '}';
                
                // Create temporary PowerShell script file for more reliable execution
                $psScriptFile = __DIR__ . '/../logs/start_bot_' . time() . '.ps1';
                $psScriptContent = implode("\r\n", $psCommands);
                @file_put_contents($psScriptFile, $psScriptContent);
                
                // Execute PowerShell script and capture both stdout and stderr
                $fullCommand = 'powershell -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($psScriptFile) . ' 2>&1';
                $pidOutput = @shell_exec($fullCommand);
                $pid = trim($pidOutput ?? '');
                
                // Clean up script file
                @unlink($psScriptFile);
                
                // Check if we got an error message instead of a PID
                if (strpos($pid, 'ERROR:') !== false) {
                    $errorDetails = $pid;
                    $pid = '0';
                }
                
                // If PID file was created by PowerShell, verify it
                if (file_exists($botPidFile)) {
                    $pidFromFile = trim(@file_get_contents($botPidFile));
                    if (!empty($pidFromFile) && is_numeric($pidFromFile)) {
                        $pid = $pidFromFile;
                    }
                } elseif (!empty($pid) && is_numeric($pid)) {
                    // Fallback: Write PID file manually if PowerShell didn't
                    @file_put_contents($botPidFile, $pid);
                } else {
                    // Fallback: Create a batch file to set env vars and start Python
                    $batchFile = __DIR__ . '/../logs/start_bot_' . time() . '.bat';
                    $batchContent = '@echo off' . "\r\n";
                    $batchContent .= 'cd /d ' . escapeshellarg($projectRoot) . "\r\n";
                    $batchContent .= 'set BOT_HANDLE=' . escapeshellarg($config['bot_handle'] ?? 'ChatBot') . "\r\n";
                    $batchContent .= 'set BOT_DISPLAY_NAME=' . escapeshellarg($config['bot_display_name'] ?? 'ChatBot') . "\r\n";
                    $batchContent .= 'set BOT_EMAIL=' . escapeshellarg($config['bot_email'] ?? 'chatbot@sentinel.local') . "\r\n";
                    $batchContent .= 'set WS_HOST=localhost' . "\r\n";
                    $batchContent .= 'set WS_PORT=8420' . "\r\n";
                    $batchContent .= 'set API_BASE_URL=' . escapeshellarg((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/iChat/api') . "\r\n";
                    $batchContent .= 'set API_SECRET=' . escapeshellarg(Config::getInstance()->get('api.shared_secret')) . "\r\n";
                    $batchContent .= 'set DEFAULT_ROOM=' . escapeshellarg($config['default_room'] ?? 'lobby') . "\r\n";
                    $batchContent .= 'set RESPONSE_DELAY=' . escapeshellarg((string)($config['response_delay'] ?? 2.0)) . "\r\n";
                    $batchContent .= 'set AI_PROVIDER=' . escapeshellarg($config['ai_provider'] ?? 'simple') . "\r\n";
                    if (!empty($config['openai_api_key'])) {
                        $batchContent .= 'set OPENAI_API_KEY=' . escapeshellarg($config['openai_api_key']) . "\r\n";
                    }
                    if (!empty($config['ollama_url'])) {
                        $batchContent .= 'set OLLAMA_URL=' . escapeshellarg($config['ollama_url']) . "\r\n";
                    }
                    if (!empty($config['ollama_model'])) {
                        $batchContent .= 'set OLLAMA_MODEL=' . escapeshellarg($config['ollama_model']) . "\r\n";
                    }
                    $batchContent .= 'start /B ' . escapeshellarg($pythonCmd) . ' ' . escapeshellarg($botScriptPath) . ' > ' . escapeshellarg($botLogPath) . ' 2>&1' . "\r\n";
                    
                    @file_put_contents($batchFile, $batchContent);
                    @exec('start /B cmd /c "' . $batchFile . '"');
                    // Note: Batch file method doesn't capture PID, but process should start
                }
            } else {
                // Unix/Linux
                $cmd = 'cd ' . escapeshellarg(dirname(__DIR__)) . ' && nohup ' . implode(' ', $envVars) . ' ' . $pythonCmd . ' ' . escapeshellarg($botScript) . ' >> ' . escapeshellarg($botLogFile) . ' 2>&1 & echo $!';
                $pid = trim(@shell_exec($cmd));
                if (!empty($pid) && is_numeric($pid)) {
                    @file_put_contents($botPidFile, $pid);
                }
            }
            
            // Wait a moment and check if it started
            sleep(2);
            $status = checkBotStatus($botPidFile);
            
            // If not running, check log file for errors and test Python directly
            $errorDetails = null;
            if (!$status['running']) {
                // First, check if we got an error from PowerShell
                if (isset($errorDetails) && !empty($errorDetails)) {
                    // Error already captured from PowerShell
                } elseif (file_exists($botLogFile)) {
                    $logContent = @file_get_contents($botLogFile);
                    if (!empty($logContent)) {
                        // Get last few lines of log
                        $logLines = explode("\n", $logContent);
                        $lastLines = array_slice($logLines, -5);
                        $errorDetails = implode("\n", $lastLines);
                    } else {
                        // Test if Python can even run the script
                        $testCmd = $pythonCheck['command'] . ' -c "import sys; print(sys.version)" 2>&1';
                        $testOutput = @shell_exec($testCmd);
                        if (empty($testOutput)) {
                            $errorDetails = 'Python command failed to execute. Command: ' . $pythonCheck['command'];
                        } else {
                            // Try to run the bot script directly to see the error
                            $testScriptCmd = $pythonCheck['command'] . ' ' . escapeshellarg($botScript) . ' 2>&1';
                            $testScriptOutput = @shell_exec($testScriptCmd);
                            if (!empty($testScriptOutput)) {
                                $errorDetails = 'Script test output: ' . substr($testScriptOutput, 0, 200);
                            } else {
                                $errorDetails = 'Log file is empty - bot may have failed to start or crashed immediately. Python: ' . trim($testOutput);
                            }
                        }
                    }
                } else {
                    $errorDetails = 'Log file not created - bot process may not have started. Check if Python and dependencies are installed.';
                }
            }
            
            echo json_encode([
                'success' => $status['running'],
                'status' => $status,
                'error' => $status['running'] ? null : ('Failed to start bot. ' . ($errorDetails ? 'Details: ' . $errorDetails : 'Check logs for details.')),
            ]);
            break;
            
        case 'stop':
            // Check if running
            $status = checkBotStatus($botPidFile);
            if (!$status['running'] || !$status['pid']) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Bot is not running',
                ]);
                break;
            }
            
            $pid = $status['pid'];
            
            // Stop bot
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                // Windows
                @exec("taskkill /F /PID $pid 2>nul");
            } else {
                // Unix/Linux
                @posix_kill($pid, SIGTERM);
                // Wait a bit, then force kill if still running
                sleep(2);
                $stillRunning = @posix_kill($pid, 0);
                if ($stillRunning) {
                    @posix_kill($pid, SIGKILL);
                }
            }
            
            // Remove PID file
            if (file_exists($botPidFile)) {
                @unlink($botPidFile);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Bot stopped',
            ]);
            break;
            
        case 'restart':
            // Stop first
            $status = checkBotStatus($botPidFile);
            if ($status['running'] && $status['pid']) {
                $pid = $status['pid'];
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    @exec("taskkill /F /PID $pid 2>nul");
                } else {
                    @posix_kill($pid, SIGTERM);
                    sleep(2);
                    $stillRunning = @posix_kill($pid, 0);
                    if ($stillRunning) {
                        @posix_kill($pid, SIGKILL);
                    }
                }
                if (file_exists($botPidFile)) {
                    @unlink($botPidFile);
                }
                sleep(1);
            }
            
            // Start again (reuse start logic)
            $_GET['action'] = 'start';
            include __FILE__;
            break;
            
        case 'check-dependencies':
            $result = checkDependencies($requirementsFile);
            echo json_encode([
                'success' => true,
                'dependencies' => $result,
            ]);
            break;
            
        case 'install-dependencies':
            $result = installDependencies($requirementsFile);
            echo json_encode([
                'success' => $result['success'],
                'output' => $result['output'] ?? '',
                'error' => $result['error'] ?? null,
            ]);
            break;
            
        case 'check-user':
            // Check if bot user exists
            $config = getBotConfig($botConfigFile);
            $botHandle = $config['bot_handle'] ?? 'ChatBot';
            
            $authRepo = new AuthRepository();
            $user = $authRepo->getUserByUsernameOrEmail($botHandle);
            
            echo json_encode([
                'success' => true,
                'exists' => $user !== null,
                'user' => $user ? [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                ] : null,
            ]);
            break;
            
        case 'create-user':
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
            
            $botHandle = $security->sanitizeInput($input['handle'] ?? '');
            $botEmail = $security->sanitizeInput($input['email'] ?? '');
            $botRole = $security->sanitizeInput($input['role'] ?? 'user');
            
            if (empty($botHandle) || empty($botEmail)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Handle and email are required',
                ]);
                break;
            }
            
            // Validate handle
            if (!$security->validateHandle($botHandle)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid bot handle format',
                ]);
                break;
            }
            
            // Validate email
            if (!filter_var($botEmail, FILTER_VALIDATE_EMAIL)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid email format',
                ]);
                break;
            }
            
            // Validate role
            $validRoles = ['guest', 'user', 'moderator', 'administrator'];
            if (!in_array($botRole, $validRoles, true)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid role',
                ]);
                break;
            }
            
            // Check if user already exists
            $authRepo = new AuthRepository();
            $existingUser = $authRepo->getUserByUsernameOrEmail($botHandle);
            if ($existingUser) {
                echo json_encode([
                    'success' => false,
                    'error' => 'User already exists',
                ]);
                break;
            }
            
            // Generate password
            $password = bin2hex(random_bytes(16));
            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            
            // Create user
            $userId = $authRepo->createUser($botHandle, $botEmail, $passwordHash, $botRole);
            
            if ($userId) {
                // Update config with bot handle
                $config = getBotConfig($botConfigFile);
                $config['bot_handle'] = $botHandle;
                $config['bot_email'] = $botEmail;
                saveBotConfig($botConfigFile, $config);
                
                echo json_encode([
                    'success' => true,
                    'user_id' => $userId,
                    'handle' => $botHandle,
                    'message' => 'Bot user created successfully',
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to create bot user',
                ]);
            }
            break;
            
        case 'save-config':
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
            
            // Get current config
            $config = getBotConfig($botConfigFile);
            
            // Update config
            if (isset($input['display_name'])) {
                $config['bot_display_name'] = $security->sanitizeInput($input['display_name']);
            }
            if (isset($input['default_room'])) {
                $config['default_room'] = $security->sanitizeInput($input['default_room']);
            }
            if (isset($input['response_delay'])) {
                $config['response_delay'] = (float)$input['response_delay'];
            }
            if (isset($input['ai_provider'])) {
                $config['ai_provider'] = $security->sanitizeInput($input['ai_provider']);
            }
            if (isset($input['openai_api_key'])) {
                $config['openai_api_key'] = $input['openai_api_key']; // Don't sanitize, it's a secret
            }
            if (isset($input['ollama_url'])) {
                $config['ollama_url'] = $security->sanitizeInput($input['ollama_url']);
            }
            if (isset($input['ollama_model'])) {
                $config['ollama_model'] = $security->sanitizeInput($input['ollama_model']);
            }
            
            // Save config
            $saved = saveBotConfig($botConfigFile, $config);
            
            echo json_encode([
                'success' => $saved,
                'config' => $config,
                'error' => $saved ? null : 'Failed to save configuration',
            ]);
            break;
            
        case 'logs':
            $lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 100;
            $lines = max(10, min(1000, $lines));
            
            $logContent = '';
            if (file_exists($botLogFile)) {
                // Read last N lines
                $file = file($botLogFile);
                if ($file) {
                    $logContent = implode('', array_slice($file, -$lines));
                }
            } else {
                $logContent = 'No log file found. Bot has not been started yet.';
            }
            
            echo json_encode([
                'success' => true,
                'logs' => $logContent,
            ]);
            break;
            
        case 'clear-logs':
            if (file_exists($botLogFile)) {
                @file_put_contents($botLogFile, '');
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Logs cleared',
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}

