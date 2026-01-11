<?php
declare(strict_types=1);

function get_tag_color(int $index): string {
    // Theme-compatible palette
    $colors = [
        '#ff3b30', // Red
        '#007aff', // Blue
        '#34c759', // Green
        '#5856d6', // Purple
        '#ff9500', // Orange
        '#af52de', // Indigo
        '#ff2d55', // Pink
        '#5ac8fa', // Teal
    ];
    return $colors[$index % count($colors)];
}

function parse_and_save_tags(PDO $pdo, int $group_id, int $task_id, string $text): string {
    // 1. Find hashtags
    preg_match_all('/#(\w+)/u', $text, $matches);
    $tags = $matches[1] ?? [];
    
    // Remove tags from the display text? (Optional: User preference. Let's keep text clean)
    $cleanText = trim(preg_replace('/#(\w+)/u', '', $text));
    if ($cleanText === '') $cleanText = $text; // Fallback if only tags exist

    // Update the task text to remove the raw tags (optional, looks cleaner)
    // $pdo->prepare("UPDATE tasks SET text=? WHERE id=?")->execute([$cleanText, $task_id]);

    if (empty($tags)) return $cleanText;

    $stmtCheck = $pdo->prepare("SELECT id FROM tags WHERE group_id=? AND name=?");
    $stmtIns   = $pdo->prepare("INSERT INTO tags (group_id, name, color) VALUES (?, ?, ?)");
    $stmtLink  = $pdo->prepare("INSERT OR IGNORE INTO task_tags (task_id, tag_id) VALUES (?, ?)");

    foreach ($tags as $tagName) {
        $tagName = mb_strtolower($tagName); // Normalize
        
        // Check if tag exists
        $stmtCheck->execute([$group_id, $tagName]);
        $tagId = $stmtCheck->fetchColumn();

        if (!$tagId) {
            // Pick a random color based on name hash
            $hash = crc32($tagName);
            $color = get_tag_color($hash);
            
            $stmtIns->execute([$group_id, $tagName, $color]);
            $tagId = $pdo->lastInsertId();
        }

        // Link to task
        $stmtLink->execute([$task_id, $tagId]);
    }
    
    return $cleanText;
}
