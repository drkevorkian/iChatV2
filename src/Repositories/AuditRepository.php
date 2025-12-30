<?php
/**
 * Sentinel Chat Platform - Audit Repository
 * 
 * Handles database operations for audit logging.
 * Stores comprehensive audit trails for compliance (SOC2, HIPAA, GDPR, ISO 27001).
 * 
 * Security: All queries use prepared statements. Audit logs are immutable
 * once written to prevent tampering.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\DatabaseHealth;

class AuditRepository
{
    /**
     * Log an audit event
     * 
     * Records a user action with full context for compliance auditing.
     * This method is designed to be fast and non-blocking - it should never
     * fail the main operation if audit logging fails.
     * 
     * @param string $userHandle User handle (required)
     * @param string $actionType Type of action (login, logout, message_send, etc.)
     * @param string $actionCategory Category for filtering (authentication, message, etc.)
     * @param array $data Additional data (user_id, ip_address, user_agent, session_id, resource_type, resource_id, success, error_message, before_value, after_value, metadata)
     * @return string|false Audit log ID on success, false on failure
     */
    public function log(
        string $userHandle,
        string $actionType,
        string $actionCategory = 'other',
        array $data = []
    ) {
        // Check if database is available
        if (!DatabaseHealth::isAvailable()) {
            // If database is down, we can't log - but don't fail the main operation
            error_log("AuditRepository: Database unavailable, cannot log audit event: {$actionType} by {$userHandle}");
            return false;
        }

        try {
            $sql = 'INSERT INTO audit_log (
                        user_id, user_handle, action_type, action_category,
                        resource_type, resource_id, ip_address, user_agent, session_id,
                        success, error_message, before_value, after_value, metadata,
                        timestamp
                    ) VALUES (
                        :user_id, :user_handle, :action_type, :action_category,
                        :resource_type, :resource_id, :ip_address, :user_agent, :session_id,
                        :success, :error_message, :before_value, :after_value, :metadata,
                        NOW()
                    )';

            $params = [
                ':user_id' => $data['user_id'] ?? null,
                ':user_handle' => $userHandle,
                ':action_type' => $actionType,
                ':action_category' => $actionCategory,
                ':resource_type' => $data['resource_type'] ?? null,
                ':resource_id' => $data['resource_id'] ?? null,
                ':ip_address' => $data['ip_address'] ?? null,
                ':user_agent' => $data['user_agent'] ?? null,
                ':session_id' => $data['session_id'] ?? null,
                ':success' => $data['success'] ?? true,
                ':error_message' => $data['error_message'] ?? null,
                ':before_value' => isset($data['before_value']) ? json_encode($data['before_value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                ':after_value' => isset($data['after_value']) ? json_encode($data['after_value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                ':metadata' => isset($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ];

            Database::execute($sql, $params);
            return (string)Database::lastInsertId();
        } catch (\Exception $e) {
            // Log the error but don't fail the main operation
            error_log("AuditRepository: Failed to log audit event: {$actionType} by {$userHandle} - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get audit logs with filtering
     * 
     * @param array $filters Filter criteria (user_handle, user_id, action_type, action_category, resource_type, resource_id, ip_address, session_id, success, start_date, end_date)
     * @param int $limit Maximum number of results
     * @param int $offset Offset for pagination
     * @param string $orderBy Column to order by (default: timestamp)
     * @param string $orderDir Order direction (ASC or DESC, default: DESC)
     * @return array Array of audit log entries
     */
    public function getLogs(
        array $filters = [],
        int $limit = 100,
        int $offset = 0,
        string $orderBy = 'timestamp',
        string $orderDir = 'DESC'
    ): array {
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }

        try {
            $sql = 'SELECT 
                        id, timestamp, user_id, user_handle, action_type, action_category,
                        resource_type, resource_id, ip_address, user_agent, session_id,
                        success, error_message, before_value, after_value, metadata
                    FROM audit_log
                    WHERE 1=1';

            $params = [];

            // Build WHERE clause from filters
            if (!empty($filters['user_handle'])) {
                $sql .= ' AND user_handle = :user_handle';
                $params[':user_handle'] = $filters['user_handle'];
            }

            if (!empty($filters['user_id'])) {
                $sql .= ' AND user_id = :user_id';
                $params[':user_id'] = $filters['user_id'];
            }

            if (!empty($filters['action_type'])) {
                $sql .= ' AND action_type = :action_type';
                $params[':action_type'] = $filters['action_type'];
            }

            if (!empty($filters['action_category'])) {
                $sql .= ' AND action_category = :action_category';
                $params[':action_category'] = $filters['action_category'];
            }

            if (!empty($filters['resource_type'])) {
                $sql .= ' AND resource_type = :resource_type';
                $params[':resource_type'] = $filters['resource_type'];
            }

            if (!empty($filters['resource_id'])) {
                $sql .= ' AND resource_id = :resource_id';
                $params[':resource_id'] = $filters['resource_id'];
            }

            if (!empty($filters['ip_address'])) {
                $sql .= ' AND ip_address = :ip_address';
                $params[':ip_address'] = $filters['ip_address'];
            }

            if (!empty($filters['session_id'])) {
                $sql .= ' AND session_id = :session_id';
                $params[':session_id'] = $filters['session_id'];
            }

            if (isset($filters['success'])) {
                $sql .= ' AND success = :success';
                $params[':success'] = (bool)$filters['success'];
            }

            if (!empty($filters['start_date'])) {
                $sql .= ' AND timestamp >= :start_date';
                $params[':start_date'] = $filters['start_date'];
            }

            if (!empty($filters['end_date'])) {
                $sql .= ' AND timestamp <= :end_date';
                $params[':end_date'] = $filters['end_date'];
            }

            // Search term (full-text search across multiple fields)
            if (!empty($filters['search_term'])) {
                $searchTerm = '%' . $filters['search_term'] . '%';
                $sql .= ' AND (
                    user_handle LIKE :search_term1
                    OR action_type LIKE :search_term2
                    OR action_category LIKE :search_term3
                    OR resource_type LIKE :search_term4
                    OR resource_id LIKE :search_term5
                    OR ip_address LIKE :search_term6
                    OR user_agent LIKE :search_term7
                    OR error_message LIKE :search_term8
                    OR before_value LIKE :search_term9
                    OR after_value LIKE :search_term10
                    OR metadata LIKE :search_term11
                )';
                $params[':search_term1'] = $searchTerm;
                $params[':search_term2'] = $searchTerm;
                $params[':search_term3'] = $searchTerm;
                $params[':search_term4'] = $searchTerm;
                $params[':search_term5'] = $searchTerm;
                $params[':search_term6'] = $searchTerm;
                $params[':search_term7'] = $searchTerm;
                $params[':search_term8'] = $searchTerm;
                $params[':search_term9'] = $searchTerm;
                $params[':search_term10'] = $searchTerm;
                $params[':search_term11'] = $searchTerm;
            }

            // Validate orderBy to prevent SQL injection
            $allowedOrderBy = ['timestamp', 'user_handle', 'action_type', 'action_category', 'ip_address'];
            if (!in_array($orderBy, $allowedOrderBy, true)) {
                $orderBy = 'timestamp';
            }

            // Validate orderDir
            $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';

            $sql .= " ORDER BY {$orderBy} {$orderDir}";
            $sql .= ' LIMIT :limit OFFSET :offset';

            $params[':limit'] = max(1, min(1000, (int)$limit));
            $params[':offset'] = max(0, (int)$offset);

            $results = Database::query($sql, $params);

            // Decode JSON fields
            foreach ($results as &$result) {
                if (!empty($result['before_value'])) {
                    $result['before_value'] = json_decode($result['before_value'], true);
                }
                if (!empty($result['after_value'])) {
                    $result['after_value'] = json_decode($result['after_value'], true);
                }
                if (!empty($result['metadata'])) {
                    $result['metadata'] = json_decode($result['metadata'], true);
                }
            }

            return $results;
        } catch (\Exception $e) {
            error_log("AuditRepository: Failed to get audit logs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get count of audit logs matching filters
     * 
     * @param array $filters Same as getLogs()
     * @return int Count of matching logs
     */
    public function getLogCount(array $filters = []): int
    {
        if (!DatabaseHealth::isAvailable()) {
            return 0;
        }

        try {
            $sql = 'SELECT COUNT(*) as count FROM audit_log WHERE 1=1';
            $params = [];

            // Build WHERE clause (same as getLogs)
            if (!empty($filters['user_handle'])) {
                $sql .= ' AND user_handle = :user_handle';
                $params[':user_handle'] = $filters['user_handle'];
            }

            if (!empty($filters['user_id'])) {
                $sql .= ' AND user_id = :user_id';
                $params[':user_id'] = $filters['user_id'];
            }

            if (!empty($filters['action_type'])) {
                $sql .= ' AND action_type = :action_type';
                $params[':action_type'] = $filters['action_type'];
            }

            if (!empty($filters['action_category'])) {
                $sql .= ' AND action_category = :action_category';
                $params[':action_category'] = $filters['action_category'];
            }

            if (!empty($filters['resource_type'])) {
                $sql .= ' AND resource_type = :resource_type';
                $params[':resource_type'] = $filters['resource_type'];
            }

            if (!empty($filters['resource_id'])) {
                $sql .= ' AND resource_id = :resource_id';
                $params[':resource_id'] = $filters['resource_id'];
            }

            if (!empty($filters['ip_address'])) {
                $sql .= ' AND ip_address = :ip_address';
                $params[':ip_address'] = $filters['ip_address'];
            }

            if (!empty($filters['session_id'])) {
                $sql .= ' AND session_id = :session_id';
                $params[':session_id'] = $filters['session_id'];
            }

            if (isset($filters['success'])) {
                $sql .= ' AND success = :success';
                $params[':success'] = (bool)$filters['success'];
            }

            if (!empty($filters['start_date'])) {
                $sql .= ' AND timestamp >= :start_date';
                $params[':start_date'] = $filters['start_date'];
            }

            if (!empty($filters['end_date'])) {
                $sql .= ' AND timestamp <= :end_date';
                $params[':end_date'] = $filters['end_date'];
            }

            // Search term (same as getLogs)
            if (!empty($filters['search_term'])) {
                $searchTerm = '%' . $filters['search_term'] . '%';
                $sql .= ' AND (
                    user_handle LIKE :search_term1
                    OR action_type LIKE :search_term2
                    OR action_category LIKE :search_term3
                    OR resource_type LIKE :search_term4
                    OR resource_id LIKE :search_term5
                    OR ip_address LIKE :search_term6
                    OR user_agent LIKE :search_term7
                    OR error_message LIKE :search_term8
                    OR before_value LIKE :search_term9
                    OR after_value LIKE :search_term10
                    OR metadata LIKE :search_term11
                )';
                $params[':search_term1'] = $searchTerm;
                $params[':search_term2'] = $searchTerm;
                $params[':search_term3'] = $searchTerm;
                $params[':search_term4'] = $searchTerm;
                $params[':search_term5'] = $searchTerm;
                $params[':search_term6'] = $searchTerm;
                $params[':search_term7'] = $searchTerm;
                $params[':search_term8'] = $searchTerm;
                $params[':search_term9'] = $searchTerm;
                $params[':search_term10'] = $searchTerm;
                $params[':search_term11'] = $searchTerm;
            }

            $result = Database::queryOne($sql, $params);
            return (int)($result['count'] ?? 0);
        } catch (\Exception $e) {
            error_log("AuditRepository: Failed to get audit log count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get retention policies
     * 
     * @return array Array of retention policies
     */
    public function getRetentionPolicies(): array
    {
        if (!DatabaseHealth::isAvailable()) {
            return [];
        }

        try {
            $sql = 'SELECT * FROM audit_retention_policy ORDER BY action_category, action_type';
            return Database::query($sql);
        } catch (\Exception $e) {
            error_log("AuditRepository: Failed to get retention policies: " . $e->getMessage());
            return [];
        }
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
        if (!DatabaseHealth::isAvailable()) {
            return false;
        }

        try {
            $sql = 'UPDATE audit_retention_policy 
                    SET retention_days = :retention_days,
                        auto_purge = :auto_purge,
                        legal_hold = :legal_hold,
                        updated_at = NOW()
                    WHERE id = :id';

            Database::execute($sql, [
                ':id' => $policyId,
                ':retention_days' => $retentionDays,
                ':auto_purge' => $autoPurge ? 1 : 0,
                ':legal_hold' => $legalHold ? 1 : 0,
            ]);

            return true;
        } catch (\Exception $e) {
            error_log("AuditRepository: Failed to update retention policy: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Purge old audit logs based on retention policies
     * 
     * This method respects legal_hold flags and only purges logs that
     * have exceeded their retention period and are not on legal hold.
     * 
     * @return int Number of logs purged
     */
    public function purgeOldLogs(): int
    {
        if (!DatabaseHealth::isAvailable()) {
            return 0;
        }

        try {
            // Get all retention policies
            $policies = $this->getRetentionPolicies();
            $totalPurged = 0;

            foreach ($policies as $policy) {
                if (!$policy['auto_purge']) {
                    continue; // Skip policies that don't auto-purge
                }

                if ($policy['legal_hold']) {
                    continue; // Skip policies on legal hold
                }

                $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$policy['retention_days']} days"));

                $sql = 'DELETE FROM audit_log WHERE timestamp < :cutoff_date';
                $params = [':cutoff_date' => $cutoffDate];

                // Apply category filter
                if ($policy['action_category'] !== 'all') {
                    $sql .= ' AND action_category = :action_category';
                    $params[':action_category'] = $policy['action_category'];
                }

                // Apply action type filter if specified
                if (!empty($policy['action_type'])) {
                    $sql .= ' AND action_type = :action_type';
                    $params[':action_type'] = $policy['action_type'];
                }

                $purged = Database::execute($sql, $params);
                $totalPurged += $purged;
            }

            return $totalPurged;
        } catch (\Exception $e) {
            error_log("AuditRepository: Failed to purge old logs: " . $e->getMessage());
            return 0;
        }
    }
}

