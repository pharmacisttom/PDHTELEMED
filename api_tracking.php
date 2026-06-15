<?php
error_reporting(0); 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'config.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

// ==========================================
// 1. ดึงข้อมูล Tracking สำหรับเปิดดูใน Modal
// ==========================================
if ($action === 'get_tracking') {
    $hn = $_POST['hn'] ?? '';
    $regdate = $_POST['regdate'] ?? '';
    
    try {
        $stmt = $app_pdo->prepare("SELECT * FROM telemed_tracking WHERE hn = ? AND regdate = ?");
        $stmt->execute([$hn, $regdate]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['status' => 'success', 'data' => $data ?: null]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ==========================================
// 2. บันทึกข้อมูล Tracking (แก้ไขแบบเลือกอัปเดตเฉพาะฟิลด์ที่มีข้อมูล)
// ==========================================
if ($action === 'save_tracking') {
    $hn = $_POST['hn'] ?? '';
    $regdate = $_POST['regdate'] ?? '';
    $tracking_no = !empty($_POST['tracking_no']) ? trim($_POST['tracking_no']) : null;
    $send_date = !empty($_POST['send_date']) ? $_POST['send_date'] : null;
    $receive_date = !empty($_POST['receive_date']) ? $_POST['receive_date'] : null;
    $followup_status = $_POST['followup_status'] ?? 'รอจัดส่ง';
    $follower_name = !empty($_POST['follower_name']) ? $_POST['follower_name'] : ($_SESSION['fullname'] ?? 'System');
    $note = !empty($_POST['note']) ? $_POST['note'] : null;

    try {
        // แก้ไขโครงสร้าง SQL มาใช้ ON DUPLICATE KEY UPDATE เพื่อป้องกันฟิลด์อื่นโดนล้างค่าเป็น NULL
        $sql = "INSERT INTO telemed_tracking 
                    (hn, regdate, tracking_no, send_date, receive_date, followup_status, follower_name, note) 
                VALUES 
                    (:hn, :regdate, :tracking_no, :send_date, :receive_date, :followup_status, :follower_name, :note)
                ON DUPLICATE KEY UPDATE 
                    followup_status = VALUES(followup_status),
                    follower_name   = VALUES(follower_name),
                    tracking_no     = IFNULL(VALUES(tracking_no), tracking_no),
                    send_date       = IFNULL(VALUES(send_date), send_date),
                    receive_date    = IFNULL(VALUES(receive_date), receive_date),
                    note            = IFNULL(VALUES(note), note)";
                    
        $stmt = $app_pdo->prepare($sql);
        $stmt->execute([
            'hn'              => $hn,
            'regdate'         => $regdate,
            'tracking_no'     => $tracking_no,
            'send_date'       => $send_date,
            'receive_date'    => $receive_date,
            'followup_status' => $followup_status,
            'follower_name'   => $follower_name,
            'note'            => $note
        ]);

        echo json_encode(['status' => 'success', 'message' => 'บันทึกการติดตามสำเร็จ']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['status' => 'info', 'message' => 'Tracking API Ready.']);
?>
