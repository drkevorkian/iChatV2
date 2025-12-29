<?php
/**
 * Sentinel Chat Platform - Profile API
 * 
 * Handles profile viewing and editing.
 * Two-way view: owners see edit mode, others see public view.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Repositories\ProfileRepository;
use iChat\Services\SecurityService;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? 'view';
$profileRepo = new ProfileRepository();

// Get current user handle
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$currentUser = $_SESSION['user_handle'] ?? null;
$userRole = $_SESSION['user_role'] ?? 'guest';

try {
    switch ($action) {
        case 'view':
            // View a profile
            $userHandle = $_GET['user'] ?? $currentUser ?? '';
            
            if (empty($userHandle)) {
                throw new \InvalidArgumentException('Missing user parameter');
            }
            
            $profile = $profileRepo->getProfile($userHandle, $currentUser);
            
            if ($profile === null) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Profile not found or not accessible',
                ]);
                exit;
            }
            
            $isOwner = ($currentUser === $userHandle);
            $isGuest = $profile['is_guest'] ?? false;
            $isBot = in_array($userHandle, ['Pinky', 'Brain'], true);
            $viewCount = ($isGuest || $isBot) ? 0 : $profileRepo->getProfileViewCount($userHandle); // Guests and bots don't track views
            
            // Get public gallery images for profile view
            $publicGallery = [];
            if (!$isGuest && !$isBot) {
                try {
                    $galleryRepo = new \iChat\Repositories\UserGalleryRepository();
                    $db = \iChat\Database::getConnection();
                    
                    // Check if is_public column exists
                    $hasIsPublic = false;
                    try {
                        $columnCheck = $db->query("SHOW COLUMNS FROM user_gallery LIKE 'is_public'");
                        $hasIsPublic = $columnCheck->rowCount() > 0;
                    } catch (\Exception $e) {
                        // Column doesn't exist
                    }
                    
                    if ($hasIsPublic) {
                        // Query only public images directly
                        $sql = 'SELECT id, filename, file_size, mime_type, width, height, is_avatar, uploaded_at, md5_checksum,
                                       CASE WHEN image_data IS NOT NULL THEN 1 ELSE 0 END as has_image_data,
                                       CASE WHEN thumbnail_data IS NOT NULL THEN 1 ELSE 0 END as has_thumbnail_data
                                FROM user_gallery
                                WHERE user_handle = :user_handle 
                                  AND is_public = 1 
                                  AND deleted_at IS NULL
                                ORDER BY uploaded_at DESC';
                        
                        $stmt = $db->prepare($sql);
                        $stmt->execute([':user_handle' => $userHandle]);
                        $publicGallery = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                    } else {
                        // Fallback: get all gallery and filter (if column doesn't exist, show none)
                        $publicGallery = [];
                    }
                } catch (\Exception $e) {
                    error_log('Failed to load public gallery: ' . $e->getMessage());
                }
            }
            
            echo json_encode([
                'success' => true,
                'profile' => $profile,
                'is_owner' => $isOwner,
                'view_count' => $viewCount,
                'is_registered' => !$isGuest && !$isBot,
                'is_guest' => $isGuest,
                'is_bot' => $isBot,
                'public_gallery' => $publicGallery,
            ]);
            break;
            
        case 'update':
        case 'edit':
            // Update profile (owner only)
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required']);
                exit;
            }
            
            if (empty($currentUser)) {
                http_response_code(401);
                echo json_encode(['error' => 'Not authenticated']);
                exit;
            }
            
            $userHandle = $_GET['user'] ?? $currentUser;
            
            // Prevent editing bot profiles (Pinky and Brain)
            if (in_array($userHandle, ['Pinky', 'Brain'], true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Bot profiles cannot be edited']);
                exit;
            }
            
            // Only owner can edit
            if ($userHandle !== $currentUser) {
                http_response_code(403);
                echo json_encode(['error' => 'Not authorized to edit this profile']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!is_array($input)) {
                throw new \InvalidArgumentException('Invalid JSON input');
            }
            
            // Sanitize input
            $data = [];
            $allowedFields = [
                'display_name', 'bio', 'avatar_url', 'avatar_data', 'banner_url',
                'location', 'website', 'status', 'status_message', 'profile_visibility'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $data[$field] = $security->sanitizeInput($input[$field]);
                }
            }
            
            // Handle preferences JSON
            if (isset($input['preferences']) && is_array($input['preferences'])) {
                $data['preferences'] = json_encode($input['preferences']);
            }
            
            $success = $profileRepo->updateProfile($userHandle, $data);
            
            if ($success) {
                // Return updated profile
                $profile = $profileRepo->getProfile($userHandle, $currentUser);
                echo json_encode([
                    'success' => true,
                    'profile' => $profile,
                    'message' => 'Profile updated successfully',
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to update profile',
                ]);
            }
            break;
            
        case 'register':
            // Register a user (create registration record)
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'POST method required']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $userHandle = $security->sanitizeInput($input['user_handle'] ?? '');
            $email = $security->sanitizeInput($input['email'] ?? null);
            
            if (empty($userHandle)) {
                throw new \InvalidArgumentException('Missing user_handle');
            }
            
            $success = $profileRepo->registerUser($userHandle, $email);
            
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'User registered successfully' : 'Registration failed',
            ]);
            break;
            
        case 'check':
            // Check if user is registered
            $userHandle = $_GET['user'] ?? $currentUser ?? '';
            
            if (empty($userHandle)) {
                throw new \InvalidArgumentException('Missing user parameter');
            }
            
            $isRegistered = $profileRepo->isRegistered($userHandle);
            
            echo json_encode([
                'success' => true,
                'is_registered' => $isRegistered,
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} catch (\Exception $e) {
    error_log('Profile API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Request failed',
        'message' => $e->getMessage(),
    ]);
}

