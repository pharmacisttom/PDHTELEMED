<?php
include 'config.php';
header('Content-Type: text/html; charset=UTF-8');

// ==========================================
// 1. ��Ǩ�ͺ�Է����������ҹ
// ==========================================
$current_page = basename($_SERVER['PHP_SELF']); 
// check_permission($current_page); // �ҡ��ͧ����Դ���к��Է��������� // ��ҧ˹���͡��Ѻ

// ==========================================
// 2. �֧ข้อมูลผู้ป่วย Telemed ����ش 50 ��� ��� Join �Ѻ���ҧ Tracking
// ==========================================
try {
    // 2.1 �֧�����ż���Ѻ��ԡ�èҡ HIS DB (251) - ��Ѻ��ا DATE(o.regdate) ���͵Ѵ�����͡
    $sql_his = "SELECT o.hn, o.fullname, DATE(o.regdate) as clean_regdate, o.regdate as raw_regdate, o.timereg 
                FROM opd.opd o 
                INNER JOIN hos.codeinhos c ON (o.comein = c.code OR CONCAT('IN', o.comein) = c.code)
                WHERE c.code = 'IN10'
                  AND EXISTS (
                      SELECT 1
                      FROM opd.drug_order_opd d
                      WHERE d.hn = o.hn
                        AND d.regdate = DATE(o.regdate)
                  )
                ORDER BY o.regdate DESC, o.timereg DESC LIMIT 100";
    $stmt_his = $his_pdo->prepare($sql_his);
    $stmt_his->execute();
    $patients = $stmt_his->fetchAll(PDO::FETCH_ASSOC);
    $note_count_map = [];

    if (count($patients) > 0) {
        $patient_hns = array_values(array_unique(array_column($patients, 'hn')));
        $note_placeholders = implode(',', array_fill(0, count($patient_hns), '?'));
        $stmt_notes_table = $app_pdo->query("SHOW TABLES LIKE 'telemed_patient_notes'");
        if ($stmt_notes_table->fetchColumn()) {
            $stmt_notes = $app_pdo->prepare("SELECT hn, COUNT(*) AS total_notes FROM telemed_patient_notes WHERE hn IN ($note_placeholders) GROUP BY hn");
            $stmt_notes->execute($patient_hns);
            foreach ($stmt_notes->fetchAll() as $note_row) {
                $note_count_map[$note_row['hn']] = (int)$note_row['total_notes'];
            }
        }
    }

    $stmt_column = $app_pdo->query("SHOW COLUMNS FROM telemed_delivery LIKE 'pickup_self'");
    if (!$stmt_column->fetch()) {
        $app_pdo->exec("ALTER TABLE telemed_delivery ADD COLUMN pickup_self TINYINT(1) NOT NULL DEFAULT 0 AFTER phone");
    }

    $stmt_trk = $app_pdo->prepare("SELECT followup_status, tracking_no, last_sync, tracking_status FROM telemed_tracking WHERE hn = ? AND regdate = ?");
    $stmt_delivery = $app_pdo->prepare("SELECT address, phone, COALESCE(pickup_self, 0) AS pickup_self FROM telemed_delivery WHERE hn = ?");
    $stmt_drug = $his_pdo->prepare("SELECT COUNT(*) FROM opd.drug_order_opd WHERE hn = ? AND regdate = ?");

    foreach ($patients as $key => $pt) {
        $stmt_trk->execute([$pt['hn'], $pt['clean_regdate']]);
        $track_data = $stmt_trk->fetch(PDO::FETCH_ASSOC);

        $stmt_delivery->execute([$pt['hn']]);
        $delivery_data = $stmt_delivery->fetch(PDO::FETCH_ASSOC);

        $stmt_drug->execute([$pt['hn'], $pt['clean_regdate']]);
        $drug_count = (int)$stmt_drug->fetchColumn();
        $delivery_address = trim((string)($delivery_data['address'] ?? ''));
        $delivery_phone = trim((string)($delivery_data['phone'] ?? ''));
        $pickup_self = (int)($delivery_data['pickup_self'] ?? 0);

        $patients[$key]['status'] = $track_data ? $track_data['followup_status'] : 'รอจัดส่ง';
        $patients[$key]['track_no'] = $track_data ? $track_data['tracking_no'] : '-';
        $patients[$key]['last_sync'] = $track_data['last_sync'] ?? null;
        $patients[$key]['tracking_status'] = $track_data['tracking_status'] ?? null;
        $patients[$key]['drug_count'] = $drug_count;
        $patients[$key]['delivery_address'] = $delivery_address;
        $patients[$key]['delivery_phone'] = $delivery_phone;
        $patients[$key]['pickup_self'] = $pickup_self;

        if ($drug_count <= 0 || $pickup_self === 1 || $delivery_address === '') {
            unset($patients[$key]);
            continue;
        }

        $patients[$key]['note_count'] = $note_count_map[$pt['hn']] ?? 0;

        if ($patients[$key]['pickup_self'] === 1) {
            $patients[$key]['status'] = 'มารับยาเอง';
        }
    }
    $patients = array_values($patients);
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$tracking_summary = [
    'total_cases' => count($patients),
    'total_drugs' => 0,
    'with_tracking' => 0,
    'without_tracking' => 0,
    'synced' => 0,
    'pending' => 0,
    'sent' => 0,
    'received' => 0,
    'problem' => 0,
];

foreach ($patients as $pt) {
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

$delivery_completion_rate = $tracking_summary['total_cases'] > 0
    ? round(($tracking_summary['received'] / $tracking_summary['total_cases']) * 100)
    : 0;

// ==========================================
// 3. �ѧ��ѹ��˹��� Badge ʶҹ�
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
                        <p class="text-sm text-gray-500 mt-1">แสดงเฉพาะรายการที่มีรายการยาและมีที่อยู่จัดส่งแล้ว อัปเดตล่าสุด <?php echo date('d/m/Y H:i'); ?> น.</p>
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

            <div class="bg-white shadow-sm rounded-2xl overflow-hidden border border-gray-200">
                <div class="px-6 py-4 bg-gray-800 text-white font-bold flex flex-wrap gap-2 justify-between items-center text-sm lg:text-base">
                    <span>รายการส่งยาไปรษณีย์สำหรับติดตามสถานะ (สูงสุด 100 รายการล่าสุด)</span>
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
                                <?php foreach($patients as $pt): ?>
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
                            <input type="text" id="trk_no" name="tracking_no" class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 font-mono font-bold text-sm lg:text-base outline-none" placeholder="EX123456789TH">
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

    document.getElementById('trackForm').addEventListener('submit', async (e) => {
        e.preventDefault();
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
    </script>
</body>
</html>
