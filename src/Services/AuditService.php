<?php
/**
 * Sentinel Chat Platform - Audit Service
 * 
 * High-level service for logging audit events with automatic context extraction.
 * Provides easy-to-use methods for common audit scenarios.
 * 
 * Security: This service ensures all user actions are logged for compliance.
 * Audit logs are immutable once written to prevent tampering.
 */

declare(strict_types=1);

namespace iChat\Services;

use iChat\Repositories\AuditRepository;

class AuditService
{
    private AuditRepository $auditRepo;

    public function __construct()
    {
        $this->auditRepo = new AuditRepository();
    }

    /**
     * Get current request context (IP, user agent, session ID)
     * 
     * @return array Context data
     */
    private function getRequestContext(): array
    {
        return [
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'session_id' => session_id() ?: null,
        ];
    }

    /**
     * Log an audit event
     * 
     * @param string $userHandle User handle
     * @param string $actionType Type of action
     * @param string $actionCategory Category
     * @param array $data Additional data
     * @return string|false Audit log ID
     */
    public function log(
        string $userHandle,
        string $actionType,
        string $actionCategory = 'other',
        array $data = []
    ) {
        // Merge request context into data
        $context = $this->getRequestContext();
        $data = array_merge($context, $data);

        return $this->auditRepo->log($userHandle, $actionType, $actionCategory, $data);
    }

    /**
     * Log authentication event (login)
     * 
     * @param string $userHandle User handle
     * @param int|null $userId User ID if registered
     * @param bool $success Whether login succeeded
     * @param string|null $errorMessage Error message if failed
     * @return string|false Audit log ID
     */
    public function logLogin(
        string $userHandle,
        ?int $userId = null,
        bool $success = true,
        ?string $errorMessage = null
    ) {
        return $this->log(
            $userHandle,
            'login',
            'authentication',
            [
                'user_id' => $userId,
                'success' => $success,
                'error_message' => $errorMessage,
            ]
        );
    }

    /**
     * Log logout event
     * 
     * @param string $userHandle User handle
     * @param int|null $userId User ID if registered
     * @return string|false Audit log ID
     */
    public function logLogout(string $userHandle, ?int $userId = null)
    {
        return $this->log(
            $userHandle,
            'logout',
            'authentication',
            [
                'user_id' => $userId,
            ]
        );
    }

    /**
     * Log failed login attempt
     * 
     * @param string $userHandle User handle (or attempted handle)
     * @param string $reason Reason for failure
     * @return string|false Audit log ID
     */
    public function logFailedLogin(string $userHandle, string $reason)
    {
        return $this->logLogin($userHandle, null, false, $reason);
    }

    /**
     * Log message send event
     * 
     * @param string $userHandle User handle
     * @param int|null $userId User ID if registered
     * @param string $messageId Message ID
     * @param string $roomId Room ID
     * @param array $metadata Additional metadata (message_length, etc.)
     * @return string|false Audit log ID
     */
    public function logMessageSend(
        string $userHandle,
        ?int $userId,
        string $messageId,
        string $roomId,
        array $metadata = []
    ) {
        return $this->log(
            $userHandle,
            'message_send',
            'message',
            [
                'user_id' => $userId,
                'resource_type' => 'message',
                'resource_id' => $messageId,
                'metadata' => array_merge($metadata, ['room_id' => $roomId]),
            ]
        );
    }

    /**
     * Log message edit event
     * 
     * @param string $userHandle User handle
     * @param int|null $userId User ID if registered
     * @param string $messageId Message ID
     * @param array $beforeValue Original message content
     * @param array $afterValue Edited message content
     * @return string|false Audit log ID
     */
    public function logMessageEdit(
        string $userHandle,
        ?int $userId,
        string $messageId,
        array $beforeValue,
        array $afterValue
    ) {
        return $this->log(
            $userHandle,
            'message_edit',
            'message',
            [
                'user_id' => $userId,
                'resource_type' => 'message',
                'resource_id' => $messageId,
                'before_value' => $beforeValue,
                'after_value' => $afterValue,
            ]
        );
    }

    /**
     * Log message delete event
     * 
     * @param string $userHandle User handle
     * @param int|null $userId User ID if registered
     * @param string $messageId Message ID
     * @param array $beforeValue Original message content
     * @return string|false Audit log ID
     */
    public function logMessageDelete(
        string $userHandle,
        ?int $userId,
        string $messageId,
        array $beforeValue
    ) {
        return $this->log(
            $userHandle,
            'message_delete',
            'message',
            [
                'user_id' => $userId,
                'resource_type' => 'message',
                'resource_id' => $messageId,
                'before_value' => $beforeValue,
            ]
        );
    }

    /**
     * Log file upload event
     * 
     * @param string $userHandle User handle
     * @param int|null $userId User ID if registered
     * @param string $fileId File ID
     * @param string $fileType File type (image, video, audio, etc.)
     * @param int $fileSize File size in bytes
     * @param string|null $fileName File name
     * @return string|false Audit log ID
     */
    public function logFileUpload(
        string $userHandle,
        ?int $userId,
        string $fileId,
        string $fileType,
        int $fileSize,
        ?string $fileName = null
    ) {
        return $this->log(
            $userHandle,
            'file_upload',
            'file',
            [
                'user_id' => $userId,
                'resource_type' => 'file',
                'resource_id' => $fileId,
                'metadata' => [
                    'file_type' => $fileType,
                    'file_size' => $fileSize,
                    'file_name' => $fileName,
                ],
            ]
        );
    }

    /**
     * Log file download event
     * 
     * @param string $userHandle User handle
     * @param int|null $userId User ID if registered
     * @param string $fileId File ID
     * @return string|false Audit log ID
     */
    public function logFileDownload(
        string $userHandle,
        ?int $userId,
        string $fileId
    ) {
        return $this->log(
            $userHandle,
            'file_download',
            'file',
            [
                'user_id' => $userId,
                'resource_type' => 'file',
                'resource_id' => $fileId,
            ]
        );
    }

    /**
     * Log room join event
     * 
     * @param string $userHandle User handle
     * @param int|null $userId User ID if registered
     * @param string $roomId Room ID
     * @return string|false Audit log ID
     */
    public function logRoomJoin(string $userHandle, ?int $userId, string $roomId)
    {
        return $this->log(
            $userHandle,
            'room_join',
            'room',
            [
                'user_id' => $userId,
                'resource_type' => 'room',
                'resource_id' => $roomId,
            ]
        );
    }

    /**
     * Log room leave event
     * 
     * @param string $userHandle User handle
     * @param int|null $userId User ID if registered
     * @param string $roomId Room ID
     * @return string|false Audit log ID
     */
    public function logRoomLeave(string $userHandle, ?int $userId, string $roomId)
    {
        return $this->log(
            $userHandle,
            'room_leave',
            'room',
            [
                'user_id' => $userId,
                'resource_type' => 'room',
                'resource_id' => $roomId,
            ]
        );
    }

    /**
     * Log admin change event
     * 
     * @param string $userHandle Admin user handle
     * @param int|null $userId Admin user ID
     * @param string $changeType Type of change (user_role_change, user_ban, user_unban, etc.)
     * @param string $targetResource Resource that was changed
     * @param string $targetId ID of the resource
     * @param array $beforeValue State before change
     * @param array $afterValue State after change
     * @return string|false Audit log ID
     */
    public function logAdminChange(
        string $userHandle,
        ?int $userId,
        string $changeType,
        string $targetResource,
        string $targetId,
        array $beforeValue,
        array $afterValue
    ) {
        return $this->log(
            $userHandle,
            $changeType,
            'admin',
            [
                'user_id' => $userId,
                'resource_type' => $targetResource,
                'resource_id' => $targetId,
                'before_value' => $beforeValue,
                'after_value' => $afterValue,
            ]
        );
    }

    /**
     * Log moderation action
     * 
     * @param string $userHandle Moderator user handle
     * @param int|null $userId Moderator user ID
     * @param string $actionType Type of moderation action (kick, mute, ban, warn, etc.)
     * @param string $targetHandle Target user handle
     * @param array $metadata Additional metadata (reason, duration, etc.)
     * @return string|false Audit log ID
     */
    public function logModerationAction(
        string $userHandle,
        ?int $userId,
        string $actionType,
        string $targetHandle,
        array $metadata = []
    ) {
        return $this->log(
            $userHandle,
            $actionType,
            'moderation',
            [
                'user_id' => $userId,
                'resource_type' => 'user',
                'resource_id' => $targetHandle,
                'metadata' => $metadata,
            ]
        );
    }

    /**
     * Get audit logs (delegates to repository)
     * 
     * @param array $filters Filter criteria
     * @param int $limit Maximum number of results
     * @param int $offset Offset for pagination
     * @param string $orderBy Column to order by
     * @param string $orderDir Order direction
     * @return array Array of audit log entries
     */
    public function getLogs(
        array $filters = [],
        int $limit = 100,
        int $offset = 0,
        string $orderBy = 'timestamp',
        string $orderDir = 'DESC'
    ): array {
        return $this->auditRepo->getLogs($filters, $limit, $offset, $orderBy, $orderDir);
    }

    /**
     * Get audit log count (delegates to repository)
     * 
     * @param array $filters Filter criteria
     * @return int Count of matching logs
     */
    public function getLogCount(array $filters = []): int
    {
        return $this->auditRepo->getLogCount($filters);
    }

    /**
     * Get retention policies (delegates to repository)
     * 
     * @return array Array of retention policies
     */
    public function getRetentionPolicies(): array
    {
        return $this->auditRepo->getRetentionPolicies();
    }

    /**
     * Update retention policy
     * 
     * @param int $policyId Policy ID
     * @param int $retentionDays Number of days to retain
     * @param bool $autoPurge Whether to auto-purge
     * @param bool $legalHold Whether on legal hold
     * @return bool True if successful
     */
    public function updateRetentionPolicy(int $policyId, int $retentionDays, bool $autoPurge, bool $legalHold): bool
    {
        return $this->auditRepo->updateRetentionPolicy($policyId, $retentionDays, $autoPurge, $legalHold);
    }

    /**
     * Purge old logs based on retention policies (delegates to repository)
     * 
     * @return int Number of logs purged
     */
    public function purgeOldLogs(): int
    {
        return $this->auditRepo->purgeOldLogs();
    }
}

