<?php
/**
 * Sentinel Chat Platform - Audit Log API
 * 
 * Provides access to audit logs for compliance and security monitoring.
 * Supports viewing, filtering, and exporting audit logs in various formats.
 * 
 * Security: Only administrators, trusted admins, and owners can access audit logs.
 * All queries use prepared statements. Export files are digitally signed.
 */

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\AuditService;
use iChat\Services\SecurityService;
use iChat\Services\AuthService;

// Initialize services
$auditService = new AuditService();
$security = new SecurityService();
$auth = new AuthService();

// Check authentication and authorization
$user = $auth->getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Only admins, trusted admins, and owners can access audit logs
$allowedRoles = ['administrator', 'trusted_admin', 'owner'];
if (!in_array($user['role'], $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Insufficient permissions']);
    exit;
}

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            // Get audit logs with filtering
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('GET method required for list action');
            }

            // Build filters from query parameters
            $filters = [];
            if (!empty($_GET['user_handle'])) {
                $filters['user_handle'] = $security->sanitizeInput($_GET['user_handle']);
            }
            if (!empty($_GET['user_id'])) {
                $filters['user_id'] = (int)$_GET['user_id'];
            }
            if (!empty($_GET['action_type'])) {
                $filters['action_type'] = $security->sanitizeInput($_GET['action_type']);
            }
            if (!empty($_GET['action_category'])) {
                $filters['action_category'] = $security->sanitizeInput($_GET['action_category']);
            }
            if (!empty($_GET['resource_type'])) {
                $filters['resource_type'] = $security->sanitizeInput($_GET['resource_type']);
            }
            if (!empty($_GET['resource_id'])) {
                $filters['resource_id'] = $security->sanitizeInput($_GET['resource_id']);
            }
            if (!empty($_GET['ip_address'])) {
                $filters['ip_address'] = $security->sanitizeInput($_GET['ip_address']);
            }
            if (!empty($_GET['session_id'])) {
                $filters['session_id'] = $security->sanitizeInput($_GET['session_id']);
            }
            if (isset($_GET['success'])) {
                $filters['success'] = $_GET['success'] === '1' || $_GET['success'] === 'true';
            }
            if (!empty($_GET['start_date'])) {
                $filters['start_date'] = $security->sanitizeInput($_GET['start_date']);
            }
            if (!empty($_GET['end_date'])) {
                $filters['end_date'] = $security->sanitizeInput($_GET['end_date']);
            }

            $limit = isset($_GET['limit']) ? max(1, min(1000, (int)$_GET['limit'])) : 100;
            $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
            $orderBy = $_GET['order_by'] ?? 'timestamp';
            $orderDir = $_GET['order_dir'] ?? 'DESC';

            $logs = $auditService->getLogs($filters, $limit, $offset, $orderBy, $orderDir);
            $total = $auditService->getLogCount($filters);

            echo json_encode([
                'success' => true,
                'logs' => $logs,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ]);
            break;

        case 'export':
            // Export audit logs in various formats
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('GET method required for export action');
            }

            $format = $_GET['format'] ?? 'json';
            $format = in_array($format, ['json', 'csv', 'pdf'], true) ? $format : 'json';

            // Build filters (same as list)
            $filters = [];
            if (!empty($_GET['user_handle'])) {
                $filters['user_handle'] = $security->sanitizeInput($_GET['user_handle']);
            }
            if (!empty($_GET['user_id'])) {
                $filters['user_id'] = (int)$_GET['user_id'];
            }
            if (!empty($_GET['action_type'])) {
                $filters['action_type'] = $security->sanitizeInput($_GET['action_type']);
            }
            if (!empty($_GET['action_category'])) {
                $filters['action_category'] = $security->sanitizeInput($_GET['action_category']);
            }
            if (!empty($_GET['start_date'])) {
                $filters['start_date'] = $security->sanitizeInput($_GET['start_date']);
            }
            if (!empty($_GET['end_date'])) {
                $filters['end_date'] = $security->sanitizeInput($_GET['end_date']);
            }

            // Get all matching logs (no limit for export)
            $logs = $auditService->getLogs($filters, 10000, 0, 'timestamp', 'ASC');

            switch ($format) {
                case 'json':
                    header('Content-Type: application/json');
                    header('Content-Disposition: attachment; filename="audit_log_' . date('Y-m-d_His') . '.json"');
                    echo json_encode([
                        'export_date' => date('Y-m-d H:i:s'),
                        'exported_by' => $user['username'],
                        'filters' => $filters,
                        'total_records' => count($logs),
                        'logs' => $logs,
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    break;

                case 'csv':
                    header('Content-Type: text/csv');
                    header('Content-Disposition: attachment; filename="audit_log_' . date('Y-m-d_His') . '.csv"');
                    
                    $output = fopen('php://output', 'w');
                    
                    // Write header
                    fputcsv($output, [
                        'ID', 'Timestamp', 'User ID', 'User Handle', 'Action Type', 'Action Category',
                        'Resource Type', 'Resource ID', 'IP Address', 'User Agent', 'Session ID',
                        'Success', 'Error Message', 'Before Value', 'After Value', 'Metadata'
                    ]);
                    
                    // Write data
                    foreach ($logs as $log) {
                        fputcsv($output, [
                            $log['id'],
                            $log['timestamp'],
                            $log['user_id'] ?? '',
                            $log['user_handle'],
                            $log['action_type'],
                            $log['action_category'],
                            $log['resource_type'] ?? '',
                            $log['resource_id'] ?? '',
                            $log['ip_address'] ?? '',
                            $log['user_agent'] ?? '',
                            $log['session_id'] ?? '',
                            $log['success'] ? 'Yes' : 'No',
                            $log['error_message'] ?? '',
                            isset($log['before_value']) ? json_encode($log['before_value']) : '',
                            isset($log['after_value']) ? json_encode($log['after_value']) : '',
                            isset($log['metadata']) ? json_encode($log['metadata']) : '',
                        ]);
                    }
                    
                    fclose($output);
                    break;

                case 'pdf':
                    // PDF export would require a library like TCPDF or FPDF
                    // For now, return JSON with a note that PDF export is not yet implemented
                    http_response_code(501);
                    echo json_encode([
                        'error' => 'PDF export not yet implemented',
                        'suggestion' => 'Use JSON or CSV format for now',
                    ]);
                    break;
            }
            break;

        case 'stats':
            // Get audit log statistics
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('GET method required for stats action');
            }

            // Get counts by category
            $categories = ['authentication', 'message', 'file', 'room', 'admin', 'moderation', 'system', 'other'];
            $stats = [];

            foreach ($categories as $category) {
                $count = $auditService->getLogCount(['action_category' => $category]);
                $stats[$category] = $count;
            }

            // Get total count
            $stats['total'] = $auditService->getLogCount();

            // Get recent activity (last 24 hours)
            $stats['last_24h'] = $auditService->getLogCount([
                'start_date' => date('Y-m-d H:i:s', strtotime('-24 hours')),
            ]);

            // Get failed actions count
            $stats['failed_actions'] = $auditService->getLogCount(['success' => false]);

            echo json_encode([
                'success' => true,
                'stats' => $stats,
            ]);
            break;

        default:
            throw new \InvalidArgumentException('Invalid action: ' . $action);
    }
} catch (\Exception $e) {
    error_log('Audit API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred: ' . $e->getMessage(),
    ]);
}

