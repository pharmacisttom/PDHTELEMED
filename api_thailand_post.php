<?php
declare(strict_types=1);

error_reporting(0);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'thailand_post_config.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

function tp_json(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function tp_tracking_no(): string
{
    return strtoupper(trim((string) ($_POST['tracking_no'] ?? $_GET['tracking_no'] ?? '')));
}

function tp_map_followup_status(array $trackingData): string
{
    $text = strtolower((string) ($trackingData['status'] ?? ''));

    if (str_contains($text, 'นำจ่ายสำเร็จ') || str_contains($text, 'delivered') || str_contains($text, 'ผู้รับได้รับ')) {
        return 'ได้รับแล้ว';
    }

    if (str_contains($text, 'ไม่สำเร็จ') || str_contains($text, 'ตีกลับ') || str_contains($text, 'failed')) {
        return 'ติดต่อไม่ได้';
    }

    if (($trackingData['found'] ?? false) === true) {
        return 'ส่งแล้ว';
    }

    return 'รอจัดส่ง';
}

function tp_log(PDO $pdo, ?string $hn, string $trackingNo, string $action, ?array $response = null, ?string $error = null): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO thailand_post_sync_logs (hn, tracking_no, action, response_data, error_message)
             VALUES (:hn, :tracking_no, :action, :response_data, :error_message)"
        );
        $stmt->execute([
            'hn' => $hn,
            'tracking_no' => $trackingNo,
            'action' => $action,
            'response_data' => $response ? json_encode($response, JSON_UNESCAPED_UNICODE) : null,
            'error_message' => $error,
        ]);
    } catch (Throwable $e) {
        // Logging must never break user workflows.
    }
}

function tp_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name"
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);
    return (int) $stmt->fetchColumn() > 0;
}

function tp_ensure_tracking_columns(PDO $pdo): void
{
    if (!tp_column_exists($pdo, 'telemed_tracking', 'last_sync')) {
        $pdo->exec("ALTER TABLE telemed_tracking ADD COLUMN last_sync DATETIME NULL COMMENT 'Last Thailand Post sync time' AFTER note");
    }

    if (!tp_column_exists($pdo, 'telemed_tracking', 'tracking_status')) {
        $pdo->exec("ALTER TABLE telemed_tracking ADD COLUMN tracking_status JSON NULL COMMENT 'Normalized Thailand Post tracking status' AFTER last_sync");
    }

    if (!tp_column_exists($pdo, 'telemed_tracking', 'sync_data')) {
        $pdo->exec("ALTER TABLE telemed_tracking ADD COLUMN sync_data JSON NULL COMMENT 'Raw Thailand Post API response' AFTER tracking_status");
    }

    if (!tp_column_exists($pdo, 'telemed_tracking', 'webhook_data')) {
        $pdo->exec("ALTER TABLE telemed_tracking ADD COLUMN webhook_data JSON NULL COMMENT 'Raw Thailand Post webhook payload' AFTER sync_data");
    }
}

function tp_save_tracking_record(
    PDO $pdo,
    string $hn,
    string $regdate,
    string $trackingNo,
    ?string $sendDate,
    string $followupStatus,
    string $followerName,
    ?array $trackingData = null,
    ?array $syncData = null
): void {
    if ($trackingData === null && ($syncData['status'] ?? null) !== 'success') {
        $followupStatus = tp_map_followup_status([]);
    }

    $sql = "INSERT INTO telemed_tracking
                (hn, regdate, tracking_no, send_date, followup_status, follower_name, tracking_status, last_sync, sync_data)
            VALUES
                (:hn, :regdate, :tracking_no, COALESCE(:send_date, CURDATE()), :followup_status, :follower_name, :tracking_status, NOW(), :sync_data)
            ON DUPLICATE KEY UPDATE
                tracking_no = VALUES(tracking_no),
                send_date = COALESCE(send_date, VALUES(send_date)),
                followup_status = VALUES(followup_status),
                follower_name = VALUES(follower_name),
                tracking_status = VALUES(tracking_status),
                last_sync = NOW(),
                sync_data = VALUES(sync_data)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'hn' => $hn,
        'regdate' => $regdate,
        'tracking_no' => $trackingNo,
        'send_date' => $sendDate,
        'followup_status' => $followupStatus,
        'follower_name' => $followerName,
        'tracking_status' => $trackingData ? json_encode($trackingData, JSON_UNESCAPED_UNICODE) : null,
        'sync_data' => $syncData ? json_encode($syncData, JSON_UNESCAPED_UNICODE) : null,
    ]);
}

if ($action === 'validate_tracking_no') {
    $trackingNo = tp_tracking_no();
    $valid = $thailand_post->isValidTrackingNumber($trackingNo);

    tp_json([
        'status' => $valid ? 'success' : 'error',
        'valid' => $valid,
        'message' => $valid ? 'เลขพัสดุถูกต้อง' : 'รูปแบบเลขพัสดุไม่ถูกต้อง ตัวอย่าง: EV123456789TH',
        'tracking_no' => $trackingNo,
    ]);
}

if ($action === 'get_track_url') {
    $trackingNo = tp_tracking_no();
    if (!$trackingNo) {
        tp_json(['status' => 'error', 'message' => 'กรุณาระบุเลขพัสดุ']);
    }

    tp_json([
        'status' => 'success',
        'url' => $thailand_post->getTrackingUrl($trackingNo),
        'tracking_no' => $trackingNo,
    ]);
}

if ($action === 'get_status') {
    $trackingNo = tp_tracking_no();
    if (!$trackingNo) {
        tp_json(['status' => 'error', 'message' => 'กรุณาระบุเลขพัสดุ']);
    }

    $result = $thailand_post->trackShipment($trackingNo);
    tp_json($result);
}

if ($action === 'sync_tracking_status') {
    $hn = trim((string) ($_POST['hn'] ?? ''));
    $regdate = trim((string) ($_POST['regdate'] ?? ''));
    $trackingNo = tp_tracking_no();
    $followerName = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'System';

    if ($hn === '' || $regdate === '' || $trackingNo === '') {
        tp_json(['status' => 'error', 'message' => 'กรุณาระบุ HN, วันที่รับบริการ และเลขพัสดุ']);
    }

    if (!$thailand_post->isValidTrackingNumber($trackingNo)) {
        tp_json(['status' => 'error', 'message' => 'Invalid Thailand Post tracking number format. Example: EV123456789TH']);
    }

    try {
        tp_ensure_tracking_columns($app_pdo);
    } catch (Throwable $e) {
        tp_log($app_pdo, $hn, $trackingNo, 'migration_failed', null, $e->getMessage());
        tp_json(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
    }

    $result = $thailand_post->trackShipment($trackingNo);
    if ($result['status'] !== 'success') {
        try {
            tp_save_tracking_record(
                $app_pdo,
                $hn,
                $regdate,
                $trackingNo,
                $_POST['send_date'] ?? null,
                'เธฃเธญเธเธฑเธ”เธชเนเธ',
                $followerName,
                null,
                $result
            );
            tp_log($app_pdo, $hn, $trackingNo, 'sync_failed_saved', $result, $result['message'] ?? null);
            tp_json([
                'status' => 'success',
                'message' => 'Saved tracking number, but Thailand Post sync is not available yet: ' . ($result['message'] ?? '-'),
                'sync_warning' => true,
                'tracking_url' => $thailand_post->getTrackingUrl($trackingNo),
            ]);
        } catch (Throwable $e) {
            tp_log($app_pdo, $hn, $trackingNo, 'sync_failed', $result, $e->getMessage());
            tp_json(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
        }
    }

    $trackingData = $result['data'];
    $followupStatus = tp_map_followup_status($trackingData);

    try {
        tp_save_tracking_record(
            $app_pdo,
            $hn,
            $regdate,
            $trackingNo,
            $_POST['send_date'] ?? null,
            $followupStatus,
            $followerName,
            $trackingData,
            $result['raw'] ?? $trackingData
        );

        tp_log($app_pdo, $hn, $trackingNo, 'sync_success', $result);
        tp_json([
            'status' => 'success',
            'message' => 'บันทึกและซิงก์สถานะพัสดุสำเร็จ',
            'data' => $trackingData,
            'followup_status' => $followupStatus,
            'tracking_url' => $thailand_post->getTrackingUrl($trackingNo),
        ]);
    } catch (Throwable $e) {
        tp_log($app_pdo, $hn, $trackingNo, 'sync_failed', $result, $e->getMessage());
        tp_json(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
    }
}

if ($action === 'webhook_receiver') {
    $payload = file_get_contents('php://input') ?: '';
    $data = json_decode($payload, true);

    if (!is_array($data)) {
        tp_json(['status' => 'error', 'message' => 'Invalid JSON payload']);
    }

    $trackingNo = strtoupper(trim((string) ($data['trackNumber'] ?? $data['tracking_no'] ?? $data['barcode'] ?? '')));
    if ($trackingNo === '') {
        tp_json(['status' => 'error', 'message' => 'ไม่พบเลขพัสดุใน webhook']);
    }

    try {
        tp_ensure_tracking_columns($app_pdo);

        if (!is_dir(__DIR__ . '/logs')) {
            mkdir(__DIR__ . '/logs', 0775, true);
        }
        file_put_contents(
            __DIR__ . '/logs/thailand_post_webhook_' . date('Y-m-d') . '.log',
            date('Y-m-d H:i:s') . ' => ' . $payload . PHP_EOL,
            FILE_APPEND
        );

        $stmt = $app_pdo->prepare(
            "UPDATE telemed_tracking
             SET webhook_data = :webhook_data, last_sync = NOW()
             WHERE tracking_no = :tracking_no"
        );
        $stmt->execute([
            'webhook_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'tracking_no' => $trackingNo,
        ]);

        tp_log($app_pdo, null, $trackingNo, 'webhook_received', $data);
        tp_json(['status' => 'success', 'message' => 'Webhook received']);
    } catch (Throwable $e) {
        tp_json(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

tp_json(['status' => 'info', 'message' => 'Thailand Post API ready']);
