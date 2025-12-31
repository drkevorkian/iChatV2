<?php
/**
 * Sentinel Chat Platform - Main Entry Point
 * 
 * This is the main entry point for the web interface.
 * Located at the root of the iChat folder (not in a subdirectory).
 * 
 * Security: Sets security headers, handles authentication, and renders
 * the appropriate view based on user role.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use iChat\Config;
use iChat\Services\SecurityService;
use iChat\Services\AuthService;

// Set security headers before any output
try {
    $security = new SecurityService();
    $security->setSecurityHeaders();
} catch (\Exception $e) {
    // Log error but continue (for development)
    error_log('SecurityService error: ' . $e->getMessage());
    // Create a minimal security service fallback
    header('Content-Type: text/html; charset=UTF-8');
}

// Start session for user state
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = false;
    try {
        if (isset($security)) {
            $isHttps = $security->isHttps();
        }
    } catch (\Exception $e) {
        // Fallback: check HTTPS manually
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    }
    
    // Build session options array (PHP 7.3+ supports cookie_samesite)
    $sessionOptions = [
        'cookie_httponly' => true,
        'cookie_secure' => $isHttps,
    ];
    
    // Add cookie_samesite if PHP version supports it (7.3+)
    if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
        $sessionOptions['cookie_samesite'] = 'Strict';
    }
    
    session_start($sessionOptions);
}

// Get configuration
$config = Config::getInstance();

// Get authenticated user
try {
    $authService = new AuthService();
    $currentUser = $authService->getCurrentUser();
} catch (\Exception $e) {
    // Log error but continue as guest
    error_log('AuthService error: ' . $e->getMessage());
    $currentUser = null;
}

// Determine user role from authentication or fallback to guest
if ($currentUser !== null) {
    $userRole = $currentUser['role'];
    $userHandle = $currentUser['username'];
    $_SESSION['user_role'] = $userRole;
    $_SESSION['user_handle'] = $userHandle;
    $_SESSION['user_id'] = $currentUser['id'];
} else {
    // Guest user (not authenticated)
    $userRole = 'guest';
    $userHandle = $_SESSION['user_handle'] ?? '';
    
    // Require guests to register a name (redirect to guest registration if not set)
    if (empty($userHandle) || $userHandle === 'Guest') {
        // Check if this is the guest registration page itself
        $currentPage = basename($_SERVER['PHP_SELF']);
        if ($currentPage !== 'guest_register.php' && $currentPage !== 'login.php' && $currentPage !== 'register.php') {
            header('Location: /iChat/guest_register.php');
            exit;
        }
        // If on guest registration page, allow empty handle
        $userHandle = '';
    }
    
    $_SESSION['user_role'] = 'guest';
    
    // For development: allow setting role via GET parameter (remove in production)
    if (isset($_GET['role']) && in_array($_GET['role'], ['guest', 'user', 'moderator', 'administrator'], true)) {
        $_SESSION['user_role'] = $_GET['role'];
        $userRole = $_GET['role'];
    }
}

// Determine current view
$view = $_GET['view'] ?? 'user';
$area51Unlocked = isset($_SESSION['area51_unlocked']) && $_SESSION['area51_unlocked'] === true;

// Handle Area 51 unlock gesture (Ctrl+Shift+A or header gesture)
if (isset($_POST['area51_unlock']) && $_POST['area51_unlock'] === 'unlock') {
    $_SESSION['area51_unlocked'] = true;
    $area51Unlocked = true;
}

// Get API base URL for JavaScript
$apiBase = $config->get('app.api_base');
$defaultRoom = $config->get('ui.default_room');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Sentinel Chat - Secure Encrypted Chat Platform">
    <title>Sentinel Chat Platform</title>
    <link rel="stylesheet" href="/iChat/css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
</head>
<body>
    <header class="app-header">
        <div class="header-content">
            <h1 class="app-title">Sentinel Chat Platform</h1>
            <nav class="header-nav">
                <button class="nav-btn" data-view="user">User</button>
                <?php if ($currentUser !== null): ?>
                <button class="nav-btn" data-view="mail">Mail</button>
                <?php endif; ?>
                <?php if ($currentUser !== null && in_array($userRole, ['moderator', 'administrator'], true)): ?>
                <button class="nav-btn" data-view="moderator">Moderator</button>
                <?php endif; ?>
                <?php if ($currentUser !== null && in_array($userRole, ['administrator', 'trusted_admin', 'owner'], true)): ?>
                <button class="nav-btn" data-view="admin">Admin</button>
                <?php endif; ?>
                <?php if ($area51Unlocked): ?>
                <button class="nav-btn area51-btn" data-view="area51">Area 51</button>
                <?php endif; ?>
            </nav>
            <div class="header-status">
                <span class="loading-indicator" id="loading-indicator" style="display: none;" title="Loading...">
                    <i class="fas fa-spinner fa-spin"></i>
                </span>
                <span class="status-indicator" id="health-status" title="Server Health">●</span>
                <span class="mail-badge" id="mail-badge" style="display: none;">0</span>
                <?php if ($currentUser !== null): ?>
                    <?php
                    // Get user profile to check if avatar is set
                    $profileRepo = new \iChat\Repositories\ProfileRepository();
                    $userProfile = $profileRepo->getProfile($userHandle);
                    $hasCustomAvatar = false;
                    $avatarUrl = '';
                    
                    if ($userProfile) {
                        $avatarType = $userProfile['avatar_type'] ?? 'default';
                        $hasCustomAvatar = ($avatarType !== 'default' && !empty($userProfile['avatar_data'])) || 
                                          ($avatarType === 'gravatar' && !empty($userProfile['gravatar_email']));
                        
                        if ($hasCustomAvatar) {
                            // Generate avatar URL
                            if ($avatarType === 'gallery' && !empty($userProfile['avatar_data'])) {
                                $avatarUrl = '/iChat/api/gallery-image.php?id=' . (int)$userProfile['avatar_data'] . '&size=32';
                            } elseif ($avatarType === 'gravatar' && !empty($userProfile['gravatar_email'])) {
                                $avatarUrl = '/iChat/api/avatar-image.php?user=' . urlencode($userHandle) . '&size=32';
                            } else {
                                $avatarUrl = '/iChat/api/avatar-image.php?user=' . urlencode($userHandle) . '&size=32';
                            }
                        }
                    }
                    ?>
                    <button id="my-profile-btn" class="btn-header-profile" title="My Profile" data-has-avatar="<?php echo $hasCustomAvatar ? 'true' : 'false'; ?>">
                        <?php if ($hasCustomAvatar): ?>
                            <img src="<?php echo htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile" class="profile-btn-avatar">
                        <?php else: ?>
                            👤
                        <?php endif; ?>
                    </button>
                    <span class="user-info-header"><?php echo htmlspecialchars($userHandle, ENT_QUOTES, 'UTF-8'); ?></span>
                    <a href="/iChat/logout.php" class="btn-header-logout" title="Logout">Logout</a>
                <?php else: ?>
                    <a href="/iChat/login.php" class="btn-header-login" title="Login">Login</a>
                    <a href="/iChat/register.php" class="btn-header-register" title="Register">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="app-main">
        <!-- User View -->
        <div id="user-view" class="view-container <?php echo $view === 'user' ? 'active' : ''; ?>">
            <div class="user-view-wrapper">
                <div class="room-container" data-room="<?php echo htmlspecialchars($defaultRoom, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="room-header">
                        <div class="room-header-left">
                            <h2 id="room-title">Room: <span id="room-name"><?php echo htmlspecialchars($defaultRoom, ENT_QUOTES, 'UTF-8'); ?></span></h2>
                            <div class="online-users" id="online-users">
                                <span class="online-indicator">●</span>
                                <span class="online-count" id="online-count">0 online</span>
                            </div>
                        </div>
                        <div class="room-header-right">
                            <div class="room-selector">
                                <label for="room-select">Switch Room:</label>
                                <select id="room-select">
                                    <option value="<?php echo htmlspecialchars($defaultRoom, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($defaultRoom, ENT_QUOTES, 'UTF-8'); ?></option>
                                    <option value="general">General</option>
                                    <option value="support">Support</option>
                                    <option value="tech">Tech</option>
                                    <option value="random">Random</option>
                                </select>
                            </div>
                            <?php if ($currentUser !== null): ?>
                            <button id="manage-room-btn" class="btn-secondary" title="Manage Room Settings" style="display: none;">
                                <i class="fas fa-cog"></i> Manage Room
                            </button>
                            <button id="join-room-btn" class="btn-secondary" title="Join a Room with Invite Code">
                                <i class="fas fa-key"></i> Join Room
                            </button>
                            <button id="request-room-btn" class="btn-secondary" title="Request a Private Room">
                                <i class="fas fa-plus"></i> Request Room
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="chat-messages" id="chat-messages">
                        <div class="messages-container" id="messages-container">
                            <div class="loading-messages">Loading messages...</div>
                        </div>
                    </div>
                    
                    <div class="message-composer">
                        <div class="composer-input-group">
                            <div class="composer-controls">
                                <div class="composer-buttons">
                                    <button id="emoji-picker-btn" class="emoji-picker-btn" title="Insert Emoji">😀</button>
                                    <button id="media-upload-btn" class="emoji-picker-btn" title="Upload Image/Video/Audio">
                                        <i class="fas fa-paperclip"></i>
                                    </button>
                                    <button id="voice-record-btn" class="emoji-picker-btn" title="Record Voice Message">
                                        <i class="fas fa-microphone"></i>
                                    </button>
                                </div>
                                <textarea id="message-input" placeholder="Type your message..." maxlength="1000" rows="3" required></textarea>
                            </div>
                            <button id="send-btn" class="btn-primary">Send</button>
                        </div>
                        <input type="file" id="chat-media-input" accept="image/*,video/*,audio/*" multiple style="display: none;">
                        <div id="chat-media-preview" class="chat-media-preview" style="display: none;"></div>
                        <!-- Emoji Picker -->
                        <div id="emoji-picker" class="emoji-picker" style="display: none;">
                            <div class="emoji-picker-header">
                                <div class="emoji-picker-tabs">
                                    <button class="emoji-tab active" data-tab="recent">Recent</button>
                                    <button class="emoji-tab" data-tab="smileys">😀</button>
                                    <button class="emoji-tab" data-tab="people">👥</button>
                                    <button class="emoji-tab" data-tab="animals">🐾</button>
                                    <button class="emoji-tab" data-tab="food">🍔</button>
                                    <button class="emoji-tab" data-tab="travel">✈️</button>
                                    <button class="emoji-tab" data-tab="objects">💡</button>
                                    <button class="emoji-tab" data-tab="symbols">🔣</button>
                                </div>
                                <div class="emoji-picker-search">
                                    <input type="text" id="emoji-search-input" placeholder="Search emojis..." />
                                </div>
                                <button class="emoji-picker-close" id="emoji-picker-close" title="Close">×</button>
                            </div>
                            <div class="emoji-picker-content" id="emoji-picker-content">
                                <div class="emoji-loading">Loading emojis...</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="online-users-list" id="online-users-list">
                    <div class="online-users-list-header">
                        <h3>Online Users</h3>
                    </div>
                    <div class="online-users-list-content">
                        <div class="users-loading">Loading users...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mail View -->
        <?php if ($currentUser !== null): ?>
        <div id="mail-view" class="view-container <?php echo $view === 'mail' ? 'active' : ''; ?>">
            <div class="mail-container">
                <div class="mail-sidebar">
                    <div class="mail-sidebar-header">
                        <button id="compose-mail-btn" class="btn-primary">
                            <i class="fas fa-plus"></i> Compose
                        </button>
                    </div>
                    <div class="mail-folders">
                        <div class="mail-folder active" data-folder="inbox">
                            <i class="fas fa-inbox"></i> Inbox
                            <span class="mail-folder-badge" id="inbox-badge" style="display: none;">0</span>
                        </div>
                        <div class="mail-folder" data-folder="sent">
                            <i class="fas fa-paper-plane"></i> Sent
                        </div>
                        <div class="mail-folder" data-folder="drafts">
                            <i class="fas fa-file-alt"></i> Drafts
                        </div>
                        <div class="mail-folder" data-folder="archive">
                            <i class="fas fa-archive"></i> Archive
                        </div>
                        <div class="mail-folder" data-folder="trash">
                            <i class="fas fa-trash"></i> Trash
                        </div>
                    </div>
                </div>
                <div class="mail-content">
                    <div class="mail-list-container" id="mail-list-container">
                        <div class="loading-messages">Loading mail...</div>
                    </div>
                    <div class="mail-viewer-container" id="mail-viewer-container" style="display: none;">
                        <div class="mail-viewer-header">
                            <button id="back-to-list-btn" class="btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back
                            </button>
                            <div class="mail-viewer-actions">
                                <button id="star-mail-btn" class="btn-icon" title="Star">
                                    <i class="far fa-star"></i>
                                </button>
                                <button id="reply-mail-btn" class="btn-secondary">
                                    <i class="fas fa-reply"></i> Reply
                                </button>
                                <button id="delete-mail-btn" class="btn-error">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                        <div class="mail-viewer-content" id="mail-viewer-content">
                            <!-- Mail content will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Settings View -->
        <?php if ($currentUser !== null): ?>
        <div id="settings-view" class="view-container <?php echo $view === 'settings' ? 'active' : ''; ?>">
            <h2>Settings</h2>
            <div class="settings-container">
                <div class="settings-tabs">
                    <button class="settings-tab-btn active" data-tab="profile">Profile</button>
                    <button class="settings-tab-btn" data-tab="appearance">Appearance</button>
                    <button class="settings-tab-btn" data-tab="gallery">Gallery</button>
                    <button class="settings-tab-btn" data-tab="notifications">Notifications</button>
                    <button class="settings-tab-btn" data-tab="chat">Chat</button>
                </div>
                
                <div class="settings-content">
                    <!-- Profile Tab -->
                    <div class="settings-tab-pane active" id="settings-tab-profile">
                        <h3>Profile Information</h3>
                        <form id="settings-profile-form">
                            <!-- Avatar Selection -->
                            <div class="form-group">
                                <label>Profile Picture:</label>
                                <div class="avatar-selection-container">
                                    <div class="avatar-preview-container">
                                        <img id="avatar-preview" src="/iChat/images/default-avatar.png" alt="Avatar Preview" class="avatar-preview">
                                    </div>
                                    <div class="avatar-options">
                                        <div class="avatar-option-group">
                                            <label>
                                                <input type="radio" name="avatar-type" value="default" checked> Default Avatar
                                            </label>
                                        </div>
                                        <div class="avatar-option-group">
                                            <label>
                                                <input type="radio" name="avatar-type" value="gravatar"> Use Gravatar
                                            </label>
                                            <input type="email" id="gravatar-email" class="form-control" placeholder="Email for Gravatar" style="margin-top: 0.5rem; display: none;">
                                        </div>
                                        <div class="avatar-option-group">
                                            <label>
                                                <input type="radio" name="avatar-type" value="gallery"> From Gallery
                                            </label>
                                            <div id="avatar-gallery-container" style="margin-top: 0.5rem; display: none;">
                                                <div id="avatar-gallery-grid" class="avatar-gallery-grid"></div>
                                                <button type="button" class="btn-secondary" id="upload-avatar-btn" style="margin-top: 0.5rem;">
                                                    <i class="fas fa-upload"></i> Upload New Image
                                                </button>
                                                <input type="file" id="avatar-upload-input" accept="image/*" style="display: none;">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="settings-display-name">Display Name:</label>
                                <input type="text" id="settings-display-name" class="form-control" placeholder="Display name">
                            </div>
                            <div class="form-group">
                                <label for="settings-bio">Bio:</label>
                                <textarea id="settings-bio" class="form-control" rows="4" placeholder="Tell us about yourself"></textarea>
                            </div>
                            <div class="form-group">
                                <label for="settings-status-message">Status Message:</label>
                                <input type="text" id="settings-status-message" class="form-control" placeholder="What's on your mind?">
                            </div>
                            <div class="form-actions">
                                <button type="button" class="btn-primary" id="settings-profile-save">Save Profile</button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Appearance Tab -->
                    <div class="settings-tab-pane" id="settings-tab-appearance">
                        <h3>Chat Appearance</h3>
                        <form id="settings-appearance-form">
                            <div class="form-group">
                                <label for="settings-chat-text-color">Chat Text Color:</label>
                                <div class="color-input-group">
                                    <input type="color" id="settings-chat-text-color" class="form-control color-picker" value="#000000">
                                    <input type="text" id="settings-chat-text-color-hex" class="form-control color-hex" value="#000000" pattern="^#[0-9A-Fa-f]{6}$">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="settings-chat-name-color">Username Color:</label>
                                <div class="color-input-group">
                                    <input type="color" id="settings-chat-name-color" class="form-control color-picker" value="#0070ff">
                                    <input type="text" id="settings-chat-name-color-hex" class="form-control color-hex" value="#0070ff" pattern="^#[0-9A-Fa-f]{6}$">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="settings-font-size">Font Size:</label>
                                <select id="settings-font-size" class="form-control">
                                    <option value="small">Small</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="large">Large</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="settings-theme">Theme:</label>
                                <select id="settings-theme" class="form-control">
                                    <option value="default" selected>Default (Blue & Ghost White)</option>
                                    <option value="dark">Dark Theme</option>
                                    <option value="colorful">Colorful Theme</option>
                                    <option value="custom">Build Your Own Theme</option>
                                </select>
                            </div>
                            
                            <!-- Custom Theme Builder (shown when custom is selected) -->
                            <div id="custom-theme-builder" class="custom-theme-builder" style="display: none;">
                                <h4>Custom Theme Colors</h4>
                                <div class="form-group">
                                    <label for="custom-primary-color">Primary Color:</label>
                                    <div class="color-input-group">
                                        <input type="color" id="custom-primary-color" class="form-control color-picker" value="#0070ff">
                                        <input type="text" id="custom-primary-color-hex" class="form-control color-hex" value="#0070ff" pattern="^#[0-9A-Fa-f]{6}$">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="custom-background-color">Background Color:</label>
                                    <div class="color-input-group">
                                        <input type="color" id="custom-background-color" class="form-control color-picker" value="#f8f8ff">
                                        <input type="text" id="custom-background-color-hex" class="form-control color-hex" value="#f8f8ff" pattern="^#[0-9A-Fa-f]{6}$">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="custom-surface-color">Surface Color:</label>
                                    <div class="color-input-group">
                                        <input type="color" id="custom-surface-color" class="form-control color-picker" value="#ffffff">
                                        <input type="text" id="custom-surface-color-hex" class="form-control color-hex" value="#ffffff" pattern="^#[0-9A-Fa-f]{6}$">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="custom-text-color">Text Color:</label>
                                    <div class="color-input-group">
                                        <input type="color" id="custom-text-color" class="form-control color-picker" value="#1a1a1a">
                                        <input type="text" id="custom-text-color-hex" class="form-control color-hex" value="#1a1a1a" pattern="^#[0-9A-Fa-f]{6}$">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="custom-border-color">Border Color:</label>
                                    <div class="color-input-group">
                                        <input type="color" id="custom-border-color" class="form-control color-picker" value="#e0e0e0">
                                        <input type="text" id="custom-border-color-hex" class="form-control color-hex" value="#e0e0e0" pattern="^#[0-9A-Fa-f]{6}$">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="custom-success-color">Success Color:</label>
                                    <div class="color-input-group">
                                        <input type="color" id="custom-success-color" class="form-control color-picker" value="#28a745">
                                        <input type="text" id="custom-success-color-hex" class="form-control color-hex" value="#28a745" pattern="^#[0-9A-Fa-f]{6}$">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="custom-warning-color">Warning Color:</label>
                                    <div class="color-input-group">
                                        <input type="color" id="custom-warning-color" class="form-control color-picker" value="#ffc107">
                                        <input type="text" id="custom-warning-color-hex" class="form-control color-hex" value="#ffc107" pattern="^#[0-9A-Fa-f]{6}$">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="custom-error-color">Error Color:</label>
                                    <div class="color-input-group">
                                        <input type="color" id="custom-error-color" class="form-control color-picker" value="#dc3545">
                                        <input type="text" id="custom-error-color-hex" class="form-control color-hex" value="#dc3545" pattern="^#[0-9A-Fa-f]{6}$">
                                    </div>
                                </div>
                                <button type="button" class="btn-secondary" id="preview-custom-theme">Preview Theme</button>
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" id="settings-compact-mode"> Use compact mode
                                </label>
                            </div>
                            <div class="form-actions">
                                <button type="button" class="btn-primary" id="settings-appearance-save">Save Appearance</button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Notifications Tab -->
                    <div class="settings-tab-pane" id="settings-tab-notifications">
                        <h3>Notifications</h3>
                        <form id="settings-notifications-form">
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" id="settings-show-timestamps" checked> Show message timestamps
                                </label>
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" id="settings-sound-notifications" checked> Enable sound notifications
                                </label>
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" id="settings-desktop-notifications"> Enable desktop notifications
                                </label>
                            </div>
                            <div class="form-actions">
                                <button type="button" class="btn-primary" id="settings-notifications-save">Save Notifications</button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Chat Tab -->
                    <!-- Gallery Tab -->
                    <div class="settings-tab-pane" id="settings-tab-gallery">
                        <h3>My Gallery</h3>
                        <div class="gallery-management">
                            <div class="gallery-controls">
                                <button type="button" class="btn-primary" id="gallery-upload-btn">
                                    <i class="fas fa-upload"></i> Upload Image
                                </button>
                                <input type="file" id="gallery-upload-input" accept="image/*" multiple style="display: none;">
                            </div>
                            <div id="gallery-grid" class="gallery-grid">
                                <div class="loading-messages">Loading gallery...</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="settings-tab-pane" id="settings-tab-chat">
                        <h3>Chat Preferences</h3>
                        <form id="settings-chat-form">
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" id="settings-auto-scroll" checked> Auto-scroll to bottom on new messages
                                </label>
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" id="settings-word-filter-enabled" checked> Enable word filtering
                                </label>
                                <small class="form-help">Filter inappropriate words from messages</small>
                            </div>
                            <div class="form-group">
                                <label for="settings-language">Language:</label>
                                <select id="settings-language" class="form-control">
                                    <option value="en" selected>English</option>
                                    <option value="es">Spanish</option>
                                    <option value="fr">French</option>
                                    <option value="de">German</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="settings-timezone">Timezone:</label>
                                <select id="settings-timezone" class="form-control">
                                    <option value="UTC" selected>UTC</option>
                                    <option value="America/New_York">Eastern Time (US)</option>
                                    <option value="America/Chicago">Central Time (US)</option>
                                    <option value="America/Denver">Mountain Time (US)</option>
                                    <option value="America/Los_Angeles">Pacific Time (US)</option>
                                    <option value="Europe/London">London</option>
                                    <option value="Europe/Paris">Paris</option>
                                    <option value="Asia/Tokyo">Tokyo</option>
                                </select>
                            </div>
                            <div class="form-actions">
                                <button type="button" class="btn-primary" id="settings-chat-save">Save Chat Preferences</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Moderator View -->
        <?php if ($currentUser !== null && in_array($userRole, ['moderator', 'administrator'], true)): ?>
        <div id="moderator-view" class="view-container <?php echo $view === 'moderator' ? 'active' : ''; ?>">
            <h2>Moderator Dashboard</h2>
            
            <!-- Moderator Tabs -->
            <div class="moderator-tabs">
                <div class="moderator-tab-nav">
                    <button class="moderator-tab-btn active" data-tab="users">
                        <i class="fas fa-users"></i> Online Users
                    </button>
                    <button class="moderator-tab-btn" data-tab="flagged">
                        <i class="fas fa-flag"></i> Flagged Messages
                    </button>
                    <button class="moderator-tab-btn" data-tab="word-filter-requests">
                        <i class="fas fa-filter"></i> Word Filter Requests
                    </button>
                    <button class="moderator-tab-btn" data-tab="logs">
                        <i class="fas fa-file-alt"></i> Logs
                    </button>
                </div>
                
                <div class="moderator-tab-content">
                    <!-- Users Tab -->
                    <div class="moderator-tab-pane active" id="moderator-tab-users">
                        <div class="user-management">
                            <h3>Online Users</h3>
                            <div class="user-controls">
                                <button id="refresh-users-btn" class="btn-primary">Refresh User List</button>
                                <select id="room-filter-select">
                                    <option value="">All Rooms</option>
                                    <option value="lobby">Lobby</option>
                                    <option value="general">General</option>
                                    <option value="support">Support</option>
                                    <option value="tech">Tech</option>
                                    <option value="random">Random</option>
                                </select>
                            </div>
                            <div id="users-list" class="users-list"></div>
                        </div>
                    </div>
                    
                    <!-- Flagged Messages Tab -->
                    <div class="moderator-tab-pane" id="moderator-tab-flagged">
                        <div class="flagged-messages">
                            <h3>Flagged Messages</h3>
                            <div id="flagged-list" class="message-list"></div>
                        </div>
                    </div>
                    
                    <!-- Word Filter Requests Tab -->
                    <div class="moderator-tab-pane" id="moderator-tab-word-filter-requests">
                        <div class="word-filter-requests-section">
                            <h3>Word Filter Change Requests</h3>
                            <p>Submit requests to add, edit, or remove words from the filter list. Admins will review your requests.</p>
                            <button id="request-word-filter-btn" class="btn-primary" style="margin-bottom: 1rem;">Submit Word Filter Request</button>
                            <div id="moderator-word-filter-requests-list" class="word-filter-requests-list"></div>
                        </div>
                    </div>
                    
                    <!-- Logs Tab -->
                    <div class="moderator-tab-pane" id="moderator-tab-logs">
                        <div class="log-viewer-section">
                            <h3>Log Viewer</h3>
                            <div class="log-viewer-controls">
                                <select id="log-file-select-moderator">
                                    <option value="">Select a log file...</option>
                                    <option value="error.log">error.log (Current)</option>
                                    <option value="patches.log">patches.log</option>
                                    <option value="rotation.log">rotation.log</option>
                                </select>
                                <button id="refresh-logs-btn-moderator" class="btn-primary">Refresh Logs</button>
                                <button id="rotate-log-btn-moderator" class="btn-secondary">Rotate Current Log</button>
                                <input type="number" id="log-limit-input-moderator" value="1000" min="100" max="5000" step="100" style="width: 100px; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px;">
                                <label for="log-limit-input-moderator">Lines to show</label>
                            </div>
                            <div id="archived-logs-list-moderator" class="archived-logs-list" style="margin-top: 1rem;">
                                <h4>Archived Logs</h4>
                                <div id="archived-logs-content-moderator" class="archived-logs-content"></div>
                            </div>
                            <div id="log-content-moderator" class="log-content" style="margin-top: 1rem; max-height: 600px; overflow-y: auto; background: var(--bg-secondary); padding: 1rem; border-radius: 4px; font-family: monospace; font-size: 0.9rem; white-space: pre-wrap; word-wrap: break-word;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Admin View -->
        <?php if ($currentUser !== null && in_array($userRole, ['administrator', 'trusted_admin', 'owner'], true)): ?>
        <div id="admin-view" class="view-container <?php echo $view === 'admin' ? 'active' : ''; ?>">
            <h2>Admin Dashboard</h2>
            
            <!-- Admin Dashboard Tabs -->
            <div class="admin-tabs">
                <!-- Category Tabs (Top Row) -->
                <div class="admin-category-nav">
                    <button class="admin-category-btn active" data-category="overview">
                        <i class="fas fa-chart-line"></i> Overview
                    </button>
                    <button class="admin-category-btn" data-category="system">
                        <i class="fas fa-cog"></i> System
                    </button>
                    <button class="admin-category-btn" data-category="users">
                        <i class="fas fa-users"></i> Users
                    </button>
                    <button class="admin-category-btn" data-category="moderation">
                        <i class="fas fa-shield-alt"></i> Moderation
                        <span class="admin-tab-badge" id="badge-reports" style="display: none;">0</span>
                    </button>
                    <button class="admin-category-btn" data-category="escrow">
                        <i class="fas fa-lock"></i> Escrow
                    </button>
                    <button class="admin-category-btn" data-category="ai-systems">
                        <i class="fas fa-robot"></i> AI Systems
                    </button>
                </div>
                
                <!-- Sub-Tabs (Second Row) - Shown when category is selected -->
                <div class="admin-subtab-nav" id="admin-subtab-nav" style="display: none;">
                    <!-- System Sub-Tabs -->
                    <div class="admin-subtab-group" data-category="system" style="display: none;">
                        <button class="admin-subtab-btn active" data-tab="database">
                            <i class="fas fa-database"></i> Database
                        </button>
                        <button class="admin-subtab-btn" data-tab="patches">
                            <i class="fas fa-code-branch"></i> Patches
                            <span class="admin-tab-badge" id="badge-patches" style="display: none;">0</span>
                        </button>
                        <button class="admin-subtab-btn" data-tab="word-filters">
                            <i class="fas fa-filter"></i> Word Filters
                            <span class="admin-tab-badge" id="badge-word-filter-requests" style="display: none;">0</span>
                        </button>
                        <button class="admin-subtab-btn" data-tab="logs">
                            <i class="fas fa-file-alt"></i> Logs
                        </button>
                        <button class="admin-subtab-btn" data-tab="websocket">
                            <i class="fas fa-plug"></i> WebSocket Server
                        </button>
                        <button class="admin-subtab-btn" data-tab="file-storage">
                            <i class="fas fa-folder-open"></i> File Storage
                        </button>
                        <button class="admin-subtab-btn" data-tab="audit-logs">
                            <i class="fas fa-clipboard-list"></i> Audit Logs
                        </button>
                        <button class="admin-subtab-btn" data-tab="rbac">
                            <i class="fas fa-user-shield"></i> RBAC Permissions
                        </button>
                    </div>
                    
                    <!-- Users Sub-Tabs -->
                    <div class="admin-subtab-group" data-category="users" style="display: none;">
                        <button class="admin-subtab-btn active" data-tab="users">
                            <i class="fas fa-users"></i> User Database
                        </button>
                        <button class="admin-subtab-btn" data-tab="online">
                            <i class="fas fa-user-check"></i> Online Users
                        </button>
                        <button class="admin-subtab-btn" data-tab="room-requests">
                            <i class="fas fa-door-open"></i> Room Requests
                            <span class="admin-tab-badge" id="badge-room-requests" style="display: none;">0</span>
                        </button>
                    </div>
                    
                    <!-- AI Systems Sub-Tabs -->
                    <div class="admin-subtab-group" data-category="ai-systems" style="display: none;">
                        <button class="admin-subtab-btn active" data-tab="ai-config">
                            <i class="fas fa-cog"></i> Configuration
                        </button>
                        <button class="admin-subtab-btn" data-tab="ai-moderation">
                            <i class="fas fa-shield-alt"></i> Auto-Moderation
                        </button>
                        <button class="admin-subtab-btn" data-tab="ai-smart-replies">
                            <i class="fas fa-lightbulb"></i> Smart Replies
                        </button>
                        <button class="admin-subtab-btn" data-tab="ai-summarization">
                            <i class="fas fa-compress"></i> Summarization
                        </button>
                        <button class="admin-subtab-btn" data-tab="ai-bot">
                            <i class="fas fa-comments"></i> Bot Features
                        </button>
                    </div>
                </div>
                
                <!-- Tab Content -->
                <div class="admin-tab-content">
                    <!-- Overview Tab -->
                    <div class="admin-tab-pane active" id="admin-tab-overview">
                        <div class="admin-telemetry">
                            <div class="telemetry-card">
                                <h3>Queue Depth</h3>
                                <div class="telemetry-value" id="queue-depth">0</div>
                            </div>
                            <div class="telemetry-card">
                                <h3>Escrow Requests</h3>
                                <div class="telemetry-value" id="escrow-count">0</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Database Tab -->
                    <div class="admin-tab-pane" id="admin-tab-database">
                        <div class="database-repair">
                            <h3>Database Health & Repair</h3>
                            <div class="repair-controls">
                                <button id="check-db-health-btn" class="btn-primary">Check Database Health</button>
                                <button id="repair-db-btn" class="btn-primary">Repair Database</button>
                            </div>
                            <div id="db-health-status" class="db-health-status"></div>
                        </div>
                    </div>
                    
                    <!-- Patches Tab -->
                    <div class="admin-tab-pane" id="admin-tab-patches">
                        <div class="patch-management">
                            <h3>Patch Management</h3>
                            <div class="patch-controls">
                                <button id="refresh-patches-btn" class="btn-primary">Refresh Patch List</button>
                            </div>
                            <div id="patches-list" class="patches-list"></div>
                        </div>
                    </div>
                    
                    <!-- Moderation Tab -->
                    <div class="admin-tab-pane" id="admin-tab-moderation">
                        <div class="message-moderation-section">
                            <h3>Message Moderation</h3>
                            <div class="moderation-controls">
                                <button id="mock-message-btn" class="btn-primary">Create Mock Message</button>
                                <p class="moderation-note">Create a message as if sent by another user (admin only)</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- User Database Tab -->
                    <div class="admin-tab-pane" id="admin-tab-users">
                        <div class="user-database">
                            <h3>User Database</h3>
                            <div class="user-db-controls">
                                <button id="refresh-all-users-btn" class="btn-primary">Refresh All Users</button>
                                <button id="refresh-online-users-btn" class="btn-primary">Online Users Only</button>
                                <select id="user-filter-select">
                                    <option value="all">All Users</option>
                                    <option value="online">Online Only</option>
                                    <option value="offline">Offline Only</option>
                                    <option value="registered">Registered Users</option>
                                    <option value="guests">Guests Only</option>
                                    <option value="banned">Banned Users</option>
                                    <option value="muted">Muted Users</option>
                                </select>
                                <input type="text" id="user-search-input" placeholder="Search users..." style="padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px; width: 200px;">
                            </div>
                            <div id="user-database-list" class="user-database-list"></div>
                        </div>
                    </div>
                    
                    <!-- Online Users Tab -->
                    <div class="admin-tab-pane" id="admin-tab-online">
                        <div class="user-management">
                            <h3>Online Users (Quick View)</h3>
                            <div class="user-controls">
                                <button id="refresh-users-admin-btn" class="btn-primary">Refresh User List</button>
                                <select id="room-filter-admin-select">
                                    <option value="">All Rooms</option>
                                    <option value="lobby">Lobby</option>
                                    <option value="general">General</option>
                                    <option value="support">Support</option>
                                    <option value="tech">Tech</option>
                                    <option value="random">Random</option>
                                </select>
                            </div>
                            <div id="users-admin-list" class="users-list"></div>
                        </div>
                    </div>
                    
                    <!-- Escrow Tab -->
                    <div class="admin-tab-pane" id="admin-tab-escrow">
                        <div class="escrow-requests">
                            <h3>Escrow Requests</h3>
                            <form id="escrow-form" class="escrow-form">
                                <input type="text" id="escrow-room" placeholder="Room ID" required pattern="[a-zA-Z0-9_-]+" maxlength="255">
                                <input type="text" id="escrow-operator" placeholder="Operator Handle" required pattern="[a-zA-Z0-9._-]{1,50}" maxlength="50">
                                <textarea id="escrow-justification" placeholder="Justification" required maxlength="5000"></textarea>
                                <button type="submit" class="btn-primary">Submit Escrow Request</button>
                            </form>
                            <div id="escrow-list" class="escrow-list"></div>
                        </div>
                    </div>
                    
                    <!-- Room Requests Tab -->
                    <div class="admin-tab-pane" id="admin-tab-room-requests">
                        <div class="room-requests-section">
                            <h3>Room Requests</h3>
                            <div class="room-requests-controls">
                                <button id="refresh-room-requests-btn" class="btn-primary">Refresh Requests</button>
                                <select id="room-request-status-filter">
                                    <option value="">All Statuses</option>
                                    <option value="pending">Pending</option>
                                    <option value="approved">Approved</option>
                                    <option value="denied">Denied</option>
                                    <option value="active">Active</option>
                                </select>
                            </div>
                            <div id="room-requests-list" class="room-requests-list"></div>
                        </div>
                    </div>
                    
                    <!-- Word Filters Tab -->
                    <div class="admin-tab-pane" id="admin-tab-word-filters">
                        <div class="word-filters-section">
                            <h3>Word Filter Management</h3>
                            <div class="word-filters-controls">
                                <button id="refresh-word-filters-btn" class="btn-primary">Refresh Filters</button>
                                <button id="add-word-filter-btn" class="btn-primary">Add New Filter</button>
                                <select id="word-filter-status-filter">
                                    <option value="">All Filters</option>
                                    <option value="active">Active Only</option>
                                    <option value="inactive">Inactive Only</option>
                                </select>
                                <select id="word-filter-severity-filter">
                                    <option value="">All Severities</option>
                                    <option value="1">Severity 1 (Mild)</option>
                                    <option value="2">Severity 2 (Moderate)</option>
                                    <option value="3">Severity 3 (Severe)</option>
                                    <option value="4">Severity 4 (Extreme)</option>
                                </select>
                                <input type="text" id="word-filter-search" placeholder="Search filters..." style="padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px; width: 200px;">
                            </div>
                            <div id="word-filters-list" class="word-filters-list"></div>
                            
                            <h3 style="margin-top: 2rem;">Word Filter Requests</h3>
                            <div class="word-filter-requests-controls">
                                <button id="refresh-word-filter-requests-btn" class="btn-primary">Refresh Requests</button>
                                <select id="word-filter-request-status-filter">
                                    <option value="">All Statuses</option>
                                    <option value="pending">Pending</option>
                                    <option value="approved">Approved</option>
                                    <option value="denied">Denied</option>
                                </select>
                            </div>
                            <div id="word-filter-requests-list" class="word-filter-requests-list"></div>
                        </div>
                    </div>
                    
                    <!-- Logs Tab -->
                    <div class="admin-tab-pane" id="admin-tab-logs">
                        <div class="log-viewer-section">
                            <h3>Log Viewer</h3>
                            <div class="log-viewer-controls">
                                <select id="log-file-select">
                                    <option value="">Select a log file...</option>
                                    <option value="error.log">error.log (Current)</option>
                                    <option value="patches.log">patches.log</option>
                                    <option value="rotation.log">rotation.log</option>
                                </select>
                                <button id="refresh-logs-btn" class="btn-primary">Refresh Logs</button>
                                <button id="rotate-log-btn" class="btn-secondary">Rotate Current Log</button>
                                <input type="number" id="log-limit-input" value="1000" min="100" max="5000" step="100" style="width: 100px; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px;">
                                <label for="log-limit-input">Lines to show</label>
                            </div>
                            <div id="archived-logs-list" class="archived-logs-list" style="margin-top: 1rem;">
                                <h4>Archived Logs</h4>
                                <div id="archived-logs-content" class="archived-logs-content"></div>
                            </div>
                            <div id="log-content" class="log-content" style="margin-top: 1rem; max-height: 600px; overflow-y: auto; background: var(--bg-secondary); padding: 1rem; border-radius: 4px; font-family: monospace; font-size: 0.9rem; white-space: pre-wrap; word-wrap: break-word;"></div>
                        </div>
                    </div>
                    
                    <!-- WebSocket Server Tab -->
                    <div class="admin-tab-pane" id="admin-tab-websocket">
                        <div class="admin-section">
                            <h3><i class="fas fa-plug"></i> WebSocket Server Management</h3>
                            
                            <!-- Python Server (Primary) -->
                            <div class="websocket-status-panel" style="margin-bottom: 2rem;">
                                <h4 style="margin-bottom: 1rem;"><i class="fab fa-python"></i> Python Server (Primary - Port 4291)</h4>
                                <div class="status-indicator">
                                    <div class="status-light" id="python-status-light"></div>
                                    <span id="python-status-text">Checking status...</span>
                                </div>
                                
                                <div class="status-details" id="python-status-details">
                                    <div class="status-row">
                                        <span class="status-label">Port:</span>
                                        <span class="status-value" id="python-port">4291</span>
                                    </div>
                                    <div class="status-row">
                                        <span class="status-label">PID:</span>
                                        <span class="status-value" id="python-pid">-</span>
                                    </div>
                                    <div class="status-row">
                                        <span class="status-label">Uptime:</span>
                                        <span class="status-value" id="python-uptime">-</span>
                                    </div>
                                </div>
                                
                                <div class="websocket-controls">
                                    <button class="btn-primary" id="python-start-btn">
                                        <i class="fas fa-play"></i> Start Python Server
                                    </button>
                                    <button class="btn-secondary" id="python-stop-btn">
                                        <i class="fas fa-stop"></i> Stop Python Server
                                    </button>
                                    <button class="btn-secondary" id="python-restart-btn">
                                        <i class="fas fa-redo"></i> Restart Python Server
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Node.js Server (Secondary) -->
                            <div class="websocket-status-panel">
                                <h4 style="margin-bottom: 1rem;"><i class="fab fa-node-js"></i> Node.js Server (Secondary - Port 8420)</h4>
                                <div class="status-indicator">
                                    <div class="status-light" id="websocket-status-light"></div>
                                    <span id="websocket-status-text">Checking status...</span>
                                </div>
                                
                                <div class="status-details" id="websocket-status-details">
                                    <div class="status-row">
                                        <span class="status-label">Port:</span>
                                        <span class="status-value" id="websocket-port">8420</span>
                                    </div>
                                    <div class="status-row">
                                        <span class="status-label">Host:</span>
                                        <span class="status-value" id="websocket-host">localhost</span>
                                    </div>
                                    <div class="status-row">
                                        <span class="status-label">PID:</span>
                                        <span class="status-value" id="websocket-pid">-</span>
                                    </div>
                                    <div class="status-row">
                                        <span class="status-label">Uptime:</span>
                                        <span class="status-value" id="websocket-uptime">-</span>
                                    </div>
                                    <div class="status-row">
                                        <span class="status-label">Connected Users:</span>
                                        <span class="status-value" id="websocket-connected-users">-</span>
                                    </div>
                                    <div class="status-row">
                                        <span class="status-label">Total Connections:</span>
                                        <span class="status-value" id="websocket-connections">-</span>
                                    </div>
                                    <div class="status-row">
                                        <span class="status-label">Active Rooms:</span>
                                        <span class="status-value" id="websocket-active-rooms">-</span>
                                    </div>
                                </div>
                                
                                <!-- Connected Users List -->
                                <div class="websocket-users-section" id="websocket-users-section" style="display: none;">
                                    <h4>Connected Users:</h4>
                                    <div class="websocket-users-list" id="websocket-users-list"></div>
                                </div>
                                
                                <!-- Active Rooms List -->
                                <div class="websocket-rooms-section" id="websocket-rooms-section" style="display: none;">
                                    <h4>Active Rooms:</h4>
                                    <div class="websocket-rooms-list" id="websocket-rooms-list"></div>
                                </div>
                                
                                <div class="websocket-controls">
                                    <button class="btn-primary" id="websocket-start-btn">
                                        <i class="fas fa-play"></i> Start Node.js Server
                                    </button>
                                    <button class="btn-secondary" id="websocket-stop-btn">
                                        <i class="fas fa-stop"></i> Stop Node.js Server
                                    </button>
                                    <button class="btn-secondary" id="websocket-restart-btn">
                                        <i class="fas fa-redo"></i> Restart Node.js Server
                                    </button>
                                    <button class="btn-secondary" id="websocket-refresh-btn">
                                        <i class="fas fa-sync"></i> Refresh Status
                                    </button>
                                </div>
                            </div>
                            
                            <div class="websocket-logs-panel" style="margin-top: 2rem;">
                                <div class="logs-header">
                                    <h4><i class="fas fa-terminal"></i> Server Logs</h4>
                                    <div class="logs-controls">
                                        <label>
                                            Server:
                                            <select id="websocket-logs-server">
                                                <option value="python">Python Server</option>
                                                <option value="node">Node.js Server</option>
                                            </select>
                                        </label>
                                        <label>
                                            Lines to show:
                                            <select id="websocket-logs-lines">
                                                <option value="50">50</option>
                                                <option value="100" selected>100</option>
                                                <option value="200">200</option>
                                                <option value="500">500</option>
                                            </select>
                                        </label>
                                        <button class="btn-secondary btn-sm" id="websocket-logs-refresh">
                                            <i class="fas fa-sync"></i> Refresh Logs
                                        </button>
                                        <button class="btn-secondary btn-sm" id="websocket-logs-clear">
                                            <i class="fas fa-trash"></i> Clear Logs
                                        </button>
                                    </div>
                                </div>
                                <div class="logs-container" id="websocket-logs-container">
                                    <div class="loading-messages">Loading logs...</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- File Storage Tab -->
                    <div class="admin-tab-pane" id="admin-tab-file-storage">
                        <div class="file-storage-section">
                            <h3>File Storage Queue Management</h3>
                            <div class="file-storage-controls">
                                <select id="file-storage-type-filter">
                                    <option value="">All Types</option>
                                    <option value="message">Messages</option>
                                    <option value="im">Instant Messages</option>
                                    <option value="presence">Presence</option>
                                    <option value="room_request">Room Requests</option>
                                    <option value="report">Reports</option>
                                </select>
                                <button id="refresh-file-storage-btn" class="btn-primary">Refresh List</button>
                                <button id="view-file-storage-btn" class="btn-secondary" style="display: none;">View Selected</button>
                                <button id="edit-file-storage-btn" class="btn-secondary" style="display: none;">Edit Selected</button>
                                <button id="delete-file-storage-btn" class="btn-danger" style="display: none;">Delete Selected</button>
                            </div>
                            <div id="file-storage-list" class="file-storage-list" style="margin-top: 1rem;">
                                <div class="loading-messages">Loading files...</div>
                            </div>
                        </div>
                        
                        <!-- File View/Edit Modal -->
                        <div id="file-storage-modal" class="modal" style="display: none;">
                            <div class="modal-content" style="max-width: 800px;">
                                <div class="modal-header">
                                    <h2 id="file-storage-modal-title">File Storage</h2>
                                    <button class="modal-close" id="file-storage-modal-close">&times;</button>
                                </div>
                                <div class="modal-body">
                                    <div id="file-storage-modal-content">
                                        <div class="loading-messages">Loading...</div>
                                    </div>
                                </div>
                                <div class="modal-footer" id="file-storage-modal-footer">
                                    <button class="btn-secondary" id="file-storage-modal-cancel">Cancel</button>
                                    <button class="btn-primary" id="file-storage-modal-save" style="display: none;">Save Changes</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Audit Logs Tab -->
                    <div class="admin-tab-pane" id="admin-tab-audit-logs">
                        <div class="audit-logs-section">
                            <h3><i class="fas fa-clipboard-list"></i> Audit Logs & Compliance</h3>
                            <p class="section-description">Searchable audit trail for all system actions. Export logs in JSON, CSV, or PDF format with digital signatures for compliance.</p>
                            
                            <!-- Search and Filter Controls -->
                            <div class="audit-logs-controls" style="margin-bottom: 1.5rem; display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end;">
                                <div class="form-group" style="flex: 1; min-width: 200px;">
                                    <label for="audit-search-input">Search:</label>
                                    <input type="text" id="audit-search-input" class="form-control" placeholder="Search logs...">
                                </div>
                                <div class="form-group" style="flex: 0 0 150px;">
                                    <label for="audit-user-filter">User Handle:</label>
                                    <input type="text" id="audit-user-filter" class="form-control" placeholder="Filter by user">
                                </div>
                                <div class="form-group" style="flex: 0 0 150px;">
                                    <label for="audit-action-filter">Action Type:</label>
                                    <select id="audit-action-filter" class="form-control">
                                        <option value="">All Actions</option>
                                        <option value="login">Login</option>
                                        <option value="logout">Logout</option>
                                        <option value="message_send">Message Send</option>
                                        <option value="message_edit">Message Edit</option>
                                        <option value="message_delete">Message Delete</option>
                                        <option value="file_upload">File Upload</option>
                                        <option value="file_download">File Download</option>
                                        <option value="room_join">Room Join</option>
                                        <option value="room_leave">Room Leave</option>
                                        <option value="admin_change">Admin Change</option>
                                        <option value="moderation_action">Moderation</option>
                                    </select>
                                </div>
                                <div class="form-group" style="flex: 0 0 150px;">
                                    <label for="audit-category-filter">Category:</label>
                                    <select id="audit-category-filter" class="form-control">
                                        <option value="">All Categories</option>
                                        <option value="authentication">Authentication</option>
                                        <option value="message">Message</option>
                                        <option value="file">File</option>
                                        <option value="room">Room</option>
                                        <option value="admin">Admin</option>
                                        <option value="moderation">Moderation</option>
                                        <option value="system">System</option>
                                    </select>
                                </div>
                                <div class="form-group" style="flex: 0 0 120px;">
                                    <label for="audit-start-date">Start Date:</label>
                                    <input type="date" id="audit-start-date" class="form-control">
                                </div>
                                <div class="form-group" style="flex: 0 0 120px;">
                                    <label for="audit-end-date">End Date:</label>
                                    <input type="date" id="audit-end-date" class="form-control">
                                </div>
                                <div class="form-group" style="flex: 0 0 100px;">
                                    <label for="audit-success-filter">Success:</label>
                                    <select id="audit-success-filter" class="form-control">
                                        <option value="">All</option>
                                        <option value="1">Success</option>
                                        <option value="0">Failed</option>
                                    </select>
                                </div>
                                <div class="form-group" style="flex: 0 0 auto;">
                                    <button id="audit-search-btn" class="btn-primary">
                                        <i class="fas fa-search"></i> Search
                                    </button>
                                </div>
                                <div class="form-group" style="flex: 0 0 auto;">
                                    <button id="audit-clear-filters-btn" class="btn-secondary">
                                        <i class="fas fa-times"></i> Clear
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Export Controls -->
                            <div class="audit-export-controls" style="margin-bottom: 1.5rem; display: flex; gap: 0.5rem; align-items: center;">
                                <span style="font-weight: 600;">Export:</span>
                                <button id="audit-export-json-btn" class="btn-secondary btn-sm">
                                    <i class="fas fa-file-code"></i> JSON
                                </button>
                                <button id="audit-export-csv-btn" class="btn-secondary btn-sm">
                                    <i class="fas fa-file-csv"></i> CSV
                                </button>
                                <button id="audit-export-pdf-btn" class="btn-secondary btn-sm">
                                    <i class="fas fa-file-pdf"></i> PDF (Signed)
                                </button>
                                <span id="audit-total-count" style="margin-left: auto; color: var(--text-medium); font-size: 0.9rem;"></span>
                            </div>
                            
                            <!-- Audit Logs Table -->
                            <div class="audit-logs-table-container" style="overflow-x: auto;">
                                <table class="audit-logs-table" style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: var(--ghost-white); border-bottom: 2px solid var(--border-color);">
                                            <th style="padding: 0.75rem; text-align: left; font-weight: 600;">ID</th>
                                            <th style="padding: 0.75rem; text-align: left; font-weight: 600;">Timestamp</th>
                                            <th style="padding: 0.75rem; text-align: left; font-weight: 600;">User</th>
                                            <th style="padding: 0.75rem; text-align: left; font-weight: 600;">Action</th>
                                            <th style="padding: 0.75rem; text-align: left; font-weight: 600;">Category</th>
                                            <th style="padding: 0.75rem; text-align: left; font-weight: 600;">Resource</th>
                                            <th style="padding: 0.75rem; text-align: left; font-weight: 600;">IP Address</th>
                                            <th style="padding: 0.75rem; text-align: center; font-weight: 600;">Success</th>
                                            <th style="padding: 0.75rem; text-align: center; font-weight: 600;">Details</th>
                                        </tr>
                                    </thead>
                                    <tbody id="audit-logs-tbody">
                                        <tr>
                                            <td colspan="9" style="padding: 2rem; text-align: center; color: var(--text-medium);">
                                                <div class="loading-messages">Click "Search" to load audit logs...</div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Pagination -->
                            <div class="audit-pagination" style="margin-top: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
                                <div style="display: flex; gap: 0.5rem; align-items: center;">
                                    <span>Show:</span>
                                    <select id="audit-limit-select" class="form-control" style="width: auto; padding: 0.25rem 0.5rem;">
                                        <option value="50">50</option>
                                        <option value="100" selected>100</option>
                                        <option value="200">200</option>
                                        <option value="500">500</option>
                                    </select>
                                    <span>per page</span>
                                </div>
                                <div id="audit-pagination-controls" style="display: flex; gap: 0.5rem; align-items: center;">
                                    <button id="audit-prev-page" class="btn-secondary btn-sm" disabled>
                                        <i class="fas fa-chevron-left"></i> Previous
                                    </button>
                                    <span id="audit-page-info" style="padding: 0 1rem;">Page 1 of 1</span>
                                    <button id="audit-next-page" class="btn-secondary btn-sm" disabled>
                                        Next <i class="fas fa-chevron-right"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Log Detail Modal -->
                            <div id="audit-log-detail-modal" class="modal" style="display: none;">
                                <div class="modal-content" style="max-width: 900px;">
                                    <div class="modal-header">
                                        <h2>Audit Log Details</h2>
                                        <button class="modal-close" id="audit-log-detail-close">&times;</button>
                                    </div>
                                    <div class="modal-body" id="audit-log-detail-content">
                                        <div class="loading-messages">Loading details...</div>
                                    </div>
                                    <div class="modal-footer">
                                        <button class="btn-secondary" id="audit-log-detail-close-btn">Close</button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Retention Policies Section -->
                            <div style="margin-top: 3rem; padding-top: 2rem; border-top: 2px solid var(--border-color);">
                                <h3><i class="fas fa-clock"></i> Retention Policies</h3>
                                <p class="section-description">Configure how long audit logs are retained before automatic purging. Logs on legal hold are never purged.</p>
                                
                                <div class="retention-policies-controls" style="margin-bottom: 1.5rem;">
                                    <button id="refresh-retention-policies-btn" class="btn-secondary">
                                        <i class="fas fa-sync"></i> Refresh Policies
                                    </button>
                                    <button id="purge-logs-now-btn" class="btn-primary" style="margin-left: 0.5rem;">
                                        <i class="fas fa-trash-alt"></i> Purge Old Logs Now
                                    </button>
                                </div>
                                
                                <div id="retention-policies-list" class="retention-policies-list">
                                    <div class="loading-messages">Loading retention policies...                                    </div>
                                </div>
                            </div>
                            
                            <!-- Retention Policies Section -->
                            <div style="margin-top: 3rem; padding-top: 2rem; border-top: 2px solid var(--border-color);">
                                <h3><i class="fas fa-clock"></i> Retention Policies</h3>
                                <p class="section-description">Configure how long audit logs are retained before automatic purging. Logs on legal hold are never purged.</p>
                                
                                <div class="retention-policies-controls" style="margin-bottom: 1.5rem;">
                                    <button id="refresh-retention-policies-btn" class="btn-secondary">
                                        <i class="fas fa-sync"></i> Refresh Policies
                                    </button>
                                    <button id="purge-logs-now-btn" class="btn-primary" style="margin-left: 0.5rem;">
                                        <i class="fas fa-trash-alt"></i> Purge Old Logs Now
                                    </button>
                                </div>
                                
                                <div id="retention-policies-list" class="retention-policies-list">
                                    <div class="loading-messages">Loading retention policies...</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- AI Systems Tabs -->
                    <!-- AI Configuration Tab -->
                    <div class="admin-tab-pane" id="admin-tab-ai-config">
                        <div class="ai-systems-section">
                            <h3><i class="fas fa-cog"></i> AI Systems Configuration</h3>
                            <p class="section-description">Configure AI providers, models, and settings for all AI-powered features.</p>
                            <div id="ai-systems-config-list" class="ai-config-list"></div>
                        </div>
                    </div>
                    
                    <!-- AI Auto-Moderation Tab -->
                    <div class="admin-tab-pane" id="admin-tab-ai-moderation">
                        <div class="ai-systems-section">
                            <h3><i class="fas fa-shield-alt"></i> AI Auto-Moderation</h3>
                            <p class="section-description">View moderation logs and test AI moderation functionality.</p>
                            <div class="moderation-controls" style="margin-bottom: 1.5rem;">
                                <button id="test-moderation-btn" class="btn-primary">
                                    <i class="fas fa-vial"></i> Test Moderation
                                </button>
                                <button id="refresh-moderation-logs-btn" class="btn-secondary">
                                    <i class="fas fa-sync"></i> Refresh Logs
                                </button>
                                <select id="moderation-action-filter" style="padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px; margin-left: 1rem;">
                                    <option value="">All Actions</option>
                                    <option value="flag">Flag</option>
                                    <option value="warn">Warn</option>
                                    <option value="hide">Hide</option>
                                    <option value="delete">Delete</option>
                                    <option value="none">None</option>
                                </select>
                            </div>
                            <div id="moderation-logs-list" class="moderation-logs-list"></div>
                        </div>
                    </div>
                    
                    <!-- AI Smart Replies Tab -->
                    <div class="admin-tab-pane" id="admin-tab-ai-smart-replies">
                        <div class="ai-systems-section">
                            <h3><i class="fas fa-lightbulb"></i> Smart Replies</h3>
                            <p class="section-description">Configure AI-powered reply suggestions for conversations.</p>
                            <div id="smart-replies-config" class="ai-config-content">
                                <div class="loading-messages">Loading configuration...</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- AI Summarization Tab -->
                    <div class="admin-tab-pane" id="admin-tab-ai-summarization">
                        <div class="ai-systems-section">
                            <h3><i class="fas fa-compress"></i> Thread Summarization</h3>
                            <p class="section-description">Configure AI-powered thread summarization for long conversations.</p>
                            <div id="summarization-config" class="ai-config-content">
                                <div class="loading-messages">Loading configuration...</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- AI Bot Features Tab -->
                    <div class="admin-tab-pane" id="admin-tab-ai-bot">
                        <div class="ai-systems-section">
                            <h3><i class="fas fa-comments"></i> Bot Features</h3>
                            <p class="section-description">Manage AI bot features including reminders and polls.</p>
                            <div id="bot-config" class="ai-config-content">
                                <div class="loading-messages">Loading configuration...</div>
                            </div>
                            <div style="margin-top: 2rem;">
                                <h4><i class="fas fa-bell"></i> Active Reminders</h4>
                                <div id="bot-reminders-list" class="bot-list">
                                    <div class="loading-messages">Loading reminders...</div>
                                </div>
                            </div>
                            <div style="margin-top: 2rem;">
                                <h4><i class="fas fa-poll"></i> Active Polls</h4>
                                <div id="bot-polls-list" class="bot-list">
                                    <div class="loading-messages">Loading polls...</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- RBAC Permissions Tab -->
                    <div class="admin-tab-pane" id="admin-tab-rbac">
                        <div class="rbac-section">
                            <h3><i class="fas fa-user-shield"></i> Role-Based Access Control (RBAC)</h3>
                            <p class="section-description">Manage permissions for each role. Trusted Admins can set permissions for Admin, Moderator, User, and Guest roles. Owners can protect permissions with passwords.</p>
                            
                            <!-- Role Selector -->
                            <div class="rbac-role-selector" style="margin-bottom: 2rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                                <label for="rbac-role-select" style="font-weight: 600;">Select Role:</label>
                                <select id="rbac-role-select" class="form-control" style="width: auto; padding: 0.5rem 1rem;">
                                    <option value="guest">Guest</option>
                                    <option value="user" selected>User</option>
                                    <option value="moderator">Moderator</option>
                                    <option value="administrator">Administrator</option>
                                    <option value="trusted_admin">Trusted Admin</option>
                                </select>
                                <button id="rbac-refresh-btn" class="btn-secondary">
                                    <i class="fas fa-sync"></i> Refresh
                                </button>
                                <span id="rbac-status" style="margin-left: auto; color: var(--text-medium); font-size: 0.9rem;"></span>
                            </div>
                            
                            <!-- Permissions by Category -->
                            <div id="rbac-permissions-container" class="rbac-permissions-container">
                                <div class="loading-messages">Loading permissions...</div>
                            </div>
                            
                            <!-- Permission Change History -->
                            <div style="margin-top: 3rem; padding-top: 2rem; border-top: 2px solid var(--border-color);">
                                <h4><i class="fas fa-history"></i> Permission Change History</h4>
                                <div class="rbac-history-controls" style="margin-bottom: 1rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                                    <select id="rbac-history-role-filter" class="form-control" style="width: auto;">
                                        <option value="">All Roles</option>
                                        <option value="guest">Guest</option>
                                        <option value="user">User</option>
                                        <option value="moderator">Moderator</option>
                                        <option value="administrator">Administrator</option>
                                        <option value="trusted_admin">Trusted Admin</option>
                                    </select>
                                    <button id="rbac-history-refresh-btn" class="btn-secondary">
                                        <i class="fas fa-sync"></i> Refresh History
                                    </button>
                                </div>
                                <div id="rbac-history-list" class="rbac-history-list">
                                    <div class="loading-messages">Loading history...</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Area 51 View -->
        <?php if ($area51Unlocked): ?>
        <div id="area51-view" class="view-container <?php echo $view === 'area51' ? 'active' : ''; ?>">
            <h2>Area 51 - Secret View</h2>
            <div class="secret-content">
                <p>Welcome to Area 51. This is a secret view unlocked via Ctrl+Shift+A or header gesture.</p>
                <div id="area51-data" class="secret-data"></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- IM System - Site-wide Sidebar -->
        <div id="im-sidebar" class="im-sidebar">
            <div class="im-sidebar-header">
                <h3><i class="fas fa-comments"></i> Messages</h3>
                <button class="im-sidebar-close" id="im-sidebar-close" title="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="im-sidebar-content">
                <!-- Conversations List -->
                <div class="im-conversations" id="im-conversations">
                    <div class="im-conversations-header">
                        <h4><i class="fas fa-inbox"></i> Conversations</h4>
                    </div>
                    <div class="im-conversations-list" id="im-conversations-list">
                        <div class="loading-messages">Loading conversations...</div>
                    </div>
                </div>
                
                <!-- Active Conversation View -->
                <div class="im-conversation-view" id="im-conversation-view" style="display: none;">
                    <div class="im-conversation-header">
                        <button class="im-back-btn" id="im-back-btn" title="Back to conversations">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <div class="im-conversation-user" id="im-conversation-user">
                            <span class="im-user-name" id="im-user-name">User</span>
                            <span class="im-user-status" id="im-user-status"></span>
                        </div>
                        <button class="im-conversation-menu" id="im-conversation-menu" title="Options">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                    </div>
                    <div class="im-messages-container" id="im-messages-container">
                        <div class="loading-messages">Loading messages...</div>
                    </div>
                    <div class="im-composer">
                        <textarea id="im-message-input" placeholder="Type a message..." rows="2" maxlength="1000"></textarea>
                        <button id="im-send-btn" class="btn-primary">
                            <i class="fas fa-paper-plane"></i> Send
                        </button>
                    </div>
                </div>
                
            </div>
        </div>
        
        <!-- IM Toggle Button (Floating) -->
        <button id="im-toggle-btn" class="im-toggle-btn" title="Messages">
            <i class="fas fa-comments"></i>
            <span class="im-badge" id="im-badge" style="display: none;">0</span>
        </button>
        
        <!-- User Context Menu -->
        <div id="user-context-menu" class="user-context-menu" style="display: none;"></div>
        
        <!-- Generic Modal System -->
        <div id="generic-modal" class="modal">
            <div class="modal-content generic-modal-content">
                <div class="modal-header">
                    <h2 id="generic-modal-title">Title</h2>
                    <button class="modal-close" id="generic-modal-close">&times;</button>
                </div>
                <div class="modal-body" id="generic-modal-body">
                    <div id="generic-modal-message">Message</div>
                    <div id="generic-modal-input-container" style="display: none;">
                        <input type="text" id="generic-modal-input" class="form-control" placeholder="Enter value...">
                        <textarea id="generic-modal-textarea" class="form-control" style="display: none;" rows="4" placeholder="Enter text..."></textarea>
                    </div>
                </div>
                <div class="modal-footer" id="generic-modal-footer">
                    <button class="btn-secondary" id="generic-modal-cancel">Cancel</button>
                    <button class="btn-primary" id="generic-modal-confirm">OK</button>
                </div>
            </div>
        </div>
        
        <!-- Compose Mail Modal -->
        <div id="compose-mail-modal" class="modal">
            <div class="modal-content compose-mail-modal-content">
                <div class="modal-header">
                    <h2 id="compose-mail-title">Compose Mail</h2>
                    <button class="modal-close" id="compose-mail-close">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="compose-mail-form">
                        <div class="form-group">
                            <label for="mail-to-input">To:</label>
                            <input type="text" id="mail-to-input" class="form-control" placeholder="Recipient username" required>
                        </div>
                        <div class="form-group">
                            <label for="mail-cc-input">CC:</label>
                            <input type="text" id="mail-cc-input" class="form-control" placeholder="CC recipients (comma-separated)">
                        </div>
                        <div class="form-group">
                            <label for="mail-bcc-input">BCC:</label>
                            <input type="text" id="mail-bcc-input" class="form-control" placeholder="BCC recipients (comma-separated)">
                        </div>
                        <div class="form-group">
                            <label for="mail-subject-input">Subject:</label>
                            <input type="text" id="mail-subject-input" class="form-control" placeholder="Mail subject" required>
                        </div>
                        <div class="form-group">
                            <label for="mail-body-input">Message:</label>
                            <textarea id="mail-body-input" class="form-control" rows="10" placeholder="Type your message..." required></textarea>
                        </div>
                        <div class="form-group">
                            <label for="mail-attachments-input">Attachments:</label>
                            <input type="file" id="mail-attachments-input" class="form-control" multiple>
                            <div id="mail-attachment-list" class="attachment-list" style="margin-top: 0.5rem;"></div>
                            <small class="form-help">Select files to attach (max 10MB per file)</small>
                        </div>
                        <input type="hidden" id="mail-reply-to-id" value="">
                        <input type="hidden" id="mail-thread-id" value="">
                        <input type="hidden" id="mail-draft-id" value="">
                    </form>
                </div>
                <div class="modal-footer">
                    <button id="save-draft-btn" class="btn-secondary">Save Draft</button>
                    <button id="send-mail-btn" class="btn-primary">Send</button>
                </div>
            </div>
        </div>
        
        <!-- Join Room Modal -->
        <div id="join-room-modal" class="modal">
            <div class="modal-content room-request-modal-content">
                <div class="modal-header">
                    <h2>Join Room with Invite Code</h2>
                    <button class="modal-close" id="join-room-modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="join-room-form">
                        <div class="form-group">
                            <label for="join-room-invite-code">Invite Code:</label>
                            <input type="text" id="join-room-invite-code" class="form-control" placeholder="Enter invite code (e.g., ABC123)" required maxlength="20" style="text-transform: uppercase;">
                            <small class="form-help">Enter the invite code provided by the room owner</small>
                        </div>
                        <div class="form-group" id="join-room-password-group" style="display: none;">
                            <label for="join-room-password">Room Password:</label>
                            <input type="password" id="join-room-password" class="form-control" placeholder="Enter room password">
                            <small class="form-help">This room requires a password</small>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" id="join-room-cancel">Cancel</button>
                    <button class="btn-primary" id="join-room-submit">Join Room</button>
                </div>
            </div>
        </div>
        
        <!-- Word Filter Request Modal -->
        <div id="word-filter-request-modal" class="modal">
            <div class="modal-content word-filter-request-modal-content">
                <div class="modal-header">
                    <h3>Submit Word Filter Request</h3>
                    <button class="modal-close" id="word-filter-request-modal-close">&times;</button>
                </div>
                <form id="word-filter-request-form">
                    <div class="form-group">
                        <label for="word-filter-request-type">Request Type:</label>
                        <select id="word-filter-request-type" class="form-control" required>
                            <option value="">Select type...</option>
                            <option value="add">Add New Filter</option>
                            <option value="edit">Edit Existing Filter</option>
                            <option value="remove">Remove Filter</option>
                        </select>
                    </div>
                    <div class="form-group" id="word-filter-request-filter-id-group" style="display: none;">
                        <label for="word-filter-request-filter-id">Filter ID (for edit/remove):</label>
                        <input type="text" id="word-filter-request-filter-id" class="form-control" placeholder="Enter filter ID">
                    </div>
                    <div class="form-group" id="word-filter-request-pattern-group">
                        <label for="word-filter-request-pattern">Word Pattern:</label>
                        <input type="text" id="word-filter-request-pattern" class="form-control" placeholder="e.g., badword|bad-word|bad word">
                        <small>Use | to separate multiple patterns</small>
                    </div>
                    <div class="form-group" id="word-filter-request-replacement-group">
                        <label for="word-filter-request-replacement">Replacement:</label>
                        <input type="text" id="word-filter-request-replacement" class="form-control" placeholder="***" value="*">
                    </div>
                    <div class="form-group" id="word-filter-request-severity-group">
                        <label for="word-filter-request-severity">Severity:</label>
                        <select id="word-filter-request-severity" class="form-control">
                            <option value="1">1 - Mild</option>
                            <option value="2" selected>2 - Moderate</option>
                            <option value="3">3 - Severe</option>
                            <option value="4">4 - Extreme</option>
                        </select>
                    </div>
                    <div class="form-group" id="word-filter-request-tags-group">
                        <label for="word-filter-request-tags">Tags (comma-separated):</label>
                        <input type="text" id="word-filter-request-tags" class="form-control" placeholder="sexual, racial, general">
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="word-filter-request-is-regex"> Pattern is regex
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="word-filter-request-justification">Justification (required):</label>
                        <textarea id="word-filter-request-justification" class="form-control" rows="4" required placeholder="Explain why this change is needed..."></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" id="word-filter-request-cancel">Cancel</button>
                        <button type="submit" class="btn-primary" id="word-filter-request-submit">Submit Request</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Room Request Modal -->
        <div id="room-request-modal" class="modal">
            <div class="modal-content room-request-modal-content">
                <div class="modal-header">
                    <h2>Request a Private Room</h2>
                    <button class="modal-close" id="room-request-modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="room-request-form">
                        <div class="form-group">
                            <label for="room-request-name">Room Name (identifier):</label>
                            <input type="text" id="room-request-name" class="form-control" 
                                   placeholder="e.g., my-private-room" 
                                   pattern="[a-zA-Z0-9_-]+" 
                                   maxlength="255" 
                                   required>
                            <small class="form-help">Only letters, numbers, underscores, and hyphens allowed</small>
                        </div>
                        <div class="form-group">
                            <label for="room-request-display-name">Display Name:</label>
                            <input type="text" id="room-request-display-name" class="form-control" 
                                   placeholder="e.g., My Private Room" 
                                   maxlength="255" 
                                   required>
                        </div>
                        <div class="form-group">
                            <label for="room-request-password">Password (optional):</label>
                            <input type="password" id="room-request-password" class="form-control" 
                                   placeholder="Leave empty for no password" 
                                   minlength="4" 
                                   maxlength="100">
                            <small class="form-help">Minimum 4 characters if provided</small>
                        </div>
                        <div class="form-group">
                            <label for="room-request-description">Description:</label>
                            <textarea id="room-request-description" class="form-control" 
                                      rows="3" 
                                      placeholder="What is this room for?" 
                                      maxlength="1000"></textarea>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn-secondary" id="room-request-cancel">Cancel</button>
                            <button type="submit" class="btn-primary">Submit Request</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Profile Modal -->
        <div id="profile-modal" class="modal">
            <div class="modal-content profile-modal-content">
                <div class="modal-header">
                    <h2 id="profile-modal-title">User Profile</h2>
                    <button class="modal-close" id="profile-modal-close">&times;</button>
                </div>
                <div class="modal-body" id="profile-modal-body">
                    <div class="loading-messages">Loading profile...</div>
                </div>
            </div>
        </div>
        
        <!-- Word Filter Modal (Admin) -->
        <div id="word-filter-modal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="word-filter-modal-title">Add/Edit Word Filter</h2>
                    <button class="modal-close" id="word-filter-modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="word-filter-form">
                        <input type="hidden" id="word-filter-id">
                        <div class="form-group">
                            <label for="modal-word-pattern">Word/Pattern:</label>
                            <input type="text" id="modal-word-pattern" class="form-control" placeholder="Word or pattern to filter" required>
                        </div>
                        <div class="form-group">
                            <label for="modal-replacement">Replacement (e.g., ***):</label>
                            <input type="text" id="modal-replacement" class="form-control" value="*">
                        </div>
                        <div class="form-group">
                            <label for="modal-severity">Severity:</label>
                            <select id="modal-severity" class="form-control">
                                <option value="1">1 (Mild)</option>
                                <option value="2" selected>2 (Moderate)</option>
                                <option value="3">3 (Severe)</option>
                                <option value="4">4 (Shock)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="modal-tags">Tags (comma-separated, e.g., sexual, racial):</label>
                            <input type="text" id="modal-tags" class="form-control" placeholder="sexual, racial, general">
                        </div>
                        <div class="form-group">
                            <label for="modal-exceptions">Exceptions (comma-separated, e.g., *script, pand*):</label>
                            <input type="text" id="modal-exceptions" class="form-control" placeholder="*script, pand*">
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" id="modal-is-regex"> Is Regex?
                            </label>
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" id="modal-is-active" checked> Is Active?
                            </label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" id="word-filter-cancel">Cancel</button>
                    <button type="button" class="btn-primary" id="word-filter-save">Save Filter</button>
                </div>
            </div>
        </div>
        
        <!-- Gallery Image View Modal -->
        <div id="gallery-image-modal" class="modal">
            <div class="modal-content gallery-image-modal-content" style="max-width: 800px;">
                <div class="modal-header">
                    <h2 id="gallery-image-title">Gallery Image</h2>
                    <button class="modal-close" id="gallery-image-modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="gallery-image-viewer" style="text-align: center; margin-bottom: 1.5rem;">
                        <img id="gallery-image-display" src="" alt="Gallery Image" style="max-width: 100%; max-height: 70vh; object-fit: contain; border-radius: 8px; box-shadow: var(--shadow-md);">
                    </div>
                    <div class="gallery-image-edit-form">
                        <div class="form-group">
                            <label for="gallery-image-filename">Filename:</label>
                            <input type="text" id="gallery-image-filename" class="form-control" maxlength="255" placeholder="Enter filename">
                        </div>
                        <div class="form-group form-check">
                            <label class="form-check-label">
                                <input type="checkbox" id="gallery-image-is-public" class="form-check-input"> Make this image public (visible to others)
                            </label>
                        </div>
                        <div class="form-group form-check">
                            <label class="form-check-label">
                                <input type="checkbox" id="gallery-image-is-avatar" class="form-check-input"> Use as avatar
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
                    <button class="btn-error" id="gallery-image-delete-btn">
                        <i class="fas fa-trash"></i> Delete Image
                    </button>
                    <div>
                        <button class="btn-secondary" id="gallery-image-cancel-btn" style="margin-right: 0.5rem;">Cancel</button>
                        <button class="btn-primary" id="gallery-image-save-btn">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="app-footer">
        <div class="footer-content">
            <span class="failover-indicator" id="failover-indicator">Primary Server Online</span>
            <span class="app-version">Sentinel Chat v1.0.0</span>
        </div>
    </footer>

    <script>
        // Configuration for JavaScript
        window.SENTINEL_CONFIG = {
            apiBase: '<?php echo htmlspecialchars($apiBase, ENT_QUOTES, 'UTF-8'); ?>',
            defaultRoom: '<?php echo htmlspecialchars($defaultRoom, ENT_QUOTES, 'UTF-8'); ?>',
            userHandle: '<?php echo htmlspecialchars($userHandle, ENT_QUOTES, 'UTF-8'); ?>',
            userRole: '<?php echo htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8'); ?>',
            websocket: {
                enabled: <?php echo $config->get('websocket.enabled') ? 'true' : 'false'; ?>,
                host: '<?php echo htmlspecialchars($config->get('websocket.host'), ENT_QUOTES, 'UTF-8'); ?>',
                port: <?php echo (int)$config->get('websocket.port'); ?>,
                secure: <?php echo $config->get('websocket.secure') ? 'true' : 'false'; ?>
            }
        };
    </script>
    <!-- E2EE and Real-time Features -->
    <script src="https://cdn.jsdelivr.net/npm/libsodium-wrappers@0.7.11/dist/browsers/sodium.min.js"></script>
    <script src="/iChat/js/e2ee.js"></script>
    <script src="/iChat/js/typing-indicators.js"></script>
    <script src="/iChat/js/read-receipts.js"></script>
    <script src="/iChat/js/key-exchange-ui.js"></script>
    <script src="/iChat/js/icon-picker.js"></script>
    <script src="/iChat/js/message-edit.js"></script>
    <script src="/iChat/js/rich-previews.js"></script>
    <script src="/iChat/js/ai-systems-admin.js"></script>
    <script src="/iChat/js/audit-logs-admin.js"></script>
    <script src="/iChat/js/rbac-admin.js"></script>
    <script src="/iChat/js/app.js"></script>
</body>
</html>

