<?php
/**
 * Sentinel Chat Platform - Repository Tests
 * 
 * CLI-only test script to verify repository functionality.
 * Run from command line: php tests/test_repositories.php
 */

declare(strict_types=1);

// Ensure CLI execution only
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from command line.\n");
}

require_once __DIR__ . '/../bootstrap.php';

use iChat\Repositories\MessageRepository;
use iChat\Repositories\ImRepository;
use iChat\Repositories\EscrowRepository;

echo "Sentinel Chat - Repository Tests\n";
echo "=================================\n\n";

try {
    // Test MessageRepository
    echo "1. Testing MessageRepository...\n";
    $messageRepo = new MessageRepository();
    
    // Test enqueue
    $testCipher = base64_encode('Test message');
    $messageId = $messageRepo->enqueueMessage('test-room', 'test-user', $testCipher, 1);
    echo "   ✓ Message enqueued (ID: $messageId)\n";
    
    // Test get pending
    $pending = $messageRepo->getPendingMessages(10);
    echo "   ✓ Retrieved " . count($pending) . " pending messages\n";
    
    // Test ImRepository
    echo "\n2. Testing ImRepository...\n";
    $imRepo = new ImRepository();
    
    $testCipher = base64_encode('Test IM');
    $inboxId = $imRepo->sendIm('sender', 'recipient', 'inbox', $testCipher);
    echo "   ✓ IM sent (Inbox ID: $inboxId)\n";
    
    $unreadCount = $imRepo->getUnreadCount('recipient');
    echo "   ✓ Unread count: $unreadCount\n";
    
    // Test EscrowRepository
    echo "\n3. Testing EscrowRepository...\n";
    $escrowRepo = new EscrowRepository();
    
    $requestId = $escrowRepo->createRequest('test-room', 'operator', 'Test justification');
    echo "   ✓ Escrow request created (ID: $requestId)\n";
    
    $requests = $escrowRepo->getAllRequests();
    echo "   ✓ Retrieved " . count($requests) . " escrow requests\n";
    
    echo "\n✓ All repository tests passed!\n";
    
} catch (\Exception $e) {
    echo "\n✗ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

