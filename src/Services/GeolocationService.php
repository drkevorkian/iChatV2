<?php
/**
 * Sentinel Chat Platform - Geolocation Service
 * 
 * Provides IP-based geolocation using free APIs.
 * Falls back gracefully if service is unavailable.
 */

declare(strict_types=1);

namespace iChat\Services;

class GeolocationService
{
    private const CACHE_DURATION = 3600; // Cache for 1 hour
    private const API_URL = 'http://ip-api.com/json/';
    
    /**
     * Get geolocation data for an IP address
     * 
     * @param string $ip IP address
     * @return array Geolocation data
     */
    public function getLocation(string $ip): array
    {
        // Validate IP address
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return $this->getDefaultLocation();
        }
        
        // Skip private/local IPs
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return [
                'country' => 'Local',
                'city' => 'Local Network',
                'isp' => 'Private Network',
                'flag' => '🏠',
            ];
        }
        
        // Check cache
        $cacheKey = 'geo_' . md5($ip);
        $cached = $this->getCachedLocation($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        
        // Fetch from API
        try {
            $url = self::API_URL . urlencode($ip) . '?fields=status,message,country,countryCode,city,isp,lat,lon,timezone';
            $context = stream_context_create([
                'http' => [
                    'timeout' => 3,
                    'user_agent' => 'Sentinel Chat Platform',
                ],
            ]);
            
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                return $this->getDefaultLocation();
            }
            
            $data = json_decode($response, true);
            if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
                return $this->getDefaultLocation();
            }
            
            $location = [
                'country' => $data['country'] ?? 'Unknown',
                'countryCode' => $data['countryCode'] ?? '',
                'city' => $data['city'] ?? 'Unknown',
                'isp' => $data['isp'] ?? 'Unknown',
                'lat' => $data['lat'] ?? null,
                'lon' => $data['lon'] ?? null,
                'timezone' => $data['timezone'] ?? '',
                'flag' => $this->getCountryFlag($data['countryCode'] ?? ''),
            ];
            
            // Cache the result
            $this->cacheLocation($cacheKey, $location);
            
            return $location;
        } catch (\Exception $e) {
            error_log('Geolocation API error: ' . $e->getMessage());
            return $this->getDefaultLocation();
        }
    }
    
    /**
     * Get default location data
     * 
     * @return array Default location
     */
    private function getDefaultLocation(): array
    {
        return [
            'country' => 'Unknown',
            'countryCode' => '',
            'city' => 'Unknown',
            'isp' => 'Unknown',
            'lat' => null,
            'lon' => null,
            'timezone' => '',
            'flag' => '🌐',
        ];
    }
    
    /**
     * Get cached location
     * 
     * @param string $cacheKey Cache key
     * @return array|null Cached location or null
     */
    private function getCachedLocation(string $cacheKey): ?array
    {
        $cacheFile = ICHAT_ROOT . '/storage/cache/' . $cacheKey . '.json';
        if (!file_exists($cacheFile)) {
            return null;
        }
        
        $mtime = filemtime($cacheFile);
        if (time() - $mtime > self::CACHE_DURATION) {
            @unlink($cacheFile);
            return null;
        }
        
        $content = file_get_contents($cacheFile);
        if ($content === false) {
            return null;
        }
        
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }
    
    /**
     * Cache location data
     * 
     * @param string $cacheKey Cache key
     * @param array $location Location data
     */
    private function cacheLocation(string $cacheKey, array $location): void
    {
        $cacheDir = ICHAT_ROOT . '/storage/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        $cacheFile = $cacheDir . '/' . $cacheKey . '.json';
        file_put_contents($cacheFile, json_encode($location));
    }
    
    /**
     * Get country flag emoji from country code
     * 
     * @param string $countryCode ISO country code (e.g., 'US')
     * @return string Flag emoji
     */
    private function getCountryFlag(string $countryCode): string
    {
        if (empty($countryCode) || strlen($countryCode) !== 2) {
            return '🌐';
        }
        
        // Convert country code to flag emoji
        $codePoints = str_split(strtoupper($countryCode));
        $flag = '';
        foreach ($codePoints as $char) {
            $flag .= mb_chr(0x1F1E6 + ord($char) - ord('A'), 'UTF-8');
        }
        
        return $flag ?: '🌐';
    }
}

