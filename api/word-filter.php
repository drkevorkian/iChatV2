<?php
/**
 * Sentinel Chat Platform - Word Filter Management API
 * 
 * Handles word filter CRUD operations (admin) and word filter requests (moderators).
 * 
 * Security: All operations require proper authentication and authorization.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\SecurityService;
use iChat\Services\AuthService;
use iChat\Repositories\WordFilterRequestRepository;
use iChat\Database;
use iChat\Services\WordFilterService;

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

$authService = new AuthService();
$currentUser = $authService->getCurrentUser();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

$wordFilterRequestRepo = new WordFilterRequestRepository();

try {
    // Word filter CRUD operations (admin only)
    if (in_array($action, ['list', 'get', 'add', 'update', 'delete'])) {
        if ($currentUser === null || $currentUser['role'] !== 'administrator') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden - Admin access required']);
            exit;
        }
        
        switch ($action) {
            case 'list':
                // List all word filters
                $status = $security->sanitizeInput($_GET['status'] ?? '');
                $severity = isset($_GET['severity']) ? (int)$_GET['severity'] : null;
                
                $db = Database::getConnection();
                
                // Check if deleted_at column exists
                $hasDeletedAt = false;
                try {
                    $columnCheck = $db->query("SHOW COLUMNS FROM word_filter LIKE 'deleted_at'");
                    $hasDeletedAt = $columnCheck->rowCount() > 0;
                } catch (\Exception $e) {
                    // Column doesn't exist, continue without it
                }
                
                $params = [];
                $where = [];
                
                if ($hasDeletedAt) {
                    $where[] = 'deleted_at IS NULL';
                }
                
                if ($status === 'active') {
                    $where[] = 'is_active = 1';
                } elseif ($status === 'inactive') {
                    $where[] = 'is_active = 0';
                }
                
                if ($severity !== null) {
                    $where[] = 'severity = :severity';
                    $params[':severity'] = $severity;
                }
                
                $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
                
                $sql = 'SELECT id, filter_id, word_pattern, replacement, severity, tags, exceptions, 
                               is_regex, is_active, created_at, updated_at
                        FROM word_filter 
                        ' . $whereClause . '
                        ORDER BY COALESCE(filter_id, word_pattern) ASC';
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $filters = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                
                // Decode JSON fields
                foreach ($filters as &$filter) {
                    if ($filter['tags']) {
                        $filter['tags'] = json_decode($filter['tags'], true);
                    }
                    if ($filter['exceptions']) {
                        $filter['exceptions'] = json_decode($filter['exceptions'], true);
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'filters' => $filters
                ]);
                break;
                
            case 'get':
                // Get single word filter
                $filterId = isset($_GET['id']) ? (int)$_GET['id'] : null;
                if (!$filterId) {
                    throw new \InvalidArgumentException('Filter ID required');
                }
                
                $db = Database::getConnection();
                
                // Check if deleted_at column exists
                $hasDeletedAt = false;
                try {
                    $columnCheck = $db->query("SHOW COLUMNS FROM word_filter LIKE 'deleted_at'");
                    $hasDeletedAt = $columnCheck->rowCount() > 0;
                } catch (\Exception $e) {
                    // Column doesn't exist
                }
                
                $whereClause = $hasDeletedAt ? 'WHERE id = :id AND deleted_at IS NULL' : 'WHERE id = :id';
                
                $stmt = $db->prepare('
                    SELECT id, filter_id, word_pattern, replacement, severity, tags, exceptions, 
                           is_regex, is_active, created_at, updated_at
                    FROM word_filter 
                    ' . $whereClause . '
                ');
                $stmt->execute([':id' => $filterId]);
                $filter = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                if ($filter) {
                    if ($filter['tags']) {
                        $filter['tags'] = json_decode($filter['tags'], true);
                    }
                    if ($filter['exceptions']) {
                        $filter['exceptions'] = json_decode($filter['exceptions'], true);
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'filter' => $filter
                ]);
                break;
                
            case 'add':
                // Add new word filter
                if ($method !== 'POST') {
                    throw new \InvalidArgumentException('POST method required');
                }
                
                $input = json_decode(file_get_contents('php://input'), true);
                if (!is_array($input)) {
                    throw new \InvalidArgumentException('Invalid JSON input');
                }
                
                $wordPattern = $security->sanitizeInput($input['word_pattern'] ?? '');
                $replacement = $security->sanitizeInput($input['replacement'] ?? '*');
                $severity = isset($input['severity']) ? (int)$input['severity'] : 2;
                $tags = $input['tags'] ?? null;
                $exceptions = $input['exceptions'] ?? null;
                $isRegex = isset($input['is_regex']) ? (bool)$input['is_regex'] : false;
                $filterId = $security->sanitizeInput($input['filter_id'] ?? null);
                
                if (empty($wordPattern)) {
                    throw new \InvalidArgumentException('Word pattern is required');
                }
                
                if ($severity < 1 || $severity > 4) {
                    throw new \InvalidArgumentException('Severity must be between 1 and 4');
                }
                
                $db = Database::getConnection();
                $stmt = $db->prepare('
                    INSERT INTO word_filter 
                    (filter_id, word_pattern, replacement, severity, tags, exceptions, is_regex, is_active)
                    VALUES (:filter_id, :word_pattern, :replacement, :severity, :tags, :exceptions, :is_regex, 1)
                ');
                
                $tagsJson = $tags ? json_encode($tags) : null;
                $exceptionsJson = $exceptions ? json_encode($exceptions) : null;
                
                $stmt->execute([
                    ':filter_id' => $filterId,
                    ':word_pattern' => $wordPattern,
                    ':replacement' => $replacement,
                    ':severity' => $severity,
                    ':tags' => $tagsJson,
                    ':exceptions' => $exceptionsJson,
                    ':is_regex' => $isRegex ? 1 : 0
                ]);
                
                // Clear word filter cache
                $wordFilterService = new WordFilterService();
                $wordFilterService->clearCache();
                
                echo json_encode([
                    'success' => true,
                    'filter_id' => $db->lastInsertId(),
                    'message' => 'Word filter added successfully'
                ]);
                break;
                
            case 'update':
                // Update word filter
                if ($method !== 'POST') {
                    throw new \InvalidArgumentException('POST method required');
                }
                
                $input = json_decode(file_get_contents('php://input'), true);
                if (!is_array($input)) {
                    throw new \InvalidArgumentException('Invalid JSON input');
                }
                
                $filterId = isset($input['id']) ? (int)$input['id'] : null;
                if (!$filterId) {
                    throw new \InvalidArgumentException('Filter ID required');
                }
                
                $wordPattern = $security->sanitizeInput($input['word_pattern'] ?? '');
                $replacement = $security->sanitizeInput($input['replacement'] ?? '*');
                $severity = isset($input['severity']) ? (int)$input['severity'] : 2;
                $tags = $input['tags'] ?? null;
                $exceptions = $input['exceptions'] ?? null;
                $isRegex = isset($input['is_regex']) ? (bool)$input['is_regex'] : false;
                $isActive = isset($input['is_active']) ? (bool)$input['is_active'] : true;
                
                if (empty($wordPattern)) {
                    throw new \InvalidArgumentException('Word pattern is required');
                }
                
                if ($severity < 1 || $severity > 4) {
                    throw new \InvalidArgumentException('Severity must be between 1 and 4');
                }
                
                $db = Database::getConnection();
                
                // Check if deleted_at column exists
                $hasDeletedAt = false;
                try {
                    $columnCheck = $db->query("SHOW COLUMNS FROM word_filter LIKE 'deleted_at'");
                    $hasDeletedAt = $columnCheck->rowCount() > 0;
                } catch (\Exception $e) {
                    // Column doesn't exist
                }
                
                $whereClause = $hasDeletedAt ? 'WHERE id = :id AND deleted_at IS NULL' : 'WHERE id = :id';
                
                $stmt = $db->prepare('
                    UPDATE word_filter 
                    SET word_pattern = :word_pattern, replacement = :replacement, severity = :severity,
                        tags = :tags, exceptions = :exceptions, is_regex = :is_regex, is_active = :is_active
                    ' . $whereClause . '
                ');
                
                $tagsJson = $tags ? json_encode($tags) : null;
                $exceptionsJson = $exceptions ? json_encode($exceptions) : null;
                
                $stmt->execute([
                    ':id' => $filterId,
                    ':word_pattern' => $wordPattern,
                    ':replacement' => $replacement,
                    ':severity' => $severity,
                    ':tags' => $tagsJson,
                    ':exceptions' => $exceptionsJson,
                    ':is_regex' => $isRegex ? 1 : 0,
                    ':is_active' => $isActive ? 1 : 0
                ]);
                
                // Clear word filter cache
                $wordFilterService = new WordFilterService();
                $wordFilterService->clearCache();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Word filter updated successfully'
                ]);
                break;
                
            case 'delete':
                // Soft delete word filter
                if ($method !== 'POST') {
                    throw new \InvalidArgumentException('POST method required');
                }
                
                $input = json_decode(file_get_contents('php://input'), true);
                if (!is_array($input)) {
                    throw new \InvalidArgumentException('Invalid JSON input');
                }
                
                $filterId = isset($input['id']) ? (int)$input['id'] : null;
                if (!$filterId) {
                    throw new \InvalidArgumentException('Filter ID required');
                }
                
                $db = Database::getConnection();
                
                // Check if deleted_at column exists
                $hasDeletedAt = false;
                try {
                    $columnCheck = $db->query("SHOW COLUMNS FROM word_filter LIKE 'deleted_at'");
                    $hasDeletedAt = $columnCheck->rowCount() > 0;
                } catch (\Exception $e) {
                    // Column doesn't exist - use is_active instead
                }
                
                if ($hasDeletedAt) {
                    $stmt = $db->prepare('
                        UPDATE word_filter 
                        SET deleted_at = NOW() 
                        WHERE id = :id
                    ');
                } else {
                    // Fallback: set is_active to false if deleted_at doesn't exist
                    $stmt = $db->prepare('
                        UPDATE word_filter 
                        SET is_active = 0 
                        WHERE id = :id
                    ');
                }
                $stmt->execute([':id' => $filterId]);
                
                // Clear word filter cache
                $wordFilterService = new WordFilterService();
                $wordFilterService->clearCache();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Word filter deleted successfully'
                ]);
                break;
        }
    }
    // Word filter requests (moderators can create, admins can list/approve/deny)
    elseif ($action === 'request-create') {
        // Create word filter request (moderator only)
        if ($currentUser === null || $currentUser['role'] !== 'moderator') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden - Moderator access required']);
            exit;
        }
        
        if ($method !== 'POST') {
            throw new \InvalidArgumentException('POST method required');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            throw new \InvalidArgumentException('Invalid JSON input');
        }
        
        $requestType = $security->sanitizeInput($input['request_type'] ?? '');
        $justification = $security->sanitizeInput($input['justification'] ?? '', 5000);
        $filterId = $security->sanitizeInput($input['filter_id'] ?? null);
        $wordPattern = $security->sanitizeInput($input['word_pattern'] ?? null);
        $replacement = $security->sanitizeInput($input['replacement'] ?? null);
        $severity = isset($input['severity']) ? (int)$input['severity'] : null;
        $tags = $input['tags'] ?? null;
        $exceptions = $input['exceptions'] ?? null;
        $isRegex = isset($input['is_regex']) ? (bool)$input['is_regex'] : null;
        
        if (!in_array($requestType, ['add', 'edit', 'remove'])) {
            throw new \InvalidArgumentException('Invalid request type');
        }
        
        if (empty($justification)) {
            throw new \InvalidArgumentException('Justification is required');
        }
        
        if ($requestType === 'add' && empty($wordPattern)) {
            throw new \InvalidArgumentException('Word pattern is required for add requests');
        }
        
        if (in_array($requestType, ['add', 'edit']) && empty($wordPattern)) {
            throw new \InvalidArgumentException('Word pattern is required');
        }
        
        if (in_array($requestType, ['edit', 'remove']) && empty($filterId)) {
            throw new \InvalidArgumentException('Filter ID is required for edit/remove requests');
        }
        
        $requestId = $wordFilterRequestRepo->createRequest(
            $requestType,
            $currentUser['username'],
            $currentUser['id'] ?? null,
            $justification,
            $filterId,
            $wordPattern,
            $replacement,
            $severity,
            $tags,
            $exceptions,
            $isRegex
        );
        
        echo json_encode([
            'success' => true,
            'request_id' => $requestId,
            'message' => 'Word filter request submitted successfully'
        ]);
    }
    elseif ($action === 'request-list') {
        // List word filter requests (admin only)
        if ($currentUser === null || $currentUser['role'] !== 'administrator') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden - Admin access required']);
            exit;
        }
        
        $status = $security->sanitizeInput($_GET['status'] ?? '');
        $requests = $wordFilterRequestRepo->getAllRequests($status ?: null);
        
        echo json_encode([
            'success' => true,
            'requests' => $requests
        ]);
    }
    elseif ($action === 'request-approve') {
        // Approve word filter request (admin only)
        if ($currentUser === null || $currentUser['role'] !== 'administrator') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden - Admin access required']);
            exit;
        }
        
        if ($method !== 'POST') {
            throw new \InvalidArgumentException('POST method required');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            throw new \InvalidArgumentException('Invalid JSON input');
        }
        
        $requestId = isset($input['request_id']) ? (int)$input['request_id'] : null;
        if (!$requestId) {
            throw new \InvalidArgumentException('Request ID required');
        }
        
        $request = $wordFilterRequestRepo->getRequestById($requestId);
        if (!$request) {
            throw new \InvalidArgumentException('Request not found');
        }
        
        if ($request['status'] !== 'pending') {
            throw new \InvalidArgumentException('Request is not pending');
        }
        
        $reviewNotes = $security->sanitizeInput($input['review_notes'] ?? '', 5000);
        
        // Update request status
        $wordFilterRequestRepo->updateRequestStatus(
            $requestId,
            'approved',
            $currentUser['username'],
            $reviewNotes
        );
        
        // Apply the requested change
        $db = Database::getConnection();
        $wordFilterService = new WordFilterService();
        
        if ($request['request_type'] === 'add') {
            // Add the word filter
            $stmt = $db->prepare('
                INSERT INTO word_filter 
                (filter_id, word_pattern, replacement, severity, tags, exceptions, is_regex, is_active)
                VALUES (:filter_id, :word_pattern, :replacement, :severity, :tags, :exceptions, :is_regex, 1)
            ');
            
            $stmt->execute([
                ':filter_id' => $request['filter_id'],
                ':word_pattern' => $request['word_pattern'],
                ':replacement' => $request['replacement'] ?? '*',
                ':severity' => $request['severity'] ?? 2,
                ':tags' => $request['tags'] ? json_encode($request['tags']) : null,
                ':exceptions' => $request['exceptions'] ? json_encode($request['exceptions']) : null,
                ':is_regex' => $request['is_regex'] ? 1 : 0
            ]);
            
        } elseif ($request['request_type'] === 'edit') {
            // Check if deleted_at column exists
            $hasDeletedAt = false;
            try {
                $columnCheck = $db->query("SHOW COLUMNS FROM word_filter LIKE 'deleted_at'");
                $hasDeletedAt = $columnCheck->rowCount() > 0;
            } catch (\Exception $e) {
                // Column doesn't exist
            }
            
            $whereClause = $hasDeletedAt ? 'WHERE filter_id = :filter_id AND deleted_at IS NULL' : 'WHERE filter_id = :filter_id';
            
            // Update the word filter
            $stmt = $db->prepare('
                UPDATE word_filter 
                SET word_pattern = :word_pattern, replacement = :replacement, severity = :severity,
                    tags = :tags, exceptions = :exceptions, is_regex = :is_regex
                ' . $whereClause . '
            ');
            
            $stmt->execute([
                ':filter_id' => $request['filter_id'],
                ':word_pattern' => $request['word_pattern'],
                ':replacement' => $request['replacement'] ?? '*',
                ':severity' => $request['severity'] ?? 2,
                ':tags' => $request['tags'] ? json_encode($request['tags']) : null,
                ':exceptions' => $request['exceptions'] ? json_encode($request['exceptions']) : null,
                ':is_regex' => $request['is_regex'] ? 1 : 0
            ]);
            
        } elseif ($request['request_type'] === 'remove') {
            // Check if deleted_at column exists
            $hasDeletedAt = false;
            try {
                $columnCheck = $db->query("SHOW COLUMNS FROM word_filter LIKE 'deleted_at'");
                $hasDeletedAt = $columnCheck->rowCount() > 0;
            } catch (\Exception $e) {
                // Column doesn't exist
            }
            
            if ($hasDeletedAt) {
                // Soft delete the word filter
                $stmt = $db->prepare('
                    UPDATE word_filter 
                    SET deleted_at = NOW() 
                    WHERE filter_id = :filter_id AND deleted_at IS NULL
                ');
            } else {
                // Fallback: set is_active to false
                $stmt = $db->prepare('
                    UPDATE word_filter 
                    SET is_active = 0 
                    WHERE filter_id = :filter_id
                ');
            }
            
            $stmt->execute([':filter_id' => $request['filter_id']]);
        }
        
        // Clear word filter cache
        $wordFilterService->clearCache();
        
        echo json_encode([
            'success' => true,
            'message' => 'Word filter request approved and applied'
        ]);
    }
    elseif ($action === 'request-deny') {
        // Deny word filter request (admin only)
        if ($currentUser === null || $currentUser['role'] !== 'administrator') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden - Admin access required']);
            exit;
        }
        
        if ($method !== 'POST') {
            throw new \InvalidArgumentException('POST method required');
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            throw new \InvalidArgumentException('Invalid JSON input');
        }
        
        $requestId = isset($input['request_id']) ? (int)$input['request_id'] : null;
        if (!$requestId) {
            throw new \InvalidArgumentException('Request ID required');
        }
        
        $reviewNotes = $security->sanitizeInput($input['review_notes'] ?? '', 5000);
        
        $wordFilterRequestRepo->updateRequestStatus(
            $requestId,
            'denied',
            $currentUser['username'],
            $reviewNotes
        );
        
        echo json_encode([
            'success' => true,
            'message' => 'Word filter request denied'
        ]);
    }
    else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
} catch (\Exception $e) {
    error_log('Word filter API error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'error' => 'Request failed',
        'message' => $e->getMessage()
    ]);
}

