<?php
// ปิดการแสดงผล Error ของ PHP ไม่ให้ไปแทรกใน JSON (ป้องกันหน้าเว็บโหลดค้าง)
error_reporting(0); 

include 'config.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

// ==========================================
// 1. ดึงข้อมูลแสดงใน Smart Modal (ยา, วินิจฉัย, ที่อยู่, นัดหมาย)
// ==========================================
if ($action === 'get_telemed_data') {
    // รับค่าจากหน้า Dashboard
    $hn = $_REQUEST['hn'] ?? '';
    $regdate = $_REQUEST['regdate'] ?? date('Y-m-d'); 
    
    if(empty($hn)) { 
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบ HN']); 
        exit; 
    }

    try {
        // A. ดึงที่อยู่จัดส่งและเบอร์โทร (APP DB - 240)
        $stmt_app = $app_pdo->prepare("SELECT address, phone FROM telemed_delivery WHERE hn = ?");
        $stmt_app->execute([$hn]);
        $delivery = $stmt_app->fetch();

        // ตัวแปรเก็บผลลัพธ์
        $diag_text = "<span class='text-gray-400 text-sm'>ไม่พบข้อมูลการวินิจฉัยของวันที่ {$regdate}</span>";
        $drugs = [];

        // B. ดึงการวินิจฉัย (HIS DB - 251) แบบระบุวันที่
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

        // C. ดึงรายการยา (HIS DB - 251) แบบระบุวันที่
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

        // D. ดึงข้อมูลนัดหมายครั้งถัดไป (ที่ยังไม่ถึงวันนัด) จาก pt.ptappoint
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

        // ส่งข้อมูลกลับไปให้ JavaScript ทำงานต่อ
        echo json_encode([
            'status' => 'success',
            'delivery' => $delivery ? $delivery : ['address' => '', 'phone' => ''],
            'drugs' => $drugs,
            'diag' => $diag_text,
            'visit_date' => $regdate,
            'appointment' => $appointment
        ]);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
    }
    exit;
}

// ==========================================
// 2. บันทึกที่อยู่และเบอร์โทร ลง APP DB (240)
// ==========================================
if ($action === 'save_delivery') {
    $hn = $_POST['hn'] ?? '';
    $address = $_POST['address'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $user = $_SESSION['username'] ?? 'System';

    if(empty($hn) || empty($address) || empty($phone)) {
        echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        exit;
    }

    try {
        $sql = "REPLACE INTO telemed_delivery (hn, address, phone, updated_by) VALUES (?, ?, ?, ?)";
        $stmt = $app_pdo->prepare($sql);
        $stmt->execute([$hn, $address, $phone, $user]);

        echo json_encode(['status' => 'success', 'message' => 'บันทึกข้อมูลจัดส่งเรียบร้อยแล้ว']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
    }
    exit;
}

// ==========================================
// 3. ลงนัดหมายผู้ป่วย ลง HIS DB (251)
// ==========================================
if ($action === 'save_appoint') {
    $hn = $_POST['hn'] ?? '';
    $app_date = $_POST['app_date'] ?? '';
    $app_time = $_POST['app_time'] ?? '';
    $cause = $_POST['cause'] ?? '';
    
    $pcucode = '10832'; 
    $clinic_code = '123'; 

    try {
        $cause_tis620 = iconv("UTF-8", "TIS-620//IGNORE", $cause);
        
        $sql = "INSERT INTO pt.ptappoint 
                (pcucode, regdate, hn, an, regroom, clinic, appointdate, timeappoint, causeappoint, encrypted, sendamp, sendpro) 
                VALUES 
                (:pcucode, CURDATE(), :hn, '', '', :clinic, :appointdate, :timeappoint, :causeappoint, 0, 0, 0)
                ON DUPLICATE KEY UPDATE 
                timeappoint = VALUES(timeappoint),
                causeappoint = VALUES(causeappoint)";
                
        $stmt = $his_pdo->prepare($sql);
        $stmt->execute([
            'pcucode' => $pcucode,
            'hn' => $hn,
            'clinic' => $clinic_code,
            'appointdate' => $app_date,
            'timeappoint' => $app_time,
            'causeappoint' => $cause_tis620
        ]);

        echo json_encode(['status' => 'success', 'message' => 'บันทึกการนัดหมายลงระบบ HIS สำเร็จ']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการลงนัดหมาย: ' . $e->getMessage()]);
    }
    exit;
}

// ==========================================
// 4. กรณีเปิดหน้าเว็บตรงๆ โดยไม่ระบุ action
// ==========================================
echo json_encode([
    'status' => 'info', 
    'message' => 'PDH Telemed API is running properly.'
]);
exit;
?>