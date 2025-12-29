<?php
/**
 * Sentinel Chat Platform - File Storage Service
 * 
 * Provides file-based storage as a fallback when database is unavailable.
 * Stores data in JSON files in a queue directory, which can be synced to
 * the database when it comes back online.
 * 
 * Security: File storage is temporary and should only be used as a fallback.
 * All data is stored in a protected directory with proper permissions.
 */

declare(strict_types=1);

namespace iChat\Services;

class FileStorage
{
    private string $storageDir;
    private string $queueDir;

    public function __construct()
    {
        $this->storageDir = ICHAT_ROOT . '/storage';
        $this->queueDir = $this->storageDir . '/queue';
        
        // Create storage directories if they don't exist
        $this->ensureDirectoriesExist();
    }

    /**
     * Ensure storage directories exist with proper permissions
     */
    private function ensureDirectoriesExist(): void
    {
        $dirs = [$this->storageDir, $this->queueDir];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            
            // Create .htaccess to protect directory
            $htaccess = $dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Deny from all\n");
            }
        }
    }

    /**
     * Store a message in the file queue
     * 
     * @param string $type Message type ('message', 'im', 'escrow')
     * @param array $data Message data
     * @return string File path where data was stored
     */
    public function queueMessage(string $type, array $data): string
    {
        $timestamp = time();
        $microtime = microtime(true);
        $filename = sprintf('%s_%s_%d.json', $type, date('YmdHis', $timestamp), (int)($microtime * 1000000));
        $filepath = $this->queueDir . '/' . $filename;
        
        // Add metadata
        $data['_metadata'] = [
            'type' => $type,
            'queued_at' => date('Y-m-d H:i:s', $timestamp),
            'queued_timestamp' => $microtime,
            'synced' => false,
            'filepath' => $filename, // Store filename for reference
        ];
        
        // Write to file atomically
        $tempFile = $filepath . '.tmp';
        file_put_contents($tempFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        rename($tempFile, $filepath);
        
        return $filepath;
    }

    /**
     * Get all queued messages of a specific type
     * 
     * @param string $type Message type ('message', 'im', 'escrow')
     * @param bool $unsyncedOnly Only return unsynced messages
     * @return array Array of message data
     */
    public function getQueuedMessages(string $type, bool $unsyncedOnly = true): array
    {
        $messages = [];
        $pattern = $this->queueDir . '/' . $type . '_*.json';
        $files = glob($pattern);
        
        if (!$files) {
            return [];
        }
        
        foreach ($files as $file) {
            $data = $this->readMessageFile($file);
            if ($data === null) {
                continue;
            }
            
            if ($unsyncedOnly && ($data['_metadata']['synced'] ?? false)) {
                continue;
            }
            
            $messages[] = $data;
        }
        
        // Sort by queued timestamp (oldest first)
        usort($messages, function($a, $b) {
            $tsA = $a['_metadata']['queued_timestamp'] ?? 0;
            $tsB = $b['_metadata']['queued_timestamp'] ?? 0;
            return $tsA <=> $tsB;
        });
        
        return $messages;
    }

    /**
     * Read a message file
     * 
     * @param string $filepath File path
     * @return array|null Message data or null if invalid
     */
    public function readMessageFile(string $filepath): ?array
    {
        if (!file_exists($filepath)) {
            return null;
        }
        
        $content = file_get_contents($filepath);
        if ($content === false) {
            return null;
        }
        
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }
        
        return $data;
    }

    /**
     * Mark a message file as synced
     * 
     * @param string $filepath File path
     * @return bool True if successful
     */
    public function markAsSynced(string $filepath): bool
    {
        $data = $this->readMessageFile($filepath);
        if ($data === null) {
            return false;
        }
        
        $data['_metadata']['synced'] = true;
        $data['_metadata']['synced_at'] = date('Y-m-d H:i:s');
        
        $tempFile = $filepath . '.tmp';
        file_put_contents($tempFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return rename($tempFile, $filepath);
    }

    /**
     * Delete a synced message file (after successful database sync)
     * 
     * @param string $filepath File path
     * @return bool True if successful
     */
    public function deleteSyncedFile(string $filepath): bool
    {
        if (!file_exists($filepath)) {
            return true; // Already deleted
        }
        
        return @unlink($filepath);
    }

    /**
     * Get count of unsynced messages
     * 
     * @param string $type Message type
     * @return int Count of unsynced messages
     */
    public function getUnsyncedCount(string $type): int
    {
        return count($this->getQueuedMessages($type, true));
    }

    /**
     * Get storage directory path
     * 
     * @return string Storage directory path
     */
    public function getStorageDir(): string
    {
        return $this->storageDir;
    }

    /**
     * Queue a room request to file storage
     * 
     * @param array $requestData Room request data
     * @return int Request ID (timestamp-based)
     */
    public function queueRoomRequest(array $requestData): int
    {
        $requestId = (int)(microtime(true) * 1000000); // Microsecond timestamp as ID
        $requestData['id'] = $requestId;
        $this->queueMessage('room_request', $requestData);
        return $requestId;
    }

    /**
     * Queue a user report to file storage
     * 
     * @param array $reportData Report data
     * @return int Report ID (timestamp-based)
     */
    public function queueReport(array $reportData): int
    {
        $reportId = (int)(microtime(true) * 1000000); // Microsecond timestamp as ID
        $reportData['id'] = $reportId;
        $this->queueMessage('report', $reportData);
        return $reportId;
    }

    /**
     * Get queue directory path
     * 
     * @return string Queue directory path
     */
    public function getQueueDir(): string
    {
        return $this->queueDir;
    }
}

