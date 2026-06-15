<?php
error_reporting(0); 
include 'config.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

function ensure_delivery_pickup_column(PDO $pdo): void {
    $stmt = $pdo->query("SHOW COLUMNS FROM telemed_delivery LIKE 'pickup_self'");
    if (!$stmt->fetch()) {
        throw new RuntimeException('ยังไม่ได้ติดตั้งคอลัมน์ pickup_self กรุณาให้ผู้ดูแลฐานข้อมูลรันไฟล์ migrate_clinic_statuses.sql');
    }
}

function ensure_patient_status_table(PDO $pdo): void {
    $stmt = $pdo->query("SHOW TABLES LIKE 'telemed_patient_status'");
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('ยังไม่ได้ติดตั้งตารางสถานะผู้ป่วย กรุณาให้ผู้ดูแลฐานข้อมูลรันไฟล์ migrate_clinic_statuses.sql');
    }
}

function ensure_clinic_status_options_table(PDO $pdo): void {
    $stmt = $pdo->query("SHOW TABLES LIKE 'telemed_clinic_status_options'");
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('ยังไม่ได้ติดตั้งตารางสถานะประจำคลินิก กรุณาให้ผู้ดูแลฐานข้อมูลรันไฟล์ migrate_clinic_statuses.sql');
    }
}

if ($action === 'add_clinic_status') {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบใหม่'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $clinic_code = trim((string)($_POST['clinic_code'] ?? ''));
    $status_name = trim((string)($_POST['status_name'] ?? ''));
    $status_name_length = function_exists('mb_strlen') ? mb_strlen($status_name, 'UTF-8') : strlen($status_name);
    if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/', $clinic_code) || $status_name === '' || $status_name_length > 100) {
        echo json_encode(['status' => 'error', 'message' => 'ข้อมูลสถานะไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        ensure_clinic_status_options_table($app_pdo);
        $stmt = $app_pdo->prepare(
            "INSERT INTO telemed_clinic_status_options (clinic_code, status_name, created_by)
             VALUES (?, ?, ?)"
        );
        $stmt->execute([$clinic_code, $status_name, $_SESSION['username'] ?? 'System']);
        echo json_encode([
            'status' => 'success',
            'id' => (int)$app_pdo->lastInsertId(),
            'status_name' => $status_name
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        $message = $e instanceof PDOException && (string)$e->getCode() === '23000'
            ? 'มีสถานะนี้ในคลินิกแล้ว'
            : $e->getMessage();
        echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'save_patient_status') {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบใหม่']);
        exit;
    }

    $hn = trim((string)($_POST['hn'] ?? ''));
    $regdate = trim((string)($_POST['regdate'] ?? ''));
    $status_type = trim((string)($_POST['status_type'] ?? ''));
    $status_detail = trim((string)($_POST['status_detail'] ?? ''));
    $clinic_code = trim((string)($_POST['clinic_code'] ?? ''));
    $allowed_statuses = [
        'discharge' => ['label' => 'กลับบ้าน', 'style' => 'bg-slate-100 text-slate-700 hover:bg-slate-200'],
        'home_delivery' => ['label' => 'รับยาที่บ้าน', 'style' => 'bg-blue-100 text-blue-700 hover:bg-blue-200'],
        'self_pickup' => ['label' => 'รับยาเอง', 'style' => 'bg-amber-100 text-amber-800 hover:bg-amber-200'],
        'other' => ['label' => 'อื่นๆ', 'style' => 'bg-violet-100 text-violet-700 hover:bg-violet-200']
    ];

    $custom_status = null;
    if (preg_match('/^clinic_(\d+)$/', $status_type, $custom_match)) {
        try {
            ensure_clinic_status_options_table($app_pdo);
            $stmt_custom = $app_pdo->prepare(
                "SELECT id, status_name
                 FROM telemed_clinic_status_options
                 WHERE id = ? AND clinic_code = ? AND is_active = 1
                 LIMIT 1"
            );
            $stmt_custom->execute([(int)$custom_match[1], $clinic_code]);
            $custom_status = $stmt_custom->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $custom_status = null;
        }
    }

    $valid_date = DateTime::createFromFormat('Y-m-d', $regdate);
    $is_allowed_status = isset($allowed_statuses[$status_type]) || $custom_status;
    if ($hn === '' || !$valid_date || $valid_date->format('Y-m-d') !== $regdate || !$is_allowed_status) {
        echo json_encode(['status' => 'error', 'message' => 'ข้อมูลสถานะไม่ถูกต้อง']);
        exit;
    }
    if ($status_type === 'other' && $status_detail === '') {
        echo json_encode(['status' => 'error', 'message' => 'กรุณาระบุสถานะอื่นๆ']);
        exit;
    }
    if ($custom_status) {
        $status_detail = (string)$custom_status['status_name'];
    } elseif ($status_type !== 'other') {
        $status_detail = '';
    }

    try {
        ensure_patient_status_table($app_pdo);
        $stmt = $app_pdo->prepare("INSERT INTO telemed_patient_status
            (hn, regdate, status_type, status_detail, updated_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                status_type = VALUES(status_type),
                status_detail = VALUES(status_detail),
                updated_by = VALUES(updated_by)");
        $stmt->execute([
            $hn,
            $regdate,
            $status_type,
            $status_detail !== '' ? $status_detail : null,
            $_SESSION['username'] ?? 'System'
        ]);

        $label = $custom_status ? (string)$custom_status['status_name'] : $allowed_statuses[$status_type]['label'];
        if (!$custom_status && $status_type === 'other') {
            $label .= ': ' . $status_detail;
        }
        echo json_encode([
            'status' => 'success',
            'status_type' => $status_type,
            'status_detail' => $status_detail,
            'label' => $label,
            'style' => $custom_status
                ? 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200'
                : $allowed_statuses[$status_type]['style']
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
    }
    exit;
}

// 1. ดึงข้อมูลแสดงใน Smart Modal
if ($action === 'get_telemed_data') {
    $hn = $_REQUEST['hn'] ?? '';
    $regdate = $_REQUEST['regdate'] ?? date('Y-m-d'); 
    
    if(empty($hn)) { echo json_encode(['status' => 'error', 'message' => 'ไม่พบ HN']); exit; }

    try {
        ensure_delivery_pickup_column($app_pdo);
        $stmt_app = $app_pdo->prepare("SELECT address, phone, pickup_self FROM telemed_delivery WHERE hn = ?");
        $stmt_app->execute([$hn]);
        $delivery = $stmt_app->fetch();

        $diag_text = "<span class='text-gray-400 text-sm'>ไม่พบข้อมูลการวินิจฉัยของวันที่ {$regdate}</span>";
        $drugs = [];

        $stmt_diag = $his_pdo->prepare("SELECT diag, descrip FROM opd.odiag WHERE hn = ? AND regdate = ?");
        $stmt_diag->execute([$hn, $regdate]);
        $diags = $stmt_diag->fetchAll();
        
        if (count($diags) > 0) {
            $diag_arr = [];
            foreach($diags as $d) {
                $diag_code = $d['diag'];
                $diag_desc = decodeThai($d['descrip']); 
                $diag_arr[] = "<div class='mb-1 text-sm text-gray-800'><b>[{$diag_code}]</b> {$diag_desc}</div>";
            }
            $diag_text = implode("", $diag_arr);
        }

        $stmt_drug = $his_pdo->prepare("SELECT namedrug, amount, item_usage FROM opd.drug_order_opd WHERE hn = ? AND regdate = ?");
        $stmt_drug->execute([$hn, $regdate]);
        $drug_rows = $stmt_drug->fetchAll();
        
        foreach($drug_rows as $r) {
            $drugs[] = [
                'name' => decodeThai($r['namedrug']),
                'qty' => floatval($r['amount']),
                'usage' => decodeThai($r['item_usage'])
            ];
        }

        $stmt_app_next = $his_pdo->prepare("SELECT appointdate, timeappoint, causeappoint FROM pt.ptappoint WHERE hn = ? AND appointdate >= CURDATE() ORDER BY appointdate ASC LIMIT 1");
        $stmt_app_next->execute([$hn]);
        $next_appoint = $stmt_app_next->fetch(PDO::FETCH_ASSOC);

        $appointment = null;
        if ($next_appoint) {
            $appointment = [
                'date' => $next_appoint['appointdate'],
                'time' => $next_appoint['timeappoint'] ? date('H:i', strtotime($next_appoint['timeappoint'])) : '09:00',
                'cause' => decodeThai($next_appoint['causeappoint'])
            ];
        }

        echo json_encode(['status' => 'success', 'delivery' => $delivery ?: ['address' => '', 'phone' => '', 'pickup_self' => 0], 'drugs' => $drugs, 'diag' => $diag_text, 'visit_date' => $regdate, 'appointment' => $appointment]);
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]); }
    exit;
}

// 2. บันทึกที่อยู่
if ($action === 'save_delivery') {
    $hn = $_POST['hn'] ?? '';
    $address = $_POST['address'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $pickup_self = isset($_POST['pickup_self']) && $_POST['pickup_self'] === '1' ? 1 : 0;
    $user = $_SESSION['username'] ?? 'System';

    if(empty($hn) || empty($phone) || (!$pickup_self && empty($address))) { echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']); exit; }

    try {
        ensure_delivery_pickup_column($app_pdo);
        $sql = "REPLACE INTO telemed_delivery (hn, address, phone, pickup_self, updated_by) VALUES (?, ?, ?, ?, ?)";
        $stmt = $app_pdo->prepare($sql);
        $stmt->execute([$hn, $address, $phone, $pickup_self, $user]);
        echo json_encode(['status' => 'success', 'message' => 'บันทึกข้อมูลจัดส่งเรียบร้อยแล้ว']);
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]); }
    exit;
}

// 3. บันทึกการนัดหมาย
if ($action === 'save_appoint') {
    $hn = $_POST['hn'] ?? '';
    $app_date = $_POST['app_date'] ?? '';
    $app_time = $_POST['app_time'] ?? '';
    $cause = $_POST['cause'] ?? '';
    $pcucode = '10832'; 
    $clinic_code = '123'; 

    try {
        $cause_tis620 = iconv("UTF-8", "TIS-620//IGNORE", $cause);
        $sql = "INSERT INTO pt.ptappoint (pcucode, regdate, hn, an, regroom, clinic, appointdate, timeappoint, causeappoint, encrypted, sendamp, sendpro) VALUES (:pcucode, CURDATE(), :hn, '', '', :clinic, :appointdate, :timeappoint, :causeappoint, 0, 0, 0) ON DUPLICATE KEY UPDATE timeappoint = VALUES(timeappoint), causeappoint = VALUES(causeappoint)";
        $stmt = $his_pdo->prepare($sql);
        $stmt->execute(['pcucode' => $pcucode, 'hn' => $hn, 'clinic' => $clinic_code, 'appointdate' => $app_date, 'timeappoint' => $app_time, 'causeappoint' => $cause_tis620]);
        echo json_encode(['status' => 'success', 'message' => 'บันทึกการนัดหมายสำเร็จ']);
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]); }
    exit;
}

echo json_encode(['status' => 'info', 'message' => 'Telemed API Ready.']);
?>
