<?php
/**
 * Safe migration script for Thailand Post integration
 * Run from browser or CLI: php migrate_thailand_post.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

include __DIR__ . '/config.php'; // must provide $app_pdo

if (!isset($app_pdo)) {
    echo "Error: app PDO not found. Ensure config.php defines \$app_pdo.\n";
    exit;
}

// Determine MySQL version to decide JSON support
$version = $app_pdo->query('SELECT VERSION()')->fetchColumn();
preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m);
$major = isset($m[1]) ? intval($m[1]) : 5;
$minor = isset($m[2]) ? intval($m[2]) : 7;
$has_json = ($major > 5) || ($major == 5 && $minor >= 7);

$results = [];

function colExists($pdo, $table, $column) {
    $sql = "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    // 1. Ensure telemed_tracking table exists
    $stmt = $app_pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'telemed_tracking'");
    $stmt->execute();
    $exists = (int)$stmt->fetchColumn() > 0;
    if (!$exists) {
        $results[] = "telemed_tracking table not found. Please create table first or run full SQL setup.";
    } else {
        // columns to add
        $cols = [
            'tracking_status' => $has_json ? "JSON NULL" : "LONGTEXT NULL",
            'last_sync' => "DATETIME NULL",
            'sync_data' => $has_json ? "JSON NULL" : "LONGTEXT NULL",
            'webhook_data' => $has_json ? "JSON NULL" : "LONGTEXT NULL",
            'created_at' => "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP",
            'updated_at' => "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
        ];

        foreach ($cols as $col => $type) {
            if (!colExists($app_pdo, 'telemed_tracking', $col)) {
                $sql = "ALTER TABLE telemed_tracking ADD COLUMN `$col` $type";
                $app_pdo->exec($sql);
                $results[] = "Added column $col ($type)";
            } else {
                $results[] = "Column $col already exists";
            }
        }

        // Add indexes if not exist (index names may vary)
        $indexes = [
            'idx_tracking_no' => 'tracking_no',
            'idx_sync_date' => 'last_sync',
            'idx_status' => 'followup_status'
        ];
        foreach ($indexes as $idxName => $col) {
            // check index
            $stmt = $app_pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'telemed_tracking' AND INDEX_NAME = ?");
            $stmt->execute([$idxName]);
            if ((int)$stmt->fetchColumn() === 0) {
                // add index
                $app_pdo->exec("ALTER TABLE telemed_tracking ADD KEY `$idxName` (`$col`)");
                $results[] = "Added index $idxName on $col";
            } else {
                $results[] = "Index $idxName already exists";
            }
        }
    }

    // 2. Create thailand_post_sync_logs table if not exists
    $jsonType = $has_json ? 'JSON' : 'LONGTEXT';
    $app_pdo->exec("CREATE TABLE IF NOT EXISTS thailand_post_sync_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        hn VARCHAR(20) NULL,
        tracking_no VARCHAR(50) NULL,
        action VARCHAR(50) NULL,
        response_data $jsonType NULL,
        error_message TEXT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_tracking_no (tracking_no),
        KEY idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = "Ensured thailand_post_sync_logs table exists";

} catch (Throwable $e) {
    $results[] = "Migration failed: " . $e->getMessage();
}

if (PHP_SAPI === 'cli') {
    echo implode(PHP_EOL, $results) . PHP_EOL;
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Thailand Post Migration</title>
    <style>
        body { font-family: sans-serif; margin: 40px; background: #f8fafc; color: #0f172a; }
        .box { max-width: 900px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; }
        li { margin: 8px 0; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Thailand Post Migration</h1>
        <ul>
            <?php foreach ($results as $line): ?>
                <li><?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</body>
</html>
