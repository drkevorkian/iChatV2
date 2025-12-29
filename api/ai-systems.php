<?php
/**
 * Sentinel Chat Platform - AI Systems API
 * 
 * Handles AI system configuration and management.
 * Accessible only by administrators.
 */

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\SecurityService;
use iChat\Services\AuthService;
use iChat\Repositories\AIConfigRepository;
use iChat\Repositories\AIModerationRepository;
use iChat\Services\AIService;

header('Content-Type: application/json');

$security = new SecurityService();
$auth = new AuthService();

// Check authentication and admin role
$currentUser = $auth->getCurrentUser();
if (!$currentUser || !in_array($currentUser['role'], ['owner', 'administrator', 'trusted_admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Administrator access required']);
    exit;
}

$security->setSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

$configRepo = new AIConfigRepository();
$moderationRepo = new AIModerationRepository();
$aiService = new AIService();

try {
    switch ($action) {
        case 'config':
            // Get or update AI system configuration
            if ($method === 'GET') {
                $systemName = $security->sanitizeInput($_GET['system'] ?? '');
                
                if (empty($systemName)) {
                    // Get all configs
                    $configs = $configRepo->getAllConfigs();
                    echo json_encode([
                        'success' => true,
                        'configs' => $configs,
                    ]);
                } else {
                    // Get specific config
                    $config = $configRepo->getConfig($systemName);
                    if ($config) {
                        echo json_encode([
                            'success' => true,
                            'config' => $config,
                        ]);
                    } else {
                        http_response_code(404);
                        echo json_encode([
                            'success' => false,
                            'error' => 'AI system not found',
                        ]);
                    }
                }
            } elseif ($method === 'POST') {
                // Update configuration
                $input = json_decode(file_get_contents('php://input'), true);
                
                if (!is_array($input) || empty($input['system_name'])) {
                    throw new \InvalidArgumentException('Invalid input: system_name required');
                }
                
                $systemName = $security->sanitizeInput($input['system_name']);
                $config = [
                    'enabled' => !empty($input['enabled']),
                    'provider' => $security->sanitizeInput($input['provider'] ?? null),
                    'model_name' => $security->sanitizeInput($input['model_name'] ?? null),
                    'api_key' => !empty($input['api_key']) ? $input['api_key'] : null, // Only update if provided
                    'config_json' => $input['config_json'] ?? null,
                ];
                
                $success = $configRepo->updateConfig($systemName, $config);
                
                echo json_encode([
                    'success' => $success,
                    'message' => $success ? 'Configuration updated' : 'Failed to update configuration',
                ]);
            }
            break;
            
        case 'moderation-logs':
            // Get moderation logs
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('GET method required');
            }
            
            $filters = [];
            if (isset($_GET['user_handle'])) {
                $filters['user_handle'] = $security->sanitizeInput($_GET['user_handle']);
            }
            if (isset($_GET['action'])) {
                $filters['action'] = $security->sanitizeInput($_GET['action']);
            }
            if (isset($_GET['start_date'])) {
                $filters['start_date'] = $security->sanitizeInput($_GET['start_date']);
            }
            if (isset($_GET['end_date'])) {
                $filters['end_date'] = $security->sanitizeInput($_GET['end_date']);
            }
            
            $limit = (int)($_GET['limit'] ?? 100);
            $offset = (int)($_GET['offset'] ?? 0);
            
            $logs = $moderationRepo->getLogs($filters, $limit, $offset);
            
            echo json_encode([
                'success' => true,
                'logs' => $logs,
                'count' => count($logs),
            ]);
            break;
            
        case 'test-moderation':
            // Test AI moderation
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('POST method required');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $testMessage = $security->sanitizeInput($input['message'] ?? '');
            
            if (empty($testMessage)) {
                throw new \InvalidArgumentException('Message required');
            }
            
            // Simulate flagged words
            $flaggedWords = $input['flagged_words'] ?? [];
            
            $result = $aiService->moderateMessage(
                $testMessage,
                $currentUser['username'],
                $flaggedWords
            );
            
            echo json_encode([
                'success' => true,
                'result' => $result,
            ]);
            break;
            
        default:
            throw new \InvalidArgumentException('Invalid action: ' . $action);
    }
} catch (\Exception $e) {
    error_log('AI Systems API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred: ' . $e->getMessage(),
    ]);
}

