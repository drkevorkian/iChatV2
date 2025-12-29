<?php
/**
 * Sentinel Chat Platform - Report Repository
 * 
 * Handles all database operations for user reports.
 * This repository uses prepared statements for all queries to prevent
 * SQL injection attacks.
 * 
 * Security: All queries use prepared statements. User input is never
 * directly concatenated into SQL queries.
 */

declare(strict_types=1);

namespace iChat\Repositories;

use iChat\Database;
use iChat\Services\DatabaseHealth;
use iChat\Services\FileStorage;

class ReportRepository
{
    private FileStorage $fileStorage;

    public function __construct()
    {
        $this->fileStorage = new FileStorage();
    }

    /**
     * Create a new user report
     * 
     * @param string $reportedUserHandle Handle of the user being reported
     * @param int|null $reportedUserId User ID if registered user
     * @param string $reporterHandle Handle of the user making the report
     * @param int|null $reporterUserId User ID if registered user
     * @param string $reportType Type of report
     * @param string $reportReason Reason for the report
     * @param string|null $roomId Room where incident occurred
     * @param int|null $messageId Message ID if report is about a specific message
     * @return int Report ID
     */
    public function createReport(
        string $reportedUserHandle,
        ?int $reportedUserId,
        string $reporterHandle,
        ?int $reporterUserId,
        string $reportType,
        string $reportReason,
        ?string $roomId = null,
        ?int $messageId = null
    ): int {
        if (DatabaseHealth::isAvailable()) {
            try {
                $db = Database::getConnection();
                $stmt = $db->prepare("
                    INSERT INTO user_reports 
                    (reported_user_handle, reported_user_id, reporter_handle, reporter_user_id, 
                     report_type, report_reason, room_id, message_id, status)
                    VALUES (:reported_user_handle, :reported_user_id, :reporter_handle, :reporter_user_id,
                            :report_type, :report_reason, :room_id, :message_id, 'pending')
                ");
                
                $stmt->execute([
                    ':reported_user_handle' => $reportedUserHandle,
                    ':reported_user_id' => $reportedUserId,
                    ':reporter_handle' => $reporterHandle,
                    ':reporter_user_id' => $reporterUserId,
                    ':report_type' => $reportType,
                    ':report_reason' => $reportReason,
                    ':room_id' => $roomId,
                    ':message_id' => $messageId
                ]);
                
                return (int)$db->lastInsertId();
            } catch (\PDOException $e) {
                error_log('ReportRepository::createReport database error: ' . $e->getMessage());
                // Fall through to file storage
            }
        }
        
        // File storage fallback
        $reportData = [
            'reported_user_handle' => $reportedUserHandle,
            'reported_user_id' => $reportedUserId,
            'reporter_handle' => $reporterHandle,
            'reporter_user_id' => $reporterUserId,
            'report_type' => $reportType,
            'report_reason' => $reportReason,
            'room_id' => $roomId,
            'message_id' => $messageId,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        return $this->fileStorage->queueReport($reportData);
    }

    /**
     * Get all pending reports (for admin)
     * 
     * @return array List of pending reports
     */
    public function getPendingReports(): array
    {
        if (DatabaseHealth::isAvailable()) {
            try {
                $db = Database::getConnection();
                $stmt = $db->prepare("
                    SELECT * FROM user_reports 
                    WHERE deleted_at IS NULL AND status = 'pending'
                    ORDER BY created_at DESC
                ");
                $stmt->execute();
                return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            } catch (\PDOException $e) {
                error_log('ReportRepository::getPendingReports database error: ' . $e->getMessage());
            }
        }
        
        return [];
    }

    /**
     * Get all reports (for admin)
     * 
     * @param string|null $status Filter by status
     * @return array List of reports
     */
    public function getAllReports(?string $status = null): array
    {
        if (DatabaseHealth::isAvailable()) {
            try {
                $db = Database::getConnection();
                
                if ($status !== null) {
                    $stmt = $db->prepare("
                        SELECT * FROM user_reports 
                        WHERE deleted_at IS NULL AND status = :status
                        ORDER BY created_at DESC
                    ");
                    $stmt->execute([':status' => $status]);
                } else {
                    $stmt = $db->prepare("
                        SELECT * FROM user_reports 
                        WHERE deleted_at IS NULL
                        ORDER BY created_at DESC
                    ");
                    $stmt->execute();
                }
                
                return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            } catch (\PDOException $e) {
                error_log('ReportRepository::getAllReports database error: ' . $e->getMessage());
            }
        }
        
        return [];
    }

    /**
     * Get count of pending reports
     * 
     * @return int Count of pending reports
     */
    public function getPendingCount(): int
    {
        if (DatabaseHealth::isAvailable()) {
            try {
                $db = Database::getConnection();
                $stmt = $db->prepare("
                    SELECT COUNT(*) as count FROM user_reports 
                    WHERE deleted_at IS NULL AND status = 'pending'
                ");
                $stmt->execute();
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                return (int)($result['count'] ?? 0);
            } catch (\PDOException $e) {
                error_log('ReportRepository::getPendingCount database error: ' . $e->getMessage());
            }
        }
        
        return 0;
    }

    /**
     * Update report status
     * 
     * @param int $reportId Report ID
     * @param string $status New status
     * @param string $reviewedBy Admin handle who reviewed
     * @param string|null $adminNotes Admin notes
     * @return bool Success
     */
    public function updateReportStatus(
        int $reportId,
        string $status,
        string $reviewedBy,
        ?string $adminNotes = null
    ): bool {
        if (DatabaseHealth::isAvailable()) {
            try {
                $db = Database::getConnection();
                $stmt = $db->prepare("
                    UPDATE user_reports 
                    SET status = :status, 
                        reviewed_at = NOW(), 
                        reviewed_by = :reviewed_by,
                        admin_notes = :admin_notes
                    WHERE id = :id AND deleted_at IS NULL
                ");
                
                return $stmt->execute([
                    ':id' => $reportId,
                    ':status' => $status,
                    ':reviewed_by' => $reviewedBy,
                    ':admin_notes' => $adminNotes
                ]);
            } catch (\PDOException $e) {
                error_log('ReportRepository::updateReportStatus database error: ' . $e->getMessage());
                return false;
            }
        }
        
        return false;
    }
}

