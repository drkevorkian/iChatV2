# Sentinel Chat Platform - Message Flow Documentation

## Message Flow Overview

### 1. Message Sending Flow

When a user sends a message in a chat room:

1. **Client-side** (`js/app.js` → `sendMessage()`):
   - User types message and clicks "Send"
   - Message is base64 encoded and sent to `/api/messages.php` via POST

2. **API Processing** (`api/messages.php`):
   - Validates message format and user permissions
   - Applies word filter
   - Processes Pinky & Brain bot commands
   - Validates ASCII art if present
   - **Stores message in `temp_outbox` table** via `MessageRepository::enqueueMessage()`

3. **Database Storage** (`src/Repositories/MessageRepository.php`):
   - Message is inserted into `temp_outbox` table with:
     - `room_id` - Which room the message belongs to
     - `sender_handle` - Who sent it
     - `cipher_blob` - Encrypted/encoded message content
     - `queued_at` - Timestamp when queued
     - `delivered_at` - NULL (not yet delivered)
     - `deleted_at` - NULL (not deleted)

4. **Fallback Storage**:
   - If database is unavailable, message is stored in `storage/queue/` as JSON files
   - FileStorage service handles this fallback

### 2. Message Retrieval Flow

When displaying messages in a chat room:

1. **Client-side** (`js/app.js` → `loadRoomMessages()`):
   - Calls `/api/messages.php?room_id=X&limit=100`
   - Filters by current room

2. **API** (`api/messages.php` → GET):
   - Calls `MessageRepository::getPendingMessages()`
   - **Only returns messages where:**
     - `delivered_at IS NULL` (not yet delivered to primary server)
     - `deleted_at IS NULL` (not soft-deleted)
     - `is_hidden = FALSE` (unless user is moderator/admin)

3. **Display**:
   - Messages are rendered in the chat interface
   - Only "pending" (undelivered) messages are shown

### 3. Why Some Messages Don't Appear

**Messages are filtered out if:**
- `delivered_at` is NOT NULL - These have been delivered to the primary server and are considered "processed"
- `deleted_at` is NOT NULL - These have been soft-deleted
- `is_hidden = TRUE` - These are hidden (unless you're a moderator/admin)

**The `temp_outbox` table is a TEMPORARY queue:**
- It's meant to hold messages until they're delivered to the primary NestJS server
- Once delivered, `delivered_at` is set and they're no longer shown
- This is by design - the table is for pending/undelivered messages only

### 4. Message Deletion

**Current Status:**
- There is a `softDelete()` method in `MessageRepository` but **NO API endpoint** to call it
- Messages cannot be deleted via the web interface currently
- Only database admins can manually set `deleted_at` timestamp

**To delete a message manually:**
```sql
UPDATE temp_outbox 
SET deleted_at = NOW() 
WHERE id = <message_id>;
```

**To permanently remove a message:**
```sql
DELETE FROM temp_outbox WHERE id = <message_id>;
```

### 5. Guest Message Issue

If you see a guest message that won't delete:
- Check if `delivered_at` is set (if so, it won't show in normal view)
- Check if `deleted_at` is set (if so, it's already soft-deleted)
- The message might be in file storage (`storage/queue/`) instead of database

**To find and delete a guest message:**

1. **Check database:**
```sql
SELECT id, sender_handle, room_id, queued_at, delivered_at, deleted_at 
FROM temp_outbox 
WHERE sender_handle LIKE '%guest%' OR sender_handle = 'Guest'
ORDER BY queued_at DESC;
```

2. **Check file storage:**
   - Look in `storage/queue/` directory
   - Files are JSON format, search for `sender_handle` containing "guest"

3. **Delete from database:**
```sql
-- Soft delete (recommended)
UPDATE temp_outbox 
SET deleted_at = NOW() 
WHERE sender_handle LIKE '%guest%' AND deleted_at IS NULL;

-- Or permanently delete
DELETE FROM temp_outbox WHERE sender_handle LIKE '%guest%';
```

### 6. Message Lifecycle

```
User sends message
    ↓
Stored in temp_outbox (delivered_at = NULL, deleted_at = NULL)
    ↓
Visible in chat room
    ↓
Python runtime service drains queue
    ↓
Message sent to primary NestJS server
    ↓
delivered_at is set to NOW()
    ↓
Message NO LONGER appears in chat (filtered out)
```

### 7. Tables Involved

- **`temp_outbox`** - Temporary queue for room messages (pending delivery)
- **`im_messages`** - Private instant messages between users
- **`storage/queue/`** - File-based fallback storage when database is unavailable

### 8. Recommendations

1. **Add Delete API Endpoint:**
   - Create `DELETE /api/messages.php?id=X` endpoint
   - Allow moderators/admins to delete messages
   - Use soft delete (set `deleted_at`) for audit trail

2. **Message History:**
   - Consider creating a `messages_history` table for delivered messages
   - Or query `temp_outbox` with `delivered_at IS NOT NULL` for history

3. **Cleanup Script:**
   - Create a script to clean up old delivered messages
   - Keep only recent messages in `temp_outbox`

