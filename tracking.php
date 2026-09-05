<?php
include 'config.php';
header('Content-Type: text/html; charset=UTF-8');

// ==========================================
// 1. ตรวจสอบสิทธิ์การเข้าใช้งาน
// ==========================================
$current_page = basename($_SERVER['PHP_SELF']); 
// check_permission($current_page); // หากต้องการเปิดระบบสิทธิ์ให้เปิดบรรทัดนี้กลับ
$selected_month = trim((string)($_GET['month'] ?? ''));
if ($selected_month !== '' && !preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = '';
}
$from_date = trim((string)($_GET['from_date'] ?? ''));
$to_date = trim((string)($_GET['to_date'] ?? ''));

$normalizeDate = static function (string $value): string {
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }

    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year) ? $value : '';
};

$from_date = $normalizeDate($from_date);
$to_date = $normalizeDate($to_date);

if ($selected_month !== '' && $from_date === '' && $to_date === '') {
    $month_start = $selected_month . '-01';
    $month_timestamp = strtotime($month_start);
    if ($month_timestamp !== false) {
        $from_date = date('Y-m-01', $month_timestamp);
        $to_date = date('Y-m-t', $month_timestamp);
    }
}

if ($from_date !== '' && $to_date !== '' && $from_date > $to_date) {
    [$from_date, $to_date] = [$to_date, $from_date];
}

$is_date_filtered = $from_date !== '' || $to_date !== '';

// ==========================================
// 2. ดึงข้อมูลผู้ป่วย Telemed ล่าสุด 50 ราย และ Join กับตาราง Tracking
// ==========================================
try {
    // 2.1 ดึงข้อมูลผู้รับบริการจาก HIS DB (251) และปรับ DATE(o.regdate) เพื่อตัดเวลาออก
    // Use the same Telemed visit flag as Dashboard so new home-delivery visits are not omitted.
    $his_conditions = [
        "o.comein IN ('10', 'IN10')",
    ];
    $his_params = [];

    if ($from_date !== '') {
        $his_conditions[] = "DATE(o.regdate) >= ?";
        $his_params[] = $from_date;
    }

    if ($to_date !== '') {
        $his_conditions[] = "DATE(o.regdate) <= ?";
        $his_params[] = $to_date;
    }

    $sql_his = "SELECT o.hn, o.fullname, DATE(o.regdate) as clean_regdate, o.regdate as raw_regdate, o.timereg 
                FROM opd.opd o 
                WHERE " . implode("
                  AND ", $his_conditions) . "
                ORDER BY o.regdate DESC, o.timereg DESC";
    $stmt_his = $his_pdo->prepare($sql_his);
    $stmt_his->execute($his_params);
    $patients = $stmt_his->fetchAll(PDO::FETCH_ASSOC);

    // Include visits explicitly marked for home delivery even if the HIS Telemed flag was not populated.
    $status_table = $app_pdo->query("SHOW TABLES LIKE 'telemed_patient_status'");
    if ($status_table->fetchColumn()) {
        $status_conditions = ["status_type = 'home_delivery'"];
        $status_params = [];
        if ($from_date !== '') {
            $status_conditions[] = "DATE(regdate) >= ?";
            $status_params[] = $from_date;
        }
        if ($to_date !== '') {
            $status_conditions[] = "DATE(regdate) <= ?";
            $status_params[] = $to_date;
        }

        $stmt_home_status = $app_pdo->prepare(
            "SELECT hn, DATE(regdate) AS clean_regdate
             FROM telemed_patient_status
             WHERE " . implode(" AND ", $status_conditions)
        );
        $stmt_home_status->execute($status_params);
        $home_status_visits = $stmt_home_status->fetchAll(PDO::FETCH_ASSOC);
        $existing_visit_keys = [];
        foreach ($patients as $patient) {
            $existing_visit_keys[$patient['hn'] . '|' . $patient['clean_regdate']] = true;
        }

        foreach (array_chunk($home_status_visits, 400) as $visit_chunk) {
            $pair_placeholders = implode(',', array_fill(0, count($visit_chunk), '(?, ?)'));
            $pair_params = [];
            foreach ($visit_chunk as $visit) {
                $pair_params[] = $visit['hn'];
                $pair_params[] = $visit['clean_regdate'];
            }

            $stmt_home_visits = $his_pdo->prepare(
                "SELECT o.hn, o.fullname, DATE(o.regdate) AS clean_regdate, o.regdate AS raw_regdate, o.timereg
                 FROM opd.opd o
                 WHERE (o.hn, DATE(o.regdate)) IN ($pair_placeholders)"
            );
            $stmt_home_visits->execute($pair_params);
            foreach ($stmt_home_visits->fetchAll(PDO::FETCH_ASSOC) as $patient) {
                $visit_key = $patient['hn'] . '|' . $patient['clean_regdate'];
                if (!isset($existing_visit_keys[$visit_key])) {
                    $patients[] = $patient;
                    $existing_visit_keys[$visit_key] = true;
                }
            }
        }
    }

    // Recover recent delivery-confirmed patients when the HIS Telemed code or status record is delayed.
    $delivery_column = $app_pdo->query("SHOW COLUMNS FROM telemed_delivery LIKE 'pickup_self'");
    $delivery_pickup_clause = $delivery_column->fetchColumn() ? "COALESCE(pickup_self, 0) = 0" : "1 = 1";
    $stmt_recent_delivery = $app_pdo->prepare(
        "SELECT hn
         FROM telemed_delivery
         WHERE $delivery_pickup_clause
           AND TRIM(COALESCE(address, '')) <> ''
           AND update_time >= DATE_SUB(CURDATE(), INTERVAL 45 DAY)"
    );
    $stmt_recent_delivery->execute();
    $recent_delivery_hns = array_values(array_unique(array_column($stmt_recent_delivery->fetchAll(PDO::FETCH_ASSOC), 'hn')));
    $existing_visit_keys = [];
    foreach ($patients as $patient) {
        $existing_visit_keys[$patient['hn'] . '|' . $patient['clean_regdate']] = true;
    }

    foreach (array_chunk($recent_delivery_hns, 400) as $hn_chunk) {
        $hn_placeholders = implode(',', array_fill(0, count($hn_chunk), '?'));
        $fallback_conditions = ["hn IN ($hn_placeholders)"];
        $fallback_params = $hn_chunk;
        if ($from_date !== '') {
            $fallback_conditions[] = "DATE(regdate) >= ?";
            $fallback_params[] = $from_date;
        }
        if ($to_date !== '') {
            $fallback_conditions[] = "DATE(regdate) <= ?";
            $fallback_params[] = $to_date;
        }

        $stmt_delivery_visits = $his_pdo->prepare(
            "SELECT o.hn, o.fullname, DATE(o.regdate) AS clean_regdate, o.regdate AS raw_regdate, o.timereg
             FROM opd.opd o
             INNER JOIN (
                 SELECT hn, MAX(regdate) AS latest_regdate
                 FROM opd.opd
                 WHERE " . implode(" AND ", $fallback_conditions) . "
                 GROUP BY hn
             ) latest ON latest.hn = o.hn AND latest.latest_regdate = o.regdate
             ORDER BY o.regdate DESC, o.timereg DESC"
        );
        $stmt_delivery_visits->execute($fallback_params);
        $added_hns = [];
        foreach ($stmt_delivery_visits->fetchAll(PDO::FETCH_ASSOC) as $patient) {
            if (isset($added_hns[$patient['hn']])) {
                continue;
            }
            $visit_key = $patient['hn'] . '|' . $patient['clean_regdate'];
            if (!isset($existing_visit_keys[$visit_key])) {
                $patients[] = $patient;
                $existing_visit_keys[$visit_key] = true;
            }
            $added_hns[$patient['hn']] = true;
        }
    }

    $note_count_map = [];
$tracking_map = [];
$delivery_map = [];
$drug_count_map = [];
$patient_status_map = [];

if ($patients) {
    $patient_hns = array_values(array_unique(array_column($patients, 'hn')));
    $has_pickup_self = (bool)$app_pdo->query("SHOW COLUMNS FROM telemed_delivery LIKE 'pickup_self'")->fetchColumn();
    $delivery_select = $has_pickup_self
        ? "address, phone, COALESCE(pickup_self, 0) AS pickup_self"
        : "address, phone, 0 AS pickup_self";

    foreach (array_chunk($patient_hns, 400) as $hn_chunk) {
        $hn_placeholders = implode(',', array_fill(0, count($hn_chunk), '?'));

        $notes_table = $app_pdo->query("SHOW TABLES LIKE 'telemed_patient_notes'");
        if ($notes_table->fetchColumn()) {
            $stmt_notes = $app_pdo->prepare("SELECT hn, COUNT(*) AS total_notes FROM telemed_patient_notes WHERE hn IN ($hn_placeholders) GROUP BY hn");
            $stmt_notes->execute($hn_chunk);
            foreach ($stmt_notes->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $note_count_map[$row['hn']] = (int)$row['total_notes'];
            }
        }

        $stmt_delivery = $app_pdo->prepare("SELECT hn, $delivery_select FROM telemed_delivery WHERE hn IN ($hn_placeholders)");
        $stmt_delivery->execute($hn_chunk);
        foreach ($stmt_delivery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $delivery_map[$row['hn']] = $row;
        }
    }

    foreach (array_chunk($patients, 400) as $patient_chunk) {
        $pair_placeholders = implode(',', array_fill(0, count($patient_chunk), '(?, ?)'));
        $pair_params = [];
        foreach ($patient_chunk as $patient) {
            $pair_params[] = $patient['hn'];
            $pair_params[] = $patient['clean_regdate'];
        }

        $stmt_tracking = $app_pdo->prepare("SELECT hn, regdate, followup_status, tracking_no, send_date, last_sync, tracking_status FROM telemed_tracking WHERE (hn, regdate) IN ($pair_placeholders)");
        $stmt_tracking->execute($pair_params);
        foreach ($stmt_tracking->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tracking_map[$row['hn'] . '|' . $row['regdate']] = $row;
        }

        $status_table = $app_pdo->query("SHOW TABLES LIKE 'telemed_patient_status'");
        if ($status_table->fetchColumn()) {
            $stmt_patient_status = $app_pdo->prepare("SELECT hn, regdate, status_type FROM telemed_patient_status WHERE (hn, regdate) IN ($pair_placeholders)");
            $stmt_patient_status->execute($pair_params);
            foreach ($stmt_patient_status->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $patient_status_map[$row['hn'] . '|' . $row['regdate']] = $row;
            }
        }

        $stmt_drug = $his_pdo->prepare("SELECT hn, regdate, COUNT(*) AS total_drugs FROM opd.drug_order_opd WHERE (hn, regdate) IN ($pair_placeholders) GROUP BY hn, regdate");
        $stmt_drug->execute($pair_params);
        foreach ($stmt_drug->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $drug_count_map[$row['hn'] . '|' . $row['regdate']] = (int)$row['total_drugs'];
        }
    }
}

foreach ($patients as $key => $pt) {
    $patient_key = $pt['hn'] . '|' . $pt['clean_regdate'];
    $track_data = $tracking_map[$patient_key] ?? null;
    $patient_status = $patient_status_map[$patient_key]['status_type'] ?? '';
    $delivery_data = $delivery_map[$pt['hn']] ?? [];
    $drug_count = $drug_count_map[$patient_key] ?? 0;
    $delivery_address = trim((string)($delivery_data['address'] ?? ''));
    $delivery_phone = trim((string)($delivery_data['phone'] ?? ''));
    $pickup_self = (int)($delivery_data['pickup_self'] ?? 0);

    $patients[$key]['status'] = $track_data['followup_status'] ?? 'รอจัดส่ง';
    $patients[$key]['track_no'] = $track_data['tracking_no'] ?? '-';
    $patients[$key]['send_date'] = $track_data['send_date'] ?? null;
    $patients[$key]['last_sync'] = $track_data['last_sync'] ?? null;
    $patients[$key]['tracking_status'] = $track_data['tracking_status'] ?? null;
    $patients[$key]['patient_status'] = $patient_status;
    $patients[$key]['drug_count'] = $drug_count;
    $patients[$key]['delivery_address'] = $delivery_address;
    $patients[$key]['delivery_phone'] = $delivery_phone;
    $patients[$key]['pickup_self'] = $pickup_self;
    $patients[$key]['note_count'] = $note_count_map[$pt['hn']] ?? 0;

    if ($pickup_self === 1) {
        $patients[$key]['status'] = 'มารับยาเอง';
    }
}
$tracking_patients = array_values(array_filter($patients, static function (array $patient): bool {
    $tracking_no = trim((string)($patient['track_no'] ?? ''));
    return $tracking_no !== '' && $tracking_no !== '-';
}));

$home_delivery_patients = array_values(array_filter($patients, static function (array $patient): bool {
    $is_home_delivery = ($patient['patient_status'] ?? '') === 'home_delivery';
    $has_delivery_data = (int)($patient['drug_count'] ?? 0) > 0
        && trim((string)($patient['delivery_address'] ?? '')) !== '';

    return (int)($patient['pickup_self'] ?? 0) !== 1
        && ($is_home_delivery || $has_delivery_data);
}));

$visible_patient_map = [];
foreach (array_merge($tracking_patients, $home_delivery_patients) as $patient) {
    $visible_patient_map[$patient['hn'] . '|' . $patient['clean_regdate']] = $patient;
}
$patients = array_values($visible_patient_map);
$tracking_summary_patients = $tracking_patients;
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$tracking_summary = [
    'total_cases' => count($tracking_summary_patients),
    'total_drugs' => 0,
    'with_tracking' => 0,
    'without_tracking' => 0,
    'synced' => 0,
    'pending' => 0,
    'sent' => 0,
    'received' => 0,
    'problem' => 0,
];

foreach ($tracking_summary_patients as $pt) {
    $status_text = (string)($pt['status'] ?? '');
    $tracking_no = trim((string)($pt['track_no'] ?? ''));
    $tracking_summary['total_drugs'] += (int)($pt['drug_count'] ?? 0);

    if ($tracking_no !== '' && $tracking_no !== '-') {
        $tracking_summary['with_tracking']++;
    } else {
        $tracking_summary['without_tracking']++;
    }

    if (!empty($pt['last_sync'])) {
        $tracking_summary['synced']++;
    }

    if (str_contains($status_text, 'รอ') || str_contains($status_text, 'เธฃเธญ')) {
        $tracking_summary['pending']++;
    } elseif (str_contains($status_text, 'ได้รับ') || str_contains($status_text, 'เธ”เนเธฃเธฑเธ')) {
        $tracking_summary['received']++;
    } elseif (str_contains($status_text, 'ติดต่อ') || str_contains($status_text, 'ไม่ได้') || str_contains($status_text, 'เธ•เธดเธ”เธ•') || str_contains($status_text, 'เนเธกเน')) {
        $tracking_summary['problem']++;
    } elseif (str_contains($status_text, 'ส่ง') || str_contains($status_text, 'เธชเนเธ')) {
        $tracking_summary['sent']++;
    } else {
        $tracking_summary['pending']++;
    }
}

$tracking_month_map = [];
foreach ($tracking_summary_patients as $pt) {
    $month_key = substr((string)($pt['clean_regdate'] ?? ''), 0, 7);
    if (!preg_match('/^\d{4}-\d{2}$/', $month_key)) {
        continue;
    }
    if (!isset($tracking_month_map[$month_key])) {
        $tracking_month_map[$month_key] = [
            'month_key' => $month_key,
            'total_cases' => 0,
            'sent_cases' => 0,
            'received_cases' => 0,
            'problem_cases' => 0,
        ];
    }
    $tracking_month_map[$month_key]['total_cases']++;
    $month_status = (string)($pt['status'] ?? '');
    if (str_contains($month_status, 'ได้รับ')) {
        $tracking_month_map[$month_key]['received_cases']++;
    } elseif (str_contains($month_status, 'ติดต่อ') || str_contains($month_status, 'ไม่ได้')) {
        $tracking_month_map[$month_key]['problem_cases']++;
    } elseif (str_contains($month_status, 'ส่ง')) {
        $tracking_month_map[$month_key]['sent_cases']++;
    }
}
krsort($tracking_month_map);
$tracking_month_cards = array_values($tracking_month_map);
$table_per_page = 100;
$table_total_cases = count($patients);
$table_total_pages = max(1, (int)ceil($table_total_cases / $table_per_page));
$table_page = max(1, (int)($_GET['page'] ?? 1));
$table_page = min($table_page, $table_total_pages);
$table_offset = ($table_page - 1) * $table_per_page;
$table_patients = array_slice($patients, $table_offset, $table_per_page);
$table_query_base = array_filter([
    'from_date' => $from_date,
    'to_date' => $to_date,
    'month' => $selected_month,
], static fn($value) => $value !== '');
$delivery_completion_rate = $tracking_summary['total_cases'] > 0
    ? round(($tracking_summary['received'] / $tracking_summary['total_cases']) * 100)
    : 0;

// ==========================================
// 3. ฟังก์ชันกำหนด Badge สถานะ
// ==========================================
function getStatusBadge($status) {
    switch ($status) {
        case 'มารับยาเอง':
            return '<span class="px-3 py-1 bg-amber-100 text-amber-800 rounded-full text-xs font-bold border border-amber-200 shadow-sm">มารับยาเอง</span>';
        case 'ส่งแล้ว':
            return '<span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-xs font-bold border border-blue-200 shadow-sm">ส่งแล้ว (ระหว่างทาง)</span>';
        case 'ได้รับแล้ว':
            return '<span class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-xs font-bold border border-green-200 shadow-sm">ได้รับยาแล้ว</span>';
        case 'ติดต่อไม่ได้':
            return '<span class="px-3 py-1 bg-red-100 text-red-800 rounded-full text-xs font-bold border border-red-200 shadow-sm">ติดต่อไม่ได้/ตีกลับ</span>';
        default:
            return '<span class="px-3 py-1 bg-yellow-100 text-yellow-800 rounded-full text-xs font-bold border border-yellow-200 shadow-sm">รอดำเนินการ/รอจัดส่ง</span>';
    }
}?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="img/pdh.ico">
    <title>Tracking - ติดตามการส่งยา Telemed</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Sarabun', sans-serif; } 
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body class="bg-gray-100 flex h-screen overflow-hidden relative">

    <?php include 'sidebar.php'; ?>

    <div class="flex-1 flex flex-col h-screen overflow-hidden w-full">
        <header class="h-16 bg-white shadow-sm border-b border-gray-200 flex items-center px-4 lg:px-6 z-10 justify-between">
            <div class="flex items-center">
                <button onclick="toggleSidebar()" class="lg:hidden text-gray-600 hover:text-blue-600 focus:outline-none mr-4 bg-gray-100 p-2 rounded-lg">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                </button>
                <h1 class="font-bold text-gray-800 text-lg flex items-center">
                    <svg class="w-6 h-6 mr-2 text-blue-600 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                    ระบบติดตามการจัดส่งยา Telemed
                </h1>
                <span class="ml-3 hidden rounded-full bg-blue-100 px-3 py-1 text-xs font-bold text-blue-700 md:inline-flex">
                    <?php echo htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
            
            <div class="flex items-center">
                <div class="bg-blue-100 h-8 w-8 rounded-full flex items-center justify-center font-bold text-blue-800 mr-2 shadow-inner uppercase text-xs">
                    <?php echo substr($_SESSION['username'] ?? 'U', 0, 1); ?>
                </div>
                <div class="hidden sm:block">
                    <p class="text-xs font-bold text-gray-800 truncate"><?php echo htmlspecialchars($_SESSION['fullname'] ?? 'Unknown'); ?></p>
                </div>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto bg-gray-50 p-4 lg:p-6 custom-scrollbar">
            <section class="mb-6">
                <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-3 mb-4">
                    <div>
                        <p class="text-xs font-bold text-blue-700 uppercase tracking-wider">Delivery Dashboard</p>
                        <h2 class="text-2xl font-black text-gray-900 mt-1">สรุปรายงานการส่งยาไปรษณีย์</h2>
                        <p class="text-sm text-gray-500 mt-1">แสดงผู้รับบริการที่มีเลข Tracking และผู้รับยาที่บ้านที่พร้อมจัดส่ง อัปเดตล่าสุด <?php echo date('d/m/Y H:i'); ?> น.</p>
                    </div>
                    <a href="daily_summary.php" class="inline-flex items-center justify-center px-4 py-2 bg-gray-900 hover:bg-gray-800 text-white rounded-lg text-sm font-bold shadow-sm transition">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-6h6v6m2 4H7a2 2 0 01-2-2V5a2 2 0 012-2h7l5 5v11a2 2 0 01-2 2z"></path></svg>
                        รายงานรายวัน
                    </a>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-4">
                    <div class="bg-white border border-gray-200 rounded-lg p-4 shadow-sm">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-bold text-gray-500 uppercase">ทั้งหมด</p>
                            <span class="w-9 h-9 rounded-lg bg-slate-100 text-slate-700 flex items-center justify-center">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5h6m-7 4h8m-9 4h10M5 3h14a1 1 0 011 1v16l-3-2-3 2-3-2-3 2-3-2V4a1 1 0 011-1z"></path></svg>
                            </span>
                        </div>
                        <div class="mt-3 text-3xl font-black text-gray-900"><?php echo number_format($tracking_summary['total_cases']); ?></div>
                        <p class="text-xs text-gray-500 mt-1">เคสส่งยาไปรษณีย์</p>
                    </div>

                    <div class="bg-white border border-gray-200 rounded-lg p-4 shadow-sm">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-bold text-gray-500 uppercase">รายการยา</p>
                            <span class="w-9 h-9 rounded-lg bg-cyan-100 text-cyan-700 flex items-center justify-center">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a4 4 0 01-5.656 0L8.572 10.23a4 4 0 015.657-5.657l5.199 5.2a4 4 0 010 5.656zM14.5 6.5l-8 8"></path></svg>
                            </span>
                        </div>
                        <div class="mt-3 text-3xl font-black text-gray-900"><?php echo number_format($tracking_summary['total_drugs']); ?></div>
                        <p class="text-xs text-gray-500 mt-1">รวมทุกรายการในหน้าปัจจุบัน</p>
                    </div>

                    <div class="bg-white border border-gray-200 rounded-lg p-4 shadow-sm">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-bold text-gray-500 uppercase">มีเลขพัสดุ</p>
                            <span class="w-9 h-9 rounded-lg bg-indigo-100 text-indigo-700 flex items-center justify-center">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7h18M7 7V5a2 2 0 012-2h6a2 2 0 012 2v2m-9 4h8m-9 4h6"></path></svg>
                            </span>
                        </div>
                        <div class="mt-3 text-3xl font-black text-gray-900"><?php echo number_format($tracking_summary['with_tracking']); ?></div>
                        <p class="text-xs text-gray-500 mt-1">ยังไม่มีเลข <?php echo number_format($tracking_summary['without_tracking']); ?> เคส</p>
                    </div>

                    <div class="bg-white border border-gray-200 rounded-lg p-4 shadow-sm">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-bold text-gray-500 uppercase">ส่งสำเร็จ</p>
                            <span class="w-9 h-9 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            </span>
                        </div>
                        <div class="mt-3 text-3xl font-black text-gray-900"><?php echo $delivery_completion_rate; ?>%</div>
                        <div class="mt-3 h-2 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full bg-emerald-500 rounded-full" style="width: <?php echo min(100, max(0, $delivery_completion_rate)); ?>%"></div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                    <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3">
                        <p class="text-xs font-bold text-amber-700">รอดำเนินการ</p>
                        <p class="text-2xl font-black text-amber-900 mt-1"><?php echo number_format($tracking_summary['pending']); ?></p>
                    </div>
                    <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3">
                        <p class="text-xs font-bold text-blue-700">ส่งแล้ว</p>
                        <p class="text-2xl font-black text-blue-900 mt-1"><?php echo number_format($tracking_summary['sent']); ?></p>
                    </div>
                    <div class="bg-emerald-50 border border-emerald-200 rounded-lg px-4 py-3">
                        <p class="text-xs font-bold text-emerald-700">ได้รับแล้ว</p>
                        <p class="text-2xl font-black text-emerald-900 mt-1"><?php echo number_format($tracking_summary['received']); ?></p>
                    </div>
                    <div class="bg-rose-50 border border-rose-200 rounded-lg px-4 py-3">
                        <p class="text-xs font-bold text-rose-700">ติดตามไม่ได้</p>
                        <p class="text-2xl font-black text-rose-900 mt-1"><?php echo number_format($tracking_summary['problem']); ?></p>
                    </div>
                    <div class="bg-violet-50 border border-violet-200 rounded-lg px-4 py-3">
                        <p class="text-xs font-bold text-violet-700">ซิงก์ Thailand Post</p>
                        <p class="text-2xl font-black text-violet-900 mt-1"><?php echo number_format($tracking_summary['synced']); ?></p>
                    </div>
                </div>
            </section>

            <section class="mb-6">
                <div class="mb-4 rounded-2xl border border-blue-100 bg-blue-50/70 p-4">
                    <form method="GET" class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
                        <div class="grid flex-1 gap-3 md:grid-cols-3">
                            <div>
                                <label for="from_date" class="mb-1 block text-xs font-bold uppercase tracking-wider text-blue-800">จากวันที่</label>
                                <input type="date" id="from_date" name="from_date" value="<?php echo htmlspecialchars($from_date, ENT_QUOTES, 'UTF-8'); ?>" class="w-full rounded-xl border border-blue-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-200">
                            </div>
                            <div>
                                <label for="to_date" class="mb-1 block text-xs font-bold uppercase tracking-wider text-blue-800">ถึงวันที่</label>
                                <input type="date" id="to_date" name="to_date" value="<?php echo htmlspecialchars($to_date, ENT_QUOTES, 'UTF-8'); ?>" class="w-full rounded-xl border border-blue-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-200">
                            </div>
                            <div>
                                <label for="month" class="mb-1 block text-xs font-bold uppercase tracking-wider text-blue-800">หรือเลือกเดือน</label>
                                <input type="month" id="month" name="month" value="<?php echo htmlspecialchars($selected_month, ENT_QUOTES, 'UTF-8'); ?>" class="w-full rounded-xl border border-blue-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-200">
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="inline-flex items-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700">
                                กรองข้อมูล
                            </button>
                            <?php if ($is_date_filtered || $selected_month !== ''): ?>
                                <a href="tracking.php" class="inline-flex items-center rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm font-bold text-gray-700 shadow-sm transition hover:bg-gray-50">
                                    ล้างตัวกรองทั้งหมด
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                    <?php if ($is_date_filtered): ?>
                        <p class="mt-3 text-xs font-semibold text-blue-800">
                            ช่วงวันที่ที่เลือก:
                            <?php echo htmlspecialchars($from_date !== '' ? $from_date : '-', ENT_QUOTES, 'UTF-8'); ?>
                            ถึง
                            <?php echo htmlspecialchars($to_date !== '' ? $to_date : '-', ENT_QUOTES, 'UTF-8'); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="flex items-center justify-between gap-3 mb-3">
                    <div>
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Monthly Delivery Summary</p>
                        <h3 class="text-lg font-black text-gray-900">สรุปการส่งยารายเดือน</h3>
                    </div>
                    <?php if ($selected_month !== '' && !$is_date_filtered): ?>
                        <a href="tracking.php" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-xs font-bold text-gray-700 shadow-sm hover:bg-gray-50">
                            ล้างตัวกรองเดือน
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!empty($tracking_month_cards)): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                        <?php foreach ($tracking_month_cards as $month_card): ?>
                            <?php
                                $month_key = (string)($month_card['month_key'] ?? '');
                                $is_active_month = $selected_month !== '' && $selected_month === $month_key;
                                $month_card_label = $month_key !== '' ? date('m/Y', strtotime($month_key . '-01')) : '-';
                            ?>
                            <a href="tracking.php?month=<?php echo urlencode($month_key); ?>" class="group rounded-2xl border <?php echo $is_active_month ? 'border-blue-500 bg-blue-600 text-white shadow-lg shadow-blue-200' : 'border-gray-200 bg-white text-gray-900 shadow-sm hover:border-blue-300 hover:shadow-md'; ?> p-5 transition">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wider <?php echo $is_active_month ? 'text-blue-100' : 'text-gray-500'; ?>">เดือนบริการ</p>
                                        <h4 class="mt-1 text-2xl font-black"><?php echo htmlspecialchars($month_card_label, ENT_QUOTES, 'UTF-8'); ?></h4>
                                    </div>
                                    <span class="rounded-full px-3 py-1 text-xs font-bold <?php echo $is_active_month ? 'bg-white/15 text-white' : 'bg-blue-50 text-blue-700'; ?>">ดูรายงาน</span>
                                </div>
                                <div class="mt-4 grid grid-cols-3 gap-3 text-sm">
                                    <div>
                                        <p class="text-[11px] <?php echo $is_active_month ? 'text-blue-100' : 'text-gray-500'; ?>">ทั้งหมด</p>
                                        <p class="text-xl font-black"><?php echo number_format((int)($month_card['total_cases'] ?? 0)); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-[11px] <?php echo $is_active_month ? 'text-blue-100' : 'text-gray-500'; ?>">ส่งแล้ว</p>
                                        <p class="text-xl font-black"><?php echo number_format((int)($month_card['sent_cases'] ?? 0)); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-[11px] <?php echo $is_active_month ? 'text-blue-100' : 'text-gray-500'; ?>">ได้รับแล้ว</p>
                                        <p class="text-xl font-black"><?php echo number_format((int)($month_card['received_cases'] ?? 0)); ?></p>
                                    </div>
                                </div>
                                <p class="mt-3 text-xs <?php echo $is_active_month ? 'text-blue-100' : 'text-rose-600'; ?>">ติดตามไม่ได้ <?php echo number_format((int)($month_card['problem_cases'] ?? 0)); ?> รายการ</p>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-8 text-center text-sm font-semibold text-gray-400">
                        ยังไม่มีข้อมูลสรุปรายเดือนสำหรับการติดตามส่งยา
                    </div>
                <?php endif; ?>
            </section>

            <div class="bg-white shadow-sm rounded-2xl overflow-hidden border border-gray-200">
                <div class="px-6 py-4 bg-gray-800 text-white font-bold flex flex-wrap gap-2 justify-between items-center text-sm lg:text-base">
                    <span>
                        รายการติดตามการส่งยา Telemed
                        <?php if ($is_date_filtered): ?>
                            (ช่วงวันที่ <?php echo htmlspecialchars($from_date !== '' ? $from_date : '-', ENT_QUOTES, 'UTF-8'); ?> ถึง <?php echo htmlspecialchars($to_date !== '' ? $to_date : '-', ENT_QUOTES, 'UTF-8'); ?>)
                        <?php else: ?>
                            (แสดงข้อมูลทั้งหมด)
                        <?php endif; ?>
                    </span>
                    <a href="daily_summary.php" class="px-4 py-2 bg-yellow-500 hover:bg-yellow-400 text-gray-900 rounded-lg text-xs font-bold transition flex items-center shadow-md">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                        พิมพ์ใบสรุปส่งธุรการ (รายวัน)
                    </a>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">วันที่รับบริการ</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">HN / ชื่อ-สกุล</th>
                                <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">รายการยา/การรับยา</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">ที่อยู่รับยา</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">เลขพัสดุ</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">สถานะปัจจุบัน</th>
                                <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">หมายเหตุ</th>
                                <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">การจัดการ</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            <?php if (count($patients) > 0): ?>
                                <?php foreach($table_patients as $pt): ?>
                                <tr class="hover:bg-blue-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap text-xs lg:text-sm text-gray-600 font-medium">
                                        <?php echo htmlspecialchars($pt['raw_regdate']); ?>
                                        <br><span class="text-[10px] text-gray-400"><?php echo htmlspecialchars($pt['timereg']); ?> น.</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-xs lg:text-sm">
                                        <div class="font-bold text-blue-700"><?php echo htmlspecialchars($pt['hn']); ?></div>
                                        <div class="text-gray-800 font-semibold"><?php echo decodeThai($pt['fullname']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-xs font-medium">
                                        <div class="flex flex-col items-center gap-1.5">
                                            <?php if ((int)$pt['drug_count'] > 0): ?>
                                                <span class="px-2 py-1 bg-blue-100 text-blue-700 rounded-lg font-bold">มียาที่ต้องจัดส่ง <?php echo number_format($pt['drug_count']); ?> รายการ</span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 bg-gray-100 text-gray-500 rounded-lg font-bold">ไม่พบรายการยา</span>
                                            <?php endif; ?>
                                            <?php if ((int)$pt['pickup_self'] === 1): ?>
                                                <span class="px-2 py-1 bg-amber-100 text-amber-800 rounded-lg font-bold">มารับยาเอง</span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 bg-green-100 text-green-800 rounded-lg font-bold">จัดส่งยา</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-xs lg:text-sm text-gray-700 min-w-[240px]">
                                        <?php if ((int)$pt['pickup_self'] === 1): ?>
                                            <span class="font-bold text-amber-700">ผู้ป่วยประสงค์มารับยาเอง</span>
                                        <?php elseif (!empty(trim((string)$pt['delivery_address']))): ?>
                                            <div class="font-semibold leading-relaxed"><?php echo htmlspecialchars($pt['delivery_address']); ?></div>
                                            <div class="text-gray-500 mt-1">โทร: <?php echo htmlspecialchars($pt['delivery_phone'] ?: '-'); ?></div>
                                        <?php else: ?>
                                            <span class="font-bold text-red-600">ยังไม่มีที่อยู่จัดส่ง</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-mono font-bold text-gray-500">
                                        <?php echo $pt['track_no'] ? htmlspecialchars($pt['track_no']) : '-'; ?>
                                        <?php if (!empty($pt['last_sync'])): ?>
                                            <div class="mt-1 text-[10px] font-sans font-semibold text-red-700">TP sync: <?php echo htmlspecialchars($pt['last_sync']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php echo getStatusBadge($pt['status']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                        <button onclick="openPatientNoteModal('<?php echo htmlspecialchars($pt['hn']); ?>', '<?php echo decodeThai($pt['fullname']); ?>', '<?php echo htmlspecialchars($pt['clean_regdate']); ?>')"
                                                class="px-3 py-2 <?php echo ((int)($pt['note_count'] ?? 0) > 0) ? 'bg-amber-500 hover:bg-amber-600' : 'bg-slate-700 hover:bg-slate-800'; ?> shadow-md text-white rounded-lg text-xs font-bold transition transform hover:-translate-y-0.5">
                                            หมายเหตุ<?php echo ((int)($pt['note_count'] ?? 0) > 0) ? ' (' . number_format((int)$pt['note_count']) . ')' : ''; ?>
                                        </button>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                        <div class="flex justify-center items-center gap-2">
                                            <button onclick="openTrackModal('<?php echo htmlspecialchars($pt['hn']); ?>', '<?php echo decodeThai($pt['fullname']); ?>', '<?php echo htmlspecialchars($pt['clean_regdate']); ?>')" 
                                                    class="px-3 py-2 bg-indigo-600 hover:bg-indigo-700 shadow-md text-white rounded-lg text-xs font-bold transition transform hover:-translate-y-0.5"
                                                    title="กรอกรายละเอียดพัสดุและสถานะ">
                                                อัปเดตสถานะ
                                            </button>
                                            
                                            <button onclick='openThailandPostModal(<?php echo json_encode((string)$pt['hn'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo json_encode(decodeThai($pt['fullname']), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo json_encode((string)$pt['clean_regdate'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo json_encode((string)($pt['track_no'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                                    class="px-3 py-2 bg-red-600 hover:bg-red-700 shadow-md text-white rounded-lg text-xs font-bold transition transform hover:-translate-y-0.5"
                                                    title="ลงทะเบียนและซิงก์สถานะกับ Thailand Post">
                                                Thailand Post
                                            </button>

                                            <button onclick="quickMarkDelivered('<?php echo htmlspecialchars($pt['hn']); ?>', '<?php echo htmlspecialchars($pt['clean_regdate']); ?>')" 
                                                    class="px-3 py-2 bg-[#10b981] hover:bg-[#059669] shadow-md text-white rounded-lg text-xs font-bold transition transform hover:-translate-y-0.5"
                                                    title="กดเพื่อยืนยันว่าได้รับยาเรียบร้อยแล้ว">
                                                <svg class="w-3 h-3 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>ปิดจ๊อบ
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="px-6 py-10 text-center text-gray-400 font-bold">ไม่พบประวัติผู้ป่วย Telemed ในระบบ</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($table_total_cases > 0): ?>
                    <div class="flex flex-col gap-3 border-t border-gray-200 bg-gray-50 px-5 py-4 text-sm sm:flex-row sm:items-center sm:justify-between">
                        <p class="font-semibold text-gray-600">
                            แสดง <?php echo number_format($table_offset + 1); ?> - <?php echo number_format(min($table_offset + $table_per_page, $table_total_cases)); ?> จากทั้งหมด <?php echo number_format($table_total_cases); ?> รายการ
                        </p>
                        <?php if ($table_total_pages > 1): ?>
                            <div class="flex items-center gap-2">
                                <?php if ($table_page > 1): ?>
                                    <?php $previous_query = http_build_query(array_merge($table_query_base, ['page' => $table_page - 1])); ?>
                                    <a href="tracking.php?<?php echo htmlspecialchars($previous_query, ENT_QUOTES, 'UTF-8'); ?>" class="rounded-lg border border-gray-300 bg-white px-3 py-2 font-bold text-gray-700 hover:bg-gray-100">ก่อนหน้า</a>
                                <?php endif; ?>
                                <span class="rounded-lg bg-blue-100 px-3 py-2 font-bold text-blue-800">หน้า <?php echo number_format($table_page); ?> / <?php echo number_format($table_total_pages); ?></span>
                                <?php if ($table_page < $table_total_pages): ?>
                                    <?php $next_query = http_build_query(array_merge($table_query_base, ['page' => $table_page + 1])); ?>
                                    <a href="tracking.php?<?php echo htmlspecialchars($next_query, ENT_QUOTES, 'UTF-8'); ?>" class="rounded-lg border border-gray-300 bg-white px-3 py-2 font-bold text-gray-700 hover:bg-gray-100">ถัดไป</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <div id="trackModal" class="fixed inset-0 z-50 hidden overflow-y-auto bg-black bg-opacity-60 backdrop-blur-sm flex justify-center items-center p-4">
        <div class="bg-white rounded-2xl overflow-hidden shadow-2xl w-full max-w-2xl border border-gray-200 mx-auto">
            
            <div class="bg-gradient-to-r from-indigo-700 to-purple-800 px-6 py-4 flex justify-between items-center text-white">
                <h2 class="text-lg lg:text-xl font-bold flex items-center">Checklist ติดตามสถานะจัดส่งยา</h2>
                <button onclick="closeTrackModal()" class="text-white hover:text-red-300 text-3xl font-bold transition">&times;</button>
            </div>
            
            <form id="trackForm" class="p-4 lg:p-6 bg-gray-50 max-h-[85vh] overflow-y-auto custom-scrollbar">
                <input type="hidden" id="trk_hn" name="hn">
                <input type="hidden" id="trk_regdate" name="regdate">

                <div class="mb-6 pb-4 border-b border-gray-200">
                    <p class="text-xs text-gray-500 font-bold uppercase tracking-wider">ข้อมูลผู้ป่วย</p>
                    <p id="trk_pt_name" class="text-lg lg:text-xl font-black text-indigo-900 mt-1">HN: XXXXX | ชื่อ-สกุล</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm space-y-4">
                        <h3 class="font-bold text-gray-800 flex items-center border-b pb-2 text-sm lg:text-base"><span class="mr-2 text-xl">📦</span> ข้อมูลไปรษณีย์</h3>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">เลขพัสดุ (Tracking No):</label>
                            <input type="text" id="trk_no" name="tracking_no" maxlength="13" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 font-mono font-bold text-sm lg:text-base uppercase outline-none" placeholder="EX123456789TH">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">วันที่ส่งไปรษณีย์:</label>
                            <input type="date" id="trk_send" name="send_date" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm">
                        </div>
                    </div>

                    <div class="bg-white p-4 rounded-xl border border-blue-200 shadow-sm space-y-4">
                        <h3 class="font-bold text-blue-800 flex items-center border-b pb-2 border-blue-100 text-sm lg:text-base"><span class="mr-2 text-xl">☎</span> การโทรติดตามคนไข้</h3>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">สถานะปัจจุบัน <span class="text-red-500">*</span></label>
                            <select id="trk_status" name="followup_status" class="w-full p-2 border border-blue-300 rounded-lg focus:ring-2 focus:ring-indigo-500 font-bold text-blue-900 bg-blue-50 outline-none text-sm">
                                <option value="รอจัดส่ง">รอจัดส่ง (รอดำเนินการ)</option>
                                <option value="ส่งแล้ว">ส่งพัสดุแล้ว (อยู่ระหว่างจัดส่ง)</option>
                                <option value="ได้รับแล้ว">คนไข้ได้รับยาเรียบร้อยแล้ว</option>
                                <option value="ติดต่อไม่ได้">ติดต่อไม่ได้ / พัสดุตีกลับ</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">วันที่คนไข้ได้รับยา:</label>
                            <input type="date" id="trk_receive" name="receive_date" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">ผู้โทรติดตาม:</label>
                            <input type="text" id="trk_follower" name="follower_name" value="<?php echo htmlspecialchars($_SESSION['fullname'] ?? ''); ?>" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 bg-gray-100 outline-none text-sm">
                        </div>
                    </div>
                </div>

                <div class="mb-6">
                    <label class="block text-xs font-bold text-gray-600 mb-1">บันทึกข้อความ / หมายเหตุ:</label>
                    <textarea id="trk_note" name="note" rows="3" class="w-full p-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none text-sm" placeholder="เช่น โทรไปไม่มีคนรับสาย จะโทรใหม่พรุ่งนี้..."></textarea>
                </div>

                <div class="flex gap-4">
                    <button type="button" onclick="closeTrackModal()" class="w-1/3 bg-gray-300 text-gray-800 py-3 rounded-xl font-bold hover:bg-gray-400 transition">ยกเลิก</button>
                    <button type="submit" class="w-2/3 bg-indigo-600 text-white py-3 rounded-xl font-bold shadow-lg hover:bg-indigo-700 transition text-base lg:text-lg flex justify-center items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        บันทึกสถานะ
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php include 'patient_notes_modal.php'; ?>
    <?php include 'thailand_post_modal.php'; ?>

    <script>
    async function openTrackModal(hn, fullname, regdate) {
        document.getElementById('trk_hn').value = hn;
        document.getElementById('trk_regdate').value = regdate;
        document.getElementById('trk_pt_name').innerText = `HN: ${hn} | ${fullname} (รับบริการ: ${regdate})`;
        
        document.getElementById('trk_no').value = '';
        document.getElementById('trk_send').value = '';
        document.getElementById('trk_receive').value = '';
        document.getElementById('trk_status').value = 'รอจัดส่ง';
        document.getElementById('trk_note').value = '';

        document.getElementById('trackModal').classList.remove('hidden');

        try {
            const fd = new FormData(); 
            fd.append('hn', hn); 
            fd.append('regdate', regdate);
            
            const res = await fetch('api_tracking.php?action=get_tracking', { method: 'POST', body: fd });
            const resData = await res.json();
            
            if (resData.status === 'success' && resData.data) {
                const d = resData.data;
                if(d.tracking_no) document.getElementById('trk_no').value = d.tracking_no;
                if(d.send_date) document.getElementById('trk_send').value = d.send_date;
                if(d.receive_date) document.getElementById('trk_receive').value = d.receive_date;
                if(d.followup_status) document.getElementById('trk_status').value = d.followup_status;
                if(d.follower_name) document.getElementById('trk_follower').value = d.follower_name;
                if(d.note) document.getElementById('trk_note').value = d.note;
            }
        } catch (e) { console.error("Error fetching data:", e); }
    }

    function closeTrackModal() {
        document.getElementById('trackModal').classList.add('hidden');
    }

    function normalizeTrackingNo(value) {
        return (value || '').toUpperCase().replace(/\s+/g, '');
    }

    function isValidTrackingNo(value) {
        return /^[A-Z]{2}\d{9}[A-Z]{2}$/.test(value);
    }

    const trackingNoInput = document.getElementById('trk_no');
    if (trackingNoInput) {
    document.getElementById('trk_no').addEventListener('input', (e) => {
        e.target.value = normalizeTrackingNo(e.target.value);
    });
    }

    const trackingForm = document.getElementById('trackForm');
    if (trackingForm) {
    document.getElementById('trackForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const trackingInput = document.getElementById('trk_no');
        const normalizedTrackingNo = normalizeTrackingNo(trackingInput.value);
        trackingInput.value = normalizedTrackingNo;

        if (normalizedTrackingNo !== '' && !isValidTrackingNo(normalizedTrackingNo)) {
            Swal.fire(
                'ข้อมูลไม่ครบ',
                'เลขพัสดุไม่ครบหรือรูปแบบไม่ถูกต้อง กรุณากรอกให้ครบ เช่น EX123456789TH',
                'warning'
            );
            trackingInput.focus();
            return;
        }

        const fd = new FormData(e.target);
        
        try {
            const res = await fetch('api_tracking.php?action=save_tracking', { method: 'POST', body: fd });
            const data = await res.json();
            
            if(data.status === 'success') {
                Swal.fire({ icon: 'success', title: 'อัปเดตสถานะสำเร็จ', timer: 1500, showConfirmButton: false }).then(() => {
                    window.location.reload(); 
                });
            } else { Swal.fire('ผิดพลาด', data.message, 'error'); }
        } catch (e) { Swal.fire('ผิดพลาด', 'ไม่สามารถเชื่อมต่อระบบได้', 'error'); }
    });
    }

    function quickMarkDelivered(hn, regdate) {
        Swal.fire({
            title: 'ปิดจ๊อบส่งยาด่วน?',
            html: `ต้องการยืนยันว่า HN: <b>${hn}</b><br>ได้รับยาเรียบร้อยแล้ว ใช่หรือไม่?<br><span class="text-sm text-gray-500">(เหมาะสำหรับเคลียร์ข้อมูลในอดีต)</span>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#10b981',
            cancelButtonColor: '#9ca3af',
            confirmButtonText: 'ใช่, ได้รับยาแล้ว',
            cancelButtonText: 'ยกเลิก'
        }).then(async (result) => {
            if (result.isConfirmed) {
                try {
                    const fd = new FormData(); 
                    fd.append('hn', hn); 
                    fd.append('regdate', regdate);
                    fd.append('followup_status', 'ได้รับแล้ว');
                    fd.append('note', 'ปิดจ๊อบด่วน (เคลียร์ข้อมูลในอดีต)');
                    
                    const res = await fetch('api_tracking.php?action=save_tracking', { method: 'POST', body: fd });
                    const data = await res.json();
                    
                    if(data.status === 'success') {
                        Swal.fire({ icon: 'success', title: 'ปิดจ๊อบสำเร็จ', text: 'ระบบอัปเดตสถานะเป็น "ได้รับยาแล้ว"', timer: 1500, showConfirmButton: false }).then(() => {
                            window.location.reload(); 
                        });
                    } else { Swal.fire('ผิดพลาด', data.message, 'error'); }
                } catch (e) { Swal.fire('ผิดพลาด', 'ไม่สามารถเชื่อมต่อระบบได้', 'error'); }
            }
        });
    }
    
    // Refresh delivery candidates created from other Telemed screens without interrupting data entry.
    const trackingRefreshIntervalMs = 60000;
    let lastTrackingRefreshAt = Date.now();

    function canRefreshTrackingPage() {
        const trackingModal = document.getElementById('trackModal');
        const activeElement = document.activeElement;
        const isEditingTracking = trackingModal && !trackingModal.classList.contains('hidden');
        const isTyping = activeElement && ['INPUT', 'TEXTAREA', 'SELECT'].includes(activeElement.tagName);
        return !document.hidden && !isEditingTracking && !isTyping;
    }

    function refreshTrackingPageIfSafe() {
        if (!canRefreshTrackingPage() || Date.now() - lastTrackingRefreshAt < trackingRefreshIntervalMs) {
            return;
        }
        window.location.reload();
    }

    window.setInterval(refreshTrackingPageIfSafe, trackingRefreshIntervalMs);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && Date.now() - lastTrackingRefreshAt >= trackingRefreshIntervalMs) {
            refreshTrackingPageIfSafe();
        }
    });</script>
</body>
</html>
