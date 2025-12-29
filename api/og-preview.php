<?php
/**
 * Sentinel Chat Platform - Open Graph Preview API
 * 
 * Fetches Open Graph metadata for URL previews.
 * Uses server-side fetching to avoid CORS issues.
 */

require_once __DIR__ . '/../bootstrap.php';

use iChat\Services\SecurityService;

header('Content-Type: application/json');

$security = new SecurityService();
$security->setSecurityHeaders();

$url = $_GET['url'] ?? '';

if (empty($url)) {
    http_response_code(400);
    echo json_encode(['error' => 'URL parameter required']);
    exit;
}

// Validate URL
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid URL']);
    exit;
}

// Only allow http/https URLs
$scheme = parse_url($url, PHP_URL_SCHEME);
if (!in_array($scheme, ['http', 'https'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Only HTTP/HTTPS URLs are allowed']);
    exit;
}

try {
    // Fetch URL content with timeout
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'user_agent' => 'Mozilla/5.0 (compatible; Sentinel Chat Platform)',
            'follow_location' => true,
            'max_redirects' => 3,
        ],
    ]);
    
    $html = @file_get_contents($url, false, $context);
    
    if ($html === false) {
        throw new \RuntimeException('Failed to fetch URL');
    }
    
    // Parse Open Graph tags
    $ogData = [];
    
    // Extract meta tags
    preg_match_all('/<meta\s+property=["\']og:([^"\']+)["\']\s+content=["\']([^"\']+)["\']/i', $html, $ogMatches, PREG_SET_ORDER);
    foreach ($ogMatches as $match) {
        $ogData['og:' . $match[1]] = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
    }
    
    // Extract regular meta tags as fallback
    preg_match_all('/<meta\s+name=["\']([^"\']+)["\']\s+content=["\']([^"\']+)["\']/i', $html, $metaMatches, PREG_SET_ORDER);
    foreach ($metaMatches as $match) {
        $name = strtolower($match[1]);
        if (in_array($name, ['title', 'description', 'image'], true)) {
            $ogData[$name] = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
        }
    }
    
    // Extract title if not found
    if (empty($ogData['og:title']) && empty($ogData['title'])) {
        preg_match('/<title>([^<]+)<\/title>/i', $html, $titleMatch);
        if (!empty($titleMatch[1])) {
            $ogData['title'] = html_entity_decode(trim($titleMatch[1]), ENT_QUOTES, 'UTF-8');
        }
    }
    
    echo json_encode([
        'success' => true,
        'og' => $ogData,
    ]);
} catch (\Exception $e) {
    error_log('Open Graph fetch error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch preview',
    ]);
}

