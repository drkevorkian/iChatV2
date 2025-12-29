<?php
/**
 * Sentinel Chat Platform - Avatar Image Serving Endpoint
 * 
 * Serves avatar images from database cache or generates from Gravatar.
 * 
 * Security: Validates user access and serves images securely.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use iChat\Repositories\AvatarCacheRepository;
use iChat\Repositories\ProfileRepository;
use iChat\Services\SecurityService;

// Error handling - catch any errors and serve default avatar
try {
    $security = new SecurityService();
    $security->setSecurityHeaders();

    // Start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
} catch (\Throwable $e) {
    // Log error but don't expose it to client
    error_log("Avatar image endpoint error: " . $e->getMessage());
    // Fall through to generate default avatar
}

$userHandle = $_GET['user'] ?? '';
$size = isset($_GET['size']) ? (int)$_GET['size'] : 128;
$size = max(16, min(512, $size)); // Limit size between 16 and 512

if (empty($userHandle)) {
    http_response_code(400);
    header('Content-Type: image/png');
    // Serve default avatar
    $defaultAvatarPath = ICHAT_ROOT . '/images/default-avatar.png';
    if (file_exists($defaultAvatarPath)) {
        readfile($defaultAvatarPath);
    }
    exit;
}

$avatarCacheRepo = new AvatarCacheRepository();
$profileRepo = new ProfileRepository();

// Get profile FIRST to determine current avatar settings
try {
    $profile = $profileRepo->getProfile($userHandle);
    if (!$profile) {
        error_log("No profile found for {$userHandle}");
    } else {
        error_log("Profile found for {$userHandle}, avatar_type: " . ($profile['avatar_type'] ?? 'not set') . ", avatar_data: " . ($profile['avatar_data'] ?? 'null'));
    }
} catch (\Throwable $e) {
    error_log("Error getting profile for {$userHandle}: " . $e->getMessage());
    $profile = null;
}

// Only check cache if profile exists and cache matches current avatar settings
$hasCachedAvatar = false;
$cachedAvatar = null;

if ($profile) {
    $avatarType = $profile['avatar_type'] ?? 'default';
    $avatarData = $profile['avatar_data'] ?? null;
    
    // Try to get cached avatar, but only use it if it matches current profile settings
    try {
        $cachedAvatar = $avatarCacheRepo->getCachedAvatar($userHandle);
        
        if ($cachedAvatar) {
            // Handle BLOB data that might be a resource
            if (isset($cachedAvatar['image_data'])) {
                if (is_resource($cachedAvatar['image_data'])) {
                    $cachedAvatar['image_data'] = stream_get_contents($cachedAvatar['image_data']);
                }
                $hasCachedAvatar = !empty($cachedAvatar['image_data']);
            }
            
            // Verify cache matches current avatar settings
            // For gallery avatars, cache URL should match current avatar_data
            if ($avatarType === 'gallery' && $avatarData) {
                $cacheUrl = $cachedAvatar['avatar_url'] ?? '';
                $expectedUrl = '/iChat/api/gallery-image.php?id=' . $avatarData;
                if ($cacheUrl !== $expectedUrl) {
                    error_log("Cache mismatch for gallery avatar: cache_url={$cacheUrl}, expected={$expectedUrl}");
                    $hasCachedAvatar = false; // Don't use cache if it doesn't match
                }
            }
            // For Gravatar, verify email matches
            elseif ($avatarType === 'gravatar' && !empty($profile['gravatar_email'])) {
                $cacheEmail = $cachedAvatar['email'] ?? '';
                $profileEmail = strtolower(trim($profile['gravatar_email']));
                if ($cacheEmail !== $profileEmail) {
                    error_log("Cache mismatch for Gravatar: cache_email={$cacheEmail}, profile_email={$profileEmail}");
                    $hasCachedAvatar = false; // Don't use cache if email doesn't match
                }
            }
            // For default avatars, cache is fine to use
        }
    } catch (\Throwable $e) {
        // Silently ignore cache errors (table might not exist)
        $hasCachedAvatar = false;
    }
}

// Use cached avatar only if it matches current profile settings
if ($hasCachedAvatar && $cachedAvatar) {
    try {
        // Use thumbnail for small sizes (chat: 50px, online users: 50px)
        $useThumbnail = ($size <= 50 && !empty($cachedAvatar['thumbnail_data']));
        
        if ($useThumbnail && !empty($cachedAvatar['thumbnail_data'])) {
            // Serve thumbnail
            $thumbData = is_resource($cachedAvatar['thumbnail_data']) 
                ? stream_get_contents($cachedAvatar['thumbnail_data']) 
                : $cachedAvatar['thumbnail_data'];
            
            header('Content-Type: ' . ($cachedAvatar['mime_type'] ?? 'image/png'));
            header('Content-Length: ' . strlen($thumbData));
            header('Cache-Control: no-cache, no-store, must-revalidate'); // Disable caching for debugging
            header('Pragma: no-cache');
            header('Expires: 0');
            
            echo $thumbData;
        } else {
            // Serve full-size image
            $imgData = is_resource($cachedAvatar['image_data']) 
                ? stream_get_contents($cachedAvatar['image_data']) 
                : $cachedAvatar['image_data'];
            
            header('Content-Type: ' . ($cachedAvatar['mime_type'] ?? 'image/png'));
            header('Content-Length: ' . strlen($imgData));
            header('Cache-Control: no-cache, no-store, must-revalidate'); // Disable caching for debugging
            header('Pragma: no-cache');
            header('Expires: 0');
            
            echo $imgData;
        }
        exit;
    } catch (\Throwable $e) {
        error_log("Error serving cached avatar for {$userHandle}: " . $e->getMessage());
        // Fall through to fetch fresh avatar
    }
}

// Not cached or cache doesn't match - get profile to determine avatar source

if (!$profile) {
    // No profile - generate default avatar (fall through to end of script)
    error_log("Generating default avatar for {$userHandle} (no profile)");
} else {
    $avatarType = $profile['avatar_type'] ?? 'default';
    error_log("Avatar type for {$userHandle}: {$avatarType}");

    if ($avatarType === 'gallery' && !empty($profile['avatar_data'])) {
        error_log("Attempting to serve gallery avatar for {$userHandle}, image_id: " . $profile['avatar_data']);
        try {
            // Serve gallery image from database
            $galleryRepo = new \iChat\Repositories\UserGalleryRepository();
            $imageId = (int)$profile['avatar_data'];
            error_log("Fetching gallery image ID {$imageId} for {$userHandle}");
            $imageData = $galleryRepo->getImageData($imageId, $userHandle, false);
            
            if ($imageData && !empty($imageData['image_data'])) {
                error_log("Gallery image data found for {$userHandle}, size: " . strlen($imageData['image_data']));
                
                // Try to cache the gallery image as avatar (silently fail if cache table doesn't exist)
                try {
                    $avatarCacheRepo->cacheAvatar(
                        $userHandle,
                        '/iChat/api/gallery-image.php?id=' . $profile['avatar_data'],
                        $imageData['image_data'],
                        $imageData['mime_type'] ?? 'image/png',
                        '',
                        $userHandle
                    );
                } catch (\Exception $e) {
                    // Cache failed (table doesn't exist) - that's OK, we'll serve directly
                    error_log("Avatar cache failed (expected if patch not applied): " . $e->getMessage());
                }
                
                // Serve the image directly - NO CACHING for debugging
                header('Content-Type: ' . ($imageData['mime_type'] ?? 'image/png'));
                header('Content-Length: ' . strlen($imageData['image_data']));
                header('Cache-Control: no-cache, no-store, must-revalidate'); // Disable caching
                header('Pragma: no-cache');
                header('Expires: 0');
                echo $imageData['image_data'];
                exit;
            } else {
                error_log("Gallery image data not found or empty for {$userHandle}, image_id: {$imageId}");
                if ($imageData === null) {
                    error_log("getImageData returned null for image_id: {$imageId}");
                } elseif (empty($imageData['image_data'])) {
                    error_log("getImageData returned empty image_data for image_id: {$imageId}, keys: " . implode(', ', array_keys($imageData)));
                }
            }
        } catch (\Throwable $e) {
            error_log("Error serving gallery avatar for {$userHandle}: " . $e->getMessage());
            // Fall through to generate default avatar
        }
    }

    // Try Gravatar if avatar_type is 'gravatar' OR if it's 'default' but user has email
    $gravatarEmail = $profile['gravatar_email'] ?? null;
    $shouldTryGravatar = ($avatarType === 'gravatar' && !empty($gravatarEmail)) 
                      || ($avatarType === 'default' && !empty($gravatarEmail));
    
    error_log("Gravatar check for {$userHandle}: avatar_type={$avatarType}, gravatar_email=" . ($gravatarEmail ? substr($gravatarEmail, 0, 3) . '...' : 'null') . ", shouldTryGravatar=" . ($shouldTryGravatar ? 'true' : 'false'));
    
    if ($shouldTryGravatar) {
        try {
            $email = strtolower(trim($gravatarEmail));
            error_log("Attempting to fetch Gravatar for {$userHandle}, email: " . substr($email, 0, 3) . '...');
            // Fetch from Gravatar and cache it
            // Gravatar uses MD5 hash (standard), not SHA256
            $hash = md5(strtolower(trim($email)));
            // Request larger size (128px) to generate good thumbnail, then resize if needed
            $gravatarUrl = "https://www.gravatar.com/avatar/{$hash}?s=128&d=404"; // Use 404 to detect if image exists
            
            // Fetch image from Gravatar
            $ch = curl_init($gravatarUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $imageData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode === 200 && !empty($imageData) && empty($curlError)) {
                // Cache the image (will generate thumbnail automatically) - silently fail if cache table doesn't exist
                $mimeType = $contentType ?: 'image/png';
                try {
                    $avatarCacheRepo->cacheAvatar(
                        $userHandle,
                        $gravatarUrl,
                        $imageData,
                        $mimeType,
                        $email,
                        $userHandle
                    );
                    
                    // CACHING DISABLED - Always serve fresh Gravatar image
                } catch (\Exception $e) {
                    // Cache failed (table doesn't exist) - that's OK, we'll serve directly
                    error_log("Avatar cache failed (expected if patch not applied): " . $e->getMessage());
                }
                
                // Serve the image directly - NO CACHING for debugging
                header('Content-Type: ' . $mimeType);
                header('Content-Length: ' . strlen($imageData));
                header('Cache-Control: no-cache, no-store, must-revalidate'); // Disable caching
                header('Pragma: no-cache');
                header('Expires: 0');
                echo $imageData;
                exit;
            } else {
                // Log Gravatar fetch failure for debugging
                error_log("Gravatar fetch failed for {$userHandle}: HTTP {$httpCode}, Error: {$curlError}");
                // Fall through to generate default avatar
            }
        } catch (\Throwable $e) {
            error_log("Error fetching Gravatar for {$userHandle}: " . $e->getMessage());
            // Fall through to generate default avatar
        }
    }
}

// Fallback to default avatar
error_log("Falling back to default avatar generation for {$userHandle}");
$defaultAvatarPath = ICHAT_ROOT . '/images/default-avatar.png';
if (file_exists($defaultAvatarPath)) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    readfile($defaultAvatarPath);
    exit;
}

    // If default avatar doesn't exist, generate a simple one - NO CACHING for debugging
    header('Content-Type: image/png');
    header('Cache-Control: no-cache, no-store, must-revalidate'); // Disable caching
    header('Pragma: no-cache');
    header('Expires: 0');
    error_log("Generating dynamic default avatar for {$userHandle}, size: {$size}");

// Create a simple default avatar (50x50 blue circle with white initial)
$img = imagecreatetruecolor($size, $size);
$bgColor = imagecolorallocate($img, 0, 112, 255); // Blizzard blue
$textColor = imagecolorallocate($img, 255, 255, 255); // White

// Fill with blue
imagefill($img, 0, 0, $bgColor);

// Draw circle
imagefilledellipse($img, $size/2, $size/2, $size-2, $size-2, $bgColor);

// Get first letter of username
$initial = strtoupper(substr($userHandle, 0, 1));
if (empty($initial)) {
    $initial = '?';
}

// Calculate font size (roughly 60% of image size)
$fontSize = (int)($size * 0.6);
$font = 5; // Built-in font (1-5, 5 is largest)

// Center text
$textWidth = imagefontwidth($font) * strlen($initial);
$textHeight = imagefontheight($font);
$x = ($size - $textWidth) / 2;
$y = ($size - $textHeight) / 2;

// Draw text
imagestring($img, $font, (int)$x, (int)$y, $initial, $textColor);

// Output image
imagepng($img);
imagedestroy($img);
exit;

