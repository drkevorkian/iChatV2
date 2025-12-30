<?php
/**
 * Sentinel Chat Platform - Messages API Endpoint
 * 
 * Handles message queuing and retrieval operations.
 * All requests require X-API-SECRET header for authentication.
 * 
 * Security: Uses prepared statements for all database queries.
 * API secret is validated on every request.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Repositories\MessageRepository;
use iChat\Services\SecurityService;
use iChat\Services\WordFilterService;
use iChat\Services\SmileyService;
use iChat\Services\AsciiArtService;
use iChat\Services\PinkyBrainService;
use iChat\Services\AuthService;
use iChat\Services\AIService;
use iChat\Services\AuditService;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Validate API secret (except for DELETE which uses session auth)
if ($method !== 'DELETE') {
    if (!$security->validateApiSecret()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

$repository = new MessageRepository();

try {
    switch ($method) {
        case 'GET':
            // Get pending messages
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
            $limit = max(1, min(1000, $limit)); // Sanitize limit
            
            // Optional room filter
            $roomId = isset($_GET['room_id']) ? $security->sanitizeInput($_GET['room_id']) : null;
            
            // Check if user is moderator/admin (to include hidden messages)
            $includeHidden = false;
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $userRole = $_SESSION['user_role'] ?? 'guest';
            if (in_array($userRole, ['moderator', 'administrator'], true)) {
                $includeHidden = isset($_GET['include_hidden']) && $_GET['include_hidden'] === '1';
            }
            
            // For display purposes, include delivered messages too
            $includeDelivered = true; // Always include delivered messages for chat display
            $messages = $repository->getPendingMessages($limit, $includeHidden, $includeDelivered);
            
            // Filter by room if specified
            if ($roomId !== null && $security->validateRoomId($roomId)) {
                $messages = array_filter($messages, function($msg) use ($roomId) {
                    return ($msg['room_id'] ?? '') === $roomId;
                });
                $messages = array_values($messages); // Re-index array
            }
            
            // Sort by queued_at descending (newest first) for display
            usort($messages, function($a, $b) {
                $tsA = strtotime($a['queued_at'] ?? '1970-01-01');
                $tsB = strtotime($b['queued_at'] ?? '1970-01-01');
                return $tsB <=> $tsA; // Descending order
            });
            
            echo json_encode([
                'success' => true,
                'messages' => $messages,
                'count' => count($messages),
            ]);
            break;
            
        case 'POST':
            // Enqueue a new message
            // SECURITY: Secure JSON parsing with error checking to prevent injection attacks
            $rawInput = file_get_contents('php://input');
            if ($rawInput === false) {
                throw new \InvalidArgumentException('Failed to read request body');
            }
            $input = json_decode($rawInput, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input: ' . json_last_error_msg());
            }
            
            // Validate required fields
            $roomId = $security->sanitizeInput($input['room_id'] ?? '');
            $senderHandle = $security->sanitizeInput($input['sender_handle'] ?? '');
            $cipherBlob = $input['cipher_blob'] ?? '';
            $filterVersion = isset($input['filter_version']) ? (int)$input['filter_version'] : 1;
            $mediaIds = isset($input['media_ids']) && is_array($input['media_ids']) ? $input['media_ids'] : [];
            
            // Must have message or media
            if (empty($cipherBlob) && empty($mediaIds)) {
                throw new \InvalidArgumentException('Message or media required');
            }
            
            if (empty($roomId) || empty($senderHandle)) {
                throw new \InvalidArgumentException('Missing required fields');
            }
            
            if (!$security->validateRoomId($roomId)) {
                throw new \InvalidArgumentException('Invalid room ID format');
            }
            
            if (!$security->validateHandle($senderHandle)) {
                throw new \InvalidArgumentException('Invalid sender handle format');
            }
            
            // Check if user is banned
            $userManagementRepo = new \iChat\Repositories\UserManagementRepository();
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
            if (strpos($ipAddress, ',') !== false) {
                $ipAddress = trim(explode(',', $ipAddress)[0]);
            }
            
            if ($userManagementRepo->isBanned($senderHandle, $ipAddress)) {
                http_response_code(403);
                $banInfo = $userManagementRepo->getBanInfo($senderHandle, $ipAddress);
                $banReason = $banInfo['reason'] ?? 'No reason provided';
                echo json_encode([
                    'success' => false,
                    'error' => 'You are banned from sending messages. Reason: ' . $banReason,
                    'banned' => true,
                ]);
                exit;
            }
            
            // Validate cipher_blob (should be base64 encoded)
            $decodedMessage = base64_decode($cipherBlob, true);
            if ($decodedMessage === false) {
                throw new \InvalidArgumentException('Invalid cipher_blob format');
            }
            
            // Decode message for processing
            $messageText = urldecode($decodedMessage);
            
            // Initialize services
            $wordFilter = new WordFilterService();
            $smileyService = new SmileyService();
            $asciiArtService = new AsciiArtService();
            $pinkyBrainService = new PinkyBrainService();
            
            // Check for Pinky & Brain command
            $botResponses = [];
            if ($pinkyBrainService->isCommand($messageText)) {
                $botResponses = $pinkyBrainService->processCommand($roomId, $senderHandle);
                
                // If bot is activated, send Brain's message
                if (!empty($botResponses['brain'])) {
                    $brainMessage = $botResponses['brain'];
                    $brainCipherBlob = base64_encode(rawurlencode($brainMessage));
                    $repository->enqueueMessage(
                        $roomId,
                        $pinkyBrainService->getBrainHandle(),
                        $brainCipherBlob,
                        $filterVersion
                    );
                    
                    // Schedule Pinky's response (will be sent after a short delay)
                    // Store in response for client to handle
                    $botResponses['pinky_delay'] = 2000; // 2 seconds delay
                }
                
                // Return bot response info (client will handle Pinky's message)
                echo json_encode([
                    'success' => true,
                    'message_id' => null,
                    'bot_command' => true,
                    'bot_responses' => $botResponses,
                ]);
                exit;
            }
            
            // Apply word filter
            $filterResult = $wordFilter->filterMessage($messageText, $senderHandle);
            $filteredMessage = $filterResult['filtered'];
            $isFlagged = $filterResult['flagged'];
            $flaggedWords = $filterResult['flagged_words'] ?? [];
            
            // If words were flagged, trigger AI moderation
            $aiModerationResult = null;
            if ($isFlagged && !empty($flaggedWords)) {
                $aiService = new AIService();
                $aiModerationResult = $aiService->moderateMessage(
                    $messageText,
                    $senderHandle,
                    $flaggedWords,
                    null, // messageId will be set after enqueue
                    'room'
                );
                
                // Apply moderation action if needed
                if ($aiModerationResult['action'] === 'delete') {
                    // Don't send the message
                    http_response_code(403);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Message was flagged by AI moderation and cannot be sent.',
                        'moderated' => true,
                    ]);
                    exit;
                } elseif ($aiModerationResult['action'] === 'hide') {
                    // Mark message as hidden
                    $isFlagged = true; // Will be handled by is_hidden flag
                }
            }
            
            // Validate ASCII art if present
            if ($asciiArtService->containsAsciiArt($filteredMessage)) {
                $validation = $asciiArtService->validateAsciiArt($filteredMessage);
                if (!$validation['valid']) {
                    throw new \InvalidArgumentException($validation['error']);
                }
            }
            
            // Apply smiley conversion (will be done on display, but we can store processed version)
            // Note: We store the filtered message, smileys are converted on display
            
            // Re-encode message (use rawurlencode to avoid + signs, uses %20 instead)
            $processedCipherBlob = base64_encode(rawurlencode($filteredMessage));
            
            // Determine if message should be hidden based on AI moderation
            $shouldHide = false;
            if ($aiModerationResult && in_array($aiModerationResult['action'], ['hide', 'warn'])) {
                $shouldHide = true;
            }
            
            $messageId = $repository->enqueueMessage(
                $roomId,
                $senderHandle,
                $processedCipherBlob,
                $filterVersion,
                $shouldHide
            );
            
            // Log audit event for message send
            if ($messageId) {
                $auditService = new AuditService();
                $authService = new AuthService();
                $senderUser = $authService->getUserByHandle($senderHandle);
                $senderUserId = $senderUser['id'] ?? null;
                
                $auditService->logMessageSend(
                    $senderHandle,
                    $senderUserId,
                    (string)$messageId,
                    $roomId,
                    [
                        'message_length' => strlen($messageText),
                        'has_media' => !empty($mediaIds),
                        'media_count' => count($mediaIds),
                        'flagged' => $isFlagged,
                        'flagged_words' => $flaggedWords,
                        'ai_moderated' => $aiModerationResult !== null,
                        'ai_action' => $aiModerationResult['action'] ?? null,
                        'is_hidden' => $shouldHide,
                    ]
                );
            }
            
            // Update AI moderation log with message ID if available
            if ($aiModerationResult && $messageId && is_numeric($messageId)) {
                // Update the most recent moderation log for this message
                $moderationRepo = new \iChat\Repositories\AIModerationRepository();
                // Note: The log was created without messageId, we could update it here if needed
            }
            
            // Mark as flagged if word filter flagged it
            if ($isFlagged) {
                // You may want to log this or notify moderators
                error_log("Message flagged by word filter: Message ID {$messageId}, Room: {$roomId}, Sender: {$senderHandle}");
            }
            
            echo json_encode([
                'success' => true,
                'message_id' => $messageId,
                'flagged' => $isFlagged,
            ]);
            break;
            
        case 'DELETE':
            // Delete a message (soft delete)
            // Check if user is moderator/admin
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $userRole = $_SESSION['user_role'] ?? 'guest';
            if (!in_array($userRole, ['moderator', 'administrator'], true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied. Moderator or Admin privileges required.']);
                exit;
            }
            
            $messageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            if ($messageId <= 0) {
                throw new \InvalidArgumentException('Invalid message ID');
            }
            
            $deleted = $repository->softDelete($messageId);
            
            if ($deleted) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Message deleted successfully',
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Message not found or already deleted',
                ]);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (\Exception $e) {
    error_log('Messages API error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'error' => 'Request failed',
        'message' => $e->getMessage(),
    ]);
}

