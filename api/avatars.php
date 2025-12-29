<?php
/**
 * Sentinel Chat Platform - Avatar API Endpoint
 * 
 * Handles avatar uploads, gallery management, and avatar selection.
 * 
 * Security: All file uploads are validated and sanitized.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Repositories\UserGalleryRepository;
use iChat\Repositories\ProfileRepository;
use iChat\Services\SecurityService;
use iChat\Services\AuthService;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get current user (same pattern as other APIs)
$authService = new AuthService();
$currentUser = $authService->getCurrentUser();
$userHandle = $currentUser['username'] ?? $_SESSION['user_handle'] ?? '';

if (empty($userHandle)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized - Login required']);
    exit;
}

$userId = $currentUser['id'] ?? null;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

$galleryRepo = new UserGalleryRepository();
$profileRepo = new ProfileRepository();

try {
    switch ($action) {
        case 'upload':
            // Upload image to gallery
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('POST method required for upload');
            }
            
            if (empty($_FILES['image'])) {
                throw new \InvalidArgumentException('No image file uploaded');
            }
            
            $file = $_FILES['image'];
            $maxFileSize = 5 * 1024 * 1024; // 5MB
            $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('File upload error: ' . $file['error']);
            }
            
            if ($file['size'] > $maxFileSize) {
                throw new \RuntimeException('File size exceeds 5MB limit');
            }
            
            $mimeType = $file['type'] ?? mime_content_type($file['tmp_name']);
            if (!in_array($mimeType, $allowedMimeTypes, true)) {
                throw new \RuntimeException('Unsupported file type: ' . $mimeType);
            }
            
            // Validate it's actually an image
            $imageInfo = @getimagesize($file['tmp_name']);
            if ($imageInfo === false) {
                throw new \RuntimeException('Invalid image file');
            }
            
            $width = $imageInfo[0] ?? null;
            $height = $imageInfo[1] ?? null;
            
            // Read image data into memory (store as BLOB in database)
            $imageData = file_get_contents($file['tmp_name']);
            if ($imageData === false) {
                throw new \RuntimeException('Failed to read uploaded file');
            }
            
            // Create thumbnail in memory
            $thumbnailData = null;
            try {
                $thumbnailData = createThumbnailFromData($imageData, $mimeType, $width, $height);
            } catch (\Exception $e) {
                error_log('Failed to create thumbnail: ' . $e->getMessage());
            }
            
            // Store in database as BLOB
            $imageId = $galleryRepo->addImage(
                $userHandle,
                $userId,
                $security->sanitizeInput($file['name']),
                $imageData,
                $file['size'],
                $mimeType,
                $width,
                $height,
                $thumbnailData
            );
            
            if (!$imageId) {
                throw new \RuntimeException('Failed to save image to gallery');
            }
            
            echo json_encode([
                'success' => true,
                'image_id' => $imageId,
                'width' => $width,
                'height' => $height,
            ]);
            break;
            
        case 'gallery':
            // Get user's gallery
            if ($method !== 'GET') {
                throw new \InvalidArgumentException('GET method required for gallery');
            }
            
            $gallery = $galleryRepo->getUserGallery($userHandle);
            
            echo json_encode([
                'success' => true,
                'gallery' => $gallery,
            ]);
            break;
            
        case 'set-avatar':
            // Set avatar (gallery image, gravatar, or default)
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('POST method required for set-avatar');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $avatarType = $security->sanitizeInput($input['avatar_type'] ?? 'default');
            $avatarPath = isset($input['avatar_path']) ? $security->sanitizeInput($input['avatar_path']) : null;
            $gravatarEmail = isset($input['gravatar_email']) ? $security->sanitizeInput($input['gravatar_email']) : null;
            $imageId = isset($input['image_id']) ? (int)$input['image_id'] : null;
            
            if (!in_array($avatarType, ['default', 'gravatar', 'gallery'], true)) {
                throw new \InvalidArgumentException('Invalid avatar type');
            }
            
            // Validate gallery image ownership if using gallery
            $avatarData = null;
            if ($avatarType === 'gallery') {
                if ($imageId !== null) {
                    $image = $galleryRepo->getImageById($imageId, $userHandle);
                    if (!$image) {
                        throw new \InvalidArgumentException('Image not found or not owned by user');
                    }
                    $avatarData = (string)$imageId; // Store gallery image ID in avatar_data
                    $galleryRepo->setAsAvatar($imageId, $userHandle);
                    error_log("Setting gallery avatar for {$userHandle}, image_id: {$imageId}");
                } else {
                    // Gallery selected but no image ID provided - this is an error
                    throw new \InvalidArgumentException('Gallery avatar type requires an image_id');
                }
            }
            
            // Update profile
            $updateData = [
                'avatar_type' => $avatarType,
                'avatar_path' => null, // Clear old file path
            ];
            
            // Handle avatar_data for gallery
            if ($avatarType === 'gallery') {
                if ($avatarData !== null) {
                    $updateData['avatar_data'] = $avatarData;
                } else {
                    // Gallery selected but no image ID - keep existing avatar_data
                    // Don't include it in updateData
                }
            } else {
                // Clear avatar_data when switching away from gallery
                $updateData['avatar_data'] = null;
            }
            
            // Handle gravatar_email
            if ($avatarType === 'gravatar') {
                if (!empty($gravatarEmail)) {
                    // Validate email format
                    if (!filter_var($gravatarEmail, FILTER_VALIDATE_EMAIL)) {
                        throw new \InvalidArgumentException('Invalid email address format');
                    }
                    $updateData['gravatar_email'] = $gravatarEmail;
                    error_log("Setting Gravatar email for {$userHandle}: " . substr($gravatarEmail, 0, 3) . '...');
                } else {
                    // If gravatar is selected but no email provided, clear existing email
                    // (user wants to use Gravatar but hasn't set email yet)
                    $updateData['gravatar_email'] = null;
                }
            } else {
                // Clear gravatar_email when switching to non-gravatar avatar
                $updateData['gravatar_email'] = null;
            }
            
            error_log("Updating avatar for {$userHandle}: " . json_encode($updateData));
            
            if ($profileRepo->updateProfile($userHandle, $updateData)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Avatar updated successfully',
                ]);
            } else {
                throw new \RuntimeException('Failed to update avatar');
            }
            break;
            
        case 'delete':
            // Delete gallery image
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('POST method required for delete');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $imageId = isset($input['image_id']) ? (int)$input['image_id'] : 0;
            
            if ($imageId <= 0) {
                throw new \InvalidArgumentException('Invalid image ID');
            }
            
            if ($galleryRepo->deleteImage($imageId, $userHandle)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Image deleted successfully',
                ]);
            } else {
                throw new \RuntimeException('Failed to delete image');
            }
            break;
            
        case 'update':
            // Update gallery image metadata (filename, is_public, is_avatar)
            if ($method !== 'POST') {
                throw new \InvalidArgumentException('POST method required for update');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $imageId = isset($input['image_id']) ? (int)$input['image_id'] : 0;
            
            if ($imageId <= 0) {
                throw new \InvalidArgumentException('Invalid image ID');
            }
            
            $updates = [];
            
            if (isset($input['filename'])) {
                $filename = $security->sanitizeInput($input['filename']);
                if (empty($filename)) {
                    throw new \InvalidArgumentException('Filename cannot be empty');
                }
                $updates['filename'] = $filename;
            }
            
            if (isset($input['is_public'])) {
                $updates['is_public'] = (bool)$input['is_public'];
            }
            
            if (isset($input['is_avatar'])) {
                $updates['is_avatar'] = (bool)$input['is_avatar'];
            }
            
            if (empty($updates)) {
                throw new \InvalidArgumentException('No fields to update');
            }
            
            $success = $galleryRepo->updateImage($imageId, $userHandle, $updates);
            
            if ($success) {
                // If setting as avatar, update profile
                if (isset($updates['is_avatar']) && $updates['is_avatar']) {
                    $profileRepo->updateProfile($userHandle, [
                        'avatar_type' => 'gallery',
                        'avatar_data' => (string)$imageId,
                    ]);
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Image updated successfully',
                ]);
            } else {
                throw new \RuntimeException('Failed to update image');
            }
            break;
            
        default:
            throw new \InvalidArgumentException('Invalid action');
    }
} catch (\Exception $e) {
    error_log('Avatars API error: ' . $e->getMessage());
    error_log('Avatars API stack trace: ' . $e->getTraceAsString());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(), // Include trace for debugging
    ]);
}

/**
 * Create thumbnail from image data in memory
 */
function createThumbnailFromData(string $imageData, string $mimeType, ?int $width, ?int $height): ?string
{
    if ($width === null || $height === null) {
        return null;
    }
    
    $maxThumbSize = 200;
    $thumbWidth = $width;
    $thumbHeight = $height;
    
    // Calculate thumbnail dimensions
    if ($width > $height) {
        $thumbWidth = $maxThumbSize;
        $thumbHeight = (int)($height * ($maxThumbSize / $width));
    } else {
        $thumbHeight = $maxThumbSize;
        $thumbWidth = (int)($width * ($maxThumbSize / $height));
    }
    
    // Create image from data
    $source = null;
    switch ($mimeType) {
        case 'image/jpeg':
            $source = imagecreatefromstring($imageData);
            break;
        case 'image/png':
            $source = imagecreatefromstring($imageData);
            break;
        case 'image/gif':
            $source = imagecreatefromstring($imageData);
            break;
        case 'image/webp':
            $source = imagecreatefromstring($imageData);
            break;
        default:
            return null;
    }
    
    if ($source === null) {
        return null;
    }
    
    // Create thumbnail
    $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
    
    if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
    }
    
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);
    
    // Output thumbnail to string
    ob_start();
    switch ($mimeType) {
        case 'image/jpeg':
            imagejpeg($thumb, null, 85);
            break;
        case 'image/png':
            imagepng($thumb);
            break;
        case 'image/gif':
            imagegif($thumb);
            break;
        case 'image/webp':
            imagewebp($thumb, null, 85);
            break;
    }
    $thumbnailData = ob_get_contents();
    ob_end_clean();
    
    imagedestroy($thumb);
    imagedestroy($source);
    
    return $thumbnailData ?: null;
}

/**
 * Create thumbnail for uploaded image (legacy - for file-based storage)
 */
function createThumbnail(string $sourcePath, string $destDir, string $filename, ?int $width, ?int $height): ?string
{
    if ($width === null || $height === null) {
        return null;
    }
    
    $maxThumbSize = 200;
    $thumbWidth = $width;
    $thumbHeight = $height;
    
    // Calculate thumbnail dimensions
    if ($width > $height) {
        $thumbWidth = $maxThumbSize;
        $thumbHeight = (int)($height * ($maxThumbSize / $width));
    } else {
        $thumbHeight = $maxThumbSize;
        $thumbWidth = (int)($width * ($maxThumbSize / $height));
    }
    
    // Create thumbnail
    $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
    $source = null;
    
    $mimeType = mime_content_type($sourcePath);
    switch ($mimeType) {
        case 'image/jpeg':
            $source = imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $source = imagecreatefrompng($sourcePath);
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            break;
        case 'image/gif':
            $source = imagecreatefromgif($sourcePath);
            break;
        case 'image/webp':
            $source = imagecreatefromwebp($sourcePath);
            break;
        default:
            return null;
    }
    
    if ($source === null) {
        return null;
    }
    
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);
    
    $thumbFilename = 'thumb_' . $filename;
    $thumbPath = $destDir . '/' . $thumbFilename;
    
    switch ($mimeType) {
        case 'image/jpeg':
            imagejpeg($thumb, $thumbPath, 85);
            break;
        case 'image/png':
            imagepng($thumb, $thumbPath);
            break;
        case 'image/gif':
            imagegif($thumb, $thumbPath);
            break;
        case 'image/webp':
            imagewebp($thumb, $thumbPath, 85);
            break;
    }
    
    imagedestroy($thumb);
    imagedestroy($source);
    
    return $thumbFilename;
}

