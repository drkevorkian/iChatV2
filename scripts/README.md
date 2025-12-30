# Sentinel Chat Platform - Scripts Directory

This directory contains command-line scripts for maintenance and automation tasks.

## Audit Log Purge Script

**File:** `purge_audit_logs.php`

**Purpose:** Automatically purges old audit logs based on retention policies configured in the database.

**Usage:**
```bash
php purge_audit_logs.php
```

**Cron Setup (Linux/Unix/MacOS):**

Add to crontab (`crontab -e`):
```
# Purge audit logs daily at 2 AM
0 2 * * * /usr/bin/php /path/to/iChat/scripts/purge_audit_logs.php >> /var/log/ichat_audit_purge.log 2>&1
```

**Windows Task Scheduler:**

1. Open Task Scheduler
2. Create Basic Task
3. Set trigger (e.g., Daily at 2:00 AM)
4. Action: Start a program
5. Program: `php.exe`
6. Arguments: `C:\wamp64\www\iChat\scripts\purge_audit_logs.php`
7. Start in: `C:\wamp64\www\iChat\scripts`

**Log Output:**

The script logs to: `logs/audit_purge.log`

**What It Does:**

- Reads retention policies from `audit_retention_policy` table
- Respects `legal_hold` flags (never purges logs on legal hold)
- Only purges logs where `auto_purge` is enabled
- Applies category and action type filters from policies
- Reports number of logs purged

**Security:**

- Uses prepared statements
- Respects legal hold flags
- Logs all operations
- Safe to run automatically

