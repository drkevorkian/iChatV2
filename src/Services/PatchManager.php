<?php
/**
 * Sentinel Chat Platform - Patch Manager
 * 
 * Manages database patches/migrations with tracking, logging, and rollback support.
 * Tracks applied patches in database and log files.
 * 
 * Security: All SQL execution uses prepared statements where possible.
 * Patches are validated before execution.
 */

declare(strict_types=1);

namespace iChat\Services;

use iChat\Database;
use iChat\Repositories\PatchRepository;

class PatchManager
{
    private PatchRepository $patchRepo;
    private string $patchesDir;
    private string $logFile;

    public function __construct()
    {
        $this->patchRepo = new PatchRepository();
        $this->patchesDir = ICHAT_ROOT . '/patches';
        $this->logFile = ICHAT_ROOT . '/logs/patches.log';
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
    }

    /**
     * Apply a patch file
     * 
     * @param string $patchId Patch identifier (e.g., '001_add_room_presence')
     * @return array Result with success status and details
     */
    public function applyPatch(string $patchId): array
    {
        $patchFile = $this->patchesDir . '/' . $patchId . '.sql';
        $infoFile = $this->patchesDir . '/' . $patchId . '.info.json';
        
        if (!file_exists($patchFile)) {
            return [
                'success' => false,
                'error' => "Patch file not found: {$patchId}.sql",
            ];
        }
        
        // Check if already applied
        if ($this->isPatchApplied($patchId)) {
            return [
                'success' => false,
                'error' => "Patch {$patchId} has already been applied",
                'already_applied' => true,
            ];
        }
        
        // Load patch info
        $patchInfo = $this->loadPatchInfo($patchId);
        
        // Check dependencies
        $dependencyCheck = $this->checkDependencies($patchInfo['dependencies'] ?? []);
        if (!$dependencyCheck['satisfied']) {
            return [
                'success' => false,
                'error' => "Dependencies not satisfied: " . implode(', ', $dependencyCheck['missing']),
            ];
        }
        
        // Read SQL file
        $sql = file_get_contents($patchFile);
        if ($sql === false) {
            return [
                'success' => false,
                'error' => "Failed to read patch file",
            ];
        }
        
        // Execute patch
        $startTime = microtime(true);
        $result = $this->executePatch($sql, $patchId);
        $duration = microtime(true) - $startTime;
        
        if ($result['success']) {
            // Record patch application
            $this->recordPatchApplied($patchId, $patchInfo, $duration);
            
            // Log to file
            $this->logPatch($patchId, $patchInfo, 'APPLIED', $duration);
            
            return [
                'success' => true,
                'patch_id' => $patchId,
                'duration' => round($duration, 3),
                'info' => $patchInfo,
            ];
        }
        
        return $result;
    }

    /**
     * Check if a patch has been applied
     * 
     * @param string $patchId Patch identifier
     * @return bool True if applied
     */
    public function isPatchApplied(string $patchId): bool
    {
        try {
            return $this->patchRepo->isPatchApplied($patchId);
        } catch (\Exception $e) {
            // If patch_history table doesn't exist yet, assume patch is not applied
            error_log('isPatchApplied check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get list of available patches
     * 
     * @return array Array of patch information
     */
    public function getAvailablePatches(): array
    {
        $patches = [];
        $files = glob($this->patchesDir . '/*.sql');
        
        foreach ($files as $file) {
            $patchId = basename($file, '.sql');
            $info = $this->loadPatchInfo($patchId);
            // Ensure patch_id is always a string
            $info['patch_id'] = (string)$patchId;
            $info['applied'] = $this->isPatchApplied($patchId);
            $info['applied_date'] = $this->patchRepo->getAppliedDate($patchId);
            $patches[] = $info;
        }
        
        // Sort by patch ID (ensure patch_id is always a string)
        // Convert all patch_ids to strings before sorting to prevent type errors
        foreach ($patches as &$patch) {
            if (isset($patch['patch_id'])) {
                $patch['patch_id'] = (string)$patch['patch_id'];
            }
        }
        unset($patch); // Break reference
        
        usort($patches, function($a, $b) {
            $aId = (string)($a['patch_id'] ?? '');
            $bId = (string)($b['patch_id'] ?? '');
            return strcmp($aId, $bId);
        });
        
        return $patches;
    }

    /**
     * Get list of applied patches
     * 
     * @return array Array of applied patch information
     */
    public function getAppliedPatches(): array
    {
        return $this->patchRepo->getAllAppliedPatches();
    }

    /**
     * Get patch log URL (for viewing without downloading)
     * 
     * @param string $patchId Patch identifier
     * @return string|null Log URL or null if not found
     */
    public function getPatchLogUrl(string $patchId): ?string
    {
        // Return URL to view log (handled by patch-log.php endpoint)
        $config = \iChat\Config::getInstance();
        $apiBase = $config->get('app.api_base');
        return $apiBase . '/patch-log.php?patch_id=' . urlencode($patchId);
    }
    
    /**
     * Get patch information with log URL
     * 
     * @param string $patchId Patch identifier
     * @return array Patch information including log URL
     */
    public function getPatchInfo(string $patchId): array
    {
        $patches = $this->getAvailablePatches();
        foreach ($patches as $patch) {
            if ($patch['patch_id'] === $patchId) {
                $patch['log_url'] = $this->getPatchLogUrl($patchId);
                $patch['has_logs'] = !empty($this->getPatchLogs($patchId));
                return $patch;
            }
        }
        
        return [];
    }

    /**
     * Load patch info from JSON file
     * 
     * @param string $patchId Patch identifier
     * @return array Patch information
     */
    private function loadPatchInfo(string $patchId): array
    {
        $infoFile = $this->patchesDir . '/' . $patchId . '.info.json';
        
        if (!file_exists($infoFile)) {
            return [
                'patch_id' => (string)$patchId,
                'description' => 'No description available',
                'dependencies' => [],
            ];
        }
        
        $content = file_get_contents($infoFile);
        if ($content === false) {
            return ['patch_id' => (string)$patchId];
        }
        
        $info = json_decode($content, true);
        if (is_array($info)) {
            // Ensure patch_id is always a string
            $info['patch_id'] = (string)($info['patch_id'] ?? $patchId);
            return $info;
        }
        return ['patch_id' => (string)$patchId];
    }

    /**
     * Check if patch dependencies are satisfied
     * 
     * @param array $dependencies Array of patch IDs that must be applied first
     * @return array Result with 'satisfied' bool and 'missing' array
     */
    private function checkDependencies(array $dependencies): array
    {
        $missing = [];
        
        foreach ($dependencies as $dep) {
            // Ensure dependency is a string
            $depId = (string)$dep;
            if (!$this->isPatchApplied($depId)) {
                $missing[] = $depId;
            }
        }
        
        return [
            'satisfied' => empty($missing),
            'missing' => $missing,
        ];
    }

    /**
     * Execute patch SQL
     * 
     * Executes SQL statements. Note: DDL statements (CREATE TABLE, etc.)
     * cannot use prepared statements and auto-commit in MySQL, so they are
     * executed directly without transactions. DML statements use transactions
     * and prepared statements where possible.
     * 
     * @param string $sql SQL to execute
     * @param string $patchId Patch identifier
     * @return array Result with success status
     */
    private function executePatch(string $sql, string $patchId): array
    {
        try {
            // Split SQL into individual statements
            $statements = $this->splitSqlStatements($sql);
            
            $conn = Database::getConnection();
            $hasDDL = false;
            $hasDML = false;
            
            // Check if patch contains DDL statements
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (empty($statement) || strpos($statement, '--') === 0) {
                    continue;
                }
                
                $isDDL = preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\s+/i', $statement);
                if ($isDDL) {
                    $hasDDL = true;
                } else {
                    $hasDML = true;
                }
            }
            
            // DDL statements auto-commit in MySQL, so we can't use transactions
            // Execute DDL statements first (outside transaction)
            $ddlIndex = 0;
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (empty($statement) || strpos($statement, '--') === 0) {
                    continue;
                }
                
                $isDDL = preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\s+/i', $statement);
                
                if ($isDDL) {
                    // Execute DDL directly (auto-commits in MySQL)
                    $ddlIndex++;
                    try {
                        $conn->exec($statement);
                    } catch (\Exception $ddlException) {
                        // Log the failing DDL statement for debugging
                        error_log("Patch {$patchId} - Failed DDL statement #{$ddlIndex}: " . substr($statement, 0, 200));
                        error_log("Patch {$patchId} - Full DDL error: " . $ddlException->getMessage());
                        throw $ddlException;
                    }
                }
            }
            
            // Execute DML statements in a transaction if present
            // Note: PREPARE/EXECUTE statements are part of DDL operations and auto-commit
            // Only start a transaction if we have actual DML (INSERT/UPDATE/DELETE) statements
            if ($hasDML) {
                // Check if we're already in a transaction (shouldn't be, but be safe)
                if (!Database::inTransaction()) {
                    Database::beginTransaction();
                }
                
                try {
                    $stmtIndex = 0;
                    foreach ($statements as $statement) {
                        $statement = trim($statement);
                        if (empty($statement) || strpos($statement, '--') === 0) {
                            continue;
                        }
                        
                        // Skip DDL statements (already executed)
                        // PREPARE/EXECUTE/DEALLOCATE are part of DDL operations and auto-commit
                        // SET @variable statements are also part of DDL operations
                        $isDDL = preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE|PREPARE|EXECUTE|DEALLOCATE|SET\s+@|SELECT\s+COUNT\(\*\)\s+INTO\s+@)/i', $statement);
                        
                        if (!$isDDL) {
                            // For INSERT/UPDATE/DELETE with literal values in patches, use exec() directly
                            // Database::execute() expects prepared statements with placeholders
                            // Patches contain raw SQL, so we use exec() for consistency
                            $stmtIndex++;
                            try {
                                $conn->exec($statement);
                            } catch (\Exception $stmtException) {
                                // Log the failing statement for debugging (full statement, not truncated)
                                error_log("Patch {$patchId} - Failed statement #{$stmtIndex}: " . $statement);
                                error_log("Patch {$patchId} - Statement length: " . strlen($statement));
                                error_log("Patch {$patchId} - Full error: " . $stmtException->getMessage());
                                throw $stmtException;
                            }
                        }
                    }
                    
                    // Only commit if we're in a transaction
                    if (Database::inTransaction()) {
                        Database::commit();
                    }
                } catch (\Exception $e) {
                    // Only rollback if we're actually in a transaction
                    if (Database::inTransaction()) {
                        try {
                            Database::rollback();
                        } catch (\Exception $rollbackException) {
                            // Ignore rollback errors (transaction may have already been committed)
                            error_log("Rollback failed (may be expected): " . $rollbackException->getMessage());
                        }
                    }
                    throw $e;
                }
            }
            
            return ['success' => true];
        } catch (\Exception $e) {
            $errorMsg = $e->getMessage();
            error_log("Patch execution failed for {$patchId}: " . $errorMsg);
            
            // Check if this is a PDO exception with more details
            if ($e instanceof \PDOException && $e->errorInfo) {
                $errorMsg .= " (Error Code: " . ($e->errorInfo[1] ?? 'N/A') . ")";
            }
            
            return [
                'success' => false,
                'error' => $errorMsg,
            ];
        }
    }

    /**
     * Split SQL into individual statements
     * 
     * @param string $sql SQL content
     * @return array Array of SQL statements
     */
    private function splitSqlStatements(string $sql): array
    {
        // Remove comments (but preserve quoted strings that might contain --)
        $sql = preg_replace('/--.*$/m', '', $sql);
        
        // Split by semicolon, but respect quoted strings
        // This regex matches semicolons that are NOT inside single or double quotes
        // It uses a lookahead to ensure the semicolon is followed by only non-quoted content
        $statements = [];
        $current = '';
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inBacktick = false;
        
        for ($i = 0; $i < strlen($sql); $i++) {
            $char = $sql[$i];
            $prevChar = $i > 0 ? $sql[$i - 1] : '';
            
            // Handle escape sequences
            if ($prevChar === '\\') {
                $current .= $char;
                continue;
            }
            
            // Toggle quote states
            if ($char === "'" && !$inDoubleQuote && !$inBacktick) {
                $inSingleQuote = !$inSingleQuote;
            } elseif ($char === '"' && !$inSingleQuote && !$inBacktick) {
                $inDoubleQuote = !$inDoubleQuote;
            } elseif ($char === '`' && !$inSingleQuote && !$inDoubleQuote) {
                $inBacktick = !$inBacktick;
            }
            
            // Split on semicolon only if not inside quotes
            if ($char === ';' && !$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
                $trimmed = trim($current);
                if (!empty($trimmed)) {
                    $statements[] = $trimmed;
                }
                $current = '';
            } else {
                $current .= $char;
            }
        }
        
        // Add remaining content
        $trimmed = trim($current);
        if (!empty($trimmed)) {
            $statements[] = $trimmed;
        }
        
        return $statements;
    }

    /**
     * Record patch as applied in database
     * 
     * @param string $patchId Patch identifier
     * @param array $patchInfo Patch information
     * @param float $duration Execution duration in seconds
     */
    private function recordPatchApplied(string $patchId, array $patchInfo, float $duration): void
    {
        try {
            $this->patchRepo->recordApplied($patchId, $patchInfo, $duration);
        } catch (\Exception $e) {
            // If patch_history table doesn't exist yet, that's OK - the patch itself creates it
            error_log('Failed to record patch application (may be expected): ' . $e->getMessage());
        }
    }

    /**
     * Reset a test patch (remove from patch_history and optionally run rollback SQL)
     * 
     * This is useful for test patches that can be reapplied multiple times.
     * Only works for patches that start with "999_" (test patches).
     * 
     * @param string $patchId Patch identifier (must start with "999_")
     * @param bool $runRollback Whether to execute rollback SQL if available
     * @return array Result with success status
     */
    public function resetTestPatch(string $patchId, bool $runRollback = true): array
    {
        // Only allow resetting test patches (those starting with "999_")
        if (strpos($patchId, '999_') !== 0) {
            return [
                'success' => false,
                'error' => 'Only test patches (starting with 999_) can be reset',
            ];
        }
        
        try {
            // Check if patch is applied
            if (!$this->isPatchApplied($patchId)) {
                return [
                    'success' => false,
                    'error' => 'Patch is not applied, nothing to reset',
                ];
            }
            
            // Run rollback SQL if available and requested
            if ($runRollback) {
                $rollbackFile = $this->patchesDir . '/rollback/' . $patchId . '_rollback.sql';
                if (file_exists($rollbackFile)) {
                    $rollbackSql = file_get_contents($rollbackFile);
                    if ($rollbackSql !== false) {
                        $this->executePatch($rollbackSql, $patchId . '_rollback');
                    }
                }
            }
            
            // Remove from patch_history
            $this->patchRepo->removePatch($patchId);
            
            return [
                'success' => true,
                'message' => 'Test patch reset successfully',
            ];
        } catch (\Exception $e) {
            error_log("Test patch reset failed for {$patchId}: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Log patch application to file
     * 
     * @param string $patchId Patch identifier
     * @param array $patchInfo Patch information
     * @param string $status Status (APPLIED, ROLLED_BACK, etc.)
     * @param float $duration Execution duration
     */
    private function logPatch(string $patchId, array $patchInfo, string $status, float $duration): void
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'patch_id' => $patchId,
            'status' => $status,
            'description' => $patchInfo['description'] ?? 'No description',
            'version' => $patchInfo['version'] ?? '1.0.0',
            'duration' => round($duration, 3),
            'files_changed' => $patchInfo['files_changed'] ?? [],
            'database_changes' => $patchInfo['database_changes'] ?? [],
        ];
        
        $logLine = json_encode($logEntry, JSON_PRETTY_PRINT) . "\n" . str_repeat('-', 80) . "\n";
        file_put_contents($this->logFile, $logLine, FILE_APPEND);
    }

    /**
     * Get patch log entries
     * 
     * @param string|null $patchId Filter by patch ID (optional)
     * @return array Array of log entries
     */
    public function getPatchLogs(?string $patchId = null): array
    {
        if (!file_exists($this->logFile)) {
            return [];
        }
        
        $content = file_get_contents($this->logFile);
        $entries = [];
        $blocks = explode(str_repeat('-', 80), $content);
        
        foreach ($blocks as $block) {
            $block = trim($block);
            if (empty($block)) {
                continue;
            }
            
            $entry = json_decode($block, true);
            if (is_array($entry)) {
                if ($patchId === null || ($entry['patch_id'] ?? '') === $patchId) {
                    $entries[] = $entry;
                }
            }
        }
        
        return array_reverse($entries); // Most recent first
    }
    
    /**
     * Rollback an applied patch (unapply it)
     * 
     * Executes rollback SQL if available and removes patch from patch_history,
     * allowing it to be reapplied.
     * 
     * @param string $patchId Patch identifier
     * @return array Result with success status
     */
    public function rollbackPatch(string $patchId): array
    {
        try {
            // Check if patch is applied
            if (!$this->isPatchApplied($patchId)) {
                return [
                    'success' => false,
                    'error' => 'Patch is not applied, nothing to rollback',
                ];
            }
            
            // Load patch info to check rollback availability
            $patchInfo = $this->loadPatchInfo($patchId);
            $rollbackAvailable = $patchInfo['rollback_available'] ?? false;
            
            // Check if rollback file exists
            $rollbackFile = $this->patchesDir . '/rollback/' . $patchId . '_rollback.sql';
            $hasRollbackFile = file_exists($rollbackFile);
            
            if (!$rollbackAvailable && !$hasRollbackFile) {
                return [
                    'success' => false,
                    'error' => 'Rollback not available for this patch (no rollback SQL file found)',
                ];
            }
            
            // Run rollback SQL if available
            if ($hasRollbackFile) {
                $rollbackSql = file_get_contents($rollbackFile);
                if ($rollbackSql === false) {
                    return [
                        'success' => false,
                        'error' => 'Failed to read rollback SQL file',
                    ];
                }
                
                $startTime = microtime(true);
                $rollbackResult = $this->executePatch($rollbackSql, $patchId . '_rollback');
                $duration = microtime(true) - $startTime;
                
                if (!$rollbackResult['success']) {
                    return [
                        'success' => false,
                        'error' => 'Rollback SQL execution failed: ' . ($rollbackResult['error'] ?? 'Unknown error'),
                    ];
                }
                
                // Log rollback execution
                $this->logPatch($patchId, $patchInfo, 'ROLLED BACK', $duration);
            }
            
            // Remove from patch_history (allows patch to be reapplied)
            $removed = $this->patchRepo->removePatch($patchId);
            
            if (!$removed) {
                return [
                    'success' => false,
                    'error' => 'Failed to remove patch from history',
                ];
            }
            
            return [
                'success' => true,
                'message' => 'Patch rolled back successfully. It can now be reapplied.',
                'rollback_executed' => $hasRollbackFile,
            ];
        } catch (\Exception $e) {
            error_log("Patch rollback failed for {$patchId}: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Rollback failed: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Check if a patch has rollback available
     * 
     * @param string $patchId Patch identifier
     * @return bool True if rollback is available
     */
    public function hasRollback(string $patchId): bool
    {
        // Check patch info
        $patchInfo = $this->loadPatchInfo($patchId);
        if (($patchInfo['rollback_available'] ?? false) === true) {
            return true;
        }
        
        // Check if rollback file exists
        $rollbackFile = $this->patchesDir . '/rollback/' . $patchId . '_rollback.sql';
        return file_exists($rollbackFile);
    }
}

