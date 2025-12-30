# Audit Logging & Compliance System

## Overview

The Sentinel Chat Platform includes a comprehensive audit logging system that tracks all significant user and administrative actions for compliance, security monitoring, and troubleshooting.

## Features

### 1. Comprehensive Event Logging

All of the following actions are logged:

#### Authentication Events
- **Login** (`LOGIN`) - Successful user login
- **Failed Login** (`FAILED_LOGIN`) - Failed login attempts
- **Logout** (`LOGOUT`) - User logout

#### Message Events
- **Message Send** (`message_send`) - Room messages and IMs
- **Message Edit** (`message_edit`) - Message edits with before/after values
- **Message Delete** (`message_delete`) - Message deletions (soft-delete)

#### File Events
- **File Upload** (`file_upload`) - Media uploads (images, videos, audio)
- **File Download** (`file_download`) - Media file downloads

#### Room Events
- **Room Join** (`room_join`) - User joins a room (first presence update only)
- **Room Leave** (`room_leave`) - User leaves a room

#### Moderation Events
- **Kick** (`kick`) - User kicked from room
- **Mute** (`mute`) - User muted
- **Ban** (`ban`) - User banned
- **Unban** (`unban`) - User unbanned
- **Hide Message** (`hide`) - Message hidden by moderator
- **Delete Message** (`delete`) - Message deleted by moderator
- **Edit Message** (`edit`) - Message edited by moderator
- **Mock Message** (`mock`) - Message mocked by moderator

#### Admin Events
- **Admin Change** (`admin_change`) - User role/permission changes (when implemented)

### 2. Audit Log Data Structure

Each audit log entry includes:
- **ID** - Unique log entry ID
- **Timestamp** - When the action occurred
- **User Handle** - Username/handle of the user who performed the action
- **User ID** - Database user ID (if registered user)
- **Action Type** - Type of action (e.g., `LOGIN`, `message_send`)
- **Action Category** - Category (`authentication`, `message`, `file`, `room`, `admin`, `moderation`, `system`, `other`)
- **Resource Type** - Type of resource affected (e.g., `message`, `user`, `file`)
- **Resource ID** - ID of the affected resource
- **IP Address** - IP address of the user
- **User Agent** - Browser/client user agent
- **Session ID** - Session identifier
- **Success** - Whether the action succeeded
- **Error Message** - Error message if action failed
- **Before Value** - Previous value (for edits)
- **After Value** - New value (for edits)
- **Metadata** - Additional JSON metadata

### 3. Searchable Admin Dashboard

The audit logs are accessible via the Admin Dashboard under **System > Audit Logs**.

Features:
- **Full-text search** across all log fields
- **Advanced filtering** by:
  - User handle
  - Action type
  - Action category
  - Date range
  - Success/failure status
- **Pagination** (50/100/200/500 per page)
- **Export options**:
  - JSON export
  - CSV export
  - PDF export with digital signature
- **Detailed log view** - Click any log entry to see full details

### 4. Retention Policies

Retention policies control how long audit logs are kept before automatic purging.

**Policy Configuration:**
- **Policy Name** - Descriptive name
- **Action Category** - Category to apply policy to (`all` for all categories)
- **Action Type** - Specific action type (optional)
- **Retention Days** - Number of days to retain logs
- **Auto Purge** - Whether to automatically purge expired logs
- **Legal Hold** - If enabled, logs are never purged (compliance requirement)

**Default Policies:**
- Authentication events: 90 days
- Message events: 2555 days (7 years)
- File events: 2555 days (7 years)
- Room events: 90 days
- Admin events: 2555 days (7 years)
- Moderation events: 2555 days (7 years)
- System events: 365 days
- Other events: 90 days

### 5. Automated Log Purging

A cron job script (`scripts/purge_audit_logs.php`) automatically purges old logs based on retention policies.

**Setup:**
```bash
# Linux/Unix/MacOS - Add to crontab
0 2 * * * /usr/bin/php /path/to/iChat/scripts/purge_audit_logs.php >> /var/log/ichat_audit_purge.log 2>&1

# Windows - Use Task Scheduler
# Program: php.exe
# Arguments: C:\wamp64\www\iChat\scripts\purge_audit_logs.php
```

**What It Does:**
- Reads all retention policies
- Respects legal hold flags (never purges)
- Only purges logs where `auto_purge` is enabled
- Applies category and action type filters
- Logs all operations to `logs/audit_purge.log`

### 6. API Endpoints

#### List Logs
```
GET /api/audit.php?action=list&[filters]
```

**Query Parameters:**
- `user_handle` - Filter by user handle
- `action_type` - Filter by action type
- `action_category` - Filter by category
- `start_date` - Start date (YYYY-MM-DD HH:MM:SS)
- `end_date` - End date (YYYY-MM-DD HH:MM:SS)
- `success` - Filter by success (1/0)
- `search_term` - Full-text search
- `limit` - Results per page (default: 100)
- `offset` - Pagination offset
- `order_by` - Sort column (default: timestamp)
- `order_dir` - Sort direction (ASC/DESC)

#### Export Logs
```
GET /api/audit.php?action=export&format=[json|csv|pdf]&[filters]
```

#### Get Statistics
```
GET /api/audit.php?action=stats
```

#### Get Retention Policies
```
GET /api/audit.php?action=retention-policies
```

#### Update Retention Policy
```
POST /api/audit.php?action=retention-policies
Content-Type: application/json

{
  "id": 1,
  "retention_days": 90,
  "auto_purge": true,
  "legal_hold": false
}
```

#### Purge Old Logs
```
POST /api/audit.php?action=purge-logs
```

### 7. Security

- **Access Control**: Only administrators, trusted admins, and owners can access audit logs
- **Prepared Statements**: All database queries use prepared statements
- **Input Validation**: All user input is sanitized
- **Digital Signatures**: PDF exports include digital signatures for compliance
- **Legal Hold**: Logs on legal hold are never purged

### 8. Integration Points

#### API Endpoints with Audit Logging

1. **api/auth.php**
   - Login/logout events

2. **api/messages.php**
   - Message send events

3. **api/im.php**
   - IM send events

4. **api/message-edit.php**
   - Message edit/delete events

5. **api/moderate.php**
   - Moderation actions (hide, delete, edit, mock)

6. **api/chat-media.php**
   - File upload/download events

7. **api/presence.php**
   - Room join/leave events

8. **api/user-management.php**
   - Kick, mute, ban, unban actions

### 9. Database Schema

#### audit_log Table
```sql
CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  user_handle VARCHAR(255),
  user_id INT UNSIGNED,
  action_type VARCHAR(100),
  action_category ENUM('authentication', 'message', 'file', 'room', 'admin', 'moderation', 'system', 'other'),
  resource_type VARCHAR(100),
  resource_id VARCHAR(255),
  ip_address VARCHAR(45),
  user_agent TEXT,
  session_id VARCHAR(255),
  success BOOLEAN DEFAULT TRUE,
  error_message TEXT,
  before_value JSON,
  after_value JSON,
  metadata JSON,
  INDEX idx_timestamp (timestamp),
  INDEX idx_user_handle (user_handle),
  INDEX idx_user_id (user_id),
  INDEX idx_action_type (action_type),
  INDEX idx_action_category (action_category),
  INDEX idx_resource (resource_type, resource_id)
);
```

#### audit_retention_policy Table
```sql
CREATE TABLE audit_retention_policy (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  policy_name VARCHAR(255) NOT NULL,
  action_category VARCHAR(50) NOT NULL DEFAULT 'all',
  action_type VARCHAR(100),
  retention_days INT UNSIGNED NOT NULL DEFAULT 90,
  auto_purge BOOLEAN DEFAULT TRUE,
  legal_hold BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### 10. Troubleshooting

#### Logs Not Appearing
1. Check database connection
2. Verify patch 023 has been applied
3. Check error logs for database errors
4. Verify user has admin/trusted_admin/owner role

#### Export Not Working
1. Check file permissions for PDF generation
2. Verify TCPDF is installed (optional, has fallback)
3. Check OpenSSL is available for digital signatures

#### Purge Not Working
1. Verify cron job is running
2. Check `logs/audit_purge.log` for errors
3. Verify retention policies are configured
4. Check legal hold flags

### 11. Best Practices

1. **Regular Review**: Review audit logs regularly for security issues
2. **Retention Policies**: Configure appropriate retention periods based on compliance requirements
3. **Legal Hold**: Enable legal hold for logs that may be needed for legal proceedings
4. **Export Backups**: Regularly export and backup audit logs
5. **Access Control**: Limit access to audit logs to trusted administrators only
6. **Monitoring**: Set up alerts for suspicious activity patterns

### 12. Compliance

The audit logging system is designed to meet common compliance requirements:
- **GDPR**: User data access and deletion tracking
- **HIPAA**: Healthcare data access logging (if applicable)
- **SOX**: Financial data access logging (if applicable)
- **PCI DSS**: Payment data access logging (if applicable)

All exports include digital signatures for authenticity verification.

