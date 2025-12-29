# Sentinel Chat Platform - Patch System Documentation

## Overview

The patch system manages database schema updates (patches/migrations) with full tracking, logging, and rollback support. Patches are tracked in both the database (when available) and log files, ensuring no patch information is lost.

## Features

- ✅ **Patch Tracking**: Tracks all applied patches in database and log files
- ✅ **Dependency Checking**: Ensures patches are applied in correct order
- ✅ **Log Viewing**: View patch logs via API without downloading files
- ✅ **File Storage Fallback**: Works even when database is offline
- ✅ **Auto-Sync**: Syncs patch records to database when it becomes available
- ✅ **Rollback Support**: Rollback scripts available for patches

## Patch Structure

Each patch consists of:

1. **SQL File**: `patches/XXX_patch_name.sql`
   - Contains database changes
   - Can include multiple statements
   - Comments are supported

2. **Info File**: `patches/XXX_patch_name.info.json`
   - Patch metadata
   - Description, version, dependencies
   - Files changed, database changes
   - Scope information

3. **Rollback File** (optional): `patches/rollback/XXX_patch_name_rollback.sql`
   - SQL to undo the patch
   - Use with caution!

## Usage

### Command Line

```bash
# List all available patches
php apply_patch.php --list

# Apply a specific patch
php apply_patch.php 001_add_room_presence

# View applied patches
php apply_patch.php --status

# Show help
php apply_patch.php --help
```

### Web API

```bash
# List patches with status
GET /iChat/api/patch-status.php?action=list

# Get applied patches
GET /iChat/api/patch-status.php?action=applied

# Get specific patch info
GET /iChat/api/patch-status.php?action=info&patch_id=001_add_room_presence

# View patch logs (scope information)
GET /iChat/api/patch-log.php?patch_id=001_add_room_presence
```

## Patch Information

Each patch includes:

- **Patch ID**: Unique identifier (e.g., `001_add_room_presence`)
- **Version**: Patch version number
- **Description**: What the patch does
- **Dependencies**: Other patches that must be applied first
- **Files Changed**: List of files modified by this patch
- **Database Changes**: List of database changes
- **Scope**: Full description of what the patch accomplishes
- **Log URL**: Link to view patch logs without downloading

## Log Files

### Database Logs
- Stored in `patch_history` table
- Includes: patch_id, version, description, applied_at, duration, full patch_info

### File Logs
- Stored in `logs/patches.log`
- JSON format with full patch information
- Includes: timestamp, status, files changed, database changes
- Auto-synced to database when available

## Viewing Patch Logs

### Via API (Recommended)
```
GET /iChat/api/patch-log.php?patch_id=001_add_room_presence
```

Returns JSON with:
- Patch information
- Applied logs
- Files changed
- Database changes
- Scope information

### Via Log File
```bash
# View entire log file
cat logs/patches.log

# Search for specific patch
grep "001_add_room_presence" logs/patches.log
```

## Patch Application Process

1. **Validation**: Checks if patch exists and hasn't been applied
2. **Dependency Check**: Verifies all dependencies are satisfied
3. **Execution**: Runs SQL statements in transaction
4. **Recording**: Records patch in database and log file
5. **Sync**: If database was offline, syncs when available

## File Storage Fallback

When database is unavailable:
- Patches are recorded in file storage (`storage/queue/patch_*.json`)
- SyncService automatically syncs to database when it comes online
- No patch information is lost

## Creating New Patches

1. Create SQL file: `patches/XXX_patch_name.sql`
2. Create info file: `patches/XXX_patch_name.info.json`
3. (Optional) Create rollback: `patches/rollback/XXX_patch_name_rollback.sql`
4. Test patch application
5. Document in PATCH_SYSTEM.md

## Security

- Patches directory protected by `.htaccess`
- All SQL uses prepared statements where possible
- Patch files validated before execution
- Rollback scripts require manual execution

## Example Patch

See `patches/001_add_room_presence.sql` and `patches/001_add_room_presence.info.json` for a complete example.

## Troubleshooting

**Patch already applied**: Use `--status` to verify, or check database directly

**Dependencies not satisfied**: Apply dependencies first using `--list` to see order

**Database offline**: Patch will be stored in file storage and synced automatically

**View logs**: Use API endpoint or check `logs/patches.log` file

