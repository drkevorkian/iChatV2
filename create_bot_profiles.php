<?php
/**
 * Create Pinky and Brain Bot Profiles
 * 
 * Sets up profiles for Pinky and Brain based on the cartoon characters.
 * These profiles are read-only and cannot be edited by users.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from command line.\n");
}

require_once __DIR__ . '/bootstrap.php';

use iChat\Database;

echo "Creating Pinky and Brain bot profiles...\n\n";

try {
    $conn = Database::getConnection();
    
    // Brain's Profile
    echo "Creating Brain's profile...\n";
    $brainSql = 'INSERT INTO user_metadata 
                 (user_handle, display_name, bio, profile_visibility, status, status_message, location, join_date, created_at)
                 VALUES 
                 (:user_handle, :display_name, :bio, :profile_visibility, :status, :status_message, :location, :join_date, NOW())
                 ON DUPLICATE KEY UPDATE
                     display_name = VALUES(display_name),
                     bio = VALUES(bio),
                     status_message = VALUES(status_message),
                     location = VALUES(location),
                     updated_at = NOW()';
    
    $brainStmt = $conn->prepare($brainSql);
    $brainStmt->execute([
        ':user_handle' => 'Brain',
        ':display_name' => 'The Brain',
        ':bio' => 'A highly intelligent laboratory mouse with an insatiable desire for world domination. Known for his grandiose plans and catchphrase: "Are you thinking what I\'m thinking, Pinky?"',
        ':profile_visibility' => 'public',
        ':status' => 'online',
        ':status_message' => 'Planning world domination...',
        ':location' => 'ACME Labs',
        ':join_date' => '1995-09-09 00:00:00', // Original air date of Pinky and the Brain
    ]);
    
    echo "✓ Brain's profile created/updated\n";
    
    // Pinky's Profile
    echo "Creating Pinky's profile...\n";
    $pinkySql = 'INSERT INTO user_metadata 
                 (user_handle, display_name, bio, profile_visibility, status, status_message, location, join_date, created_at)
                 VALUES 
                 (:user_handle, :display_name, :bio, :profile_visibility, :status, :status_message, :location, :join_date, NOW())
                 ON DUPLICATE KEY UPDATE
                     display_name = VALUES(display_name),
                     bio = VALUES(bio),
                     status_message = VALUES(status_message),
                     location = VALUES(location),
                     updated_at = NOW()';
    
    $pinkyStmt = $conn->prepare($pinkySql);
    $pinkyStmt->execute([
        ':user_handle' => 'Pinky',
        ':display_name' => 'Pinky',
        ':bio' => 'A cheerful and somewhat dim-witted laboratory mouse, best friend and partner to The Brain. Known for his silly responses and catchphrase: "Narf!"',
        ':profile_visibility' => 'public',
        ':status' => 'online',
        ':status_message' => 'Narf!',
        ':location' => 'ACME Labs',
        ':join_date' => '1995-09-09 00:00:00', // Original air date of Pinky and the Brain
    ]);
    
    echo "✓ Pinky's profile created/updated\n";
    
    echo "\n✅ Bot profiles created successfully!\n";
    echo "\nYou can now view their profiles by clicking on their names in chat.\n";
    
} catch (\Exception $e) {
    echo "❌ Error creating bot profiles: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);

