<?php
include 'config.php';
header('Content-Type: text/html; charset=UTF-8');

// ==========================================
// 1. ตรวจสอบสิทธิ์การเข้าใช้งาน
// ==========================================
$current_page = basename($_SERVER['PHP_SELF']); 
check_permission($current_page); 

$selected_fy = isset($_GET['fy']) ? (int)$_GET['fy'] : (int)date('Y'); 
$start_date = ($selected_fy - 1) . "-10-01";
$end_date   = $selected_fy . "-09-30";
$today      = date('Y-m-d'); // ใช้วันที่ปัจจุบันของระบบเพื่อให้นับยอดวันนี้ได้ถูกต้องเสมอ

$db_error = null;
$monthly_data = array_fill(1, 12, 0); 
$quarterly_data = array_fill(1, 4, 0); 
$total_fy_patients = 0;      // ตัวแปรเก็บจำนวนผู้ป่วย (ราย) ประจำปีงบ
$total_fy_visits = 0;        // ตัวแปรเก็บจำนวนครั้ง (ครั้ง) ประจำปีงบที่เลือก
$total_all_time_telemed = 0; // ตัวแปรเก็บยอดรวมสะสมทั้งหมดตั้งแต่เปิดระบบ
$today_patients = 0;         // [เพิ่มใหม่] ตัวแปรเก็บยอดผู้มารับบริการวันนี้

$month_names_th = ['ต.ค.', 'พ.ย.', 'ธ.ค.', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.'];

try {
    // ---------------------------------------------------
    // A. ดึงข้อมูลสถิติกราฟแยกรายเดือนตามปีงบประมาณที่เลือก
    // ---------------------------------------------------
    $sql_stats = "SELECT MONTH(o.regdate) as m_num, COUNT(o.hn) as total 
                  FROM opd.opd o 
                  WHERE o.comein IN ('10', 'IN10') AND o.regdate BETWEEN :start AND :end 
                  GROUP BY MONTH(o.regdate)";
                  
    $stmt_stats = $his_pdo->prepare($sql_stats);
    $stmt_stats->execute(['start' => $start_date, 'end' => $end_date]);
    $stats = $stmt_stats->fetchAll();

    foreach ($stats as $row) {
        $m = (int)$row['m_num'];
        $total = (int)$row['total'];
        $total_fy_visits += $total; // รวมจำนวนครั้งสะสมของปีงบประมาณนี้

        if ($m == 10) { $monthly_data[1] = $total; $quarterly_data[1] += $total; }
        if ($m == 11) { $monthly_data[2] = $total; $quarterly_data[1] += $total; }
        if ($m == 12) { $monthly_data[3] = $total; $quarterly_data[1] += $total; }
        if ($m == 1) { $monthly_data[4] = $total; $quarterly_data[2] += $total; }
        if ($m == 2) { $monthly_data[5] = $total; $quarterly_data[2] += $total; }
        if ($m == 3) { $monthly_data[6] = $total; $quarterly_data[2] += $total; }
        if ($m == 4) { $monthly_data[7] = $total; $quarterly_data[3] += $total; }
        if ($m == 5) { $monthly_data[8] = $total; $quarterly_data[3] += $total; }
        if ($m == 6) { $monthly_data[9] = $total; $quarterly_data[3] += $total; }
        if ($m == 7) { $monthly_data[10] = $total; $quarterly_data[4] += $total; }
        if ($m == 8) { $monthly_data[11] = $total; $quarterly_data[4] += $total; }
        if ($m == 9) { $monthly_data[12] = $total; $quarterly_data[4] += $total; }
    }

    // ---------------------------------------------------
    // A.2 ดึงจำนวนผู้ป่วยแบบนับแยก "รายคน" (Unique HN) ในปีงบประมาณนี้
    // ---------------------------------------------------
    $sql_unique_pt = "SELECT COUNT(DISTINCT o.hn) 
                      FROM opd.opd o 
                      WHERE o.comein IN ('10', 'IN10') AND o.regdate BETWEEN :start AND :end";
    $stmt_unique = $his_pdo->prepare($sql_unique_pt);
    $stmt_unique->execute(['start' => $start_date, 'end' => $end_date]);
    $total_fy_patients = (int)$stmt_unique->fetchColumn();

    // ---------------------------------------------------
    // A.3 ดึงจำนวนการทำ Telemed สะสมรวมทั้งหมดตั้งแต่เปิดระบบ (All-Time)
    // ---------------------------------------------------
    $sql_all_time = "SELECT COUNT(o.hn) as total_all 
                     FROM opd.opd o 
                     WHERE o.comein IN ('10', 'IN10')";
    $stmt_all_time = $his_pdo->query($sql_all_time);
    $total_all_time_telemed = (int)$stmt_all_time->fetchColumn();

    // ---------------------------------------------------
    // A.4 [เพิ่มใหม่] ดึงจำนวนผู้มารับบริการวันนี้ (Today)
    // ---------------------------------------------------
    $sql_today = "SELECT COUNT(DISTINCT o.hn) as today_total 
                  FROM opd.opd o 
                  WHERE o.comein IN ('10', 'IN10') AND o.regdate = :today";
    $stmt_today = $his_pdo->prepare($sql_today);
    $stmt_today->execute(['today' => $today]);
    $today_patients = (int)$stmt_today->fetchColumn();

    // ---------------------------------------------------
    // B. ดึงรายชื่อ 20 คิวล่าสุด เพื่อแสดงในตารางตรวจสอบประวัติ
    // ---------------------------------------------------
    $sql_latest = "SELECT o.hn, o.fullname, o.regdate, o.timereg, o.ptclass,
                          o.clinic AS clinic_code, cl.NAME AS clinic_name
                   FROM opd.opd o 
                   LEFT JOIN hos.clinic cl ON o.clinic = cl.code
                   WHERE o.comein IN ('10', 'IN10') 
                   ORDER BY o.regdate DESC, o.timereg DESC
                   LIMIT 200";
                  
    $stmt_latest = $his_pdo->prepare($sql_latest);
    $stmt_latest->execute();
    $latest_patients = $stmt_latest->fetchAll();

    $delivery_map = [];
    $drug_delivery_map = [];
    $note_count_map = [];
    $patient_status_map = [];
    if (count($latest_patients) > 0) {
        $latest_hns = array_values(array_unique(array_column($latest_patients, 'hn')));
        $placeholders = implode(',', array_fill(0, count($latest_hns), '?'));
        $visit_dates = array_values(array_unique(array_column($latest_patients, 'regdate')));
        $min_visit_date = min($visit_dates);
        $max_visit_date = max($visit_dates);
        $stmt_status_table = $app_pdo->query("SHOW TABLES LIKE 'telemed_patient_status'");
        if ($stmt_status_table->fetchColumn()) {
            $stmt_status = $app_pdo->prepare("SELECT hn, regdate, status_type, status_detail
                                             FROM telemed_patient_status
                                             WHERE hn IN ($placeholders)
                                               AND regdate BETWEEN ? AND ?");
            $stmt_status->execute(array_merge($latest_hns, [$min_visit_date, $max_visit_date]));
            foreach ($stmt_status->fetchAll() as $status_row) {
                $patient_status_map[$status_row['hn'] . '|' . $status_row['regdate']] = $status_row;
            }
        }

        $stmt_column = $app_pdo->query("SHOW COLUMNS FROM telemed_delivery LIKE 'pickup_self'");
        $has_pickup_self = (bool)$stmt_column->fetch();
        $pickup_self_select = $has_pickup_self ? 'COALESCE(pickup_self, 0)' : '0';
        $stmt_delivery = $app_pdo->prepare("SELECT hn, address, phone, $pickup_self_select AS pickup_self FROM telemed_delivery WHERE hn IN ($placeholders)");
        $stmt_delivery->execute($latest_hns);
        foreach ($stmt_delivery->fetchAll() as $delivery_row) {
            $delivery_map[$delivery_row['hn']] = $delivery_row;
        }

        if (count($visit_dates) > 0) {
            $sql_drug_delivery = "SELECT hn, regdate, COUNT(*) AS total_drugs
                                  FROM opd.drug_order_opd
                                  WHERE hn IN ($placeholders)
                                    AND regdate BETWEEN ? AND ?
                                  GROUP BY hn, regdate";
            $stmt_drug_delivery = $his_pdo->prepare($sql_drug_delivery);
            $stmt_drug_delivery->execute(array_merge($latest_hns, [$min_visit_date, $max_visit_date]));
            foreach ($stmt_drug_delivery->fetchAll() as $drug_row) {
                $drug_delivery_map[$drug_row['hn'] . '|' . $drug_row['regdate']] = (int)$drug_row['total_drugs'];
            }
        }

        $stmt_notes_table = $app_pdo->query("SHOW TABLES LIKE 'telemed_patient_notes'");
        if ($stmt_notes_table->fetchColumn()) {
            $stmt_notes = $app_pdo->prepare("SELECT hn, COUNT(*) AS total_notes FROM telemed_patient_notes WHERE hn IN ($placeholders) GROUP BY hn");
            $stmt_notes->execute($latest_hns);
            foreach ($stmt_notes->fetchAll() as $note_row) {
                $note_count_map[$note_row['hn']] = (int)$note_row['total_notes'];
            }
        }
    }

} catch (PDOException $e) {
    $db_error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - PDH Telemed</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Sarabun', sans-serif; } 
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .dataTables_wrapper { padding: 1rem; }
        .dataTables_wrapper .dataTables_filter input,
        .dataTables_wrapper .dataTables_length select {
            border: 1px solid #cbd5e1;
            border-radius: 0.5rem;
            padding: 0.35rem 0.55rem;
            outline: none;
            margin-left: 0.5rem;
        }
        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.18);
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current,
        .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
            background: #2563eb !important;
            border-color: #2563eb !important;
            color: #fff !important;
            border-radius: 0.5rem;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: #eff6ff !important;
            border-color: #bfdbfe !important;
            color: #1d4ed8 !important;
            border-radius: 0.5rem;
        }
        #latestPatientsTable {
            table-layout: fixed;
            width: 100% !important;
            min-width: 1400px;
        }
        #latestPatientsTable th,
        #latestPatientsTable td {
            vertical-align: middle;
        }
        #latestPatientsTable .patient-status-cell {
            white-space: normal;
        }
        #latestPatientsTable .patient-status-button {
            display: inline-flex;
            max-width: 100%;
            min-height: 2rem;
            align-items: center;
            justify-content: center;
            overflow-wrap: anywhere;
            line-height: 1.25;
        }
    </style>
</head>
<body class="bg-gray-100 flex h-screen overflow-hidden relative">

    <?php include 'sidebar.php'; ?>

    <div class="flex-1 flex flex-col h-screen overflow-hidden w-full">
        <header class="h-16 bg-white shadow-sm border-b border-gray-200 flex items-center justify-between px-4 lg:px-6 z-10">
            <div class="flex items-center">
                <button onclick="toggleSidebar()" class="lg:hidden text-gray-600 hover:text-blue-600 focus:outline-none mr-4 bg-gray-100 p-2 rounded-lg">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                </button>
                <div class="font-bold text-gray-800 text-lg sm:block hidden">ระบบข้อมูลวิเคราะห์ Telemedicine</div>
                <div class="font-bold text-gray-800 text-md sm:hidden">Dashboard</div>
                <span class="ml-3 hidden rounded-full bg-blue-100 px-3 py-1 text-xs font-bold text-blue-700 md:inline-flex">
                    <?php echo htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
            <form method="GET">
                <select name="fy" onchange="this.form.submit()" class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm font-bold outline-none focus:ring-2 focus:ring-blue-500">
                    <?php for($y = 2024; $y <= 2027; $y++): ?>
                    <option value="<?php echo $y; ?>" <?php echo $selected_fy == $y ? 'selected' : ''; ?>>ปีงบ <?php echo $y + 543; ?></option>
                    <?php endfor; ?>
                </select>
            </form>
        </header>

        <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 p-4 lg:p-6 custom-scrollbar">
            
            <?php if ($db_error): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg shadow-sm">
                    <p class="font-bold">เกิดข้อผิดพลาด HIS:</p>
                    <p class="text-xs font-mono"><?php echo htmlspecialchars($db_error); ?></p>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6 mb-6">
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 flex items-center">
                    <div class="p-3 bg-blue-100 rounded-xl text-blue-600 mr-4 font-black text-xl">ฮฃ</div>
                    <div>
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">ยอดรวมปีงบ <?php echo $selected_fy + 543; ?></p>
                        <p class="text-2xl lg:text-3xl font-black text-gray-800"><?php echo number_format($total_fy_patients); ?> <span class="text-sm font-normal text-gray-400">ราย</span></p>
                    </div>
                </div>

                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 flex items-center">
                    <div class="p-3 bg-indigo-100 rounded-xl text-indigo-600 mr-4">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">สถิติปีงบนี้</p>
                        <p class="text-2xl lg:text-3xl font-black text-indigo-800"><?php echo number_format($total_fy_visits); ?> <span class="text-sm font-normal text-gray-400">ครั้ง</span></p>
                    </div>
                </div>

                <div class="bg-white rounded-2xl p-6 shadow-sm border border-orange-100 flex items-center">
                    <div class="p-3 bg-orange-100 rounded-xl text-orange-600 mr-4">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-orange-500 uppercase tracking-wide">ผู้มารับบริการวันนี้</p>
                        <p class="text-2xl lg:text-3xl font-black text-orange-600"><?php echo number_format($today_patients); ?> <span class="text-sm font-normal text-gray-400">ราย</span></p>
                    </div>
                </div>
                
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 flex items-center">
                    <div class="p-3 bg-green-100 rounded-xl text-green-600 mr-4">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">TELEMED ทั้งหมด</p>
                        <p class="text-2xl lg:text-3xl font-black text-green-700"><?php echo number_format($total_all_time_telemed); ?> <span class="text-sm font-normal text-gray-400">ครั้ง</span></p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <div class="lg:col-span-2 bg-white p-4 lg:p-6 rounded-2xl shadow-sm border border-gray-100">
                    <h3 class="font-bold text-gray-800 mb-4 border-b pb-2 flex justify-between items-center text-sm lg:text-base">
                        <span>สถิติรายเดือน (ต.ค. - ก.ย.)</span>
                        <span class="text-blue-600 font-black text-xs bg-blue-50 px-3 py-1 rounded-full">รวม <?php echo number_format($total_fy_patients); ?> ราย</span>
                    </h3>
                    <div class="relative h-64 w-full">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                    <div class="mt-6 grid grid-cols-4 md:grid-cols-6 lg:grid-cols-12 gap-2 text-center border-t pt-4">
                        <?php foreach($monthly_data as $index => $value): ?>
                            <div class="p-1 lg:p-2 rounded-lg bg-gray-50 border border-gray-100 hover:bg-blue-50 transition cursor-default">
                                <div class="text-[10px] lg:text-[11px] font-bold text-gray-500 uppercase"><?php echo $month_names_th[$index-1]; ?></div>
                                <div class="text-sm font-black text-blue-700"><?php echo number_format($value); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="bg-white p-4 lg:p-6 rounded-2xl shadow-sm border border-gray-100">
                    <h3 class="font-bold text-gray-800 mb-4 border-b pb-2 text-sm lg:text-base text-center">สัดส่วนแยกตามไตรมาส</h3>
                    <div class="relative h-64 w-full flex justify-center">
                        <canvas id="quarterChart"></canvas>
                    </div>
                    <div class="mt-4 space-y-2">
                        <?php 
                        $q_colors = ['text-blue-600', 'text-green-600', 'text-orange-500', 'text-red-600'];
                        foreach($quarterly_data as $q => $val): ?>
                            <div class="flex justify-between items-center text-sm font-bold border-b border-gray-50 pb-2">
                                <span class="<?php echo $q_colors[$q-1]; ?> flex items-center"><span class="w-2 h-2 rounded-full mr-2 bg-current"></span> ไตรมาส <?php echo $q; ?></span>
                                <span class="bg-gray-100 px-2 py-1 rounded text-gray-700"><?php echo number_format($val); ?> ราย</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-2xl overflow-hidden border border-gray-200 mb-8">
                <div class="px-4 lg:px-6 py-4 bg-gray-800 text-white font-bold flex flex-wrap gap-2 justify-between items-center text-sm lg:text-base">
                    <span>รายชื่อผู้ป่วย Telemed ล่าสุด 200 รายการ</span>
                    <span class="text-xs bg-white/10 px-3 py-1 rounded-full">กำลังแสดง <?php echo number_format(count($latest_patients)); ?> รายการ</span>
                </div>
                <div class="overflow-x-auto">
                    <table id="latestPatientsTable" class="min-w-full divide-y divide-gray-200">
                        <colgroup>
                            <col style="width: 9%;">
                            <col style="width: 6%;">
                            <col style="width: 14%;">
                            <col style="width: 12%;">
                            <col style="width: 12%;">
                            <col style="width: 15%;">
                            <col style="width: 11%;">
                            <col style="width: 9%;">
                            <col style="width: 12%;">
                        </colgroup>
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 lg:px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">วันที่-เวลา</th>
                                <th class="px-4 lg:px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">HN</th>
                                <th class="px-4 lg:px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase min-w-[160px]">ชื่อ-นามสกุล</th>
                                <th class="px-4 lg:px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">ชื่อคลินิก</th>
                                <th class="px-4 lg:px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">สถานะ</th>
                                <th class="px-4 lg:px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">ที่อยู่รับยา</th>
                                <th class="px-4 lg:px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">แฟ้มรักษา</th>
                                <th class="px-4 lg:px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">หมายเหตุ</th>
                                <th class="px-4 lg:px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">จัดส่งยา</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            <?php if (count($latest_patients) > 0): ?>
                                <?php foreach($latest_patients as $pt): ?>
                                <tr class="hover:bg-blue-50 transition-colors">
                                    <td class="px-4 lg:px-6 py-4 whitespace-nowrap text-xs text-gray-600 font-medium">
                                        <?php echo htmlspecialchars($pt['regdate']); ?> 
                                        <?php if($pt['regdate'] == $today): ?>
                                            <span class="ml-1 px-1.5 py-0.5 bg-red-100 text-red-600 text-[9px] font-bold rounded animate-pulse">วันนี้</span>
                                        <?php endif; ?>
                                        <br><span class="text-[10px] text-gray-400"><?php echo htmlspecialchars($pt['timereg']); ?> น.</span>
                                    </td>
                                    <td class="px-4 lg:px-6 py-4 whitespace-nowrap text-xs lg:text-sm font-bold text-blue-700"><?php echo htmlspecialchars($pt['hn']); ?></td>
                                    <td class="px-4 lg:px-6 py-4 whitespace-nowrap text-xs lg:text-sm font-semibold"><?php echo decodeThai($pt['fullname']); ?></td>
                                    <td class="px-4 lg:px-6 py-4 text-center text-xs font-medium text-gray-700">
                                        <?php
                                            $clinic_name = !empty($pt['clinic_name'])
                                                ? decodeThai($pt['clinic_name'])
                                                : (!empty($pt['clinic_code']) ? 'คลินิก ' . $pt['clinic_code'] : 'ไม่ระบุคลินิก');
                                            echo htmlspecialchars($clinic_name, ENT_QUOTES, 'UTF-8');
                                        ?>
                                    </td>
                                    <?php
                                        $patient_status = $patient_status_map[$pt['hn'] . '|' . $pt['regdate']] ?? null;
                                        $status_type = $patient_status['status_type'] ?? '';
                                        $status_detail = $patient_status['status_detail'] ?? '';
                                        $status_labels = [
                                            'discharge' => 'กลับบ้าน',
                                            'home_delivery' => 'รับยาที่บ้าน',
                                            'self_pickup' => 'รับยาเอง',
                                            'other' => 'อื่นๆ' . ($status_detail !== '' ? ': ' . $status_detail : '')
                                        ];
                                        $status_styles = [
                                            'discharge' => 'bg-slate-100 text-slate-700 hover:bg-slate-200',
                                            'home_delivery' => 'bg-blue-100 text-blue-700 hover:bg-blue-200',
                                            'self_pickup' => 'bg-amber-100 text-amber-800 hover:bg-amber-200',
                                            'other' => 'bg-violet-100 text-violet-700 hover:bg-violet-200'
                                        ];
                                        $is_clinic_custom_status = strpos($status_type, 'clinic_') === 0 && $status_detail !== '';
                                        $status_label = $status_labels[$status_type] ?? ($is_clinic_custom_status ? $status_detail : 'เลือกสถานะ');
                                        $status_style = $status_styles[$status_type] ?? ($is_clinic_custom_status
                                            ? 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200'
                                            : 'bg-gray-100 text-gray-600 hover:bg-gray-200');
                                    ?>
                                    <td class="patient-status-cell px-4 lg:px-6 py-4 text-center text-xs font-medium">
                                        <button type="button"
                                                class="patient-status-button px-3 py-2 rounded-lg font-bold transition <?php echo $status_style; ?>"
                                                data-hn="<?php echo htmlspecialchars($pt['hn'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-regdate="<?php echo htmlspecialchars($pt['regdate'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-fullname="<?php echo htmlspecialchars(decodeThai($pt['fullname']), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-status="<?php echo htmlspecialchars($status_type, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-detail="<?php echo htmlspecialchars($status_detail, ENT_QUOTES, 'UTF-8'); ?>"
                                                onclick="openPatientStatusModal(this)">
                                            <?php echo htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8'); ?>
                                        </button>
                                    </td>
                                    <?php
                                        $delivery = $delivery_map[$pt['hn']] ?? null;
                                        $has_delivery_address = $delivery && !empty(trim((string)$delivery['address'])) && !empty(trim((string)$delivery['phone']));
                                        $pickup_self = $delivery && (int)($delivery['pickup_self'] ?? 0) === 1;
                                        $drug_count = $drug_delivery_map[$pt['hn'] . '|' . $pt['regdate']] ?? 0;
                                        $has_delivery_drugs = $drug_count > 0;
                                        $note_count = $note_count_map[$pt['hn']] ?? 0;
                                    ?>
                                    <td class="px-4 lg:px-6 py-4 whitespace-nowrap text-center text-xs font-medium">
                                        <div class="flex flex-col items-center gap-1.5">
                                            <?php if ($has_delivery_drugs): ?>
                                                <span class="px-2 py-1 bg-blue-100 text-blue-700 rounded-lg font-bold">
                                                    มียาที่ต้องจัดส่ง <?php echo number_format($drug_count); ?> รายการ
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 bg-gray-100 text-gray-500 rounded-lg font-bold">
                                                    ไม่พบรายการยา
                                                </span>
                                            <?php endif; ?>
                                        <?php if ($pickup_self): ?>
                                            <button onclick="openDeliveryModal('<?php echo htmlspecialchars($pt['hn']); ?>', '<?php echo decodeThai($pt['fullname']); ?>', '<?php echo htmlspecialchars($pt['regdate']); ?>')"
                                                    class="px-3 py-2 bg-amber-100 hover:bg-amber-200 text-amber-800 rounded-lg font-bold transition">
                                                มารับยาเอง
                                            </button>
                                        <?php elseif ($has_delivery_address): ?>
                                            <button onclick="openDeliveryModal('<?php echo htmlspecialchars($pt['hn']); ?>', '<?php echo decodeThai($pt['fullname']); ?>', '<?php echo htmlspecialchars($pt['regdate']); ?>')"
                                                    class="px-3 py-2 bg-green-100 hover:bg-green-200 text-green-800 rounded-lg font-bold transition">
                                                มีที่อยู่แล้ว
                                            </button>
                                        <?php else: ?>
                                            <button onclick="openDeliveryModal('<?php echo htmlspecialchars($pt['hn']); ?>', '<?php echo decodeThai($pt['fullname']); ?>', '<?php echo htmlspecialchars($pt['regdate']); ?>')"
                                                    class="px-3 py-2 bg-red-100 hover:bg-red-200 text-red-700 rounded-lg font-bold transition">
                                                เพิ่มที่อยู่
                                            </button>
                                        <?php endif; ?>
                                        </div>
                                    </td>
                                    
                                    <td class="px-4 lg:px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                        <button onclick="openSmartModal('<?php echo htmlspecialchars($pt['hn']); ?>', '<?php echo decodeThai($pt['fullname']); ?>', '<?php echo htmlspecialchars($pt['regdate']); ?>')" 
                                                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 shadow-md text-white rounded-lg text-xs font-bold transition transform hover:-translate-y-0.5">
                                            <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                            เปิดแฟ้มรักษา
                                        </button>
                                    </td>

                                    <td class="px-4 lg:px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                        <button onclick="openPatientNoteModal('<?php echo htmlspecialchars($pt['hn']); ?>', '<?php echo decodeThai($pt['fullname']); ?>', '<?php echo htmlspecialchars($pt['regdate']); ?>')"
                                                class="px-4 py-2 <?php echo $note_count > 0 ? 'bg-amber-500 hover:bg-amber-600' : 'bg-slate-700 hover:bg-slate-800'; ?> shadow-md text-white rounded-lg text-xs font-bold transition transform hover:-translate-y-0.5">
                                            หมายเหตุ<?php echo $note_count > 0 ? ' (' . number_format($note_count) . ')' : ''; ?>
                                        </button>
                                    </td>

                                    <td class="px-4 lg:px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                        <button onclick="printLabelDirect('<?php echo htmlspecialchars($pt['hn']); ?>')" 
                                                class="px-4 py-2 bg-[#22c55e] hover:bg-[#16a34a] shadow-md text-white rounded-lg text-xs font-bold transition transform hover:-translate-y-0.5">
                                            <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                                            พิมพ์ใบปะหน้า
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <div id="smartModal" class="fixed inset-0 z-50 hidden overflow-y-auto bg-black bg-opacity-60 backdrop-blur-sm flex justify-center items-center pt-10 pb-10">
        <div id="smartModalPanel" class="bg-white rounded-2xl overflow-hidden shadow-2xl w-[98%] max-w-7xl border border-gray-200 mx-auto">
            
            <div class="bg-[#1e40af] px-6 py-4 flex justify-between items-center text-white">
                <div>
                    <h2 id="smartModalTitle" class="text-xl font-bold flex items-center">แฟ้มข้อมูลการรักษา Telemedicine</h2>
                    <p id="modalPtName" class="text-sm text-blue-200 mt-1 font-semibold">HN: XXXXX | ชื่อ-สกุล (รับบริการเมื่อ: 0000-00-00)</p>
                </div>
                <button onclick="closeSmartModal()" class="text-white hover:text-red-300 transition text-3xl font-bold">&times;</button>
            </div>
            
            <div id="smartModalGrid" class="p-6 grid grid-cols-1 lg:grid-cols-3 gap-6 bg-gray-50 max-h-[80vh] overflow-y-auto custom-scrollbar">
                
                <div id="treatmentColumn" class="space-y-6">
                    <div class="bg-white p-5 rounded-xl shadow-sm border border-blue-100">
                        <h3 class="font-bold text-blue-800 flex items-center mb-3"><span class="text-xl mr-2">🩺</span> ผลการวินิจฉัย (Diagnosis)</h3>
                        <div id="diagContent" class="p-3 bg-blue-50 text-sm font-semibold text-gray-700 rounded-lg border border-blue-200">
                            กำลังโหลดข้อมูล...
                        </div>
                    </div>

                    <div class="bg-white p-5 rounded-xl shadow-sm border border-orange-100 flex-1">
                        <h3 class="font-bold text-orange-800 flex items-center mb-4 pb-2 border-b border-orange-50"><span class="text-xl mr-2">💊</span> รายการยา</h3>
                        <div id="drugContent" class="space-y-3 overflow-y-auto custom-scrollbar pr-2" style="max-height: 350px;">
                            กำลังโหลดข้อมูล...
                        </div>
                    </div>
                </div>

                <div id="deliveryColumn" class="space-y-6">
                    
                    <div class="bg-white p-5 rounded-xl shadow-sm border border-green-200">
                        <div class="flex items-center mb-4 pb-2 border-b border-gray-100">
                            <span class="text-2xl mr-2">๐Ÿ  </span>
                            <div>
                                <h3 class="font-bold text-gray-800 text-lg">ลงข้อมูลที่อยู่รับยา (สำหรับจัดส่ง)</h3>
                            </div>
                        </div>

                        <input type="hidden" id="deliv_hn">
                        <div id="delivery_status" class="mb-4 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-bold text-gray-600">
                            กำลังตรวจสอบข้อมูลจัดส่ง...
                        </div>                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1">เบอร์โทรศัพท์ <span class="text-red-500">*</span></label>
                                <input type="text" id="deliv_phone" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none text-base" placeholder="081-234-5678">
                            </div>

                            <label class="flex items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-3 text-sm font-bold text-amber-800 cursor-pointer">
                                <input type="checkbox" id="deliv_pickup_self" class="h-5 w-5 rounded border-gray-300 text-amber-600 focus:ring-amber-500" onchange="togglePickupSelf()">
                                <span>มารับยาเอง</span>
                            </label>
                            
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1">ที่อยู่สำหรับจัดส่ง <span class="text-red-500">*</span></label>
                                <textarea id="deliv_address" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none text-base leading-relaxed" rows="6" placeholder="บ้านเลขที่ หมู่ที่ ถนน ตำบล อำเภอ จังหวัด"></textarea>
                            </div>

                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1">รหัสไปรษณีย์ <span class="text-red-500">*</span></label>
                                <input type="text" id="deliv_zip" class="w-1/2 p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 outline-none text-lg font-bold tracking-widest text-blue-700" placeholder="21140" maxlength="5">
                            </div>

                            <div class="pt-2">
                                <button onclick="saveDelivery()" class="w-full bg-green-600 text-white py-2 rounded-lg font-bold hover:bg-green-700 shadow transition text-sm">
                                    บันทึกข้อมูลที่อยู่
                                </button>
                            </div>
                        </div>
                    </div>

                </div>

                <div id="appointmentColumn" class="space-y-6">
                    <div class="bg-white p-5 rounded-xl shadow-sm border border-purple-200">
                        <h3 class="font-bold text-purple-800 flex items-center mb-3"><span class="text-xl mr-2">📅</span> นัดหมายครั้งถัดไป (HIS)</h3>
                        <form id="appointForm" class="space-y-3">
                            <input type="hidden" id="app_hn" name="hn">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-bold text-gray-600 mb-1">วันที่นัด:</label>
                                    <input type="date" id="app_date" name="app_date" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 outline-none text-sm" required>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-600 mb-1">เวลา:</label>
                                    <input type="time" id="app_time" name="app_time" value="09:00" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 outline-none text-sm">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-600 mb-1">หมายเหตุ:</label>
                                <input type="text" id="app_cause" name="cause" value="ติดตามอาการ Telemed" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 outline-none text-sm">
                            </div>
                            <button type="submit" class="w-full bg-purple-600 text-white py-2 rounded-lg font-bold hover:bg-purple-700 shadow text-sm mt-2 transition">
                                ยืนยันลงนัดหมาย
                            </button>
                        </form>
                    </div>

                </div>

            </div>
        </div>
    </div>

    <?php include 'patient_notes_modal.php'; ?>

    <div id="patientStatusModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-black bg-opacity-60 px-4">
        <div class="w-full max-w-md overflow-hidden rounded-lg bg-white shadow-2xl">
            <div class="flex items-center justify-between bg-gray-800 px-5 py-4 text-white">
                <div>
                    <h2 class="text-lg font-bold">สถานะ</h2>
                    <p id="patientStatusSubtitle" class="mt-1 text-xs text-gray-300"></p>
                </div>
                <button type="button" onclick="closePatientStatusModal()" class="text-2xl leading-none text-gray-300 hover:text-white" aria-label="ปิด">&times;</button>
            </div>
            <form id="patientStatusForm" class="p-5">
                <input type="hidden" id="status_hn" name="hn">
                <input type="hidden" id="status_regdate" name="regdate">
                <div class="space-y-2">
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 p-3 hover:bg-blue-50">
                        <input type="radio" name="status_type" value="discharge" class="h-4 w-4 text-slate-600" required>
                        <span class="font-semibold text-gray-700">กลับบ้าน</span>
                    </label>
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 p-3 hover:bg-emerald-50">
                        <input type="radio" name="status_type" value="home_delivery" class="h-4 w-4 text-blue-600">
                        <span class="font-semibold text-gray-700">รับยาที่บ้าน</span>
                    </label>
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 p-3 hover:bg-amber-50">
                        <input type="radio" name="status_type" value="self_pickup" class="h-4 w-4 text-amber-600">
                        <span class="font-semibold text-gray-700">รับยาเอง</span>
                    </label>
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 p-3 hover:bg-violet-50">
                        <input type="radio" name="status_type" value="other" class="h-4 w-4 text-violet-600">
                        <span class="font-semibold text-gray-700">อื่นๆ</span>
                    </label>
                </div>
                <div id="statusOtherContainer" class="mt-4 hidden">
                    <label for="status_detail" class="mb-1 block text-sm font-bold text-gray-700">ระบุสถานะอื่นๆ</label>
                    <input type="text" id="status_detail" name="status_detail" maxlength="255"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none focus:border-violet-500 focus:ring-2 focus:ring-violet-200">
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" onclick="closePatientStatusModal()" class="rounded-lg border border-gray-300 px-4 py-2 font-bold text-gray-600 hover:bg-gray-50">ยกเลิก</button>
                    <button type="submit" id="savePatientStatusButton" class="rounded-lg bg-blue-600 px-4 py-2 font-bold text-white hover:bg-blue-700">บันทึกสถานะ</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        if (window.jQuery && $.fn.DataTable) {
            $('#latestPatientsTable').DataTable({
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'ทั้งหมด']],
                order: [],
                autoWidth: false,
                initComplete: function() {
                    this.api().columns.adjust();
                },
                language: {
                    search: 'ค้นหา:',
                    lengthMenu: 'แสดง _MENU_ รายการ',
                    info: 'แสดง _START_ ถึง _END_ จากทั้งหมด _TOTAL_ รายการ',
                    infoEmpty: 'ไม่มีข้อมูล',
                    infoFiltered: '(กรองจากทั้งหมด _MAX_ รายการ)',
                    zeroRecords: 'ไม่พบข้อมูลที่ค้นหา',
                    emptyTable: 'ไม่พบประวัติผู้ป่วย Telemed ในระบบ',
                    paginate: {
                        first: 'หน้าแรก',
                        previous: 'ก่อนหน้า',
                        next: 'ถัดไป',
                        last: 'หน้าสุดท้าย'
                    }
                },
                columnDefs: [
                    { orderable: false, targets: [4, 5, 6, 7, 8] }
                ]
            });
        }

        const ctxMonthly = document.getElementById('monthlyChart');
        if (ctxMonthly) {
            new Chart(ctxMonthly.getContext('2d'), { 
                type: 'bar', 
                data: { 
                    labels: ['ต.ค.', 'พ.ย.', 'ธ.ค.', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.'], 
                    datasets: [{ 
                        label: 'จำนวน (ราย)', 
                        data: [<?php echo implode(',', $monthly_data); ?>], 
                        backgroundColor: 'rgba(59, 130, 246, 0.7)', 
                        borderColor: 'rgba(37, 99, 235, 1)', 
                        borderWidth: 1, 
                        borderRadius: 4 
                    }]
                }, 
                options: { 
                    responsive: true, maintainAspectRatio: false, 
                    plugins: { legend: { display: false } }, 
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } 
                } 
            });
        }

        const ctxQuarter = document.getElementById('quarterChart');
        if (ctxQuarter) {
            new Chart(ctxQuarter.getContext('2d'), { 
                type: 'doughnut', 
                data: { 
                    labels: ['Q1', 'Q2', 'Q3', 'Q4'], 
                    datasets: [{ 
                        data: [<?php echo implode(',', $quarterly_data); ?>], 
                        backgroundColor: ['#3b82f6','#10b981','#f59e0b','#ef4444'], 
                        borderWidth: 2, 
                        borderColor: '#ffffff' 
                    }]
                }, 
                options: { 
                    responsive: true, maintainAspectRatio: false, cutout: '65%', 
                    plugins: { legend: { position: 'right', labels: { font: { family: 'Sarabun' } } } } 
                } 
            });
        }
    });

    function setSmartModalMode(mode) {
        const panel = document.getElementById('smartModalPanel');
        const grid = document.getElementById('smartModalGrid');
        const treatmentColumn = document.getElementById('treatmentColumn');
        const deliveryColumn = document.getElementById('deliveryColumn');
        const appointmentColumn = document.getElementById('appointmentColumn');
        const title = document.getElementById('smartModalTitle');

        if (mode === 'delivery') {
            title.textContent = 'ข้อมูลที่อยู่รับยา';
            panel.classList.remove('max-w-7xl', 'max-w-5xl');
            panel.classList.add('max-w-xl');
            grid.className = 'p-6 grid grid-cols-1 gap-6 bg-gray-50 max-h-[80vh] overflow-y-auto custom-scrollbar';
            treatmentColumn.classList.add('hidden');
            appointmentColumn.classList.add('hidden');
            deliveryColumn.classList.remove('hidden');
            return;
        }

        title.textContent = 'แฟ้มข้อมูลการรักษา Telemedicine';
        panel.classList.remove('max-w-xl', 'max-w-7xl');
        panel.classList.add('max-w-5xl');
        grid.className = 'p-6 grid grid-cols-1 lg:grid-cols-2 gap-6 bg-gray-50 max-h-[80vh] overflow-y-auto custom-scrollbar';
        treatmentColumn.classList.remove('hidden');
        appointmentColumn.classList.remove('hidden');
        deliveryColumn.classList.add('hidden');
    }

    function openSmartModal(hn, fullname, regdate, mode = 'treatment') {
        document.getElementById('modalPtName').innerText = 'HN: ' + hn + ' | ' + fullname + ' (รับบริการเมื่อ: ' + regdate + ')';
        document.getElementById('deliv_hn').value = hn; 
        document.getElementById('app_hn').value = hn; 
        setSmartModalMode(mode);
        
        document.getElementById('deliv_phone').value = ''; 
        document.getElementById('deliv_address').value = '';
        document.getElementById('deliv_zip').value = '';
        document.getElementById('deliv_pickup_self').checked = false;
        updateDeliveryStatus('', '', false);
        togglePickupSelf();
        document.getElementById('diagContent').innerHTML = 'กำลังโหลดข้อมูล...';
        document.getElementById('drugContent').innerHTML = '<div class="text-center py-10 text-gray-500 font-bold">กำลังโหลดรายการยา...</div>';
        
        document.getElementById('smartModal').classList.remove('hidden');
        fetchData(hn, regdate);
    }

    function openDeliveryModal(hn, fullname, regdate) {
        openSmartModal(hn, fullname, regdate, 'delivery');
        setTimeout(() => {
            const addressInput = document.getElementById('deliv_address');
            if (addressInput) {
                addressInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                addressInput.focus();
            }
        }, 450);
    }
    
    function closeSmartModal() { document.getElementById('smartModal').classList.add('hidden'); }

    let activePatientStatusButton = null;

    function toggleStatusOtherField() {
        const selected = document.querySelector('input[name="status_type"]:checked');
        const isOther = selected && selected.value === 'other';
        const container = document.getElementById('statusOtherContainer');
        const detail = document.getElementById('status_detail');
        container.classList.toggle('hidden', !isOther);
        detail.required = isOther;
        if (isOther) detail.focus();
    }

    function openPatientStatusModal(button) {
        activePatientStatusButton = button;
        document.getElementById('status_hn').value = button.dataset.hn;
        document.getElementById('status_regdate').value = button.dataset.regdate;
        document.getElementById('status_detail').value = button.dataset.detail || '';
        document.getElementById('patientStatusSubtitle').textContent =
            'HN: ' + button.dataset.hn + ' | ' + button.dataset.fullname + ' | ' + button.dataset.regdate;

        document.querySelectorAll('input[name="status_type"]').forEach((radio) => {
            radio.checked = radio.value === button.dataset.status;
        });
        toggleStatusOtherField();

        const modal = document.getElementById('patientStatusModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closePatientStatusModal() {
        const modal = document.getElementById('patientStatusModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        activePatientStatusButton = null;
    }

    document.querySelectorAll('input[name="status_type"]').forEach((radio) => {
        radio.addEventListener('change', toggleStatusOtherField);
    });

    document.getElementById('patientStatusModal').addEventListener('click', (event) => {
        if (event.target.id === 'patientStatusModal') closePatientStatusModal();
    });

    document.getElementById('patientStatusForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        const selected = document.querySelector('input[name="status_type"]:checked');
        const detail = document.getElementById('status_detail').value.trim();
        if (!selected) {
            Swal.fire('แจ้งเตือน', 'กรุณาเลือกสถานะ', 'warning');
            return;
        }
        if (selected.value === 'other' && !detail) {
            Swal.fire('แจ้งเตือน', 'กรุณาระบุสถานะอื่นๆ', 'warning');
            return;
        }

        const saveButton = document.getElementById('savePatientStatusButton');
        saveButton.disabled = true;
        saveButton.textContent = 'กำลังบันทึก...';

        try {
            const response = await fetch('api_telemed.php?action=save_patient_status', {
                method: 'POST',
                body: new FormData(event.target)
            });
            const data = await response.json();
            if (data.status !== 'success') throw new Error(data.message || 'ไม่สามารถบันทึกสถานะได้');

            if (activePatientStatusButton) {
                activePatientStatusButton.dataset.status = data.status_type;
                activePatientStatusButton.dataset.detail = data.status_detail || '';
                activePatientStatusButton.textContent = data.label;
                activePatientStatusButton.className =
                    'patient-status-button px-3 py-2 rounded-lg font-bold transition ' + data.style;

                if (window.jQuery && $.fn.DataTable && $.fn.DataTable.isDataTable('#latestPatientsTable')) {
                    $('#latestPatientsTable').DataTable().row($(activePatientStatusButton).closest('tr')).invalidate('dom');
                }
            }

            closePatientStatusModal();
            Swal.fire({ icon: 'success', title: 'บันทึกสถานะแล้ว', timer: 1200, showConfirmButton: false });
        } catch (error) {
            Swal.fire('ผิดพลาด', error.message, 'error');
        } finally {
            saveButton.disabled = false;
            saveButton.textContent = 'บันทึกสถานะ';
        }
    });

    function updateDeliveryStatus(address, phone, pickupSelf) {
        const status = document.getElementById('delivery_status');
        if (!status) return;

        if (pickupSelf) {
            status.className = 'mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-bold text-amber-800';
            status.textContent = 'สถานะ: ผู้ป่วยประสงค์มารับยาเอง';
        } else if ((address || '').trim() && (phone || '').trim()) {
            status.className = 'mb-4 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-sm font-bold text-green-800';
            status.textContent = 'สถานะ: มีที่อยู่จัดส่งแล้ว';
        } else {
            status.className = 'mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-bold text-red-700';
            status.textContent = 'สถานะ: ยังไม่มีข้อมูลจัดส่ง';
        }
    }

    function togglePickupSelf() {
        const pickupSelf = document.getElementById('deliv_pickup_self').checked;
        const address = document.getElementById('deliv_address');
        const zip = document.getElementById('deliv_zip');

        address.disabled = pickupSelf;
        zip.disabled = pickupSelf;
        address.classList.toggle('bg-gray-100', pickupSelf);
        zip.classList.toggle('bg-gray-100', pickupSelf);

        updateDeliveryStatus(address.value, document.getElementById('deliv_phone').value, pickupSelf);
    }
    
    async function fetchData(hn, regdate) {
        try {
            const fd = new FormData(); fd.append('hn', hn); fd.append('regdate', regdate); 
            const res = await fetch('api_telemed.php?action=get_telemed_data', { method: 'POST', body: fd });
            const data = await res.json();

            if(data.status === 'success') {
                let fullAddress = data.delivery.address || '';
                let zipCode = '';
                let zipMatch = fullAddress.match(/(\d{5})$/);
                if(zipMatch) {
                    zipCode = zipMatch[1];
                    fullAddress = fullAddress.replace(/\s*\d{5}$/, ''); 
                }

                document.getElementById('deliv_address').value = fullAddress.trim();
                document.getElementById('deliv_zip').value = zipCode;
                document.getElementById('deliv_phone').value = data.delivery.phone || '';
                const pickupSelf = String(data.delivery.pickup_self || '0') === '1';
                document.getElementById('deliv_pickup_self').checked = pickupSelf;
                togglePickupSelf();
                updateDeliveryStatus(fullAddress.trim(), data.delivery.phone || '', pickupSelf);
                
                document.getElementById('diagContent').innerHTML = data.diag || '<span class="text-gray-400">ไม่พบข้อมูลการวินิจฉัย</span>';

                let drugHtml = '';
                if(data.drugs.length > 0) { 
                    data.drugs.forEach(d => { 
                        drugHtml += `<div class="p-3 bg-white border border-gray-200 rounded-xl text-sm mb-2 shadow-sm"><div class="font-bold text-gray-800 text-base">${d.name} <span class="float-right text-orange-600 bg-orange-50 px-2 py-1 rounded-lg border border-orange-100 font-black">x ${d.qty}</span></div><div class="text-xs text-gray-500 mt-1 bg-gray-50 p-2 rounded">${d.usage}</div></div>`; 
                    }); 
                } 
                else { drugHtml = '<div class="text-sm text-center py-10 text-red-400 font-bold bg-red-50 rounded-xl border border-dashed border-red-200">ไม่พบรายการยาที่ต้องจัดส่งในระบบ HIS</div>'; }
                document.getElementById('drugContent').innerHTML = drugHtml;

                if (data.appointment) {
                    document.getElementById('app_date').value = data.appointment.date;
                    document.getElementById('app_time').value = data.appointment.time;
                    document.getElementById('app_cause').value = data.appointment.cause;
                } else {
                    document.getElementById('app_date').value = '';
                    document.getElementById('app_time').value = '09:00';
                    document.getElementById('app_cause').value = 'ติดตามอาการ Telemed';
                }

            } else { Swal.fire('ข้อผิดพลาด', data.message, 'warning'); }
        } catch (e) { Swal.fire('ผิดพลาด', 'เชื่อมต่อระบบล้มเหลว', 'error'); }
    }

    async function saveDelivery() {
        const hn = document.getElementById('deliv_hn').value;
        const addressText = document.getElementById('deliv_address').value.trim();
        const zip = document.getElementById('deliv_zip').value.trim();
        const phone = document.getElementById('deliv_phone').value.trim();
        const pickupSelf = document.getElementById('deliv_pickup_self').checked;
        
        if(!phone || (!pickupSelf && (!addressText || !zip))) {
            Swal.fire('แจ้งเตือน', 'กรุณากรอกเบอร์โทร และกรอกที่อยู่/รหัสไปรษณีย์ หากไม่ได้เลือกมารับยาเอง', 'warning');
            return;
        }
        
        const fullAddress = pickupSelf ? addressText : (addressText + ' ' + zip).trim();

        const fd = new FormData();
        fd.append('hn', hn);
        fd.append('address', fullAddress);
        fd.append('phone', phone);
        fd.append('pickup_self', pickupSelf ? '1' : '0');

        // --- เพิ่ม Loading ระหว่างบันทึกที่อยู่ ---
        Swal.fire({
            title: 'กำลังบันทึกข้อมูล...',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });

        try {
            const res = await fetch('api_telemed.php?action=save_delivery', { method: 'POST', body: fd });
            const data = await res.json();
            
            if(data.status === 'success') {
                updateDeliveryStatus(addressText, phone, pickupSelf);
                Swal.fire({ 
                    icon: 'success', 
                    title: pickupSelf ? 'บันทึกสถานะมารับยาเองแล้ว' : 'บันทึกที่อยู่เรียบร้อย', 
                    timer: 1500, 
                    showConfirmButton: false 
                }).then(() => {
                    window.location.reload(); // รีเฟรชหน้าเมื่อบันทึกเสร็จ
                });
            } else { 
                Swal.fire('ผิดพลาด', data.message, 'error'); 
            }
        } catch (error) {
            Swal.fire('ข้อผิดพลาด', 'ระบบไม่สามารถเชื่อมต่อได้', 'error');
        }
    }
    
    function printLabelDirect(hn) { 
        window.open('print_label.php?hn=' + hn, '_blank'); 
    }

    document.getElementById('appointForm').addEventListener('submit', async (e) => {
        e.preventDefault();

        // --- เพิ่ม Loading ระหว่างบันทึกนัดหมาย ---
        Swal.fire({
            title: 'กำลังบันทึกข้อมูล...',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });

        const fd = new FormData(e.target);
        try {
            const res = await fetch('api_telemed.php?action=save_appoint', { method: 'POST', body: fd });
            const data = await res.json();
            
            if(data.status === 'success') {
                Swal.fire({ 
                    icon: 'success', 
                    title: 'ลงนัดหมายสำเร็จ', 
                    timer: 1500, 
                    showConfirmButton: false 
                }).then(() => {
                    window.location.reload(); // รีเฟรชหน้าเมื่อบันทึกเสร็จ
                });
            } else { 
                Swal.fire('ผิดพลาด', data.message || 'ไม่สามารถบันทึกได้', 'error'); 
            }
        } catch (error) {
            Swal.fire('ข้อผิดพลาด', 'ระบบไม่สามารถเชื่อมต่อได้', 'error');
        }
    });
    </script>
</body>
</html>
