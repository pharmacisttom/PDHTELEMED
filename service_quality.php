<?php
include 'config.php';
header('Content-Type: text/html; charset=UTF-8');

$current_page = basename($_SERVER['PHP_SELF']);
check_permission($current_page);

$today = date('Y-m-d');
$range_end_date = trim((string) ($_GET['date_to'] ?? $_GET['date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $range_end_date) || !DateTime::createFromFormat('Y-m-d', $range_end_date)) {
    $range_end_date = $today;
}

$range_start_date = trim((string) ($_GET['date_from'] ?? date('Y-m-d', strtotime($range_end_date . ' -29 days'))));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $range_start_date) || !DateTime::createFromFormat('Y-m-d', $range_start_date)) {
    $range_start_date = date('Y-m-d', strtotime($range_end_date . ' -29 days'));
}

if ($range_start_date > $range_end_date) {
    [$range_start_date, $range_end_date] = [$range_end_date, $range_start_date];
}

if ((strtotime($range_end_date) - strtotime($range_start_date)) / 86400 > 365) {
    $range_start_date = date('Y-m-d', strtotime($range_end_date . ' -365 days'));
}

$selected_clinic = trim((string) ($_GET['clinic'] ?? ''));
$page_version = APP_VERSION;
$db_error = null;
$quality_rows = [];
$clinic_options = [];
$clinic_issue_summary = [];
$quality_summary = [
    'total_visits' => 0,
    'quality_pass' => 0,
    'needs_improvement' => 0,
    'home_delivery_cases' => 0,
    'missing_service_fee' => 0,
    'missing_shipping_fee' => 0,
    'problem_clinics' => 0,
];

// รหัสบริการจริงจาก HIS
$service_fee_codes = ['19003', '190003'];
$service_fee_keywords = [];
$shipping_fee_codes = ['19004', '190004'];
$shipping_fee_keywords = [];

function buildOrderMatchSql(string $codeColumn, string $nameColumn, array $codes, array $keywords, array &$params): string {
    $conditions = [];

    foreach (array_values($codes) as $code) {
        $conditions[] = "$codeColumn = ?";
        $params[] = (string) $code;
    }

    foreach (array_values($keywords) as $keyword) {
        $conditions[] = "LOWER($nameColumn) LIKE ?";
        $keywordText = (string) $keyword;
        if (function_exists('mb_strtolower')) {
            $keywordText = mb_strtolower($keywordText, 'UTF-8');
        } else {
            $keywordText = strtolower($keywordText);
        }
        $params[] = '%' . $keywordText . '%';
    }

    return $conditions ? '(' . implode(' OR ', $conditions) . ')' : '0 = 1';
}

try {
    $clinic_stmt = $his_pdo->prepare(
        "SELECT DISTINCT TRIM(o.clinic) AS clinic_code, cl.NAME AS clinic_name
         FROM opd.opd o
         LEFT JOIN hos.clinic cl ON cl.code = o.clinic
         WHERE o.comein IN ('10', 'IN10')
           AND o.regdate BETWEEN ? AND ?
           AND TRIM(o.clinic) <> ''
         ORDER BY cl.NAME ASC, clinic_code ASC"
    );
    $clinic_stmt->execute([$range_start_date, $range_end_date]);
    $clinic_options = $clinic_stmt->fetchAll(PDO::FETCH_ASSOC);

    $service_match_params = [];
    $shipping_match_params = [];
    $service_match_sql = buildOrderMatchSql('ooo.codeother', 'ooo.nameother', $service_fee_codes, $service_fee_keywords, $service_match_params);
    $shipping_match_sql = buildOrderMatchSql('ooo.codeother', 'ooo.nameother', $shipping_fee_codes, $shipping_fee_keywords, $shipping_match_params);

    $quality_sql = "
        SELECT
            o.hn,
            o.fullname,
            o.regdate,
            o.timereg,
            TRIM(o.clinic) AS clinic_code,
            cl.NAME AS clinic_name,
            COALESCE(drug_summary.drug_count, 0) AS drug_count,
            COALESCE(service_summary.service_fee_count, 0) AS service_fee_count,
            COALESCE(service_summary.service_fee_amount, 0) AS service_fee_amount,
            COALESCE(service_summary.shipping_fee_count, 0) AS shipping_fee_count,
            COALESCE(service_summary.shipping_fee_amount, 0) AS shipping_fee_amount
        FROM opd.opd o
        LEFT JOIN hos.clinic cl
            ON cl.code = o.clinic
        LEFT JOIN (
            SELECT d.hn, d.regdate, COUNT(*) AS drug_count
            FROM opd.drug_order_opd d
            WHERE d.regdate BETWEEN ? AND ?
            GROUP BY d.hn, d.regdate
        ) AS drug_summary
            ON drug_summary.hn = o.hn AND drug_summary.regdate = o.regdate
        LEFT JOIN (
            SELECT
                ooo.hn,
                ooo.regdate,
                SUM(CASE WHEN $service_match_sql THEN 1 ELSE 0 END) AS service_fee_count,
                SUM(CASE WHEN $service_match_sql THEN COALESCE(ooo.price, 0) ELSE 0 END) AS service_fee_amount,
                SUM(CASE WHEN $shipping_match_sql THEN 1 ELSE 0 END) AS shipping_fee_count,
                SUM(CASE WHEN $shipping_match_sql THEN COALESCE(ooo.price, 0) ELSE 0 END) AS shipping_fee_amount
            FROM opd.other_order_opd ooo
            WHERE ooo.regdate BETWEEN ? AND ?
            GROUP BY ooo.hn, ooo.regdate
        ) AS service_summary
            ON service_summary.hn = o.hn AND service_summary.regdate = o.regdate
        WHERE o.comein IN ('10', 'IN10')
          AND o.regdate BETWEEN ? AND ?";

    if ($selected_clinic !== '') {
        $quality_sql .= " AND TRIM(o.clinic) = ?";
    }

    $quality_sql .= " ORDER BY o.regdate DESC, o.timereg DESC LIMIT 1000";

    $quality_params = [
        $range_start_date,
        $range_end_date,
    ];
    $quality_params = array_merge($quality_params, $service_match_params, $service_match_params, $shipping_match_params, $shipping_match_params, [
        $range_start_date,
        $range_end_date,
        $range_start_date,
        $range_end_date,
    ]);
    if ($selected_clinic !== '') {
        $quality_params[] = $selected_clinic;
    }

    $quality_stmt = $his_pdo->prepare($quality_sql);
    $quality_stmt->execute($quality_params);
    $quality_rows = $quality_stmt->fetchAll(PDO::FETCH_ASSOC);

    $delivery_map = [];
    $status_map = [];
    $tracking_map = [];

    if (count($quality_rows) > 0) {
        $quality_hns = array_values(array_unique(array_column($quality_rows, 'hn')));
        $quality_placeholders = implode(',', array_fill(0, count($quality_hns), '?'));

        $delivery_stmt = $app_pdo->prepare(
            "SELECT hn, address, phone, COALESCE(pickup_self, 0) AS pickup_self
             FROM telemed_delivery
             WHERE hn IN ($quality_placeholders)"
        );
        $delivery_stmt->execute($quality_hns);
        foreach ($delivery_stmt->fetchAll(PDO::FETCH_ASSOC) as $delivery_row) {
            $delivery_map[$delivery_row['hn']] = $delivery_row;
        }

        $status_stmt = $app_pdo->prepare(
            "SELECT hn, regdate, status_type
             FROM telemed_patient_status
             WHERE hn IN ($quality_placeholders)
               AND regdate BETWEEN ? AND ?"
        );
        $status_stmt->execute(array_merge($quality_hns, [$range_start_date, $range_end_date]));
        foreach ($status_stmt->fetchAll(PDO::FETCH_ASSOC) as $status_row) {
            $status_map[$status_row['hn'] . '|' . $status_row['regdate']] = $status_row;
        }

        $tracking_stmt = $app_pdo->prepare(
            "SELECT hn, regdate, tracking_no, followup_status
             FROM telemed_tracking
             WHERE hn IN ($quality_placeholders)
               AND regdate BETWEEN ? AND ?"
        );
        $tracking_stmt->execute(array_merge($quality_hns, [$range_start_date, $range_end_date]));
        foreach ($tracking_stmt->fetchAll(PDO::FETCH_ASSOC) as $tracking_row) {
            $tracking_map[$tracking_row['hn'] . '|' . $tracking_row['regdate']] = $tracking_row;
        }
    }

    foreach ($quality_rows as &$row) {
        $quality_summary['total_visits']++;

        $row_key = $row['hn'] . '|' . $row['regdate'];
        $delivery = $delivery_map[$row['hn']] ?? null;
        $patient_status = $status_map[$row_key] ?? null;
        $tracking = $tracking_map[$row_key] ?? null;

        $drug_count = (int) ($row['drug_count'] ?? 0);
        $service_fee_count = (int) ($row['service_fee_count'] ?? 0);
        $shipping_fee_count = (int) ($row['shipping_fee_count'] ?? 0);
        $pickup_self = (int) ($delivery['pickup_self'] ?? 0) === 1;
        $delivery_address = trim((string) ($delivery['address'] ?? ''));
        $status_type = trim((string) ($patient_status['status_type'] ?? ''));
        $tracking_no = trim((string) ($tracking['tracking_no'] ?? ''));

        $is_home_delivery = $drug_count > 0 && !$pickup_self && (
            $status_type === 'home_delivery'
            || $tracking_no !== ''
            || $delivery_address !== ''
        );

        $missing_reasons = [];
        if ($service_fee_count <= 0) {
            $missing_reasons[] = 'ไม่มีค่าบริการ Telemed';
            $quality_summary['missing_service_fee']++;
        }
        if ($is_home_delivery && $shipping_fee_count <= 0) {
            $missing_reasons[] = 'มียาส่งบ้านแต่ไม่มีค่าส่งยา';
            $quality_summary['missing_shipping_fee']++;
        }
        if ($is_home_delivery) {
            $quality_summary['home_delivery_cases']++;
        }

        $row['decoded_fullname'] = decodeThai($row['fullname']);
        $row['decoded_clinic_name'] = !empty($row['clinic_name'])
            ? decodeThai($row['clinic_name'])
            : 'คลินิก ' . ($row['clinic_code'] ?: '-');
        $row['pickup_self'] = $pickup_self ? 1 : 0;
        $row['is_home_delivery'] = $is_home_delivery;
        $row['quality_status'] = $missing_reasons ? 'ต้องปรับปรุง' : 'ผ่านเกณฑ์';
        $row['quality_reasons'] = $missing_reasons;

        if ($missing_reasons) {
            $quality_summary['needs_improvement']++;
            $clinic_issue_key = ($row['clinic_code'] ?: '-') . '|' . $row['decoded_clinic_name'];
            if (!isset($clinic_issue_summary[$clinic_issue_key])) {
                $clinic_issue_summary[$clinic_issue_key] = [
                    'clinic_code' => (string) ($row['clinic_code'] ?: '-'),
                    'clinic_name' => $row['decoded_clinic_name'],
                    'patients' => 0,
                    'missing_service_fee' => 0,
                    'missing_shipping_fee' => 0,
                ];
            }
            $clinic_issue_summary[$clinic_issue_key]['patients']++;
            if ($service_fee_count <= 0) {
                $clinic_issue_summary[$clinic_issue_key]['missing_service_fee']++;
            }
            if ($is_home_delivery && $shipping_fee_count <= 0) {
                $clinic_issue_summary[$clinic_issue_key]['missing_shipping_fee']++;
            }
        } else {
            $quality_summary['quality_pass']++;
        }
    }
    unset($row);
    uasort($clinic_issue_summary, static function (array $a, array $b): int {
        return $b['patients'] <=> $a['patients'];
    });
    $quality_summary['problem_clinics'] = count($clinic_issue_summary);
} catch (Exception $e) {
    $db_error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตรวจสอบคุณภาพบริการ - PDH Telemed</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 9999px; }
        .dataTables_wrapper { padding: 1rem; }
        .dataTables_wrapper .dataTables_filter input,
        .dataTables_wrapper .dataTables_length select {
            border: 1px solid #cbd5e1;
            border-radius: 0.5rem;
            padding: 0.35rem 0.55rem;
            margin-left: 0.5rem;
        }
    </style>
</head>
<body class="bg-gray-100 flex h-screen overflow-hidden relative">
    <?php include 'sidebar.php'; ?>

    <div class="flex-1 flex flex-col h-screen overflow-hidden w-full">
        <header class="h-16 bg-white shadow-sm border-b border-gray-200 flex items-center justify-between px-4 lg:px-6 z-10">
            <div class="flex items-center min-w-0">
                <button onclick="toggleSidebar()" class="lg:hidden text-gray-600 hover:text-blue-600 focus:outline-none mr-4 bg-gray-100 p-2 rounded-lg">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                </button>
                <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-wide text-emerald-600">Service Quality Monitor</p>
                    <h1 class="truncate text-lg font-bold text-gray-800">หน้าตรวจสอบคุณภาพการให้บริการ Telemed</h1>
                </div>
                <span class="ml-3 hidden rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-700 md:inline-flex">
                    <?php echo htmlspecialchars($page_version, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
            <form method="GET" class="flex flex-wrap items-center justify-end gap-2">
                <label class="hidden text-sm font-bold text-gray-600 md:block">ตั้งแต่</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($range_start_date, ENT_QUOTES, 'UTF-8'); ?>" class="rounded-lg border border-gray-300 bg-white p-2 text-sm font-bold text-gray-700">
                <label class="hidden text-sm font-bold text-gray-600 md:block">ถึง</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($range_end_date, ENT_QUOTES, 'UTF-8'); ?>" class="rounded-lg border border-gray-300 bg-white p-2 text-sm font-bold text-gray-700">
                <select name="clinic" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-bold text-gray-700">
                    <option value="">ทุกคลินิก</option>
                    <?php foreach ($clinic_options as $clinic_option): ?>
                        <?php
                        $clinic_code = trim((string) ($clinic_option['clinic_code'] ?? ''));
                        $clinic_name = !empty($clinic_option['clinic_name']) ? decodeThai($clinic_option['clinic_name']) : 'คลินิก ' . $clinic_code;
                        ?>
                        <option value="<?php echo htmlspecialchars($clinic_code, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selected_clinic === $clinic_code ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($clinic_name, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700">แสดงผล</button>
            </form>
        </header>

        <main class="flex-1 overflow-y-auto bg-gray-50 p-4 lg:p-6 custom-scrollbar">
            <?php if ($db_error): ?>
                <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-red-700">
                    <p class="font-bold">เกิดข้อผิดพลาดในการอ่านข้อมูล</p>
                    <p class="mt-1 text-sm font-mono"><?php echo htmlspecialchars($db_error, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            <?php endif; ?>

            <div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-900">
                <p class="font-bold">เกณฑ์ตรวจสอบที่ใช้ตอนนี้</p>
                <p class="mt-1">1. ผู้ป่วย Telemed ต้องพบค่าบริการรหัส `19003` หรือ `190003` ใน `opd.other_order_opd`</p>
                <p class="mt-1">2. ถ้ามียาและเป็นเคสส่งยากลับบ้าน ต้องพบค่าจัดส่งไปรษณีย์รหัส `19004` หรือ `190004` ใน `opd.other_order_opd`</p>
                <p class="mt-1">ตอนนี้ระบบตรวจจากรหัสจริงแล้ว ไม่ได้อิง keyword แบบเดิม</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div class="rounded-2xl border border-red-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-bold uppercase tracking-wide text-red-500">คลินิกที่ต้องปรับปรุง</p>
                    <p class="mt-2 text-3xl font-black text-red-600"><?php echo number_format($quality_summary['problem_clinics']); ?></p>
                    <p class="mt-1 text-sm text-gray-500">แสดงเฉพาะคลินิกที่มีรายงานปัญหา</p>
                </div>
                <div class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-bold uppercase tracking-wide text-amber-600">ผู้ป่วยที่ต้องปรับปรุง</p>
                    <p class="mt-2 text-3xl font-black text-amber-600"><?php echo number_format($quality_summary['needs_improvement']); ?></p>
                    <p class="mt-1 text-sm text-gray-500">จำนวนรายที่ขาดค่าบริการหรือค่าส่งยา</p>
                </div>
            </div>

            <div class="mb-6">
                <div class="mb-3 flex items-center justify-between">
                    <?php /* sorted by highest problem count first */ ?>
                    <p class="mt-1 text-xs text-gray-500">เรียงจากจำนวนผู้ป่วยที่ต้องปรับปรุงมากสุดไปน้อยสุด</p>
                    <h2 class="text-base font-bold text-gray-800">คลินิกที่ต้องปรับปรุง</h2>
                    <span class="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-red-600">
                        <?php echo number_format($quality_summary['problem_clinics']); ?> คลินิก
                    </span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    <?php if (count($clinic_issue_summary) > 0): ?>
                        <?php foreach (array_values($clinic_issue_summary) as $clinic_index => $clinic_issue): ?>
                            <div class="rounded-2xl border border-red-200 bg-white p-5 shadow-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-red-500">คลินิก <?php echo htmlspecialchars($clinic_issue['clinic_code'], ENT_QUOTES, 'UTF-8'); ?></p>
                                        <p class="text-[11px] font-bold text-red-400">Rank <?php echo number_format($clinic_index + 1); ?></p>
                                        <h3 class="mt-1 text-lg font-black text-gray-900"><?php echo htmlspecialchars($clinic_issue['clinic_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                    </div>
                                    <span class="inline-flex rounded-full bg-red-100 px-3 py-1 text-xs font-bold text-red-700">
                                        <?php echo number_format($clinic_issue['patients']); ?> คน
                                    </span>
                                </div>
                                <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                    <div class="rounded-xl bg-amber-50 px-3 py-3">
                                        <p class="font-bold text-amber-700">ขาดค่าบริการ</p>
                                        <p class="mt-1 text-2xl font-black text-amber-600"><?php echo number_format($clinic_issue['missing_service_fee']); ?></p>
                                    </div>
                                    <div class="rounded-xl bg-sky-50 px-3 py-3">
                                        <p class="font-bold text-sky-700">ขาดค่าส่งยา</p>
                                        <p class="mt-1 text-2xl font-black text-sky-600"><?php echo number_format($clinic_issue['missing_shipping_fee']); ?></p>
                                    </div>
                                </div>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <button type="button"
                                            class="clinic-filter-btn inline-flex items-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-bold text-white transition hover:bg-gray-800"
                                            data-clinic-code="<?php echo htmlspecialchars($clinic_issue['clinic_code'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-clinic-name="<?php echo htmlspecialchars($clinic_issue['clinic_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                        ดูเฉพาะรายชื่อคนของคลินิกนี้
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-span-full rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-4 text-sm font-bold text-emerald-700">
                            ไม่พบคลินิกที่ต้องปรับปรุงในช่วงวันที่ที่เลือก
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-2xl overflow-hidden border border-gray-200">
                <div class="px-4 lg:px-6 py-4 bg-gray-900 text-white font-bold flex flex-wrap gap-2 justify-between items-center text-sm lg:text-base">
                    <span id="activeClinicFilter" class="hidden rounded-full bg-amber-400/20 px-3 py-1 text-xs font-bold text-amber-200"></span>
                    <span>รายการตรวจสอบคุณภาพบริการ Telemed</span>
                    <span class="text-xs bg-white/10 px-3 py-1 rounded-full">แสดง <?php echo number_format(count($quality_rows)); ?> รายการ</span>
                </div>
                <div class="border-b border-gray-100 bg-gray-50 px-4 py-3">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <button type="button" id="clearClinicFilter" class="hidden rounded-xl border border-gray-300 bg-white px-4 py-2 font-bold text-gray-700 transition hover:bg-gray-100">
                            แสดงทุกคลินิกอีกครั้ง
                        </button>
                        <span class="text-xs text-gray-500">กดปุ่มจากการ์ดคลินิกเพื่อดูรายชื่อคนของคลินิกนั้นได้ทันที</span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table id="qualityTable" class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase">วันที่</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase">HN</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase">ชื่อผู้ป่วย</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase">คลินิก</th>
                                <th class="px-4 py-3 text-center text-xs font-bold text-gray-500 uppercase">มียา</th>
                                <th class="px-4 py-3 text-center text-xs font-bold text-gray-500 uppercase">ส่งยาบ้าน</th>
                                <th class="px-4 py-3 text-center text-xs font-bold text-gray-500 uppercase">ค่าบริการ</th>
                                <th class="px-4 py-3 text-center text-xs font-bold text-gray-500 uppercase">ค่าส่งยา</th>
                                <th class="px-4 py-3 text-center text-xs font-bold text-gray-500 uppercase">ผลประเมิน</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase">ข้อสังเกต</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            <?php if (count($quality_rows) > 0): ?>
                                <?php foreach ($quality_rows as $row): ?>
                                    <tr class="hover:bg-gray-50 transition-colors" data-clinic-code="<?php echo htmlspecialchars((string) $row['clinic_code'], ENT_QUOTES, 'UTF-8'); ?>" data-clinic-name="<?php echo htmlspecialchars($row['decoded_clinic_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <td class="px-4 py-4 whitespace-nowrap text-xs text-gray-600">
                                            <div class="font-semibold"><?php echo htmlspecialchars((string) $row['regdate'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="text-[11px] text-gray-400"><?php echo htmlspecialchars((string) $row['timereg'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm font-bold text-blue-700"><?php echo htmlspecialchars((string) $row['hn'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="px-4 py-4 text-sm font-semibold text-gray-800"><?php echo htmlspecialchars($row['decoded_fullname'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="px-4 py-4 text-sm text-gray-700"><?php echo htmlspecialchars($row['decoded_clinic_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="px-4 py-4 text-center text-sm">
                                            <?php if ((int) $row['drug_count'] > 0): ?>
                                                <span class="inline-flex rounded-lg bg-blue-100 px-3 py-1 font-bold text-blue-700"><?php echo number_format((int) $row['drug_count']); ?> รายการ</span>
                                            <?php else: ?>
                                                <span class="inline-flex rounded-lg bg-gray-100 px-3 py-1 font-bold text-gray-500">ไม่มี</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-4 text-center text-sm">
                                            <?php if ($row['is_home_delivery']): ?>
                                                <span class="inline-flex rounded-lg bg-sky-100 px-3 py-1 font-bold text-sky-700">ส่งกลับบ้าน</span>
                                            <?php elseif ((int) $row['pickup_self'] === 1): ?>
                                                <span class="inline-flex rounded-lg bg-amber-100 px-3 py-1 font-bold text-amber-700">รับยาเอง</span>
                                            <?php else: ?>
                                                <span class="inline-flex rounded-lg bg-gray-100 px-3 py-1 font-bold text-gray-500">ไม่ใช่</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-4 text-center text-sm">
                                            <?php if ((int) $row['service_fee_count'] > 0): ?>
                                                <span class="inline-flex rounded-lg bg-emerald-100 px-3 py-1 font-bold text-emerald-700"><?php echo number_format((float) $row['service_fee_amount'], 2); ?></span>
                                            <?php else: ?>
                                                <span class="inline-flex rounded-lg bg-red-100 px-3 py-1 font-bold text-red-600">ไม่พบ</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-4 text-center text-sm">
                                            <?php if ($row['is_home_delivery']): ?>
                                                <?php if ((int) $row['shipping_fee_count'] > 0): ?>
                                                    <span class="inline-flex rounded-lg bg-emerald-100 px-3 py-1 font-bold text-emerald-700"><?php echo number_format((float) $row['shipping_fee_amount'], 2); ?></span>
                                                <?php else: ?>
                                                    <span class="inline-flex rounded-lg bg-red-100 px-3 py-1 font-bold text-red-600">ไม่พบ</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="inline-flex rounded-lg bg-gray-100 px-3 py-1 font-bold text-gray-500">ไม่จำเป็น</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-4 text-center text-sm">
                                            <?php if ($row['quality_status'] === 'ผ่านเกณฑ์'): ?>
                                                <span class="inline-flex rounded-lg bg-emerald-100 px-3 py-1 font-bold text-emerald-700">ผ่านเกณฑ์</span>
                                            <?php else: ?>
                                                <span class="inline-flex rounded-lg bg-red-100 px-3 py-1 font-bold text-red-600">ต้องปรับปรุง</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-4 text-sm text-gray-700">
                                            <?php if (count($row['quality_reasons']) > 0): ?>
                                                <?php echo htmlspecialchars(implode(', ', $row['quality_reasons']), ENT_QUOTES, 'UTF-8'); ?>
                                            <?php else: ?>
                                                <span class="font-semibold text-emerald-700">ข้อมูลบริการครบตามเกณฑ์</span>
                                            <?php endif; ?>
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

    <script>
        if (window.jQuery && $.fn.DataTable) {
            const qualityTableApi = $('#qualityTable').DataTable({
                pageLength: 25,
                order: [[0, 'desc']],
                language: {
                    search: 'ค้นหา:',
                    lengthMenu: 'แสดง _MENU_ รายการ',
                    info: 'แสดง _START_ ถึง _END_ จากทั้งหมด _TOTAL_ รายการ',
                    infoEmpty: 'ไม่มีข้อมูล',
                    zeroRecords: 'ไม่พบข้อมูลที่ค้นหา',
                    paginate: {
                        first: 'แรก',
                        last: 'สุดท้าย',
                        next: 'ถัดไป',
                        previous: 'ก่อนหน้า'
                    }
                }
            });

            let activeClinicCode = '';
            const activeClinicFilterEl = document.getElementById('activeClinicFilter');
            const clearClinicFilterBtn = document.getElementById('clearClinicFilter');

            $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
                if (settings.nTable !== qualityTableApi.table().node()) {
                    return true;
                }
                if (!activeClinicCode) {
                    return true;
                }

                const rowNode = qualityTableApi.row(dataIndex).node();
                if (!rowNode) {
                    return true;
                }

                return rowNode.getAttribute('data-clinic-code') === activeClinicCode;
            });

            const updateClinicFilterUi = (clinicName) => {
                if (activeClinicCode && clinicName && activeClinicFilterEl && clearClinicFilterBtn) {
                    activeClinicFilterEl.textContent = 'กำลังดู: ' + clinicName;
                    activeClinicFilterEl.classList.remove('hidden');
                    clearClinicFilterBtn.classList.remove('hidden');
                } else if (activeClinicFilterEl && clearClinicFilterBtn) {
                    activeClinicFilterEl.textContent = '';
                    activeClinicFilterEl.classList.add('hidden');
                    clearClinicFilterBtn.classList.add('hidden');
                }
            };

            document.querySelectorAll('.clinic-filter-btn').forEach((button) => {
                button.addEventListener('click', () => {
                    activeClinicCode = button.dataset.clinicCode || '';
                    const clinicName = button.dataset.clinicName || '';
                    updateClinicFilterUi(clinicName);
                    qualityTableApi.draw();
                    document.getElementById('qualityTable').scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            });

            if (clearClinicFilterBtn) {
                clearClinicFilterBtn.addEventListener('click', () => {
                    activeClinicCode = '';
                    updateClinicFilterUi('');
                    qualityTableApi.draw();
                });
            }
        }
    </script>
</body>
</html>
