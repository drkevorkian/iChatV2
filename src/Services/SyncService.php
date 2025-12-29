<?php
/**
 * Sentinel Chat Platform - Sync Service
 * 
 * Syncs file-stored messages to the database when database becomes available.
 * This service should be called periodically (via cron or background process)
 * to ensure file-stored messages are eventually persisted to the database.
 * 
 * Security: All database operations use prepared statements. File operations
 * are atomic to prevent data corruption.
 */

declare(strict_types=1);

namespace iChat\Services;

use iChat\Database;
use iChat\Repositories\MessageRepository;
use iChat\Repositories\ImRepository;
use iChat\Repositories\EscrowRepository;

class SyncService
{
    private FileStorage $fileStorage;
    private MessageRepository $messageRepo;
    private ImRepository $imRepo;
    private EscrowRepository $escrowRepo;

    public function __construct()
    {
        $this->fileStorage = new FileStorage();
        $this->messageRepo = new MessageRepository();
        $this->imRepo = new ImRepository();
        $this->escrowRepo = new EscrowRepository();
    }

    /**
     * Sync all file-stored messages to database
     * 
     * Processes messages from file storage and attempts to store them
     * in the database. Marks files as synced on success.
     * 
     * @param int $batchSize Maximum number of messages to process per type
     * @return array Sync results
     */
    public function syncAll(int $batchSize = 100): array
    {
        $results = [
            'messages' => ['synced' => 0, 'failed' => 0],
            'im' => ['synced' => 0, 'failed' => 0],
            'escrow' => ['synced' => 0, 'failed' => 0],
        ];

        // Check if database is available
        if (!DatabaseHealth::isAvailable()) {
            return $results; // Database not available, nothing to sync
        }

        // Sync messages
        $results['messages'] = $this->syncMessages($batchSize);
        
        // Sync IMs
        $results['im'] = $this->syncIms($batchSize);
        
        // Sync escrow requests
        $results['escrow'] = $this->syncEscrowRequests($batchSize);
        
        // Sync patch records
        $results['patches'] = $this->syncPatchRecords($batchSize);

        return $results;
    }

    /**
     * Sync message files to database
     * 
     * @param int $batchSize Maximum number to process
     * @return array Sync results
     */
    private function syncMessages(int $batchSize): array
    {
        $synced = 0;
        $failed = 0;
        
        $fileMessages = $this->fileStorage->getQueuedMessages('message', true);
        $processed = 0;
        
        foreach ($fileMessages as $fileMsg) {
            if ($processed >= $batchSize) {
                break;
            }
            
            $filename = $fileMsg['_metadata']['filepath'] ?? '';
            if (empty($filename)) {
                continue; // Skip if no filename
            }
            $filepath = $this->fileStorage->getQueueDir() . '/' . $filename;
            
            try {
                // Insert into database
                $sql = 'INSERT INTO temp_outbox 
                        (room_id, sender_handle, cipher_blob, filter_version, queued_at) 
                        VALUES (:room_id, :sender_handle, :cipher_blob, :filter_version, :queued_at)';
                
                Database::execute($sql, [
                    ':room_id' => $fileMsg['room_id'] ?? '',
                    ':sender_handle' => $fileMsg['sender_handle'] ?? '',
                    ':cipher_blob' => $fileMsg['cipher_blob'] ?? '',
                    ':filter_version' => (int)($fileMsg['filter_version'] ?? 1),
                    ':queued_at' => $fileMsg['_metadata']['queued_at'] ?? date('Y-m-d H:i:s'),
                ]);
                
                // Mark as synced
                $this->fileStorage->markAsSynced($filepath);
                
                // Delete file after successful sync (optional - can keep for audit)
                // $this->fileStorage->deleteSyncedFile($filepath);
                
                $synced++;
            } catch (\Exception $e) {
                error_log('Failed to sync message file: ' . $e->getMessage());
                $failed++;
            }
            
            $processed++;
        }
        
        return ['synced' => $synced, 'failed' => $failed];
    }

    /**
     * Sync IM files to database
     * 
     * @param int $batchSize Maximum number to process
     * @return array Sync results
     */
    private function syncIms(int $batchSize): array
    {
        $synced = 0;
        $failed = 0;
        
        $fileMessages = $this->fileStorage->getQueuedMessages('im', true);
        $processed = 0;
        
        foreach ($fileMessages as $fileMsg) {
            if ($processed >= $batchSize) {
                break;
            }
            
            $filename = $fileMsg['_metadata']['filepath'] ?? '';
            if (empty($filename)) {
                continue; // Skip if no filename
            }
            $filepath = $this->fileStorage->getQueueDir() . '/' . $filename;
            
            try {
                // Insert into database (both inbox and sent folders)
                $sql = 'INSERT INTO im_messages 
                        (from_user, to_user, folder, status, cipher_blob, queued_at) 
                        VALUES (:from_user, :to_user, :folder, :status, :cipher_blob, :queued_at)';
                
                $params = [
                    ':from_user' => $fileMsg['from_user'] ?? '',
                    ':to_user' => $fileMsg['to_user'] ?? '',
                    ':folder' => $fileMsg['folder'] ?? 'inbox',
                    ':status' => $fileMsg['status'] ?? 'queued',
                    ':cipher_blob' => $fileMsg['cipher_blob'] ?? '',
                    ':queued_at' => $fileMsg['_metadata']['queued_at'] ?? date('Y-m-d H:i:s'),
                ];
                
                Database::execute($sql, $params);
                
                // Mark as synced
                $this->fileStorage->markAsSynced($filepath);
                
                $synced++;
            } catch (\Exception $e) {
                error_log('Failed to sync IM file: ' . $e->getMessage());
                $failed++;
            }
            
            $processed++;
        }
        
        return ['synced' => $synced, 'failed' => $failed];
    }

    /**
     * Sync escrow request files to database
     * 
     * @param int $batchSize Maximum number to process
     * @return array Sync results
     */
    private function syncEscrowRequests(int $batchSize): array
    {
        $synced = 0;
        $failed = 0;
        
        $fileMessages = $this->fileStorage->getQueuedMessages('escrow', true);
        $processed = 0;
        
        foreach ($fileMessages as $fileMsg) {
            if ($processed >= $batchSize) {
                break;
            }
            
            $filename = $fileMsg['_metadata']['filepath'] ?? '';
            if (empty($filename)) {
                continue; // Skip if no filename
            }
            $filepath = $this->fileStorage->getQueueDir() . '/' . $filename;
            
            try {
                // Insert into database
                $sql = 'INSERT INTO admin_escrow_requests 
                        (room_id, operator_handle, justification, status, requested_at) 
                        VALUES (:room_id, :operator_handle, :justification, :status, :requested_at)';
                
                Database::execute($sql, [
                    ':room_id' => $fileMsg['room_id'] ?? '',
                    ':operator_handle' => $fileMsg['operator_handle'] ?? '',
                    ':justification' => $fileMsg['justification'] ?? '',
                    ':status' => $fileMsg['status'] ?? 'pending',
                    ':requested_at' => $fileMsg['_metadata']['queued_at'] ?? date('Y-m-d H:i:s'),
                ]);
                
                // Mark as synced
                $this->fileStorage->markAsSynced($filepath);
                
                $synced++;
            } catch (\Exception $e) {
                error_log('Failed to sync escrow file: ' . $e->getMessage());
                $failed++;
            }
            
            $processed++;
        }
        
        return ['synced' => $synced, 'failed' => $failed];
    }

    /**
     * Sync patch records from file storage to database
     * 
     * @param int $batchSize Maximum number to process
     * @return array Sync results
     */
    private function syncPatchRecords(int $batchSize): array
    {
        $synced = 0;
        $failed = 0;
        
        $filePatches = $this->fileStorage->getQueuedMessages('patch', true);
        $processed = 0;
        
        foreach ($filePatches as $filePatch) {
            if ($processed >= $batchSize) {
                break;
            }
            
            $filename = $filePatch['_metadata']['filepath'] ?? '';
            if (empty($filename)) {
                continue;
            }
            $filepath = $this->fileStorage->getQueueDir() . '/' . $filename;
            
            try {
                // Only sync applied patches
                if (($filePatch['status'] ?? '') !== 'applied') {
                    continue;
                }
                
                // Check if already in database
                $sql = 'SELECT COUNT(*) as count FROM patch_history WHERE patch_id = :patch_id';
                $exists = Database::queryOne($sql, [':patch_id' => $filePatch['patch_id'] ?? '']);
                
                if (($exists['count'] ?? 0) > 0) {
                    // Already synced, mark file as synced
                    $this->fileStorage->markAsSynced($filepath);
                    $synced++;
                    continue;
                }
                
                // Insert into database
                $sql = 'INSERT INTO patch_history 
                        (patch_id, version, description, applied_at, duration, patch_info)
                        VALUES (:patch_id, :version, :description, :applied_at, :duration, :patch_info)';
                
                Database::execute($sql, [
                    ':patch_id' => $filePatch['patch_id'] ?? '',
                    ':version' => $filePatch['version'] ?? '1.0.0',
                    ':description' => $filePatch['description'] ?? '',
                    ':applied_at' => $filePatch['applied_at'] ?? date('Y-m-d H:i:s'),
                    ':duration' => $filePatch['duration'] ?? 0,
                    ':patch_info' => json_encode($filePatch['patch_info'] ?? []),
                ]);
                
                // Mark as synced
                $this->fileStorage->markAsSynced($filepath);
                
                $synced++;
            } catch (\Exception $e) {
                error_log('Failed to sync patch record: ' . $e->getMessage());
                $failed++;
            }
            
            $processed++;
        }
        
        return ['synced' => $synced, 'failed' => $failed];
    }

    /**
     * Get sync statistics
     * 
     * @return array Statistics about pending syncs
     */
    public function getStats(): array
    {
        return [
            'messages' => $this->fileStorage->getUnsyncedCount('message'),
            'im' => $this->fileStorage->getUnsyncedCount('im'),
            'escrow' => $this->fileStorage->getUnsyncedCount('escrow'),
            'patches' => $this->fileStorage->getUnsyncedCount('patch'),
            'database_available' => DatabaseHealth::isAvailable(),
        ];
    }
}

