# Sentinel Chat Platform - Patch System

This directory contains database patches/migrations for the Sentinel Chat Platform.

## Patch Files

Each patch consists of:
- `XXX_patch_name.sql` - SQL file with database changes
- `XXX_patch_name.info.json` - Patch metadata and information

## Applying Patches

### Command Line:
```bash
# List available patches
php apply_patch.php --list

# Apply a specific patch
php apply_patch.php 001_add_room_presence

# View applied patches
php apply_patch.php --status
```

### Web Interface:
```
GET /iChat/api/patch-log.php?patch_id=001_add_room_presence
GET /iChat/apply_patch.php?web=allow&action=list
POST /iChat/apply_patch.php?web=allow&action=apply&patch_id=001_add_room_presence
```

## Patch Logs

Patch logs are stored in:
- Database: `patch_history` table (when available)
- File: `logs/patches.log` (always)

View logs via API:
```
GET /iChat/api/patch-log.php?patch_id=001_add_room_presence
```

## Patch Information

Each patch includes:
- Patch ID and version
- Description
- Dependencies
- Files changed
- Database changes
- Rollback availability

## Rollback

Rollback scripts are in `patches/rollback/` directory.
Manual rollback only - use with caution!

