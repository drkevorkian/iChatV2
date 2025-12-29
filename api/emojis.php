<?php
/**
 * Sentinel Chat Platform - Emoji API
 * 
 * Provides API endpoints to fetch emojis for the emoji picker.
 * 
 * Security: Public endpoint (emojis are not sensitive data).
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\SecurityService;
use iChat\Database;
use iChat\Services\DatabaseHealth;
use iChat\Services\AuthService;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

// Get action from query parameter
$action = $_GET['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($action) {
        case 'list':
            // List emojis with optional filtering
            $category = $_GET['category'] ?? '';
            $search = $_GET['search'] ?? '';
            $limit = (int)($_GET['limit'] ?? 200);
            $offset = (int)($_GET['offset'] ?? 0);
            
            if (!DatabaseHealth::isAvailable()) {
                throw new \RuntimeException('Database is not available');
            }
            
            $conn = Database::getConnection();
            $params = [];
            $where = ['is_active = TRUE'];
            
            if (!empty($category)) {
                $where[] = 'category = :category';
                $params[':category'] = $category;
            }
            
            if (!empty($search)) {
                $where[] = '(short_name LIKE :search OR keywords LIKE :search_keywords)';
                $params[':search'] = '%' . $search . '%';
                $params[':search_keywords'] = '%' . $search . '%';
            }
            
            $whereClause = implode(' AND ', $where);
            
            $sql = "SELECT id, code_points, emoji, short_name, category, subcategory, keywords, version, usage_count
                    FROM emoji_library
                    WHERE {$whereClause}
                    ORDER BY usage_count DESC, short_name ASC
                    LIMIT :limit OFFSET :offset";
            
            $stmt = $conn->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            
            $emojis = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Get categories for filter
            $categoriesSql = 'SELECT DISTINCT category FROM emoji_library WHERE is_active = TRUE AND category IS NOT NULL ORDER BY category';
            $categoriesStmt = $conn->query($categoriesSql);
            $categories = $categoriesStmt->fetchAll(\PDO::FETCH_COLUMN);
            
            echo json_encode([
                'success' => true,
                'emojis' => $emojis,
                'categories' => $categories,
                'count' => count($emojis),
            ]);
            break;
            
        case 'categories':
            // Get list of all categories
            if (!DatabaseHealth::isAvailable()) {
                throw new \RuntimeException('Database is not available');
            }
            
            $conn = Database::getConnection();
            $sql = 'SELECT DISTINCT category, COUNT(*) as count 
                    FROM emoji_library 
                    WHERE is_active = TRUE AND category IS NOT NULL 
                    GROUP BY category 
                    ORDER BY category';
            $stmt = $conn->query($sql);
            $categories = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'categories' => $categories,
            ]);
            break;
            
        case 'favorites':
            // Get user's favorite emojis
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('Invalid method');
            }
            
            $authService = new AuthService();
            $user = $authService->getCurrentUser();
            
            if (!$user) {
                throw new \RuntimeException('Authentication required');
            }
            
            if (!DatabaseHealth::isAvailable()) {
                throw new \RuntimeException('Database is not available');
            }
            
            $conn = Database::getConnection();
            $sql = 'SELECT e.id, e.code_points, e.emoji, e.short_name, e.category, e.subcategory, ef.position
                    FROM emoji_favorites ef
                    INNER JOIN emoji_library e ON ef.emoji_id = e.id
                    WHERE ef.user_id = :user_id AND e.is_active = TRUE
                    ORDER BY ef.position ASC, ef.created_at DESC';
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([':user_id' => $user['id']]);
            $favorites = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'favorites' => $favorites,
            ]);
            break;
            
        case 'recent':
            // Get user's recently used emojis
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('Invalid method');
            }
            
            $authService = new AuthService();
            $user = $authService->getCurrentUser();
            
            if (!$user) {
                throw new \RuntimeException('Authentication required');
            }
            
            if (!DatabaseHealth::isAvailable()) {
                throw new \RuntimeException('Database is not available');
            }
            
            $limit = (int)($_GET['limit'] ?? 20);
            
            $conn = Database::getConnection();
            $sql = 'SELECT e.id, e.code_points, e.emoji, e.short_name, e.category, er.use_count, er.used_at
                    FROM emoji_recent er
                    INNER JOIN emoji_library e ON er.emoji_id = e.id
                    WHERE er.user_id = :user_id AND e.is_active = TRUE
                    ORDER BY er.used_at DESC
                    LIMIT :limit';
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':user_id', $user['id'], \PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $recent = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'recent' => $recent,
            ]);
            break;
            
        case 'use':
            // Record emoji usage
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('Invalid method');
            }
            
            $authService = new AuthService();
            $user = $authService->getCurrentUser();
            
            if (!$user) {
                throw new \RuntimeException('Authentication required');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $emojiId = (int)($input['emoji_id'] ?? 0);
            
            if ($emojiId <= 0) {
                throw new \InvalidArgumentException('Invalid emoji_id');
            }
            
            if (!DatabaseHealth::isAvailable()) {
                throw new \RuntimeException('Database is not available');
            }
            
            $conn = Database::getConnection();
            
            // Update usage count in emoji_library
            $updateSql = 'UPDATE emoji_library SET usage_count = usage_count + 1 WHERE id = :emoji_id';
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute([':emoji_id' => $emojiId]);
            
            // Update or insert into emoji_recent
            $recentSql = 'INSERT INTO emoji_recent (user_id, emoji_id, use_count, used_at)
                          VALUES (:user_id, :emoji_id, 1, NOW())
                          ON DUPLICATE KEY UPDATE 
                            use_count = use_count + 1,
                            used_at = NOW()';
            $recentStmt = $conn->prepare($recentSql);
            $recentStmt->execute([
                ':user_id' => $user['id'],
                ':emoji_id' => $emojiId,
            ]);
            
            echo json_encode([
                'success' => true,
            ]);
            break;
            
        default:
            throw new \InvalidArgumentException('Invalid action');
    }
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}

