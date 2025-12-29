<?php
/**
 * Sentinel Chat Platform - Authentication Service
 * 
 * Handles user authentication, registration, login, logout, and session management.
 * Manages role-based access control.
 */

declare(strict_types=1);

namespace iChat\Services;

use iChat\Repositories\AuthRepository;
use iChat\Repositories\ProfileRepository;
use iChat\Repositories\UserManagementRepository;
use iChat\Services\UniqueUserIdService;

class AuthService
{
    private AuthRepository $authRepo;
    private ProfileRepository $profileRepo;
    private UserManagementRepository $userManagementRepo;
    private const SESSION_DURATION = 86400; // 24 hours
    
    public function __construct()
    {
        $this->authRepo = new AuthRepository();
        $this->profileRepo = new ProfileRepository();
        $this->userManagementRepo = new UserManagementRepository();
    }
    
    /**
     * Register a new user
     * 
     * @param string $username Username/handle
     * @param string $email Email address
     * @param string $password Plain text password
     * @param string $role User role (default: 'user')
     * @return array Registration result
     */
    public function register(
        string $username,
        string $email,
        string $password,
        string $role = 'user'
    ): array {
        // Validate input
        if (empty($username) || empty($password)) {
            return [
                'success' => false,
                'error' => 'Username and password are required',
            ];
        }
        
        if (strlen($username) < 3 || strlen($username) > 100) {
            return [
                'success' => false,
                'error' => 'Username must be between 3 and 100 characters',
            ];
        }
        
        if (strlen($password) < 8) {
            return [
                'success' => false,
                'error' => 'Password must be at least 8 characters',
            ];
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error' => 'Invalid email address',
            ];
        }
        
        try {
            // Check if username already exists
            if ($this->authRepo->usernameExists($username)) {
                return [
                    'success' => false,
                    'error' => 'Username already taken',
                ];
            }
            
            // Check if email already exists
            if (!empty($email) && $this->authRepo->emailExists($email)) {
                return [
                    'success' => false,
                    'error' => 'Email already registered',
                ];
            }
            
            // Hash password
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            
            // Create user account
            $userId = $this->authRepo->createUser($username, $email, $passwordHash, $role);
            
            if ($userId === null) {
                return [
                    'success' => false,
                    'error' => 'Failed to create user account',
                ];
            }
            
            // Create default profile (this creates user_metadata, not user_registrations)
            // Note: user_registrations is legacy and not used for authentication
            try {
                $this->profileRepo->registerUser($username, $email);
            } catch (\Exception $e) {
                // Log but don't fail registration if profile creation fails
                error_log('Profile creation failed during registration: ' . $e->getMessage());
            }
            
            // Verify the user was actually created in the users table
            $verifyUser = $this->authRepo->getUserById($userId);
            if (empty($verifyUser)) {
                throw new \RuntimeException('User account was not created successfully');
            }
            
            if (empty($verifyUser['password_hash'] ?? '')) {
                throw new \RuntimeException('User account was created but password hash is missing');
            }
            
            return [
                'success' => true,
                'user_id' => $userId,
                'username' => $username,
                'message' => 'Registration successful',
            ];
        } catch (\RuntimeException $e) {
            // Re-throw runtime exceptions (like database table missing)
            throw $e;
        } catch (\Exception $e) {
            error_log('Registration error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Registration failed: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Login user
     * 
     * @param string $username Username or email
     * @param string $password Plain text password
     * @return array Login result
     */
    public function login(string $username, string $password): array
    {
        if (empty($username) || empty($password)) {
            return [
                'success' => false,
                'error' => 'Username and password are required',
            ];
        }
        
        // Get user by username or email
        $user = $this->authRepo->getUserByUsernameOrEmail($username);
        
        // If not found, try direct query (fallback for MySQL compatibility issues)
        if (empty($user)) {
            try {
                // PDO requires separate parameters for OR clauses
                $sql = 'SELECT id, username, email, password_hash, role, is_active, is_verified
                        FROM users
                        WHERE username = :username1 OR email = :username2';
                $user = \iChat\Database::queryOne($sql, [
                    ':username1' => $username,
                    ':username2' => $username,
                ]);
                if (!empty($user)) {
                    error_log('Login: Found user via fallback query for: ' . $username);
                }
            } catch (\Exception $e) {
                error_log('Login fallback query failed: ' . $e->getMessage());
            }
        }
        
        if (empty($user)) {
            error_log('Login failed: User not found for username: ' . $username);
            return [
                'success' => false,
                'error' => 'Invalid username or password',
            ];
        }
        
        // Check if account is active
        if (!($user['is_active'] ?? true)) {
            return [
                'success' => false,
                'error' => 'Account is disabled',
            ];
        }
        
        // Check if user is banned
        $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if (strpos($ipAddress, ',') !== false) {
            $ipAddress = trim(explode(',', $ipAddress)[0]);
        }
        
        if ($this->userManagementRepo->isBanned($user['username'], $ipAddress)) {
            $banInfo = $this->userManagementRepo->getBanInfo($user['username'], $ipAddress);
            $banReason = $banInfo['reason'] ?? 'No reason provided';
            $expiresAt = $banInfo['expires_at'] ?? null;
            
            $errorMsg = "Your account has been banned. Reason: {$banReason}";
            if ($expiresAt) {
                $expiresDate = new \DateTime($expiresAt);
                $errorMsg .= " Ban expires: " . $expiresDate->format('Y-m-d H:i:s');
            } else {
                $errorMsg .= " This ban is permanent.";
            }
            
            return [
                'success' => false,
                'error' => $errorMsg,
                'banned' => true,
            ];
        }
        
        // Verify password (support both bcrypt and MD5 for migration)
        $passwordValid = false;
        $passwordHash = $user['password_hash'] ?? '';
        
        // Check if password hash is empty
        if (empty($passwordHash)) {
            error_log('Login attempt for user ' . $username . ' failed: password_hash is empty');
            return [
                'success' => false,
                'error' => 'Account password not set. Please reset your password.',
            ];
        }
        
        // Check if it's MD5 (32 hex characters)
        if (strlen($passwordHash) === 32 && ctype_xdigit($passwordHash)) {
            // MD5 hash - verify and upgrade to bcrypt
            $md5Hash = md5($password);
            $passwordValid = ($md5Hash === $passwordHash);
            
            error_log('Login attempt for user ' . $username . ': MD5 check - provided=' . substr($md5Hash, 0, 8) . '..., stored=' . substr($passwordHash, 0, 8) . '..., match=' . ($passwordValid ? 'YES' : 'NO'));
            
            if ($passwordValid) {
                // Upgrade to bcrypt
                $newHash = password_hash($password, PASSWORD_BCRYPT);
                try {
                    $this->authRepo->updatePasswordHash($user['id'], $newHash);
                    error_log('Password hash upgraded to bcrypt for user ' . $username);
                } catch (\Exception $e) {
                    error_log('Failed to upgrade password hash: ' . $e->getMessage());
                    // Continue anyway - password is valid
                }
            }
        } else {
            // Bcrypt or other modern hash
            $passwordValid = password_verify($password, $passwordHash);
            error_log('Login attempt for user ' . $username . ': BCRYPT check - match=' . ($passwordValid ? 'YES' : 'NO') . ', hash_length=' . strlen($passwordHash) . ', hash_start=' . substr($passwordHash, 0, 20));
            
            if (!$passwordValid) {
                // Double-check with direct verification
                error_log('Password verification failed. Re-checking...');
                error_log('Provided password length: ' . strlen($password));
                error_log('Stored hash: ' . substr($passwordHash, 0, 30) . '...');
            }
        }
        
        if (!$passwordValid) {
            error_log('Login failed: Password verification failed for user: ' . $username);
            return [
                'success' => false,
                'error' => 'Invalid username or password',
            ];
        }
        
        error_log('Login: Password verified successfully for user: ' . $username);
        
        // Create session
        $sessionToken = $this->createSession($user['id'], $user['username'], $user['role']);
        
        if ($sessionToken === null) {
            return [
                'success' => false,
                'error' => 'Failed to create session',
            ];
        }
        
        // Update last login
        $this->authRepo->updateLastLogin($user['id']);
        
        return [
            'success' => true,
            'user_id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'session_token' => $sessionToken,
            'message' => 'Login successful',
        ];
    }
    
    /**
     * Logout user
     * 
     * @param string $sessionToken Session token
     * @return bool True if successful
     */
    public function logout(string $sessionToken): bool
    {
        return $this->authRepo->destroySession($sessionToken);
    }
    
    /**
     * Get current user from session
     * 
     * @return array|null User data or null if not authenticated
     */
    public function getCurrentUser(): ?array
    {
        try {
            // Check PHP session first
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            $sessionToken = $_SESSION['auth_token'] ?? null;
            if (empty($sessionToken)) {
                return null;
            }
            
            // Get session from database
            $session = $this->authRepo->getSession($sessionToken);
            if (empty($session)) {
                return null;
            }
            
            // Check if session expired
            if (strtotime($session['expires_at']) < time()) {
                $this->logout($sessionToken);
                return null;
            }
            
            // Get user data
            $user = $this->authRepo->getUserById($session['user_id']);
            if (empty($user)) {
                return null;
            }
            
            return [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
                'is_active' => $user['is_active'],
                'is_verified' => $user['is_verified'],
            ];
        } catch (\Exception $e) {
            // Log error and return null (user not authenticated)
            error_log('getCurrentUser error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if user is authenticated
     * 
     * @return bool True if authenticated
     */
    public function isAuthenticated(): bool
    {
        return $this->getCurrentUser() !== null;
    }
    
    /**
     * Check if user has role
     * 
     * @param string $role Role to check
     * @return bool True if user has role
     */
    public function hasRole(string $role): bool
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return false;
        }
        
        return $user['role'] === $role;
    }
    
    /**
     * Check if user has any of the specified roles
     * 
     * @param array $roles Roles to check
     * @return bool True if user has any role
     */
    public function hasAnyRole(array $roles): bool
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return false;
        }
        
        return in_array($user['role'], $roles, true);
    }
    
    /**
     * Create authentication session
     * 
     * @param int $userId User ID
     * @param string $username Username
     * @param string $role User role
     * @return string|null Session token or null on failure
     */
    private function createSession(int $userId, string $username, string $role): ?string
    {
        // Generate session token
        $sessionToken = bin2hex(random_bytes(32));
        $phpSessionId = session_id();
        
        // Get IP and user agent
        $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if (strpos($ipAddress, ',') !== false) {
            $ipAddress = trim(explode(',', $ipAddress)[0]);
        }
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Calculate expiration
        $expiresAt = date('Y-m-d H:i:s', time() + self::SESSION_DURATION);
        
        // Create session in database
        $success = $this->authRepo->createSession(
            $userId,
            $sessionToken,
            $phpSessionId,
            $ipAddress,
            $userAgent,
            $expiresAt
        );
        
        if (!$success) {
            return null;
        }
        
        // Store in PHP session
        $_SESSION['auth_token'] = $sessionToken;
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_handle'] = $username;
        $_SESSION['user_role'] = $role;
        
        return $sessionToken;
    }
}

