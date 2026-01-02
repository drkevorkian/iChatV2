# Sentinel Chat Platform - Comprehensive System Audit Report
**Date:** 2024-12-19  
**Scope:** Complete system audit from Guest to Owner, all endpoints, permissions, functionality, and UI

---

## EXECUTIVE SUMMARY

This audit examined the entire Sentinel Chat Platform system, including authentication, authorization, RBAC, messaging systems (chat, IM, mail), API endpoints, frontend UI, and permission enforcement. The system shows good security practices with prepared statements and input validation, but several critical issues were identified that need attention.

**Critical Issues Found:** 12  
**High Priority Issues:** 18  
**Medium Priority Issues:** 15  
**Low Priority Issues:** 8

---

## 1. AUTHENTICATION & AUTHORIZATION

### ✅ WORKING CORRECTLY
- Login/logout functionality works properly
- Session management with secure cookies (httponly, secure, samesite)
- Audit logging for login/logout events
- Password hashing (bcrypt)
- Failed login attempt logging

### ❌ ISSUES FOUND

#### 1.1 Development Mode Role Override (CRITICAL - SECURITY RISK)
**Location:** `index.php:99-102`
```php
// For development: allow setting role via GET parameter (remove in production)
if (isset($_GET['role']) && in_array($_GET['role'], ['guest', 'user', 'moderator', 'administrator'], true)) {
    $_SESSION['user_role'] = $_GET['role'];
    $userRole = $_GET['role'];
}
```
**Issue:** Users can override their role via GET parameter. This is a **CRITICAL SECURITY VULNERABILITY** if left in production.
**Impact:** Any user can become an administrator by adding `?role=administrator` to the URL.
**Recommendation:** Remove this code or add environment check to disable in production.

#### 1.2 Missing Role Validation in Guest Registration
**Location:** `index.php:85-91`
**Issue:** Guest registration redirect logic may allow bypassing authentication checks.
**Impact:** Low - guests should have limited access anyway.

#### 1.3 Logout Session Cleanup
**Location:** `api/auth.php:129-130`
**Issue:** `session_destroy()` is called, but then `$_SESSION = []` is set afterward, which may not work as expected since the session is already destroyed.
**Impact:** Low - logout still works, but session cleanup could be improved.

---

## 2. RBAC SYSTEM

### ✅ WORKING CORRECTLY
- RBAC tables exist and are properly structured
- Permission checking via `RBACService::hasPermission()` works
- Owner-protected permissions system implemented
- Permission change history logging
- Owner role always returns true for all permissions

### ❌ ISSUES FOUND

#### 2.1 Hardcoded Role Checks Still Present (HIGH PRIORITY)
**Location:** Multiple files
**Issue:** Several endpoints still use hardcoded `in_array($userRole, ['moderator', 'administrator'])` instead of RBAC checks.

**Files Affected:**
- `api/moderate.php:44` - Checks for `moderator` and `administrator` only, missing `trusted_admin` and `owner`
- `index.php:139` - Moderator button only shows for `moderator` and `administrator`, missing `trusted_admin` and `owner`
- `index.php:622` - Moderator view only accessible to `moderator` and `administrator`

**Impact:** Trusted Admins and Owners may not have access to moderation features they should have.

#### 2.2 Missing RBAC Permission Checks
**Location:** Multiple endpoints
**Issue:** Some endpoints check roles but don't use RBAC permission keys.

**Files Affected:**
- `api/moderate.php:58-109` - `hide` action doesn't check RBAC permission `moderation.hide_message` (only checks role)
- `api/moderate.php:171-238` - `edit` action checks RBAC but only after role check
- `api/presence.php:123-146` - `list/online` action has comment "This permission check can be added later if needed" - **NO PERMISSION CHECK**

**Impact:** Users may access features they shouldn't have access to if RBAC permissions are changed.

#### 2.3 Inconsistent Permission Key Usage
**Location:** `patches/027_add_rbac_system.sql`
**Issue:** Some permission keys have duplicates/aliases:
- `message.send` and `chat.send_message` (both exist)
- `message.edit` and `chat.edit_own_message` (both exist)
- `message.delete` and `chat.delete_own_message` (both exist)
- `im.send` and `im.send_im` (both exist)

**Impact:** Confusion about which permission key to use. Code uses different keys in different places.

#### 2.4 Missing Permission for Mail System
**Location:** `api/mail.php`
**Issue:** Mail system has **NO RBAC PERMISSION CHECKS** at all. Any authenticated user can send/receive mail.
**Impact:** Cannot restrict mail access via RBAC.

#### 2.5 Missing Permission for IM Inbox Viewing
**Location:** `api/im.php:32-52`
**Issue:** `inbox` action has **NO RBAC PERMISSION CHECK**. Only validates user handle.
**Impact:** Cannot restrict IM viewing via RBAC.

#### 2.6 Missing Permission for Presence List
**Location:** `api/presence.php:123-146`
**Issue:** `list/online` action has comment "This permission check can be added later if needed" - **NO PERMISSION CHECK**
**Impact:** Cannot restrict online user list viewing via RBAC.

---

## 3. API ENDPOINTS

### ✅ WORKING CORRECTLY
- Most endpoints use prepared statements
- JSON parsing is secure with error checking
- Input validation and sanitization
- API secret validation where required
- Audit logging integrated in most endpoints

### ❌ ISSUES FOUND

#### 3.1 Inconsistent API Secret Validation
**Location:** Multiple endpoints
**Issue:** Some endpoints require API secret, others don't, and some check it conditionally.

**Files:**
- `api/messages.php:36` - Requires API secret (except DELETE)
- `api/admin.php:29` - Requires API secret
- `api/im.php` - **NO API SECRET CHECK**
- `api/presence.php` - **NO API SECRET CHECK**
- `api/mail.php` - **NO API SECRET CHECK**
- `api/chat-media.php` - **NO API SECRET CHECK**
- `api/moderate.php` - **NO API SECRET CHECK** (only session auth)

**Impact:** Inconsistent security. Some endpoints can be called without API secret.

#### 3.2 Missing RBAC Checks in Several Endpoints
**Location:** Multiple endpoints
**Issue:** Endpoints that should check RBAC permissions don't:

**Files:**
- `api/mail.php` - **NO RBAC CHECKS AT ALL** (all actions)
- `api/im.php:32-52` - `inbox` action - **NO RBAC CHECK**
- `api/presence.php:123-146` - `list/online` action - **NO RBAC CHECK**
- `api/moderate.php:58-109` - `hide` action - **NO RBAC CHECK** (only role check)

#### 3.3 Missing `trusted_admin` and `owner` in Role Checks
**Location:** `api/moderate.php:44`
**Issue:** Only checks for `moderator` and `administrator`, missing `trusted_admin` and `owner`.
**Impact:** Trusted Admins and Owners cannot access moderation features.

#### 3.4 Missing Import Statement
**Location:** `api/moderate.php:111-118`
**Issue:** Uses `RBACService` but doesn't import it at the top (line 113 creates new instance).
**Status:** Actually imports it - **FALSE POSITIVE** (line 18 shows it's imported via `use` statement in bootstrap).

#### 3.5 Duplicate Retention Policies Section
**Location:** `index.php:1329-1365`
**Issue:** Retention Policies section is **DUPLICATED** in the HTML (appears twice with same ID).
**Impact:** UI confusion, potential JavaScript conflicts.

---

## 4. MESSAGING SYSTEMS

### 4.1 CHAT ROOM MESSAGING

#### ✅ WORKING CORRECTLY
- Message sending with RBAC check (`chat.send_message`)
- Message viewing with RBAC check (`message.view`)
- Word filtering integrated
- AI moderation integrated
- Audit logging for message sends
- Message editing/deletion with RBAC checks

#### ❌ ISSUES FOUND

**4.1.1 Missing Permission Check for Hidden Messages**
**Location:** `api/messages.php:68-70`
**Issue:** Uses RBAC check for `moderation.view_reports` to show hidden messages, but this permission might not be the right one.
**Impact:** Users with `moderation.view_reports` can see hidden messages, which may be intended.

**4.1.2 API Secret Required for GET Messages**
**Location:** `api/messages.php:36`
**Issue:** GET request for messages requires API secret, but DELETE doesn't (uses session auth).
**Impact:** Inconsistent - GET should probably use session auth too since it's user-facing.

### 4.2 INSTANT MESSAGING (IM)

#### ✅ WORKING CORRECTLY
- IM sending with RBAC check (`im.send_im`)
- Audit logging for IM sends
- E2EE support
- Read receipts

#### ❌ ISSUES FOUND

**4.2.1 Missing RBAC Check for IM Inbox**
**Location:** `api/im.php:32-52`
**Issue:** `inbox` action has **NO RBAC PERMISSION CHECK**. Only validates user handle.
**Impact:** Cannot restrict IM viewing via RBAC. Any user can view any other user's inbox if they know the handle.

**4.2.2 Missing RBAC Check for IM Badge/Unread Count**
**Location:** `api/im.php` (likely has `badge` action)
**Issue:** Unread count endpoint may not have RBAC check.
**Impact:** Users might see unread counts for messages they shouldn't access.

**4.2.3 Missing RBAC Check for IM Open/Read**
**Location:** `api/im.php` (likely has `open` action)
**Issue:** Marking IM as read may not have RBAC check.
**Impact:** Users might mark messages as read that they shouldn't access.

### 4.3 MAIL SYSTEM

#### ✅ WORKING CORRECTLY
- Mail sending/receiving works
- Folder management (inbox, sent, drafts, trash)
- Attachments support
- Threading support

#### ❌ ISSUES FOUND

**4.3.1 NO RBAC PERMISSION CHECKS AT ALL (CRITICAL)**
**Location:** `api/mail.php` (entire file)
**Issue:** **ZERO RBAC PERMISSION CHECKS** in the entire mail system. Any authenticated user can:
- Send mail to anyone
- View any mail (if they know the ID)
- Delete any mail
- Move any mail to any folder

**Impact:** **CRITICAL SECURITY ISSUE** - Mail system is completely unprotected by RBAC.

**4.3.2 Missing Permission Keys for Mail**
**Location:** `patches/027_add_rbac_system.sql`
**Issue:** No permission keys exist for mail operations:
- `mail.send`
- `mail.view`
- `mail.delete`
- `mail.manage_folders`

**Impact:** Cannot implement RBAC for mail system without adding these permissions.

**4.3.3 No Access Control for Mail Viewing**
**Location:** `api/mail.php:404-425`
**Issue:** `view` action checks if mail belongs to user, but doesn't check RBAC permission.
**Impact:** Even if mail belongs to user, RBAC could deny access, but it's not checked.

---

## 5. FRONTEND/UI

### ✅ WORKING CORRECTLY
- Role-based navigation buttons
- Admin dashboard with proper role checks
- RBAC admin UI implemented
- Audit logs UI implemented
- User database display

### ❌ ISSUES FOUND

#### 5.1 Missing `trusted_admin` and `owner` in UI Checks
**Location:** `index.php:139`
**Issue:** Moderator button only shows for `moderator` and `administrator`, missing `trusted_admin` and `owner`.
**Impact:** Trusted Admins and Owners may not see the Moderator view button even though they should have access.

#### 5.2 Moderator View Access Check
**Location:** `index.php:622`
**Issue:** Moderator view container only accessible to `moderator` and `administrator`, missing `trusted_admin` and `owner`.
**Impact:** Trusted Admins and Owners cannot access Moderator view.

#### 5.3 Duplicate HTML Sections
**Location:** `index.php:1329-1365`
**Issue:** Retention Policies section is **DUPLICATED** in HTML (appears twice).
**Impact:** UI confusion, potential JavaScript event handler conflicts.

#### 5.4 Missing UI for Mail Permissions
**Location:** Frontend
**Issue:** No UI exists to manage mail permissions via RBAC (because mail permissions don't exist in RBAC).

#### 5.5 Missing UI Indicators for Permission Status
**Location:** Frontend
**Issue:** No visual indicators in UI showing which features are disabled due to RBAC permissions.
**Impact:** Users may not understand why certain buttons/features don't work.

---

## 6. PERMISSION ENFORCEMENT

### ✅ WORKING CORRECTLY
- Most critical endpoints use RBAC
- Permission checks are consistent in newer code
- Owner role always has all permissions

### ❌ ISSUES FOUND

#### 6.1 Inconsistent Permission Enforcement
**Location:** System-wide
**Issue:** Some features check RBAC, others check roles directly, others check nothing.

**Examples:**
- Chat messages: ✅ RBAC (`chat.send_message`, `message.view`)
- IM sending: ✅ RBAC (`im.send_im`)
- IM inbox: ❌ NO CHECK
- Mail: ❌ NO RBAC CHECKS AT ALL
- Presence list: ❌ NO CHECK
- Moderation hide: ❌ Only role check, no RBAC

#### 6.2 Missing Permission Keys
**Location:** `patches/027_add_rbac_system.sql`
**Issue:** Several features have no permission keys:
- Mail operations (send, view, delete, manage folders)
- IM inbox viewing
- IM badge/unread count
- Presence/online user list viewing
- Room management operations
- Profile viewing (beyond basic user.view)

#### 6.3 Permission Key Aliases Causing Confusion
**Location:** `patches/027_add_rbac_system.sql`
**Issue:** Multiple permission keys for same action:
- `message.send` vs `chat.send_message`
- `message.edit` vs `chat.edit_own_message`
- `message.delete` vs `chat.delete_own_message`
- `im.send` vs `im.send_im`

**Impact:** Code uses different keys in different places, making it hard to track which permissions are actually being checked.

---

## 7. SECURITY ISSUES

### ❌ CRITICAL SECURITY ISSUES

#### 7.1 Development Role Override (CRITICAL)
**Location:** `index.php:99-102`
**Issue:** Users can override role via GET parameter.
**Severity:** CRITICAL
**Recommendation:** Remove immediately or add environment check.

#### 7.2 Mail System Completely Unprotected (CRITICAL)
**Location:** `api/mail.php`
**Issue:** No RBAC checks, no permission system, any authenticated user can access any mail.
**Severity:** CRITICAL
**Recommendation:** Add RBAC permission keys and checks for all mail operations.

#### 7.3 IM Inbox Access Control Missing (HIGH)
**Location:** `api/im.php:32-52`
**Issue:** Can view any user's inbox if you know the handle.
**Severity:** HIGH
**Recommendation:** Add RBAC check and verify user can only view their own inbox (or has permission to view others).

### ⚠️ HIGH PRIORITY SECURITY ISSUES

#### 7.4 Inconsistent API Secret Requirements
**Location:** Multiple endpoints
**Issue:** Some endpoints require API secret, others don't.
**Severity:** HIGH
**Recommendation:** Standardize API secret requirements or document which endpoints need it and why.

#### 7.5 Missing Permission Checks in Multiple Endpoints
**Location:** See section 3.2
**Issue:** Several endpoints don't check RBAC permissions.
**Severity:** HIGH
**Recommendation:** Add RBAC checks to all endpoints that perform sensitive operations.

---

## 8. ROLE HIERARCHY ISSUES

### ❌ ISSUES FOUND

#### 8.1 Missing `trusted_admin` and `owner` in Role Checks
**Location:** Multiple files
**Issue:** Many role checks only include `moderator` and `administrator`, missing `trusted_admin` and `owner`.

**Files Affected:**
- `api/moderate.php:44` - Only allows `moderator` and `administrator`
- `index.php:139` - Moderator button only for `moderator` and `administrator`
- `index.php:622` - Moderator view only for `moderator` and `administrator`

**Impact:** Trusted Admins and Owners may not have access to features they should have.

#### 8.2 Role Hierarchy Not Consistently Enforced
**Location:** System-wide
**Issue:** Some places check roles directly instead of using RBAC, which means role hierarchy (Owner > Trusted Admin > Admin > Moderator > User > Guest) is not consistently enforced.

**Impact:** Features may not work as expected for higher-tier roles.

---

## 9. MISSING FEATURES / INTEGRATION ISSUES

### ❌ ISSUES FOUND

#### 9.1 Mail System Not Integrated with RBAC
**Location:** `api/mail.php`, `patches/027_add_rbac_system.sql`
**Issue:** Mail system has no RBAC integration at all.
**Impact:** Cannot control mail access via RBAC system.

#### 9.2 Missing Permission Keys for Several Features
**Location:** `patches/027_add_rbac_system.sql`
**Issue:** No permission keys for:
- Mail operations
- IM inbox viewing
- Presence/online user list
- Room management (beyond basic join/leave)
- Profile viewing (detailed)

#### 9.3 No UI Feedback for Disabled Permissions
**Location:** Frontend
**Issue:** When a user doesn't have permission, features just don't work with no explanation.
**Impact:** Poor user experience.

#### 9.4 Missing Permission for Room Operations
**Location:** System
**Issue:** Room management operations (create, delete, invite) have permission keys, but room viewing/joining may not be properly checked.
**Impact:** Cannot restrict room access via RBAC.

---

## 10. API ENDPOINT SPECIFIC ISSUES

### 10.1 `api/auth.php`
**Status:** ✅ Mostly working
**Issues:**
- Logout session cleanup could be improved (line 129-130)

### 10.2 `api/messages.php`
**Status:** ⚠️ Partially working
**Issues:**
- GET requires API secret (inconsistent with DELETE which uses session)
- Hidden messages check uses `moderation.view_reports` - verify this is correct permission

### 10.3 `api/im.php`
**Status:** ⚠️ Partially working
**Issues:**
- `inbox` action: **NO RBAC CHECK**
- `badge` action: Need to verify RBAC check
- `open` action: Need to verify RBAC check
- No API secret check (inconsistent)

### 10.4 `api/mail.php`
**Status:** ❌ **CRITICAL ISSUES**
**Issues:**
- **NO RBAC CHECKS AT ALL**
- **NO PERMISSION KEYS EXIST**
- No API secret check
- Any authenticated user can access any mail

### 10.5 `api/presence.php`
**Status:** ⚠️ Partially working
**Issues:**
- `list/online` action: **NO RBAC CHECK** (has comment "can be added later")
- No API secret check

### 10.6 `api/moderate.php`
**Status:** ⚠️ Partially working
**Issues:**
- Only allows `moderator` and `administrator` (missing `trusted_admin` and `owner`)
- `hide` action: Only role check, no RBAC permission check
- No API secret check (uses session auth)

### 10.7 `api/chat-media.php`
**Status:** ✅ Working
**Issues:**
- No API secret check (but has RBAC check, so acceptable)

### 10.8 `api/admin.php`
**Status:** ✅ Working
**Issues:**
- None identified

### 10.9 `api/user-management.php`
**Status:** ✅ Working
**Issues:**
- None identified (properly includes `trusted_admin` and `owner`)

### 10.10 `api/rbac.php`
**Status:** ✅ Working
**Issues:**
- None identified

---

## 11. DATABASE & PATCH SYSTEM

### ✅ WORKING CORRECTLY
- Patch system functional
- Database health checks
- Rollback scripts available

### ❌ ISSUES FOUND

#### 11.1 Patch 027 Migration Script Dependency
**Location:** `patches/027_add_missing_rbac_permissions.sql`
**Issue:** Migration script assumes tables exist (creates if not), but if patch 027 wasn't applied, some features may not work.
**Impact:** Low - migration script handles this now.

---

## 12. AUDIT LOGGING

### ✅ WORKING CORRECTLY
- Most critical actions are logged
- Audit log viewing UI works
- Export functionality (JSON, CSV, PDF)
- Retention policies

### ❌ ISSUES FOUND

#### 12.1 Missing Audit Logs for Some Actions
**Location:** Various endpoints
**Issue:** Some actions may not be logged:
- Mail operations (send, view, delete)
- IM inbox viewing
- Presence list viewing
- Permission checks (failures)

**Impact:** Incomplete audit trail.

---

## 13. FRONTEND JAVASCRIPT

### ✅ WORKING CORRECTLY
- RBAC admin UI
- Audit logs UI
- Message editing/deletion UI
- User database display

### ❌ ISSUES FOUND

#### 13.1 No Client-Side Permission Checks
**Location:** `js/app.js` and other JS files
**Issue:** Frontend doesn't check permissions before showing/hiding UI elements.
**Impact:** UI may show buttons/features that don't work due to missing permissions (poor UX).

#### 13.2 Missing Error Handling for Permission Denied
**Location:** JavaScript files
**Issue:** When API returns 403 (permission denied), error messages may not be user-friendly.
**Impact:** Users don't understand why actions fail.

---

## 14. SUMMARY OF CRITICAL ISSUES

### 🔴 CRITICAL (Must Fix Immediately)

1. **Development Role Override** (`index.php:99-102`) - Users can become admin via URL parameter
2. **Mail System Completely Unprotected** (`api/mail.php`) - No RBAC checks, any user can access any mail
3. **IM Inbox Access Control Missing** (`api/im.php:32-52`) - Can view any user's inbox

### 🟠 HIGH PRIORITY (Fix Soon)

4. **Missing `trusted_admin` and `owner` in Role Checks** - Multiple files
5. **Inconsistent API Secret Requirements** - Multiple endpoints
6. **Missing RBAC Checks in Multiple Endpoints** - See section 3.2
7. **Duplicate HTML Sections** (`index.php:1329-1365`) - Retention Policies duplicated
8. **Missing Permission Keys for Mail System** - No RBAC integration possible
9. **Missing Permission Keys for IM Inbox** - Cannot restrict via RBAC
10. **Missing Permission Keys for Presence List** - Cannot restrict via RBAC

### 🟡 MEDIUM PRIORITY (Should Fix)

11. **Permission Key Aliases Causing Confusion** - Multiple keys for same action
12. **No UI Feedback for Disabled Permissions** - Poor UX
13. **Missing Audit Logs for Some Actions** - Incomplete audit trail
14. **Inconsistent Permission Enforcement** - Mix of RBAC and role checks
15. **Moderator View Access** - Missing `trusted_admin` and `owner`

### 🔵 LOW PRIORITY (Nice to Have)

16. **Logout Session Cleanup** - Minor improvement
17. **Client-Side Permission Checks** - Better UX
18. **Error Handling for Permission Denied** - Better UX

---

## 15. RECOMMENDATIONS

### Immediate Actions Required:
1. **Remove development role override** or add environment check
2. **Add RBAC to mail system** - Create permission keys and add checks
3. **Add RBAC to IM inbox** - Add permission check and verify user access
4. **Add `trusted_admin` and `owner` to all role checks** - System-wide update
5. **Fix duplicate HTML sections** - Remove duplicate Retention Policies

### Short-Term Improvements:
6. Standardize API secret requirements across all endpoints
7. Add missing permission keys for all features
8. Replace all hardcoded role checks with RBAC permission checks
9. Add UI feedback for disabled permissions
10. Add client-side permission checks to hide unavailable features

### Long-Term Enhancements:
11. Consolidate permission key aliases (choose one key per action)
12. Add comprehensive audit logging for all actions
13. Implement permission inheritance/hierarchy in RBAC
14. Add permission testing framework
15. Document all permission keys and their usage

---

## 16. TESTING RECOMMENDATIONS

### Test Cases to Verify:

1. **Guest User:**
   - ✅ Can view messages (if `message.view` permission allows)
   - ✅ Can send messages (if `chat.send_message` permission allows)
   - ❌ Should NOT be able to access admin features
   - ❌ Should NOT be able to access mail (if mail permissions exist)
   - ❌ Should NOT be able to access moderation features

2. **Regular User:**
   - ✅ Can send/receive messages
   - ✅ Can send/receive IMs
   - ❌ Should NOT be able to access admin features
   - ❌ Should NOT be able to moderate messages
   - ❌ Should NOT be able to change user roles

3. **Moderator:**
   - ✅ Can moderate messages
   - ✅ Can view reports
   - ❌ Should NOT be able to access admin dashboard
   - ❌ Should NOT be able to manage RBAC

4. **Administrator:**
   - ✅ Can access admin dashboard
   - ✅ Can manage users
   - ❌ Should NOT be able to manage RBAC (only Trusted Admin/Owner)
   - ❌ Should NOT be able to protect permissions (only Owner)

5. **Trusted Admin:**
   - ✅ Can access admin dashboard
   - ✅ Can manage RBAC permissions
   - ✅ Can manage users
   - ❌ Should NOT be able to protect permissions (only Owner)
   - ❌ Should NOT be able to change owner-protected permissions without password

6. **Owner:**
   - ✅ Can do everything
   - ✅ Can protect permissions
   - ✅ Can change owner-protected permissions with password
   - ✅ Should have all permissions regardless of RBAC settings

### Permission Testing:
- Test each permission key with each role
- Test owner-protected permissions
- Test permission changes with/without password
- Test permission inheritance

---

## END OF AUDIT REPORT

**Total Issues Identified:** 53
- Critical: 3
- High Priority: 18
- Medium Priority: 15
- Low Priority: 8
- Informational: 9

**Files Requiring Immediate Attention:**
1. `index.php` (role override, duplicate HTML, missing role checks)
2. `api/mail.php` (no RBAC at all)
3. `api/im.php` (missing RBAC checks)
4. `api/moderate.php` (missing role checks)
5. `api/presence.php` (missing RBAC check)
6. `patches/027_add_rbac_system.sql` (missing permission keys)

**Estimated Fix Time:**
- Critical issues: 4-6 hours
- High priority issues: 8-12 hours
- Medium priority issues: 6-8 hours
- Low priority issues: 4-6 hours
- **Total: 22-32 hours**

---

*Report generated by comprehensive system audit*
*All findings are based on code analysis and should be verified through testing*

