<?php
/**
 * Sentinel Chat Platform - Log Rotation Service
 * 
 * Manages log file rotation by gzipping logs when they exceed a certain size
 * and archiving them by date. Keeps current logs manageable in size.
 * 
 * Security: Log files may contain sensitive information. Archived logs are
 * stored securely and only accessible to authorized users.
 */

declare(strict_types=1);

namespace iChat\Services;

class LogRotationService
{
    private string $logsDir;
    private int $maxLines;
    private const ARCHIVE_DIR = 'archived';

    /**
     * Constructor
     * 
     * @param string $logsDir Directory where logs are stored
     * @param int $maxLines Maximum lines before rotation (default: 5000)
     */
    public function __construct(?string $logsDir = null, int $maxLines = 5000)
    {
        $this->logsDir = $logsDir ?? ICHAT_ROOT . '/logs';
        $this->maxLines = $maxLines;
        
        // Ensure logs directory exists
        if (!is_dir($this->logsDir)) {
            @mkdir($this->logsDir, 0755, true);
        }
        
        // Ensure archive directory exists
        $archiveDir = $this->logsDir . '/' . self::ARCHIVE_DIR;
        if (!is_dir($archiveDir)) {
            @mkdir($archiveDir, 0755, true);
        }
    }

    /**
     * Check and rotate log file if necessary
     * 
     * Checks if the log file exceeds maxLines and rotates it if needed.
     * This should be called before writing to the log file.
     * 
     * @param string $logFile Path to log file (relative to logsDir or absolute)
     * @return bool True if rotation occurred, false otherwise
     */
    public function rotateIfNeeded(string $logFile): bool
    {
        // Resolve full path
        $fullPath = $this->resolveLogPath($logFile);
        
        // If file doesn't exist or is empty, no rotation needed
        if (!file_exists($fullPath) || filesize($fullPath) === 0) {
            return false;
        }
        
        // Count lines in file
        $lineCount = $this->countLines($fullPath);
        
        // If under limit, no rotation needed
        if ($lineCount < $this->maxLines) {
            return false;
        }
        
        // Rotate the log file (pass lineCount for logging)
        return $this->rotate($fullPath, $lineCount);
    }

    /**
     * Rotate a log file by gzipping it with date stamp
     * 
     * @param string $logFile Full path to log file
     * @param int $lineCount Number of lines in the file (for logging)
     * @return bool True on success, false on failure
     */
    private function rotate(string $logFile, int $lineCount): bool
    {
        try {
            // Generate archive filename with date stamp
            $basename = basename($logFile);
            $date = date('Y-m-d_His');
            $archiveName = $basename . '.' . $date . '.gz';
            $archivePath = $this->logsDir . '/' . self::ARCHIVE_DIR . '/' . $archiveName;
            
            // Open source file for reading
            $sourceHandle = @fopen($logFile, 'rb');
            if ($sourceHandle === false) {
                error_log("LogRotationService: Failed to open log file for reading: {$logFile}");
                return false;
            }
            
            // Open destination file for writing (gzip)
            $destHandle = @gzopen($archivePath, 'wb9'); // Compression level 9
            if ($destHandle === false) {
                fclose($sourceHandle);
                error_log("LogRotationService: Failed to create gzip archive: {$archivePath}");
                return false;
            }
            
            // Copy content from source to gzip archive
            while (!feof($sourceHandle)) {
                $chunk = fread($sourceHandle, 8192); // 8KB chunks
                if ($chunk !== false) {
                    gzwrite($destHandle, $chunk);
                }
            }
            
            // Close handles
            fclose($sourceHandle);
            gzclose($destHandle);
            
            // Truncate original log file (keep it but empty)
            $truncateHandle = @fopen($logFile, 'wb');
            if ($truncateHandle !== false) {
                fclose($truncateHandle);
            }
            
            // Log the rotation (to a separate rotation log to avoid recursion)
            $this->logRotation($basename, $archiveName, $lineCount);
            
            return true;
        } catch (\Exception $e) {
            error_log("LogRotationService: Exception during rotation: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Count lines in a file efficiently
     * 
     * @param string $filePath Path to file (can be relative to logsDir or absolute)
     * @return int Number of lines
     */
    public function countLines(string $filePath): int
    {
        $fullPath = $this->resolveLogPath($filePath);
        
        // Handle gzipped files
        if (substr($fullPath, -3) === '.gz') {
            $count = 0;
            $handle = @gzopen($fullPath, 'rb');
            if ($handle === false) {
                return 0;
            }
            
            while (!gzeof($handle)) {
                $chunk = gzread($handle, 8192);
                if ($chunk !== false) {
                    $count += substr_count($chunk, "\n");
                }
            }
            
            gzclose($handle);
            return $count;
        }
        
        // Regular file
        $count = 0;
        $handle = @fopen($fullPath, 'rb');
        
        if ($handle === false) {
            return 0;
        }
        
        // Count newlines efficiently
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if ($chunk !== false) {
                $count += substr_count($chunk, "\n");
            }
        }
        
        fclose($handle);
        return $count;
    }

    /**
     * Resolve log file path (handle relative and absolute paths)
     * 
     * @param string $logFile Log file path
     * @return string Full resolved path
     */
    private function resolveLogPath(string $logFile): string
    {
        // If absolute path, use as-is
        if (strpos($logFile, '/') === 0 || (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Z]:/i', $logFile))) {
            return $logFile;
        }
        
        // Otherwise, relative to logsDir
        return $this->logsDir . '/' . $logFile;
    }

    /**
     * Log rotation event to a separate rotation log
     * 
     * @param string $logName Original log file name
     * @param string $archiveName Archive file name
     * @param int $lineCount Number of lines archived
     */
    private function logRotation(string $logName, string $archiveName, int $lineCount): void
    {
        $rotationLog = $this->logsDir . '/rotation.log';
        $message = sprintf(
            "[%s] Rotated log: %s -> %s (%d lines)\n",
            date('Y-m-d H:i:s'),
            $logName,
            $archiveName,
            $lineCount
        );
        
        @file_put_contents($rotationLog, $message, FILE_APPEND | LOCK_EX);
    }

    /**
     * Read lines from a log file (handles both regular and gzipped files)
     * 
     * @param string $logFile Log file path (can be .gz)
     * @param int $limit Maximum number of lines to read (0 = all)
     * @param int $offset Number of lines to skip from start
     * @return array Array of log lines
     */
    public function readLog(string $logFile, int $limit = 0, int $offset = 0): array
    {
        $fullPath = $this->resolveLogPath($logFile);
        
        // Check if file exists
        if (!file_exists($fullPath)) {
            return [];
        }
        
        $lines = [];
        
        // Handle gzipped files
        if (substr($fullPath, -3) === '.gz') {
            $handle = @gzopen($fullPath, 'rb');
            if ($handle === false) {
                return [];
            }
            
            $currentLine = 0;
            while (!gzeof($handle)) {
                $line = gzgets($handle);
                if ($line === false) {
                    break;
                }
                
                // Skip lines before offset
                if ($currentLine < $offset) {
                    $currentLine++;
                    continue;
                }
                
                // Stop if limit reached
                if ($limit > 0 && count($lines) >= $limit) {
                    break;
                }
                
                $lines[] = rtrim($line, "\r\n");
                $currentLine++;
            }
            
            gzclose($handle);
        } else {
            // Handle regular files
            $handle = @fopen($fullPath, 'rb');
            if ($handle === false) {
                return [];
            }
            
            $currentLine = 0;
            while (!feof($handle)) {
                $line = fgets($handle);
                if ($line === false) {
                    break;
                }
                
                // Skip lines before offset
                if ($currentLine < $offset) {
                    $currentLine++;
                    continue;
                }
                
                // Stop if limit reached
                if ($limit > 0 && count($lines) >= $limit) {
                    break;
                }
                
                $lines[] = rtrim($line, "\r\n");
                $currentLine++;
            }
            
            fclose($handle);
        }
        
        return $lines;
    }

    /**
     * Get list of archived log files
     * 
     * @param string $logName Base log file name (e.g., 'error.log')
     * @return array Array of archive file names with metadata
     */
    public function getArchivedLogs(string $logName): array
    {
        $archiveDir = $this->logsDir . '/' . self::ARCHIVE_DIR;
        $archives = [];
        
        if (!is_dir($archiveDir)) {
            return [];
        }
        
        $pattern = $archiveDir . '/' . preg_quote($logName, '/') . '\..*\.gz';
        $files = glob($pattern);
        
        foreach ($files as $file) {
            $archives[] = [
                'filename' => basename($file),
                'path' => $file,
                'size' => filesize($file),
                'modified' => filemtime($file),
            ];
        }
        
        // Sort by modified time (newest first)
        usort($archives, function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
        
        return $archives;
    }

    /**
     * Clean up old archived logs (older than specified days)
     * 
     * @param int $daysOld Number of days to keep (default: 30)
     * @return int Number of files deleted
     */
    public function cleanupOldArchives(int $daysOld = 30): int
    {
        $archiveDir = $this->logsDir . '/' . self::ARCHIVE_DIR;
        $cutoffTime = time() - ($daysOld * 24 * 60 * 60);
        $deleted = 0;
        
        if (!is_dir($archiveDir)) {
            return 0;
        }
        
        $files = glob($archiveDir . '/*.gz');
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }
        
        return $deleted;
    }
}

