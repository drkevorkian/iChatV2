<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\SecurityService;
use iChat\Services\AuthService;
use iChat\Services\PatchManager;
use iChat\Repositories\RoomRequestRepository;
use iChat\Repositories\WordFilterRequestRepository;
use iChat\Repositories\ReportRepository;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

$authService = new AuthService();
$currentUser = $authService->getCurrentUser();

// Only admins can access this
if ($currentUser === null || $currentUser['role'] !== 'administrator') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden - Admin access required']);
    exit;
}

try {
    $notifications = [
        'patches' => 0,
        'room_requests' => 0,
        'reports' => 0,
        'word_filter_requests' => 0,
        'total' => 0
    ];
    
    // Check for pending patches (patches that are not yet applied)
    $patchManager = new PatchManager();
    $patches = $patchManager->getAvailablePatches();
    foreach ($patches as $patch) {
        // Check if patch is not applied (pending)
        if (isset($patch['applied']) && $patch['applied'] === false) {
            $notifications['patches']++;
        }
    }
    
    // Check for pending room requests
    $roomRequestRepo = new RoomRequestRepository();
    $pendingRequests = $roomRequestRepo->getAllRequests('pending');
    $notifications['room_requests'] = count($pendingRequests);
    
    // Check for pending reports
    $reportRepo = new ReportRepository();
    $notifications['reports'] = $reportRepo->getPendingCount();
    
    // Check for pending word filter requests
    $wordFilterRequestRepo = new WordFilterRequestRepository();
    $pendingWordFilterRequests = $wordFilterRequestRepo->getAllRequests('pending');
    $notifications['word_filter_requests'] = count($pendingWordFilterRequests);
    
    // Calculate total
    $notifications['total'] = $notifications['patches'] + $notifications['room_requests'] + $notifications['reports'] + $notifications['word_filter_requests'];
    
    echo json_encode([
        'success' => true,
        'notifications' => $notifications
    ]);
} catch (\Exception $e) {
    error_log('Admin notifications API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}

