# Sentinel Chat Platform - Updates Log

This log documents important fixes, improvements, and lessons learned during development.

## 2025-12-26 - Comprehensive Unicode Emoji Library Implementation

### Feature
Implemented a complete Unicode emoji library system with emoji picker UI, supporting all Unicode 17.0 emojis.

### Details
- **Database Structure** (`patches/011_add_emoji_library.sql`):
  - `emoji_library` table - Stores Unicode emojis with metadata (code points, short names, categories, keywords)
  - `emoji_favorites` table - User-specific favorite emojis
  - `emoji_recent` table - Tracks recently used emojis per user
  - Full-text search support on keywords
  - Usage tracking for popular emoji sorting

- **Import Script** (`import_emojis.php`):
  - Parses Unicode emoji-sequences.txt file (Unicode 17.0 format)
  - Handles code point ranges (e.g., `231A..231B`)
  - Automatically categorizes emojis (Smileys & Emotion, People & Body, Animals & Nature, etc.)
  - Generates search keywords from short names
  - **Import Results**: Successfully imported **1,429 emojis** with 0 errors
  - Command: `php import_emojis.php emoji-sequences.txt`

- **API Endpoint** (`api/emojis.php`):
  - `action=list` - List emojis with category/search filtering
  - `action=categories` - Get all available categories
  - `action=recent` - Get user's recently used emojis (requires auth)
  - `action=favorites` - Get user's favorite emojis (requires auth)
  - `action=use` - Record emoji usage for tracking (requires auth)

- **Emoji Picker UI**:
  - Integrated into message composer with 😀 button
  - Tabbed interface: Recent, Smileys, People, Animals, Food, Travel, Objects, Symbols
  - Real-time search functionality
  - Click-to-insert emojis at cursor position
  - Tracks usage for "Recent" tab
  - Responsive grid layout with hover effects

- **JavaScript Integration** (`js/app.js`):
  - `toggleEmojiPicker()` - Show/hide picker
  - `loadEmojisForTab()` - Load emojis by category
  - `searchEmojis()` - Search with debouncing
  - `insertEmoji()` - Insert at cursor position
  - `recordEmojiUsage()` - Track usage for analytics

### Benefits
- **1,429 emojis** available from Unicode 17.0 standard
- Easy emoji insertion via visual picker
- Search functionality for quick access
- Usage tracking for personalized experience
- Category-based browsing
- Recent emojis for quick access to frequently used

### Files Created
- `patches/011_add_emoji_library.sql` - Database schema
- `patches/011_add_emoji_library.info.json` - Patch metadata
- `patches/rollback/011_add_emoji_library_rollback.sql` - Rollback script
- `import_emojis.php` - Emoji import script
- `api/emojis.php` - Emoji API endpoint

### Files Modified
- `index.php` - Added emoji picker UI to message composer
- `css/styles.css` - Added emoji picker styles
- `js/app.js` - Added emoji picker functionality
- `api/proxy.php` - Added 'emojis' to allowed paths

### Usage
1. Apply patch `011_add_emoji_library` via Admin → Patches
2. Download `emoji-sequences.txt` from Unicode website
3. Run: `php import_emojis.php emoji-sequences.txt`
4. Click 😀 button in message composer to open picker
5. Browse categories or search to find emojis
6. Click emoji to insert into message

### Import Statistics
- **Total Imported**: 1,429 emojis
- **Skipped**: 0
- **Errors**: 0
- **Source**: Unicode 17.0 emoji-sequences.txt
- **Date**: 2025-12-26

---

## 2025-12-26 - SQL Statement Splitting Fix

### Issue
SQL patch files were failing to apply with syntax errors like:
```
SQLSTATE[42000]: Syntax error or access violation: 1064 
You have an error in your SQL syntax; check the manual that corresponds to 
your MySQL server version for the right syntax to use near ''' at line 1
```

### Root Cause
The `PatchManager::splitSqlStatements()` method was using `explode(';', $sql)` which incorrectly split SQL statements on semicolons even when they appeared inside quoted strings. For example:
- `VALUES (':)', 'text');` was being split at the semicolon inside `':)'`
- `VALUES ('text'), 'value', 6);` had an extra closing parenthesis that closed VALUES() too early

### Solution
1. **Rewrote `splitSqlStatements()` method** (`src/Services/PatchManager.php`):
   - Changed from simple `explode(';', $sql)` to character-by-character parsing
   - Tracks quote state (single quotes, double quotes, backticks)
   - Only splits on semicolons when NOT inside quoted strings
   - Properly handles escape sequences (`\'`, `\"`, etc.)

2. **Fixed SQL syntax errors** in `patches/010_add_chat_features.sql`:
   - Removed extra closing parentheses in INSERT statements
   - Changed `'), 'pinky_response',` to `', 'pinky_response',` to keep all values inside VALUES()

### Key Learnings
- **Never use `explode()` on SQL** - Always parse character-by-character to respect quoted strings
- **Test SQL splitting** with complex statements containing quotes, semicolons, and special characters
- **Validate SQL syntax** before applying patches - check for balanced parentheses and proper VALUES() structure

### Files Modified
- `src/Services/PatchManager.php` - Rewrote `splitSqlStatements()` method
- `patches/010_add_chat_features.sql` - Fixed INSERT statement syntax

### Testing
- Patch `010_add_chat_features` now applies successfully
- All SQL statements are correctly split and executed
- No more quote parsing errors

---

## 2025-12-26 - Log Rotation System Implementation

### Feature
Implemented automatic log rotation system to keep log files manageable.

### Details
- **LogRotationService** (`src/Services/LogRotationService.php`):
  - Automatically rotates logs when they exceed 5000 lines
  - Gzips archived logs with date stamps (e.g., `error.log.2025-12-25_182402.gz`)
  - Stores archived logs in `logs/archived/`
  - Can read from both regular and gzipped log files
  - Provides cleanup of old archived logs

- **Automatic rotation** (integrated into `bootstrap.php`):
  - Custom error handler checks and rotates before writing
  - Works with PHP's `error_log()` function
  - Keeps current logs under 5000 lines

- **API endpoint** (`api/log-viewer.php`):
  - Accessible to both admins and moderators
  - `action=list` - List current and archived logs
  - `action=view&file=error.log&limit=1000&offset=0` - View log content (supports gzipped files)
  - `action=rotate&file=error.log` - Manually rotate a log
  - `action=cleanup&days=30` - Clean up old archives

- **Utility script** (`rotate_logs.php`):
  - Command-line tool to manually rotate logs
  - Usage: `php rotate_logs.php [log_file]`

### Benefits
- Current logs stay under 5000 lines (easier to read and debug)
- Archived logs are compressed (saves disk space)
- Easy to view archived logs via API
- Automatic cleanup of old archives
- Works seamlessly with existing `error_log()` calls

### Files Created
- `src/Services/LogRotationService.php` - Main log rotation service
- `api/log-viewer.php` - API endpoint for log viewing
- `rotate_logs.php` - Command-line utility
- `UPDATES_LOG.md` - This file

### Files Modified
- `bootstrap.php` - Integrated log rotation into error handler
- `api/proxy.php` - Added `log-viewer` to allowed paths

---

## Notes for Future Development

### SQL Patch Files
- Always test SQL statement splitting with complex queries
- Use character-by-character parsing for SQL, never `explode()` or simple regex
- Validate VALUES() clauses have correct number of values matching column count
- Test with quotes, semicolons, and special characters in data

### Log Management
- Keep log files under 5000 lines for readability
- Use gzip compression for archived logs
- Implement rotation before writing, not after
- Provide API access for viewing archived logs

### Error Handling
- Always log full statements when SQL errors occur
- Include statement length and position in error messages
- Test error handlers don't cause infinite loops or recursion

