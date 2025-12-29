<?php
/**
 * Sentinel Chat Platform - Emoji Importer
 * 
 * Parses Unicode emoji-sequences.txt file and imports emojis into the database.
 * 
 * Usage: php import_emojis.php [path_to_emoji-sequences.txt]
 * 
 * Security: This script should only be run from command line by administrators.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from command line.\n");
}

require_once __DIR__ . '/bootstrap.php';

use iChat\Database;
use iChat\Services\DatabaseHealth;

// Get file path from command line argument or use default
$emojiFile = $argv[1] ?? __DIR__ . '/emoji-sequences.txt';

if (!file_exists($emojiFile)) {
    die("Error: Emoji file not found: {$emojiFile}\n");
}

echo "Importing emojis from: {$emojiFile}\n";

if (!DatabaseHealth::isAvailable()) {
    die("Error: Database is not available.\n");
}

// Category mappings (based on Unicode emoji organization)
$categoryMap = [
    'Smileys & Emotion' => ['face', 'emotion', 'smile', 'grin', 'laugh', 'wink', 'kiss', 'heart', 'love'],
    'People & Body' => ['hand', 'finger', 'person', 'man', 'woman', 'baby', 'child', 'adult'],
    'Animals & Nature' => ['animal', 'cat', 'dog', 'bird', 'fish', 'insect', 'plant', 'tree', 'flower'],
    'Food & Drink' => ['food', 'drink', 'fruit', 'vegetable', 'meal', 'beverage'],
    'Travel & Places' => ['car', 'vehicle', 'building', 'house', 'hotel', 'airplane', 'train', 'ship'],
    'Activities' => ['sport', 'game', 'music', 'dance', 'art', 'activity'],
    'Objects' => ['object', 'tool', 'phone', 'computer', 'book', 'money', 'clock'],
    'Symbols' => ['symbol', 'sign', 'arrow', 'mark', 'punctuation'],
    'Flags' => ['flag', 'country', 'nation'],
];

function categorizeEmoji(string $shortName): array {
    global $categoryMap;
    
    $shortNameLower = strtolower($shortName);
    
    foreach ($categoryMap as $category => $keywords) {
        foreach ($keywords as $keyword) {
            if (strpos($shortNameLower, $keyword) !== false) {
                // Determine subcategory
                $subcategory = '';
                if (strpos($shortNameLower, 'skin tone') !== false) {
                    $subcategory = 'Skin Tones';
                } elseif (strpos($shortNameLower, 'flag') !== false) {
                    $subcategory = 'Country Flags';
                } elseif (strpos($shortNameLower, 'face') !== false || strpos($shortNameLower, 'smile') !== false) {
                    $subcategory = 'Faces';
                } elseif (strpos($shortNameLower, 'hand') !== false) {
                    $subcategory = 'Hands';
                }
                
                return [$category, $subcategory];
            }
        }
    }
    
    return ['Symbols', ''];
}

function codePointsToEmoji(string $codePoints): string {
    // Handle ranges in the code points string (e.g., "231A..231B")
    if (preg_match('/^([0-9A-Fa-f]+)\.\.([0-9A-Fa-f]+)$/', trim($codePoints), $rangeMatch)) {
        // This is a range - we'll only use the first one for now
        $codePoints = $rangeMatch[1];
    }
    
    $points = explode(' ', trim($codePoints));
    $emoji = '';
    
    foreach ($points as $point) {
        // Skip empty points
        if (empty($point)) {
            continue;
        }
        
        // Handle ranges within a point (shouldn't happen after above, but just in case)
        if (strpos($point, '..') !== false) {
            [$start, $end] = explode('..', $point);
            // For ranges, just use the start point
            $point = $start;
        }
        
        try {
            $codeInt = hexdec($point);
            if ($codeInt > 0) {
                $char = mb_chr($codeInt, 'UTF-8');
                if ($char !== false) {
                    $emoji .= $char;
                }
            }
        } catch (\Exception $e) {
            // Skip invalid code points
            continue;
        }
    }
    
    return $emoji;
}

function extractVersion(string $comment): string {
    // Extract version from comment like "# E0.6   [2]"
    if (preg_match('/#\s*(E\d+\.\d+)/', $comment, $matches)) {
        return $matches[1];
    }
    return '';
}

function extractKeywords(string $shortName): string {
    // Generate keywords from short name
    $keywords = [];
    
    // Split on common separators
    $parts = preg_split('/[\s\-_]+/', strtolower($shortName));
    $keywords = array_merge($keywords, $parts);
    
    // Add common variations
    if (strpos($shortName, 'face') !== false) {
        $keywords[] = 'face';
    }
    if (strpos($shortName, 'hand') !== false) {
        $keywords[] = 'hand';
    }
    if (strpos($shortName, 'skin tone') !== false) {
        $keywords[] = 'skin';
        $keywords[] = 'tone';
    }
    
    return implode(',', array_unique($keywords));
}

// Read and parse emoji file
$lines = file($emojiFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$imported = 0;
$skipped = 0;
$errors = 0;

$conn = Database::getConnection();

// Start transaction
$conn->beginTransaction();

try {
    foreach ($lines as $lineNum => $line) {
        // Skip comments and empty lines
        if (empty(trim($line)) || $line[0] === '#') {
            continue;
        }
        
        // Parse line format: code_points ; type_field ; description # comments
        if (preg_match('/^([0-9A-Fa-f\s\.]+)\s*;\s*(\w+)\s*;\s*([^#]+)(?:#\s*(.+))?$/', $line, $matches)) {
            $codePoints = trim($matches[1]);
            $typeField = trim($matches[2]);
            $description = trim($matches[3]);
            $comment = isset($matches[4]) ? trim($matches[4]) : '';
            
            // Skip if not Basic_Emoji or RGI sequence
            if (!in_array($typeField, ['Basic_Emoji', 'RGI_Emoji_Flag_Sequence', 'RGI_Emoji_Tag_Sequence', 'RGI_Emoji_Modifier_Sequence'])) {
                continue;
            }
            
            // Convert code points to emoji
            try {
                $emoji = codePointsToEmoji($codePoints);
                
                if (empty($emoji)) {
                    $skipped++;
                    continue;
                }
                
                // Extract version
                $version = extractVersion($comment);
                
                // Categorize
                [$category, $subcategory] = categorizeEmoji($description);
                
                // Generate keywords
                $keywords = extractKeywords($description);
                
                // Insert into database
                $sql = 'INSERT INTO emoji_library 
                        (code_points, emoji, short_name, category, subcategory, keywords, version, is_active)
                        VALUES (:code_points, :emoji, :short_name, :category, :subcategory, :keywords, :version, TRUE)
                        ON DUPLICATE KEY UPDATE
                            short_name = VALUES(short_name),
                            category = VALUES(category),
                            subcategory = VALUES(subcategory),
                            keywords = VALUES(keywords),
                            version = VALUES(version)';
                
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':code_points' => $codePoints,
                    ':emoji' => $emoji,
                    ':short_name' => $description,
                    ':category' => $category,
                    ':subcategory' => $subcategory,
                    ':keywords' => $keywords,
                    ':version' => $version ?: null,
                ]);
                
                $imported++;
                
                if ($imported % 100 === 0) {
                    echo "Imported {$imported} emojis...\n";
                }
            } catch (\Exception $e) {
                $errors++;
                echo "Error on line " . ($lineNum + 1) . ": " . $e->getMessage() . "\n";
                continue;
            }
        }
    }
    
    // Commit transaction
    $conn->commit();
    
    echo "\nImport complete!\n";
    echo "Imported: {$imported}\n";
    echo "Skipped: {$skipped}\n";
    echo "Errors: {$errors}\n";
    
} catch (\Exception $e) {
    $conn->rollBack();
    die("Fatal error: " . $e->getMessage() . "\n");
}

