<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\SecurityService;
use iChat\Services\AuthService;
use iChat\Repositories\ReportRepository;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

$authService = new AuthService();
$currentUser = $authService->getCurrentUser();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

$reportRepo = new ReportRepository();

try {
    switch ($action) {
        case 'list':
            // List all reports (admin/moderator only)
            if ($currentUser === null || !in_array($currentUser['role'], ['administrator', 'moderator'], true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - Admin/Moderator access required']);
                exit;
            }
            
            $status = $security->sanitizeInput($_GET['status'] ?? '');
            $reports = $reportRepo->getAllReports($status ?: null);
            
            echo json_encode([
                'success' => true,
                'reports' => $reports
            ]);
            break;
            
        case 'pending':
            // Get pending reports count (admin/moderator only)
            if ($currentUser === null || !in_array($currentUser['role'], ['administrator', 'moderator'], true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - Admin/Moderator access required']);
                exit;
            }
            
            $count = $reportRepo->getPendingCount();
            
            echo json_encode([
                'success' => true,
                'count' => $count
            ]);
            break;
            
        case 'update':
            // Update report status (admin/moderator only)
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for update action');
            }
            
            if ($currentUser === null || !in_array($currentUser['role'], ['administrator', 'moderator'], true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden - Admin/Moderator access required']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            $reportId = (int)($input['report_id'] ?? 0);
            $status = $security->sanitizeInput($input['status'] ?? '');
            $adminNotes = $security->sanitizeInput($input['admin_notes'] ?? '');
            
            if ($reportId <= 0) {
                throw new \InvalidArgumentException('Invalid report ID');
            }
            
            $validStatuses = ['reviewed', 'resolved', 'dismissed'];
            if (!in_array($status, $validStatuses, true)) {
                throw new \InvalidArgumentException('Invalid status');
            }
            
            $success = $reportRepo->updateReportStatus(
                $reportId,
                $status,
                $currentUser['username'],
                $adminNotes
            );
            
            if ($success) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Report status updated'
                ]);
            } else {
                throw new \RuntimeException('Failed to update report status');
            }
            break;
            
        default:
            throw new \InvalidArgumentException('Invalid action');
    }
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Exception $e) {
    error_log('Reports API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

