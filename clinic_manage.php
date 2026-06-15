<?php
include 'config.php';
header('Content-Type: text/html; charset=UTF-8');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$clinic_code = trim((string)($_GET['clinic'] ?? ''));
$selected_fy = isset($_GET['fy']) ? (int)$_GET['fy'] : (int)date('Y');
$start_date = ($selected_fy - 1) . '-10-01';
$end_date = $selected_fy . '-09-30';
$today = date('Y-m-d');
$range_end_date = trim((string)($_GET['date_to'] ?? $_GET['date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $range_end_date) || !DateTime::createFromFormat('Y-m-d', $range_end_date)) {
    $range_end_date = $today;
}
$range_start_date = trim((string)($_GET['date_from'] ?? date('Y-m-d', strtotime($range_end_date . ' -29 days'))));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $range_start_date) || !DateTime::createFromFormat('Y-m-d', $range_start_date)) {
    $range_start_date = date('Y-m-d', strtotime($range_end_date . ' -29 days'));
}
if ($range_start_date > $range_end_date) {
    [$range_start_date, $range_end_date] = [$range_end_date, $range_start_date];
}
if ((strtotime($range_end_date) - strtotime($range_start_date)) / 86400 > 365) {
    $range_start_date = date('Y-m-d', strtotime($range_end_date . ' -365 days'));
}
$range_day_count = (int)((strtotime($range_end_date) - strtotime($range_start_date)) / 86400) + 1;
$db_error = null;
$clinic_name = '';
$patients = [];
$total_visits = 0;
$total_hn = 0;
$selected_date_visits = 0;
$selected_date_hn = 0;
$drug_items = 0;
$drug_patients = 0;
$recorded_statuses = 0;
$trend_labels = [];
$trend_visits = [];
$trend_hn = [];
$status_chart_labels = [];
$status_chart_values = [];
$status_map = [];
$clinic_status_options = [];
$status_setup_required = false;
$status_labels = [
    'discharge' => 'กลับบ้าน',
    'home_delivery' => 'รับยาที่บ้าน',
    'self_pickup' => 'รับยาเอง',
    'other' => 'อื่นๆ'
];
$status_styles = [
    'discharge' => 'bg-slate-100 text-slate-700',
    'home_delivery' => 'bg-blue-100 text-blue-700',
    'self_pickup' => 'bg-amber-100 text-amber-800',
    'other' => 'bg-violet-100 text-violet-700'
];

if ($clinic_code === '' || !preg_match('/^[A-Za-z0-9_-]{1,20}$/', $clinic_code)) {
    render_access_denied('ไม่พบรหัสคลินิกที่ต้องการเปิดใช้งาน');
}

try {
    $stmt_clinic = $his_pdo->prepare('SELECT NAME FROM hos.clinic WHERE code = ? LIMIT 1');
    $stmt_clinic->execute([$clinic_code]);
    $clinic_raw_name = $stmt_clinic->fetchColumn();
    $clinic_name = $clinic_raw_name ? decodeThai($clinic_raw_name) : 'คลินิก ' . $clinic_code;

    $stmt_options_table = $app_pdo->query("SHOW TABLES LIKE 'telemed_clinic_status_options'");
    if ($stmt_options_table->fetchColumn()) {
        $stmt_options = $app_pdo->prepare(
            "SELECT id, status_name
             FROM telemed_clinic_status_options
             WHERE clinic_code = ? AND is_active = 1
             ORDER BY id ASC"
        );
        $stmt_options->execute([$clinic_code]);
        $clinic_status_options = $stmt_options->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $status_setup_required = true;
    }

    $stmt_summary = $his_pdo->prepare(
        "SELECT COUNT(*) AS total_visits,
                COUNT(DISTINCT o.hn) AS total_hn
         FROM opd.opd o
         WHERE o.comein IN ('10', 'IN10')
           AND o.clinic = ?
           AND o.regdate BETWEEN ? AND ?"
    );
    $stmt_summary->execute([$clinic_code, $start_date, $end_date]);
    $summary = $stmt_summary->fetch(PDO::FETCH_ASSOC) ?: [];
    $total_visits = (int)($summary['total_visits'] ?? 0);
    $total_hn = (int)($summary['total_hn'] ?? 0);

    $stmt_trend = $his_pdo->prepare(
        "SELECT o.regdate AS service_date,
                COUNT(*) AS total_visits,
                COUNT(DISTINCT o.hn) AS total_hn
         FROM opd.opd o
         WHERE o.comein IN ('10', 'IN10')
           AND o.clinic = ?
           AND o.regdate BETWEEN ? AND ?
         GROUP BY o.regdate
         ORDER BY o.regdate ASC"
    );
    $stmt_trend->execute([$clinic_code, $range_start_date, $range_end_date]);
    $trend_map = [];
    foreach ($stmt_trend->fetchAll(PDO::FETCH_ASSOC) as $trend_row) {
        $trend_map[$trend_row['service_date']] = $trend_row;
    }
    for ($offset = 0; $offset < $range_day_count; $offset++) {
        $trend_date = date('Y-m-d', strtotime($range_start_date . " +{$offset} days"));
        $trend_labels[] = date('d/m', strtotime($trend_date));
        $trend_visits[] = (int)($trend_map[$trend_date]['total_visits'] ?? 0);
        $trend_hn[] = (int)($trend_map[$trend_date]['total_hn'] ?? 0);
    }

    $stmt_patients = $his_pdo->prepare(
        "SELECT o.hn, o.fullname, o.regdate, o.timereg
         FROM opd.opd o
         WHERE o.comein IN ('10', 'IN10')
           AND o.clinic = ?
           AND o.regdate BETWEEN ? AND ?
         ORDER BY o.regdate DESC, o.timereg DESC
         LIMIT 1000"
    );
    $stmt_patients->execute([$clinic_code, $range_start_date, $range_end_date]);
    $patients = $stmt_patients->fetchAll(PDO::FETCH_ASSOC);
    $selected_date_visits = count($patients);
    $selected_date_hn = count(array_unique(array_column($patients, 'hn')));

    if (count($patients) > 0) {
        $patient_hns = array_values(array_unique(array_column($patients, 'hn')));
        $patient_placeholders = implode(',', array_fill(0, count($patient_hns), '?'));
        $diagnosis_map = [];
        $drug_count_map = [];

        $stmt_diagnosis = $his_pdo->prepare(
            "SELECT hn, regdate, GROUP_CONCAT(DISTINCT diag ORDER BY diag SEPARATOR ', ') AS diagnosis_codes
             FROM opd.odiag
             WHERE regdate BETWEEN ? AND ? AND hn IN ($patient_placeholders)
             GROUP BY hn, regdate"
        );
        $stmt_diagnosis->execute(array_merge([$range_start_date, $range_end_date], $patient_hns));
        foreach ($stmt_diagnosis->fetchAll(PDO::FETCH_ASSOC) as $diagnosis_row) {
            $diagnosis_map[$diagnosis_row['hn'] . '|' . $diagnosis_row['regdate']] = $diagnosis_row['diagnosis_codes'];
        }

        $stmt_drug_counts = $his_pdo->prepare(
            "SELECT hn, regdate, COUNT(*) AS drug_count
             FROM opd.drug_order_opd
             WHERE regdate BETWEEN ? AND ? AND hn IN ($patient_placeholders)
             GROUP BY hn, regdate"
        );
        $stmt_drug_counts->execute(array_merge([$range_start_date, $range_end_date], $patient_hns));
        foreach ($stmt_drug_counts->fetchAll(PDO::FETCH_ASSOC) as $drug_row) {
            $drug_count_map[$drug_row['hn'] . '|' . $drug_row['regdate']] = (int)$drug_row['drug_count'];
        }

        foreach ($patients as &$patient) {
            $patient_key = $patient['hn'] . '|' . $patient['regdate'];
            $patient['diagnosis_codes'] = $diagnosis_map[$patient_key] ?? '';
            $patient['drug_count'] = $drug_count_map[$patient_key] ?? 0;
        }
        unset($patient);
        $drug_items = array_sum($drug_count_map);
        $drug_patients = count(array_filter($drug_count_map, static function ($count) {
            return $count > 0;
        }));

        $stmt_status_table = $app_pdo->query("SHOW TABLES LIKE 'telemed_patient_status'");
        if ($stmt_status_table->fetchColumn()) {
            $stmt_status = $app_pdo->prepare(
                "SELECT hn, regdate, status_type, status_detail
                 FROM telemed_patient_status
                 WHERE hn IN ($patient_placeholders)
                   AND regdate BETWEEN ? AND ?"
            );
            $stmt_status->execute(array_merge($patient_hns, [$range_start_date, $range_end_date]));
            foreach ($stmt_status->fetchAll(PDO::FETCH_ASSOC) as $status_row) {
                $status_map[$status_row['hn'] . '|' . $status_row['regdate']] = $status_row;
            }
        } else {
            $status_setup_required = true;
        }
    }

    $status_counts = [];
    foreach ($patients as $patient) {
        $patient_status = $status_map[$patient['hn'] . '|' . $patient['regdate']] ?? null;
        if (!$patient_status) {
            continue;
        }
        $recorded_statuses++;
        $chart_status_type = $patient_status['status_type'] ?? '';
        $chart_status_detail = trim((string)($patient_status['status_detail'] ?? ''));
        $chart_status_label = $status_labels[$chart_status_type] ?? ($chart_status_detail !== '' ? $chart_status_detail : 'อื่นๆ');
        if ($chart_status_type === 'other' && $chart_status_detail !== '') {
            $chart_status_label = 'อื่นๆ: ' . $chart_status_detail;
        }
        $status_counts[$chart_status_label] = ($status_counts[$chart_status_label] ?? 0) + 1;
    }
    $status_chart_labels = array_keys($status_counts);
    $status_chart_values = array_values($status_counts);
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($clinic_name, ENT_QUOTES, 'UTF-8'); ?> - PDH Telemed</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .dataTables_wrapper { padding: 1rem; }
        .dataTables_wrapper .dataTables_filter input,
        .dataTables_wrapper .dataTables_length select {
            border: 1px solid #cbd5e1; border-radius: .5rem;
            padding: .35rem .55rem; margin-left: .5rem;
        }
    </style>
</head>
<body class="flex h-screen overflow-hidden bg-gray-100">
    <?php include 'sidebar.php'; ?>

    <div class="flex h-screen w-full flex-1 flex-col overflow-hidden">
        <header class="z-10 flex min-h-16 items-center justify-between gap-4 border-b border-gray-200 bg-white px-4 py-3 shadow-sm lg:px-6">
            <div class="flex min-w-0 items-center">
                <button onclick="toggleSidebar()" class="mr-4 rounded-lg bg-gray-100 p-2 text-gray-600 hover:text-blue-600 lg:hidden">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                </button>
                <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-wide text-blue-600">บริหารจัดการคลินิก Telemed</p>
                    <h1 class="truncate text-lg font-bold text-gray-800"><?php echo htmlspecialchars($clinic_name, ENT_QUOTES, 'UTF-8'); ?></h1>
                </div>
                <span class="ml-3 hidden rounded-full bg-blue-100 px-3 py-1 text-xs font-bold text-blue-700 md:inline-flex">
                    <?php echo htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
            <form method="get" class="flex flex-wrap items-center justify-end gap-2">
                <input type="hidden" name="clinic" value="<?php echo htmlspecialchars($clinic_code, ENT_QUOTES, 'UTF-8'); ?>">
                <label class="hidden text-sm font-bold text-gray-600 md:block">ตั้งแต่</label>
                <input id="dateFrom" type="date" name="date_from" value="<?php echo htmlspecialchars($range_start_date, ENT_QUOTES, 'UTF-8'); ?>"
                       class="rounded-lg border border-gray-300 bg-white p-2 text-sm font-bold">
                <label class="hidden text-sm font-bold text-gray-600 md:block">ถึง</label>
                <input id="dateTo" type="date" name="date_to" value="<?php echo htmlspecialchars($range_end_date, ENT_QUOTES, 'UTF-8'); ?>"
                       class="rounded-lg border border-gray-300 bg-white p-2 text-sm font-bold">
                <label class="hidden text-sm font-bold text-gray-600 md:block">ปีงบประมาณ</label>
                <select name="fy" onchange="this.form.submit()" class="rounded-lg border border-gray-300 bg-white p-2 text-sm font-bold">
                    <?php for ($year = (int)date('Y') + 1; $year >= (int)date('Y') - 4; $year--): ?>
                        <option value="<?php echo $year; ?>" <?php echo $selected_fy === $year ? 'selected' : ''; ?>><?php echo $year + 543; ?></option>
                    <?php endfor; ?>
                </select>
                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">ค้นหา</button>
            </form>
        </header>

        <main class="custom-scrollbar flex-1 overflow-y-auto bg-gray-50 p-4 lg:p-6">
            <?php if ($db_error): ?>
                <div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 font-bold text-red-700"><?php echo htmlspecialchars($db_error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($status_setup_required): ?>
                <div class="mb-5 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-800">
                    <p class="font-bold">ระบบสถานะยังติดตั้งไม่ครบ</p>
                    <p class="mt-1 text-sm">กรุณาให้ผู้ดูแลฐานข้อมูลรันไฟล์ <code class="rounded bg-amber-100 px-1.5 py-0.5">migrate_clinic_statuses.sql</code> ด้วยบัญชีที่มีสิทธิ์ CREATE/ALTER หนึ่งครั้ง</p>
                </div>
            <?php endif; ?>

            <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-blue-100 bg-blue-50 p-3">
                <span class="mr-1 text-sm font-bold text-blue-800">ช่วงลัด:</span>
                <button type="button" onclick="setQuickRange(0)" class="rounded-lg bg-white px-3 py-1.5 text-xs font-bold text-blue-700 shadow-sm hover:bg-blue-100">วันนี้</button>
                <button type="button" onclick="setQuickRange(6)" class="rounded-lg bg-white px-3 py-1.5 text-xs font-bold text-blue-700 shadow-sm hover:bg-blue-100">7 วัน</button>
                <button type="button" onclick="setQuickRange(29)" class="rounded-lg bg-white px-3 py-1.5 text-xs font-bold text-blue-700 shadow-sm hover:bg-blue-100">30 วัน</button>
                <button type="button" onclick="setCurrentMonth()" class="rounded-lg bg-white px-3 py-1.5 text-xs font-bold text-blue-700 shadow-sm hover:bg-blue-100">เดือนนี้</button>
            </div>

            <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-700 p-5 text-white shadow">
                    <p class="text-sm font-bold text-blue-100">รับบริการในช่วงที่เลือก</p>
                    <p class="mt-2 text-3xl font-black"><?php echo number_format($selected_date_visits); ?> <span class="text-base">ครั้ง</span></p>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-bold text-gray-500">ผู้ป่วยไม่ซ้ำในช่วงที่เลือก</p>
                    <p class="mt-2 text-3xl font-black text-emerald-600"><?php echo number_format($selected_date_hn); ?> <span class="text-base">HN</span></p>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-bold text-gray-500">ผู้ป่วยที่มีรายการยา</p>
                    <p class="mt-2 text-3xl font-black text-amber-600"><?php echo number_format($drug_patients); ?> <span class="text-base">HN</span></p>
                    <p class="mt-1 text-xs text-gray-400"><?php echo number_format($drug_items); ?> รายการยา</p>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-bold text-gray-500">บันทึกสถานะแล้ว</p>
                    <p class="mt-2 text-3xl font-black text-violet-600"><?php echo number_format($recorded_statuses); ?> <span class="text-base">รายการ</span></p>
                    <p class="mt-1 text-xs text-gray-400">จาก <?php echo number_format(count($patients)); ?> รายการบริการ</p>
                </div>
            </div>

            <div class="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm xl:col-span-2">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                        <h2 class="font-bold text-gray-800">แนวโน้มการให้บริการตามช่วงวันที่</h2>
                        <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">
                            <?php echo date('d/m/Y', strtotime($range_start_date)); ?> - <?php echo date('d/m/Y', strtotime($range_end_date)); ?>
                        </span>
                    </div>
                    <div class="h-72"><canvas id="serviceTrendChart"></canvas></div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="mb-4 font-bold text-gray-800">สัดส่วนสถานะในช่วงที่เลือก</h2>
                    <div class="h-64"><canvas id="statusChart"></canvas></div>
                    <?php if ($recorded_statuses === 0): ?>
                        <p class="mt-2 text-center text-sm text-gray-400">ยังไม่มีการบันทึกสถานะ</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-bold text-gray-500">ผู้ป่วยไม่ซ้ำสะสม ปีงบ <?php echo $selected_fy + 543; ?></p>
                    <p class="mt-2 text-2xl font-black text-emerald-600"><?php echo number_format($total_hn); ?> HN</p>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-bold text-gray-500">บริการสะสม ปีงบ <?php echo $selected_fy + 543; ?></p>
                    <p class="mt-2 text-2xl font-black text-blue-600"><?php echo number_format($total_visits); ?> ครั้ง</p>
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 bg-gray-800 px-5 py-4 font-bold text-white">
                    <span>ข้อมูลบริการ <?php echo date('d/m/Y', strtotime($range_start_date)); ?> - <?php echo date('d/m/Y', strtotime($range_end_date)); ?> - <?php echo htmlspecialchars($clinic_name, ENT_QUOTES, 'UTF-8'); ?></span>
                    <div class="flex items-center gap-2">
                        <span class="rounded-full bg-white/10 px-3 py-1 text-xs"><?php echo number_format(count($patients)); ?> รายการล่าสุด</span>
                        <button type="button" onclick="addClinicStatus()" <?php echo $status_setup_required ? 'disabled' : ''; ?>
                                class="rounded-lg bg-emerald-500 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-50">
                            + เพิ่มสถานะ
                        </button>
                    </div>
                </div>
                <div class="custom-scrollbar overflow-x-auto">
                    <table id="clinicPatientsTable" class="w-full min-w-[1180px] divide-y divide-gray-200">
                        <thead class="bg-gray-50 text-xs font-bold uppercase text-gray-500">
                            <tr>
                                <th class="px-4 py-3 text-left">วันที่-เวลา</th>
                                <th class="px-4 py-3 text-left">HN</th>
                                <th class="px-4 py-3 text-left">ชื่อ-นามสกุล</th>
                                <th class="px-4 py-3 text-left">วินิจฉัย</th>
                                <th class="px-4 py-3 text-center">รายการยา</th>
                                <th class="px-4 py-3 text-center">สถานะ</th>
                                <th class="px-4 py-3 text-center">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            <?php foreach ($patients as $patient):
                                $status = $status_map[$patient['hn'] . '|' . $patient['regdate']] ?? null;
                                $status_type = $status['status_type'] ?? '';
                                $status_detail = $status['status_detail'] ?? '';
                                $status_label = $status_labels[$status_type] ?? ($status_detail !== '' ? $status_detail : 'ยังไม่ระบุ');
                                if ($status_type === 'other' && $status_detail !== '') {
                                    $status_label .= ': ' . $status_detail;
                                }
                            ?>
                            <tr class="hover:bg-blue-50">
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-600">
                                    <?php echo htmlspecialchars($patient['regdate'], ENT_QUOTES, 'UTF-8'); ?><br>
                                    <span class="text-gray-400"><?php echo htmlspecialchars($patient['timereg'], ENT_QUOTES, 'UTF-8'); ?> น.</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 font-bold text-blue-700"><?php echo htmlspecialchars($patient['hn'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="px-4 py-3 font-semibold text-gray-800"><?php echo htmlspecialchars(decodeThai($patient['fullname']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="px-4 py-3 text-xs text-gray-600">
                                    <?php echo !empty($patient['diagnosis_codes']) ? htmlspecialchars($patient['diagnosis_codes'], ENT_QUOTES, 'UTF-8') : '-'; ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="rounded-lg bg-blue-50 px-2 py-1 text-xs font-bold text-blue-700">
                                        <?php echo number_format((int)$patient['drug_count']); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-block rounded-lg px-3 py-1.5 text-xs font-bold <?php echo $status_styles[$status_type] ?? (strpos($status_type, 'clinic_') === 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500'); ?>">
                                        <?php echo htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex flex-wrap items-center justify-center gap-2">
                                    <button type="button" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white hover:bg-blue-700"
                                            data-hn="<?php echo htmlspecialchars($patient['hn'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-regdate="<?php echo htmlspecialchars($patient['regdate'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-fullname="<?php echo htmlspecialchars(decodeThai($patient['fullname']), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-status="<?php echo htmlspecialchars($status_type, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-detail="<?php echo htmlspecialchars($status_detail, ENT_QUOTES, 'UTF-8'); ?>"
                                            onclick="editPatientStatus(this)">แก้ไขสถานะ</button>
                                    <button type="button" class="rounded-lg bg-slate-700 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800"
                                            data-hn="<?php echo htmlspecialchars($patient['hn'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-regdate="<?php echo htmlspecialchars($patient['regdate'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-fullname="<?php echo htmlspecialchars(decodeThai($patient['fullname']), ENT_QUOTES, 'UTF-8'); ?>"
                                            onclick="setDischargeStatus(this)">ส่งกลับบ้าน</button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script>
    const clinicCode = <?php echo json_encode($clinic_code, JSON_UNESCAPED_UNICODE); ?>;
    const clinicStatusOptions = <?php echo json_encode($clinic_status_options, JSON_UNESCAPED_UNICODE); ?>;
    const trendLabels = <?php echo json_encode($trend_labels, JSON_UNESCAPED_UNICODE); ?>;
    const trendVisits = <?php echo json_encode($trend_visits); ?>;
    const trendHn = <?php echo json_encode($trend_hn); ?>;
    const statusChartLabels = <?php echo json_encode($status_chart_labels, JSON_UNESCAPED_UNICODE); ?>;
    const statusChartValues = <?php echo json_encode($status_chart_values); ?>;
    const lineValueLabels = {
        id: 'lineValueLabels',
        afterDatasetsDraw: function (chart) {
            const ctx = chart.ctx;
            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'bottom';
            ctx.font = 'bold 11px Sarabun';
            chart.data.datasets.forEach(function (dataset, datasetIndex) {
                const meta = chart.getDatasetMeta(datasetIndex);
                meta.data.forEach(function (point, index) {
                    const value = Number(dataset.data[index] || 0);
                    if (value <= 0) return;
                    ctx.fillStyle = dataset.borderColor;
                    ctx.fillText(String(value), point.x, point.y - (datasetIndex === 0 ? 8 : 22));
                });
            });
            ctx.restore();
        }
    };
    const doughnutValueLabels = {
        id: 'doughnutValueLabels',
        afterDatasetsDraw: function (chart) {
            if (!statusChartValues.length) return;
            const ctx = chart.ctx;
            const meta = chart.getDatasetMeta(0);
            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.font = 'bold 12px Sarabun';
            ctx.fillStyle = '#ffffff';
            meta.data.forEach(function (arc, index) {
                const value = Number(chart.data.datasets[0].data[index] || 0);
                if (value <= 0) return;
                const position = arc.tooltipPosition();
                ctx.fillText(String(value), position.x, position.y);
            });
            ctx.restore();
        }
    };

    function formatLocalDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    function submitDateRange(fromDate, toDate) {
        document.getElementById('dateFrom').value = formatLocalDate(fromDate);
        document.getElementById('dateTo').value = formatLocalDate(toDate);
        document.getElementById('dateTo').form.submit();
    }

    function setQuickRange(daysBack) {
        const end = new Date();
        const start = new Date();
        start.setDate(end.getDate() - daysBack);
        submitDateRange(start, end);
    }

    function setCurrentMonth() {
        const end = new Date();
        const start = new Date(end.getFullYear(), end.getMonth(), 1);
        submitDateRange(start, end);
    }

    document.addEventListener('DOMContentLoaded', function () {
        const trendCanvas = document.getElementById('serviceTrendChart');
        if (trendCanvas && window.Chart) {
            new Chart(trendCanvas, {
                type: 'line',
                data: {
                    labels: trendLabels,
                    datasets: [
                        {
                            label: 'จำนวนครั้งรับบริการ',
                            data: trendVisits,
                            borderColor: '#2563eb',
                            backgroundColor: 'rgba(37, 99, 235, 0.12)',
                            fill: true,
                            tension: 0.3,
                            pointRadius: 2
                        },
                        {
                            label: 'ผู้ป่วยไม่ซ้ำ (HN)',
                            data: trendHn,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.08)',
                            tension: 0.3,
                            pointRadius: 2
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: { top: 28 } },
                    interaction: { mode: 'index', intersect: false },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                },
                plugins: [lineValueLabels]
            });
        }

        const statusCanvas = document.getElementById('statusChart');
        if (statusCanvas && window.Chart) {
            new Chart(statusCanvas, {
                type: 'doughnut',
                data: {
                    labels: statusChartLabels.length ? statusChartLabels : ['ยังไม่บันทึกสถานะ'],
                    datasets: [{
                        data: statusChartValues.length ? statusChartValues : [1],
                        backgroundColor: statusChartValues.length
                            ? ['#64748b', '#3b82f6', '#f59e0b', '#8b5cf6', '#10b981', '#ec4899', '#ef4444']
                            : ['#e5e7eb'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '58%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { boxWidth: 12, font: { family: 'Sarabun' } }
                        }
                    }
                },
                plugins: [doughnutValueLabels]
            });
        }

        if (window.jQuery && $.fn.DataTable) {
            $('#clinicPatientsTable').DataTable({
                pageLength: 25,
                order: [],
                columnDefs: [{ orderable: false, targets: [5, 6] }],
                language: {
                    search: 'ค้นหา:', lengthMenu: 'แสดง _MENU_ รายการ',
                    info: 'แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ',
                    infoEmpty: 'ไม่มีข้อมูล', zeroRecords: 'ไม่พบข้อมูลที่ค้นหา',
                    paginate: { previous: 'ก่อนหน้า', next: 'ถัดไป' }
                }
            });
        }
    });

    function escapeHtml(value) {
        const element = document.createElement('div');
        element.textContent = value;
        return element.innerHTML;
    }

    async function savePatientStatus(hn, regdate, statusType, detail) {
        const formData = new FormData();
        formData.append('hn', hn);
        formData.append('regdate', regdate);
        formData.append('status_type', statusType);
        formData.append('status_detail', detail || '');
        formData.append('clinic_code', clinicCode);

        const response = await fetch('api_telemed.php?action=save_patient_status', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        if (data.status !== 'success') {
            throw new Error(data.message || 'ไม่สามารถบันทึกสถานะได้');
        }
        return data;
    }

    async function setDischargeStatus(button) {
        const result = await Swal.fire({
            icon: 'question',
            title: 'ยืนยันส่งกลับบ้าน',
            html: 'ต้องการเปลี่ยนสถานะของ<br><b>' + escapeHtml(button.dataset.fullname) + '</b><br>เป็น “กลับบ้าน” หรือไม่?',
            showCancelButton: true,
            confirmButtonText: 'ยืนยันส่งกลับบ้าน',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#334155'
        });
        if (!result.isConfirmed) return;

        button.disabled = true;
        try {
            await savePatientStatus(button.dataset.hn, button.dataset.regdate, 'discharge', '');
            await Swal.fire({
                icon: 'success',
                title: 'แก้ไขเป็นส่งกลับบ้านแล้ว',
                timer: 1000,
                showConfirmButton: false
            });
            window.location.reload();
        } catch (error) {
            button.disabled = false;
            Swal.fire('ผิดพลาด', error.message, 'error');
        }
    }

    async function addClinicStatus() {
        const result = await Swal.fire({
            title: 'เพิ่มสถานะของคลินิก',
            input: 'text',
            inputLabel: 'ชื่อสถานะ',
            inputPlaceholder: 'เช่น รอติดตามอาการ, นัดตรวจซ้ำ',
            inputAttributes: { maxlength: 100 },
            showCancelButton: true,
            confirmButtonText: 'เพิ่มสถานะ',
            cancelButtonText: 'ยกเลิก',
            inputValidator: function (value) {
                if (!value || !value.trim()) return 'กรุณาระบุชื่อสถานะ';
            }
        });
        if (!result.isConfirmed) return;

        const formData = new FormData();
        formData.append('clinic_code', clinicCode);
        formData.append('status_name', result.value.trim());

        try {
            const response = await fetch('api_telemed.php?action=add_clinic_status', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.status !== 'success') throw new Error(data.message || 'ไม่สามารถเพิ่มสถานะได้');
            await Swal.fire({
                icon: 'success',
                title: 'เพิ่มสถานะแล้ว',
                text: data.status_name,
                timer: 1200,
                showConfirmButton: false
            });
            window.location.reload();
        } catch (error) {
            Swal.fire('ผิดพลาด', error.message, 'error');
        }
    }

    async function editPatientStatus(button) {
        const customOptionsHtml = clinicStatusOptions.map(function (option) {
            return '<option value="clinic_' + option.id + '">' + escapeHtml(option.status_name) + '</option>';
        }).join('');
        const result = await Swal.fire({
            title: 'แก้ไขสถานะผู้ป่วย',
            html: '<div class="mb-3 text-sm text-gray-600">HN: ' + escapeHtml(button.dataset.hn) + ' | ' + escapeHtml(button.dataset.fullname) + '</div>' +
                '<select id="clinicStatusType" class="swal2-input"><option value="">เลือกสถานะ</option><option value="discharge">กลับบ้าน</option><option value="home_delivery">รับยาที่บ้าน</option><option value="self_pickup">รับยาเอง</option>' +
                customOptionsHtml + '<option value="other">อื่นๆ</option></select>' +
                '<input id="clinicStatusDetail" class="swal2-input" maxlength="255" placeholder="ระบุรายละเอียด กรณีเลือกอื่นๆ">',
            showCancelButton: true,
            confirmButtonText: 'บันทึก',
            cancelButtonText: 'ยกเลิก',
            didOpen: function () {
                document.getElementById('clinicStatusType').value = button.dataset.status || '';
                document.getElementById('clinicStatusDetail').value = button.dataset.detail || '';
            },
            preConfirm: function () {
                const statusType = document.getElementById('clinicStatusType').value;
                const detail = document.getElementById('clinicStatusDetail').value.trim();
                if (!statusType) {
                    Swal.showValidationMessage('กรุณาเลือกสถานะ');
                    return false;
                }
                if (statusType === 'other' && !detail) {
                    Swal.showValidationMessage('กรุณาระบุรายละเอียด');
                    return false;
                }
                return { statusType: statusType, detail: detail };
            }
        });
        if (!result.isConfirmed) return;

        try {
            await savePatientStatus(
                button.dataset.hn,
                button.dataset.regdate,
                result.value.statusType,
                result.value.detail
            );
            await Swal.fire({ icon: 'success', title: 'บันทึกสถานะแล้ว', timer: 1000, showConfirmButton: false });
            window.location.reload();
        } catch (error) {
            Swal.fire('ผิดพลาด', error.message, 'error');
        }
    }
    </script>
</body>
</html>
