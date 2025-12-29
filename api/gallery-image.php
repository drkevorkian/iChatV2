<?php
/**
 * Sentinel Chat Platform - Gallery Image Serving Endpoint
 * 
 * Serves gallery images from database.
 * 
 * Security: Validates user ownership before serving images.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Repositories\UserGalleryRepository;
use iChat\Services\SecurityService;
use iChat\Services\AuthService;

$security = new SecurityService();
// Set minimal security headers (don't interfere with image serving)
header('X-Content-Type-Options: nosniff');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$imageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$thumbnail = isset($_GET['thumb']) && $_GET['thumb'] === '1';

if ($imageId <= 0) {
    http_response_code(400);
    exit;
}

// Get current user (may be empty for public images)
$authService = new AuthService();
$currentUser = $authService->getCurrentUser();
$currentUserHandle = $currentUser['username'] ?? $_SESSION['user_handle'] ?? '';

$galleryRepo = new UserGalleryRepository();

// Get image metadata first to check if it's public
$userHandle = $_GET['user'] ?? '';
if (empty($userHandle)) {
    // If no user specified, require authentication
    if (empty($currentUserHandle)) {
        http_response_code(401);
        exit;
    }
    $userHandle = $currentUserHandle;
}

// Get image metadata to check ownership/public status
$imageMetadata = null;
$ownerHandle = null;

// First, try to get image with the provided userHandle (if authenticated)
if (!empty($userHandle)) {
    $imageMetadata = $galleryRepo->getImageById($imageId, $userHandle);
    if ($imageMetadata && !empty($imageMetadata['user_handle'])) {
        $ownerHandle = $imageMetadata['user_handle'];
    }
}

// If not found, check database for image owner and public status
if (!$imageMetadata || empty($ownerHandle)) {
    $db = \iChat\Database::getConnection();
    $ownerCheck = $db->prepare('SELECT user_handle, is_public FROM user_gallery WHERE id = :id AND deleted_at IS NULL');
    $ownerCheck->execute([':id' => $imageId]);
    $ownerData = $ownerCheck->fetch(\PDO::FETCH_ASSOC);
    
    if (!$ownerData || empty($ownerData['user_handle'])) {
        http_response_code(404);
        exit;
    }
    
    $ownerHandle = $ownerData['user_handle'];
    
    // Check if current user owns it or if it's public
    if (!empty($currentUserHandle) && $currentUserHandle === $ownerHandle) {
        // Current user owns it - get metadata
        $imageMetadata = $galleryRepo->getImageById($imageId, $ownerHandle);
    } elseif (!empty($ownerData['is_public']) && $ownerData['is_public'] == 1) {
        // Public image - allow access, get metadata
        $imageMetadata = $galleryRepo->getImageById($imageId, $ownerHandle);
    } else {
        // Not public and not owned by current user
        http_response_code(403);
        exit;
    }
}

// Ensure we have a valid owner handle
if (empty($ownerHandle)) {
    error_log("Gallery image missing owner handle for ID: {$imageId}");
    http_response_code(404);
    exit;
}

// Get image data (validates ownership or public status)
$imageData = $galleryRepo->getImageData($imageId, $ownerHandle, $thumbnail);

if (!$imageData) {
    http_response_code(404);
    exit;
}

// Check if we have BLOB data or file path
if (!empty($imageData['image_data'])) {
    // Serve from BLOB
    header('Content-Type: ' . ($imageData['mime_type'] ?? 'image/png'));
    header('Content-Length: ' . strlen($imageData['image_data']));
    header('Cache-Control: no-cache, no-store, must-revalidate'); // Disable caching for debugging
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Disposition: inline; filename="' . htmlspecialchars($imageData['filename'], ENT_QUOTES, 'UTF-8') . '"');
    echo $imageData['image_data'];
} elseif (!empty($imageData['file_path'])) {
    // Serve from file path (fallback when BLOB is empty)
    $filePath = ICHAT_ROOT . '/' . ltrim($imageData['file_path'], '/');
    if (!file_exists($filePath)) {
        error_log("Gallery image file not found: {$filePath}");
        http_response_code(404);
        echo json_encode(['error' => 'File not found', 'path' => $filePath]);
        exit;
    }
    
    $mimeType = $imageData['mime_type'] ?? mime_content_type($filePath) ?? 'image/png';
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache, no-store, must-revalidate'); // Disable caching for debugging
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Disposition: inline; filename="' . htmlspecialchars($imageData['filename'], ENT_QUOTES, 'UTF-8') . '"');
    readfile($filePath);
    exit;
} else {
    // Try to get metadata and serve from file_path (should already have metadata from above)
    if (!$imageMetadata || empty($imageMetadata['file_path'])) {
        error_log("Gallery image metadata not found for ID: {$imageId}");
        http_response_code(404);
        echo json_encode(['error' => 'Image not found']);
        exit;
    }
    
    $filePath = ICHAT_ROOT . '/' . ltrim($imageMetadata['file_path'], '/');
    if (!file_exists($filePath)) {
        error_log("Gallery image file not found: {$filePath}");
        http_response_code(404);
        echo json_encode(['error' => 'File not found', 'path' => $filePath]);
        exit;
    }
    
    $mimeType = $imageMetadata['mime_type'] ?? mime_content_type($filePath) ?? 'image/png';
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache, no-store, must-revalidate'); // Disable caching for debugging
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Disposition: inline; filename="' . htmlspecialchars($imageMetadata['filename'], ENT_QUOTES, 'UTF-8') . '"');
    readfile($filePath);
    exit;
}

