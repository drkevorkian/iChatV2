# Patch Management - Web Interface Guide

## Accessing Patch Management

1. **Set Administrator Role** (for testing):
   - Visit: `http://localhost/iChat/?role=administrator`
   - This sets your session role to administrator
   - In production, this would come from proper authentication

2. **Navigate to Admin View**:
   - Click the "Admin" button in the header
   - Scroll down to "Patch Management" section

## Features

### View Available Patches
- All available patches are listed automatically
- Shows patch status: **APPLIED** (green) or **PENDING** (yellow)
- Displays patch ID, description, version, and applied date (if applicable)

### Apply Patches
- Click **"Apply Patch"** button on any pending patch
- Confirm the action in the dialog
- Patch will be applied and the list will refresh
- Success message shows duration

### View Patch Logs
- Click **"View Log"** link next to applied patches
- Opens patch log in new tab (JSON format)
- Shows full scope, files changed, database changes, and execution details
- No file download required - view directly in browser

### Refresh Patch List
- Click **"Refresh Patch List"** button to reload patches
- Useful after applying patches or checking for new patches

## Patch Information Displayed

Each patch shows:
- **Patch ID**: Unique identifier (e.g., `001_add_room_presence`)
- **Description**: What the patch does
- **Version**: Patch version number
- **Status**: APPLIED or PENDING
- **Applied Date**: When patch was applied (if applicable)
- **Log URL**: Link to view detailed logs

## Security

- Patches can only be applied by administrators
- All patch operations are logged
- Patch files are protected from direct web access
- API endpoints require authentication

## Troubleshooting

**"Unauthorized" Error**:
- Make sure you're logged in as administrator
- Visit `?role=administrator` to set admin role (development only)

**Patch Already Applied**:
- Applied patches show "APPLIED" status
- Cannot apply the same patch twice

**Patch Application Failed**:
- Check browser console for error details
- Verify database is accessible
- Check `logs/patches.log` for detailed error information

## API Endpoints (for reference)

- `GET /iChat/api/patch-status.php?action=list` - List all patches
- `GET /iChat/api/patch-status.php?action=applied` - Get applied patches
- `GET /iChat/api/patch-log.php?patch_id=XXX` - View patch logs
- `POST /iChat/api/patch-apply.php` - Apply a patch (requires auth)

