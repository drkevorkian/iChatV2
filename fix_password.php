<?php
/**
 * Sentinel Chat Platform - Password Fix Utility
 * 
 * This script helps diagnose and fix password issues.
 * SECURITY: Delete this file after use!
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use iChat\Repositories\AuthRepository;
use iChat\Services\DatabaseHealth;

header('Content-Type: text/html; charset=UTF-8');

// Simple authentication check
$key = $_GET['key'] ?? '';
if ($key !== 'fix_password_2024') {
    die('Access denied. Use ?key=fix_password_2024');
}

$username = $_GET['username'] ?? '';
$password = $_GET['password'] ?? '';
$action = $_GET['action'] ?? 'check';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Password Fix Utility</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 2rem auto; padding: 2rem; }
        .success { background: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin: 1rem 0; }
        .error { background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin: 1rem 0; }
        .info { background: #d1ecf1; color: #0c5460; padding: 1rem; border-radius: 4px; margin: 1rem 0; }
        input, button { padding: 0.5rem; margin: 0.5rem 0; }
        button { background: #0066cc; color: white; border: none; padding: 0.75rem 1.5rem; cursor: pointer; }
        pre { background: #f8f9fa; padding: 1rem; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>Password Fix Utility</h1>
    
    <form method="GET">
        <input type="hidden" name="key" value="fix_password_2024">
        <div>
            <label>Username:</label><br>
            <input type="text" name="username" value="<?php echo htmlspecialchars($username); ?>" required>
        </div>
        <div>
            <label>Password:</label><br>
            <input type="password" name="password" value="<?php echo htmlspecialchars($password); ?>" required>
        </div>
        <div>
            <label>Action:</label><br>
            <select name="action">
                <option value="check" <?php echo $action === 'check' ? 'selected' : ''; ?>>Check User</option>
                <option value="fix" <?php echo $action === 'fix' ? 'selected' : ''; ?>>Fix Password</option>
                <option value="verify" <?php echo $action === 'verify' ? 'selected' : ''; ?>>Verify Login</option>
            </select>
        </div>
        <button type="submit">Execute</button>
    </form>
    
    <?php
    if (!empty($username) && !empty($password)) {
        echo '<hr><h2>Results:</h2>';
        
        $authRepo = new AuthRepository();
        
        if ($action === 'check') {
            echo '<h3>Checking User Account...</h3>';
            
            if (!DatabaseHealth::isAvailable()) {
                echo '<div class="error">Database is not available!</div>';
            } else {
                try {
                    // Try direct database query as fallback
                    $user = $authRepo->getUserByUsernameOrEmail($username);
                    
                    // If not found, try direct query
                    if (empty($user)) {
                        echo '<div class="info">User not found via getUserByUsernameOrEmail. Trying direct query...</div>';
                        try {
                            $sql = 'SELECT id, username, email, password_hash, role, is_active, is_verified FROM users WHERE username = :username1 OR email = :username2';
                            $user = \iChat\Database::queryOne($sql, [
                                ':username1' => $username,
                                ':username2' => $username,
                            ]);
                        } catch (\Exception $e) {
                            echo '<div class="error">Direct query failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
                            // Try without is_active check
                            try {
                                $sql = 'SELECT id, username, email, password_hash, role, is_active, is_verified FROM users WHERE username = :username';
                                $user = \iChat\Database::queryOne($sql, [':username' => $username]);
                                if (!empty($user)) {
                                    echo '<div class="info">Found user without is_active check. User is_active status: ' . ($user['is_active'] ?? 'unknown') . '</div>';
                                }
                            } catch (\Exception $e2) {
                                echo '<div class="error">Simple query also failed: ' . htmlspecialchars($e2->getMessage()) . '</div>';
                            }
                        }
                    }
                    
                    if (empty($user)) {
                        echo '<div class="error">User not found in users table!</div>';
                        echo '<div class="info">Make sure you are checking the correct table. Authentication uses the <strong>users</strong> table, not user_registrations.</div>';
                        echo '<div class="info">Try checking: SELECT * FROM users WHERE username = \'' . htmlspecialchars($username) . '\' in phpMyAdmin</div>';
                    } else {
                        echo '<div class="success">User found!</div>';
                        echo '<pre>';
                        echo "ID: " . htmlspecialchars((string)($user['id'] ?? 'N/A')) . "\n";
                        echo "Username: " . htmlspecialchars($user['username'] ?? 'N/A') . "\n";
                        echo "Email: " . htmlspecialchars($user['email'] ?? 'N/A') . "\n";
                        echo "Role: " . htmlspecialchars($user['role'] ?? 'N/A') . "\n";
                        echo "Is Active: " . ($user['is_active'] ?? false ? 'Yes' : 'No') . "\n";
                        echo "Password Hash: " . (empty($user['password_hash']) ? 'NULL/EMPTY!' : substr($user['password_hash'], 0, 20) . '...') . "\n";
                        echo "Hash Length: " . strlen($user['password_hash'] ?? '') . "\n";
                        echo "Hash Type: " . (empty($user['password_hash']) ? 'NONE' : (strlen($user['password_hash']) === 32 && ctype_xdigit($user['password_hash']) ? 'MD5' : 'BCRYPT')) . "\n";
                        echo '</pre>';
                        
                        if (empty($user['password_hash'])) {
                            echo '<div class="error"><strong>PROBLEM FOUND:</strong> Password hash is NULL or empty!</div>';
                        }
                    }
                } catch (\Exception $e) {
                    echo '<div class="error">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
                }
            }
        } elseif ($action === 'fix') {
            echo '<h3>Fixing Password...</h3>';
            
            try {
                // Try getUserByUsernameOrEmail first
                $user = $authRepo->getUserByUsernameOrEmail($username);
                
                // If not found, try direct query (same fallback as check action)
                if (empty($user)) {
                    echo '<div class="info">User not found via getUserByUsernameOrEmail. Trying direct query...</div>';
                    try {
                        $sql = 'SELECT id, username, email, password_hash, role, is_active, is_verified FROM users WHERE username = :username';
                        $user = \iChat\Database::queryOne($sql, [':username' => $username]);
                    } catch (\Exception $e) {
                        $errorMsg = $e->getMessage();
                        if ($e->getPrevious() instanceof \PDOException) {
                            $errorMsg .= ' | PDO Error: ' . $e->getPrevious()->getMessage();
                        }
                        echo '<div class="error">Direct query failed: ' . htmlspecialchars($errorMsg) . '</div>';
                    }
                }
                
                if (empty($user)) {
                    echo '<div class="error">User not found!</div>';
                    echo '<div class="info">Try using the "Check User" action first to verify the user exists.</div>';
                } else {
                    // Generate bcrypt hash
                    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                    
                    // Update password
                    $sql = 'UPDATE users SET password_hash = :password_hash WHERE id = :user_id';
                    \iChat\Database::execute($sql, [
                        ':password_hash' => $passwordHash,
                        ':user_id' => $user['id'],
                    ]);
                    
                    echo '<div class="success">Password updated successfully!</div>';
                    echo '<pre>';
                    echo "New Hash: " . substr($passwordHash, 0, 30) . "...\n";
                    echo "Hash Length: " . strlen($passwordHash) . "\n";
                    echo '</pre>';
                    
                    // Verify it was saved
                    $verifyUser = $authRepo->getUserByUsernameOrEmail($username);
                    if (!empty($verifyUser['password_hash'])) {
                        echo '<div class="success">Verification: Password hash was saved correctly!</div>';
                    } else {
                        echo '<div class="error">Verification FAILED: Password hash is still empty!</div>';
                    }
                }
            } catch (\Exception $e) {
                echo '<div class="error">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        } elseif ($action === 'verify') {
            echo '<h3>Verifying Login...</h3>';
            
            try {
                // Try getUserByUsernameOrEmail first
                $user = $authRepo->getUserByUsernameOrEmail($username);
                
                // If not found, try direct query (same fallback as check action)
                if (empty($user)) {
                    echo '<div class="info">User not found via getUserByUsernameOrEmail. Trying direct query...</div>';
                    try {
                        $sql = 'SELECT id, username, email, password_hash, role, is_active, is_verified FROM users WHERE username = :username';
                        $user = \iChat\Database::queryOne($sql, [':username' => $username]);
                    } catch (\Exception $e) {
                        $errorMsg = $e->getMessage();
                        if ($e->getPrevious() instanceof \PDOException) {
                            $errorMsg .= ' | PDO Error: ' . $e->getPrevious()->getMessage();
                        }
                        echo '<div class="error">Direct query failed: ' . htmlspecialchars($errorMsg) . '</div>';
                    }
                }
                
                if (empty($user)) {
                    echo '<div class="error">User not found!</div>';
                    echo '<div class="info">Try using the "Check User" action first to verify the user exists.</div>';
                } else {
                    echo '<pre>';
                    echo "Checking password for user: " . htmlspecialchars($user['username']) . "\n";
                    echo "Password hash in DB: " . (empty($user['password_hash']) ? 'NULL/EMPTY' : substr($user['password_hash'], 0, 30) . '...') . "\n";
                    echo "Hash length: " . strlen($user['password_hash'] ?? '') . "\n";
                    echo "\n";
                    
                    if (empty($user['password_hash'])) {
                        echo "RESULT: Password hash is empty - login will fail!\n";
                    } else {
                        $hash = $user['password_hash'];
                        $isMD5 = (strlen($hash) === 32 && ctype_xdigit($hash));
                        
                        if ($isMD5) {
                            echo "Hash type: MD5\n";
                            $md5Check = md5($password);
                            $matches = ($md5Check === $hash);
                            echo "MD5 of password: " . $md5Check . "\n";
                            echo "MD5 in database: " . $hash . "\n";
                            echo "Match: " . ($matches ? 'YES' : 'NO') . "\n";
                        } else {
                            echo "Hash type: BCRYPT\n";
                            $matches = password_verify($password, $hash);
                            echo "Password verify: " . ($matches ? 'YES' : 'NO') . "\n";
                        }
                        
                        echo "\n";
                        echo "FINAL RESULT: " . ($matches ? 'PASSWORD CORRECT' : 'PASSWORD INCORRECT') . "\n";
                    }
                    echo '</pre>';
                }
            } catch (\Exception $e) {
                echo '<div class="error">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }
    ?>
    
    <hr>
    <p><small><strong>Security Note:</strong> Delete this file after use!</small></p>
</body>
</html>

