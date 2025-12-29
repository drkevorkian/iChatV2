<?php
/**
 * Sentinel Chat Platform - Mail API Endpoint
 * 
 * Handles mail operations including sending, receiving, folders,
 * threading, and attachments.
 * 
 * Security: All operations use prepared statements and input validation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Repositories\MailRepository;
use iChat\Services\SecurityService;
use iChat\Services\AuthService;
use iChat\Database;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

// Start session if needed
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get current user
$authService = new AuthService();
$currentUser = $authService->getCurrentUser();
$userHandle = $currentUser['username'] ?? $_SESSION['user_handle'] ?? '';

if (empty($userHandle)) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';
$repository = new MailRepository();

try {
    switch ($action) {
        case 'inbox':
            // Get inbox messages
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('Invalid method for inbox action');
            }
            
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            
            $messages = $repository->getMailByFolder($userHandle, 'inbox', $limit, $offset);
            
            echo json_encode([
                'success' => true,
                'messages' => $messages,
                'count' => count($messages),
            ]);
            break;
            
        case 'sent':
            // Get sent messages
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('Invalid method for sent action');
            }
            
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            
            $messages = $repository->getMailByFolder($userHandle, 'sent', $limit, $offset);
            
            echo json_encode([
                'success' => true,
                'messages' => $messages,
                'count' => count($messages),
            ]);
            break;
            
        case 'drafts':
            // Get draft messages
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('Invalid method for drafts action');
            }
            
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            
            $messages = $repository->getMailByFolder($userHandle, 'drafts', $limit, $offset);
            
            echo json_encode([
                'success' => true,
                'messages' => $messages,
                'count' => count($messages),
            ]);
            break;
            
        case 'trash':
            // Get trash messages
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('Invalid method for trash action');
            }
            
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            
            $messages = $repository->getMailByFolder($userHandle, 'trash', $limit, $offset);
            
            echo json_encode([
                'success' => true,
                'messages' => $messages,
                'count' => count($messages),
            ]);
            break;
            
        case 'send':
            // Send a mail message
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for send action');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            $toUser = $security->sanitizeInput($input['to_user'] ?? '');
            $subject = $security->sanitizeInput($input['subject'] ?? '');
            $cipherBlob = $input['cipher_blob'] ?? '';
            $ccUsers = isset($input['cc_users']) && is_array($input['cc_users']) 
                ? array_map([$security, 'sanitizeInput'], $input['cc_users']) 
                : [];
            $bccUsers = isset($input['bcc_users']) && is_array($input['bcc_users']) 
                ? array_map([$security, 'sanitizeInput'], $input['bcc_users']) 
                : [];
            $replyToId = isset($input['reply_to_id']) ? (int)$input['reply_to_id'] : null;
            $threadId = isset($input['thread_id']) ? (int)$input['thread_id'] : null;
            
            if (empty($toUser) || empty($subject) || empty($cipherBlob)) {
                throw new \InvalidArgumentException('Missing required fields');
            }
            
            if (!$security->validateHandle($toUser)) {
                throw new \InvalidArgumentException('Invalid recipient handle');
            }
            
            // Validate CC and BCC users
            foreach ($ccUsers as $ccUser) {
                if (!$security->validateHandle($ccUser)) {
                    throw new \InvalidArgumentException('Invalid CC user handle');
                }
            }
            foreach ($bccUsers as $bccUser) {
                if (!$security->validateHandle($bccUser)) {
                    throw new \InvalidArgumentException('Invalid BCC user handle');
                }
            }
            
            // Validate cipher_blob
            if (!base64_decode($cipherBlob, true)) {
                throw new \InvalidArgumentException('Invalid cipher_blob format');
            }
            
            $mailId = $repository->sendMail(
                $userHandle,
                $toUser,
                $subject,
                $cipherBlob,
                $ccUsers,
                $bccUsers,
                $replyToId,
                $threadId,
                [] // attachmentIds - will be linked separately
            );
            
            // Handle attachments if provided
            $attachmentIds = [];
            if (isset($input['attachment_ids']) && is_array($input['attachment_ids'])) {
                $attachmentIds = $input['attachment_ids'];
                
                // Link attachments to mail
                if (!empty($attachmentIds)) {
                    $attachmentsDir = ICHAT_ROOT . '/storage/mail_attachments';
                    $linkedCount = 0;
                    
                    foreach ($attachmentIds as $attachmentId) {
                        $tempFile = $attachmentsDir . '/temp_' . $attachmentId . '.json';
                        if (!file_exists($tempFile)) {
                            continue;
                        }
                        
                        $attachmentData = json_decode(file_get_contents($tempFile), true);
                        if (!$attachmentData || $attachmentData['uploaded_by'] !== $userHandle) {
                            continue; // Security check
                        }
                        
                        // Add to database
                        $attachmentDbId = $repository->addAttachment(
                            $mailId,
                            $attachmentData['filename'],
                            $attachmentData['file_path'],
                            $attachmentData['file_size'],
                            $attachmentData['mime_type']
                        );
                        
                        if ($attachmentDbId > 0) {
                            // Delete temp file
                            @unlink($tempFile);
                            $linkedCount++;
                        }
                    }
                    
                    // Update has_attachments flag
                    if ($linkedCount > 0) {
                        Database::execute(
                            'UPDATE mail_messages SET has_attachments = TRUE WHERE id = :id',
                            [':id' => $mailId]
                        );
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'mail_id' => $mailId,
                'attachment_ids' => $attachmentIds,
            ]);
            break;
            
        case 'upload-attachment':
            // Upload a file attachment
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for upload-attachment action');
            }
            
            if (empty($_FILES['file'])) {
                throw new \InvalidArgumentException('No file uploaded');
            }
            
            $file = $_FILES['file'];
            
            // Validate file
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new \InvalidArgumentException('File upload error: ' . $file['error']);
            }
            
            // Check file size (max 10MB)
            $maxSize = 10 * 1024 * 1024; // 10MB
            if ($file['size'] > $maxSize) {
                throw new \InvalidArgumentException('File size exceeds maximum of 10MB');
            }
            
            // Validate file type (basic check)
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt', 'zip', 'rar'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, $allowedExtensions, true)) {
                throw new \InvalidArgumentException('File type not allowed. Allowed types: ' . implode(', ', $allowedExtensions));
            }
            
            // Create mail attachments directory
            $attachmentsDir = ICHAT_ROOT . '/storage/mail_attachments';
            if (!is_dir($attachmentsDir)) {
                @mkdir($attachmentsDir, 0755, true);
                // Create .htaccess to protect directory
                file_put_contents($attachmentsDir . '/.htaccess', "Deny from all\n");
            }
            
            // Generate unique filename
            $filename = $security->sanitizeInput($file['name']);
            $uniqueFilename = $userHandle . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
            $filePath = $attachmentsDir . '/' . $uniqueFilename;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                throw new \RuntimeException('Failed to save uploaded file');
            }
            
            // Get MIME type
            $mimeType = $file['type'] ?? mime_content_type($filePath);
            
            // Store attachment metadata (temporary - will be linked to mail when sent)
            $attachmentData = [
                'filename' => $filename,
                'file_path' => 'storage/mail_attachments/' . $uniqueFilename,
                'file_size' => $file['size'],
                'mime_type' => $mimeType,
                'uploaded_by' => $userHandle,
                'uploaded_at' => date('Y-m-d H:i:s'),
            ];
            
            // Store in temporary file for now (will be moved to database when mail is sent)
            $tempFile = $attachmentsDir . '/temp_' . $uniqueFilename . '.json';
            file_put_contents($tempFile, json_encode($attachmentData, JSON_PRETTY_PRINT));
            
            echo json_encode([
                'success' => true,
                'attachment_id' => $uniqueFilename, // Temporary ID
                'filename' => $filename,
                'file_size' => $file['size'],
                'mime_type' => $mimeType,
            ]);
            break;
            
        case 'link-attachments':
            // Link uploaded attachments to a mail message
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for link-attachments action');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            $mailId = isset($input['mail_id']) ? (int)$input['mail_id'] : 0;
            $attachmentIds = isset($input['attachment_ids']) && is_array($input['attachment_ids']) 
                ? $input['attachment_ids'] : [];
            
            if ($mailId <= 0) {
                throw new \InvalidArgumentException('Invalid mail ID');
            }
            
            $attachmentsDir = ICHAT_ROOT . '/storage/mail_attachments';
            $linkedCount = 0;
            
            foreach ($attachmentIds as $attachmentId) {
                $tempFile = $attachmentsDir . '/temp_' . $attachmentId . '.json';
                if (!file_exists($tempFile)) {
                    continue;
                }
                
                $attachmentData = json_decode(file_get_contents($tempFile), true);
                if (!$attachmentData || $attachmentData['uploaded_by'] !== $userHandle) {
                    continue; // Security check
                }
                
                // Add to database
                $attachmentDbId = $repository->addAttachment(
                    $mailId,
                    $attachmentData['filename'],
                    $attachmentData['file_path'],
                    $attachmentData['file_size'],
                    $attachmentData['mime_type']
                );
                
                if ($attachmentDbId > 0) {
                    // Delete temp file
                    @unlink($tempFile);
                    $linkedCount++;
                }
            }
            
            // Update has_attachments flag
            if ($linkedCount > 0) {
                Database::execute(
                    'UPDATE mail_messages SET has_attachments = TRUE WHERE id = :id',
                    [':id' => $mailId]
                );
            }
            
            echo json_encode([
                'success' => true,
                'linked_count' => $linkedCount,
            ]);
            break;
            
        case 'save-draft':
            // Save a draft
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for save-draft action');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            $toUser = $security->sanitizeInput($input['to_user'] ?? '');
            $subject = $security->sanitizeInput($input['subject'] ?? '');
            $cipherBlob = $input['cipher_blob'] ?? '';
            $draftId = isset($input['draft_id']) ? (int)$input['draft_id'] : null;
            
            if (empty($subject) && empty($cipherBlob)) {
                throw new \InvalidArgumentException('Draft must have at least subject or body');
            }
            
            if (!empty($toUser) && !$security->validateHandle($toUser)) {
                throw new \InvalidArgumentException('Invalid recipient handle');
            }
            
            $draftId = $repository->saveDraft($userHandle, $toUser, $subject, $cipherBlob, $draftId);
            
            echo json_encode([
                'success' => true,
                'draft_id' => $draftId,
            ]);
            break;
            
        case 'view':
            // View a single mail message
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('Invalid method for view action');
            }
            
            $mailId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            
            if ($mailId <= 0) {
                throw new \InvalidArgumentException('Invalid mail ID');
            }
            
            $message = $repository->getMailById($mailId, $userHandle);
            
            if ($message === null) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Mail not found or not accessible',
                ]);
                exit;
            }
            
            // Mark as read if viewing inbox message
            if ($message['folder'] === 'inbox' && $message['read_at'] === null) {
                $repository->markAsRead($mailId, $userHandle);
                $message['read_at'] = date('Y-m-d H:i:s');
                $message['status'] = 'read';
            }
            
            // Get attachments if any
            $attachments = [];
            if ($message['has_attachments']) {
                $attachments = $repository->getAttachments($mailId);
            }
            
            echo json_encode([
                'success' => true,
                'message' => $message,
                'attachments' => $attachments,
            ]);
            break;
            
        case 'move':
            // Move mail to folder
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for move action');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            $mailId = isset($input['mail_id']) ? (int)$input['mail_id'] : 0;
            $folder = $security->sanitizeInput($input['folder'] ?? '');
            
            if ($mailId <= 0) {
                throw new \InvalidArgumentException('Invalid mail ID');
            }
            
            $validFolders = ['inbox', 'sent', 'drafts', 'trash', 'archive', 'spam'];
            if (!in_array($folder, $validFolders, true)) {
                throw new \InvalidArgumentException('Invalid folder');
            }
            
            $success = $repository->moveToFolder($mailId, $userHandle, $folder);
            
            if ($success) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Mail moved successfully',
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Mail not found or not accessible',
                ]);
            }
            break;
            
        case 'delete':
            // Delete mail (move to trash)
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for delete action');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            $mailId = isset($input['mail_id']) ? (int)$input['mail_id'] : 0;
            
            if ($mailId <= 0) {
                throw new \InvalidArgumentException('Invalid mail ID');
            }
            
            $success = $repository->deleteMail($mailId, $userHandle);
            
            if ($success) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Mail deleted successfully',
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Mail not found or not accessible',
                ]);
            }
            break;
            
        case 'permanent-delete':
            // Permanently delete mail
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for permanent-delete action');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            $mailId = isset($input['mail_id']) ? (int)$input['mail_id'] : 0;
            
            if ($mailId <= 0) {
                throw new \InvalidArgumentException('Invalid mail ID');
            }
            
            $success = $repository->permanentlyDeleteMail($mailId, $userHandle);
            
            if ($success) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Mail permanently deleted',
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Mail not found or not accessible',
                ]);
            }
            break;
            
        case 'star':
            // Toggle starred flag
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method for star action');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            $mailId = isset($input['mail_id']) ? (int)$input['mail_id'] : 0;
            
            if ($mailId <= 0) {
                throw new \InvalidArgumentException('Invalid mail ID');
            }
            
            $success = $repository->toggleStarred($mailId, $userHandle);
            
            if ($success) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Star status toggled',
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Mail not found or not accessible',
                ]);
            }
            break;
            
        case 'thread':
            // Get mail thread (conversation)
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('Invalid method for thread action');
            }
            
            $threadId = isset($_GET['thread_id']) ? (int)$_GET['thread_id'] : 0;
            
            if ($threadId <= 0) {
                throw new \InvalidArgumentException('Invalid thread ID');
            }
            
            $messages = $repository->getThread($threadId, $userHandle);
            
            echo json_encode([
                'success' => true,
                'messages' => $messages,
                'count' => count($messages),
            ]);
            break;
            
        case 'unread-count':
            // Get unread mail count
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('Invalid method for unread-count action');
            }
            
            $count = $repository->getUnreadCount($userHandle);
            
            echo json_encode([
                'success' => true,
                'count' => $count,
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} catch (\Exception $e) {
    error_log('Mail API error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'error' => 'Request failed',
        'message' => $e->getMessage(),
    ]);
}

