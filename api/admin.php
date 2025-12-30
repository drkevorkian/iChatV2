<?php
/**
 * Sentinel Chat Platform - Admin API Endpoint
 * 
 * Provides admin dashboard data and escrow request management.
 * Requires API secret authentication.
 * 
 * Security: All operations are logged for audit purposes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Repositories\MessageRepository;
use iChat\Repositories\EscrowRepository;
use iChat\Services\SecurityService;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

// Validate API secret for admin endpoints
if (!$security->validateApiSecret()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$messageRepo = new MessageRepository();
$escrowRepo = new EscrowRepository();

try {
    if ($method === 'GET' && $action === '') {
        // Get admin dashboard data
        $pendingMessages = $messageRepo->getPendingMessages(100);
        $escrowRequests = $escrowRepo->getAllRequests();
        
        // Calculate statistics
        $queueDepth = count($pendingMessages);
        $pendingEscrowCount = count(array_filter($escrowRequests, function($req) {
            return $req['status'] === 'pending';
        }));
        
        echo json_encode([
            'success' => true,
            'telemetry' => [
                'queue_depth' => $queueDepth,
                'pending_escrow_requests' => $pendingEscrowCount,
                'total_escrow_requests' => count($escrowRequests),
            ],
            'pending_messages' => $pendingMessages,
            'escrow_requests' => $escrowRequests,
        ]);
    } elseif ($method === 'POST' && $action === 'escrow-request') {
        // Create escrow request
        // SECURITY: Secure JSON parsing with error checking to prevent injection attacks
        $rawInput = file_get_contents('php://input');
        if ($rawInput === false) {
            throw new \InvalidArgumentException('Failed to read request body');
        }
        $input = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
            throw new \InvalidArgumentException('Invalid JSON input: ' . json_last_error_msg());
        }
        
        $roomId = $security->sanitizeInput($input['room_id'] ?? '');
        $operatorHandle = $security->sanitizeInput($input['operator_handle'] ?? '');
        $justification = $security->sanitizeInput($input['justification'] ?? '', 5000);
        
        if (empty($roomId) || empty($operatorHandle) || empty($justification)) {
            throw new \InvalidArgumentException('Missing required fields');
        }
        
        if (!$security->validateRoomId($roomId)) {
            throw new \InvalidArgumentException('Invalid room ID format');
        }
        
        if (!$security->validateHandle($operatorHandle)) {
            throw new \InvalidArgumentException('Invalid operator handle format');
        }
        
        $requestId = $escrowRepo->createRequest($roomId, $operatorHandle, $justification);
        
        echo json_encode([
            'success' => true,
            'request_id' => $requestId,
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request']);
    }
} catch (\Exception $e) {
    error_log('Admin API error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'error' => 'Request failed',
        'message' => $e->getMessage(),
    ]);
}

