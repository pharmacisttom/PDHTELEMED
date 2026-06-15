<?php
error_reporting(0);
include 'config.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

function notes_table_sql(): string {
    return "CREATE TABLE IF NOT EXISTS telemed_patient_notes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hn VARCHAR(20) NOT NULL,
  regdate DATE NULL,
  note TEXT NOT NULL,
  created_by VARCHAR(100) NULL,
  created_name VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hn_regdate (hn, regdate),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
}

function ensure_notes_table(PDO $pdo): bool {
    $stmt = $pdo->query("SHOW TABLES LIKE 'telemed_patient_notes'");
    return (bool)$stmt->fetchColumn();
}

function setup_required_response(): void {
    echo json_encode([
        'status' => 'setup_required',
        'message' => 'ยังไม่ได้สร้างตาราง telemed_patient_notes',
        'sql' => notes_table_sql(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!ensure_notes_table($app_pdo)) {
    setup_required_response();
}

if ($action === 'list') {
    $hn = trim($_POST['hn'] ?? $_GET['hn'] ?? '');
    $regdate = trim($_POST['regdate'] ?? $_GET['regdate'] ?? '');

    if ($hn === '') {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบ HN'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $stmt = $app_pdo->prepare(
            "SELECT id, hn, regdate, note, created_by, created_name, created_at
             FROM telemed_patient_notes
             WHERE hn = ? AND (regdate = ? OR regdate IS NULL OR ? = '')
             ORDER BY created_at DESC, id DESC"
        );
        $stmt->execute([$hn, $regdate, $regdate]);

        echo json_encode([
            'status' => 'success',
            'notes' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'save') {
    $hn = trim($_POST['hn'] ?? '');
    $regdate = trim($_POST['regdate'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $created_by = $_SESSION['username'] ?? 'System';
    $created_name = $_SESSION['fullname'] ?? $created_by;

    if ($hn === '' || $note === '') {
        echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกหมายเหตุให้ครบถ้วน'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $stmt = $app_pdo->prepare(
            "INSERT INTO telemed_patient_notes (hn, regdate, note, created_by, created_name)
             VALUES (?, NULLIF(?, ''), ?, ?, ?)"
        );
        $stmt->execute([$hn, $regdate, $note, $created_by, $created_name]);

        echo json_encode(['status' => 'success', 'message' => 'บันทึกหมายเหตุสำเร็จ'], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

echo json_encode(['status' => 'info', 'message' => 'Patient Notes API Ready'], JSON_UNESCAPED_UNICODE);
?>
