<?php
include 'config.php';
header('Content-Type: text/html; charset=UTF-8');

// ==========================================
// 1. ตรวจสอบสิทธิ์การเข้าใช้งาน
// ==========================================
$current_page = basename($_SERVER['PHP_SELF']); 
// check_permission($current_page); // เอา // ออกหากเปิดใช้งานสิทธิ์

// รับค่าปีงบประมาณที่เลือก (ค่าเริ่มต้นปีปัจจุบัน พ.ศ. 2569 / ค.ศ. 2026)
$selected_fy = isset($_GET['fy']) ? (int)$_GET['fy'] : (int)date('Y');
$start_date = ($selected_fy - 1) . "-10-01";
$end_date   = $selected_fy . "-09-30";
$selected_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = date('Y-m-d');
}

$yearly_stats = [];
$clinic_stats = [];
$daily_clinic_stats = [];
$js_daily_clinic_data = [];
$total_patients = 0;
$total_daily_visits = 0;
$total_daily_hn = 0;

try {
    // ==========================================
    // 2. ดึงข้อมูลสรุปแยกรายปีงบประมาณ (5 ปีย้อนหลัง)
    // ==========================================
    $sql_yearly = "SELECT 
                        CASE 
                            WHEN MONTH(o.regdate) >= 10 THEN YEAR(o.regdate) + 1 
                            ELSE YEAR(o.regdate) 
                        END as fy_year,
                        COUNT(DISTINCT o.hn) as total_hn,
                        COUNT(o.hn) as total_visit
                   FROM opd.opd o
                   WHERE o.comein IN ('10', 'IN10')
                   GROUP BY fy_year
                   ORDER BY fy_year DESC LIMIT 5";
                   
    $stmt_year = $his_pdo->prepare($sql_yearly);
    $stmt_year->execute();
    $yearly_stats = $stmt_year->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // 3. ดึงข้อมูลแยกรายคลินิก ตามปีงบประมาณที่เลือก
    // ==========================================
    // ⚡ [แก้ไขแล้ว] ถอด IFNULL ภาษาไทยออกจาก SQL เพื่อป้องกันข้อความเพี้ยน (TIS-620 vs UTF-8)
    $sql_clinic = "SELECT 
                        o.clinic as clinic_code,
                        cl.NAME as clinic_name,
                        COUNT(DISTINCT o.hn) as total_hn,
                        COUNT(o.hn) as total_visit
                   FROM opd.opd o
                   LEFT JOIN hos.clinic cl ON o.clinic = cl.code
                   WHERE o.comein IN ('10', 'IN10') AND o.regdate BETWEEN ? AND ?
                   GROUP BY o.clinic, cl.NAME
                   ORDER BY total_visit DESC";
                   
    $stmt_clinic = $his_pdo->prepare($sql_clinic);
    $stmt_clinic->execute([$start_date, $end_date]);
    $clinic_stats = $stmt_clinic->fetchAll(PDO::FETCH_ASSOC);

    // คำนวณยอดรวมทั้งหมดของปีงบประมาณนั้นๆ
    foreach($clinic_stats as $row) {
        $total_patients += $row['total_visit'];
    }

    // ==========================================
    // 3.1 ดึงข้อมูลแยกรายคลินิกตามวันที่เลือก
    // ==========================================
    $sql_daily_clinic = "SELECT
                            o.clinic as clinic_code,
                            cl.NAME as clinic_name,
                            COUNT(DISTINCT o.hn) as total_hn,
                            COUNT(o.hn) as total_visit
                         FROM opd.opd o
                         LEFT JOIN hos.clinic cl ON o.clinic = cl.code
                         WHERE o.comein IN ('10', 'IN10') AND o.regdate = ?
                         GROUP BY o.clinic, cl.NAME
                         ORDER BY total_visit DESC";

    $stmt_daily_clinic = $his_pdo->prepare($sql_daily_clinic);
    $stmt_daily_clinic->execute([$selected_date]);
    $daily_clinic_stats = $stmt_daily_clinic->fetchAll(PDO::FETCH_ASSOC);

    foreach ($daily_clinic_stats as $row) {
        $total_daily_visits += (int)$row['total_visit'];
        $total_daily_hn += (int)$row['total_hn'];
    }

    // ⚡ [แก้ไขแล้ว] จัดการค่าว่างและแปลงภาษาไทย (Decode) ในฝั่ง PHP แทน
    $js_clinic_data = [];
    foreach (array_slice($clinic_stats, 0, 7) as $c) {
        $c_name = !empty($c['clinic_name']) ? decodeThai($c['clinic_name']) : 'ไม่ระบุคลินิก / ทั่วไป';
        $js_clinic_data[] = [
            'clinic_name' => $c_name,
            'total_visit' => (int)$c['total_visit']
        ];
    }

    foreach (array_slice($daily_clinic_stats, 0, 10) as $c) {
        $c_name = !empty($c['clinic_name']) ? decodeThai($c['clinic_name']) : 'ไม่ระบุคลินิก / ทั่วไป';
        $js_daily_clinic_data[] = [
            'clinic_name' => $c_name,
            'total_visit' => (int)$c['total_visit'],
            'total_hn' => (int)$c['total_hn']
        ];
    }

} catch (Exception $e) {
    $db_error = "เกิดข้อผิดพลาดในการเชื่อมโยงฐานข้อมูล: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard สถิติตามคลินิก - PDH Telemed</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Sarabun', sans-serif; } 
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body class="bg-gray-100 flex h-screen overflow-hidden">

    <?php include 'sidebar.php'; ?>

    <div class="flex-1 flex flex-col h-screen overflow-hidden w-full">
        <header class="h-16 bg-white shadow-sm border-b border-gray-200 flex items-center px-6 justify-between z-10">
            <div class="flex items-center">
                <button onclick="toggleSidebar()" class="lg:hidden text-gray-600 hover:text-blue-600 focus:outline-none mr-4 bg-gray-100 p-2 rounded-lg">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                </button>
                <h1 class="font-bold text-gray-800 text-lg flex items-center">
                    <svg class="w-6 h-6 mr-2 text-indigo-600 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.003 9.003 0 1020.945 13H11V3.055z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"></path></svg>
                    รายงานสถิติแยกตามปีและคลินิก
                </h1>
                <span class="ml-3 hidden rounded-full bg-indigo-100 px-3 py-1 text-xs font-bold text-indigo-700 md:inline-flex">
                    <?php echo htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
            
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="text-sm font-bold text-gray-600 hidden sm:block">ปีงบประมาณ พ.ศ. :</label>
                <select name="fy" onchange="this.form.submit()" class="p-2 border border-gray-300 rounded-lg font-bold text-gray-700 focus:ring-2 focus:ring-indigo-500 outline-none text-sm bg-white shadow-sm">
                    <?php 
                    $current_year = (int)date('Y');
                    for($y = $current_year + 1; $y >= $current_year - 4; $y--): 
                        $th_year = $y + 543;
                    ?>
                        <option value="<?php echo $y; ?>" <?php echo $selected_fy === $y ? 'selected' : ''; ?>>
                            <?php echo $th_year; ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <label class="text-sm font-bold text-gray-600 hidden md:block ml-2">วันที่:</label>
                <input type="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>" onchange="this.form.submit()" class="p-2 border border-gray-300 rounded-lg font-bold text-gray-700 focus:ring-2 focus:ring-indigo-500 outline-none text-sm bg-white shadow-sm">
            </form>
        </header>

        <main class="flex-1 overflow-y-auto bg-gray-50 p-4 lg:p-6 custom-scrollbar">
            <?php if (isset($db_error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-xl mb-6 font-bold"><?php echo $db_error; ?></div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-gradient-to-br from-indigo-600 to-indigo-700 rounded-2xl p-6 text-white shadow-md">
                    <p class="text-sm opacity-80 font-bold">ปีงบประมาณปัจจุบันที่เลือก</p>
                    <p class="text-3xl font-black mt-2">พ.ศ. <?php echo $selected_fy + 543; ?></p>
                    <p class="text-xs opacity-60 mt-1">ช่วงวันที่: <?php echo date('d/m/Y', strtotime($start_date)); ?> ถึง <?php echo date('d/m/Y', strtotime($end_date)); ?></p>
                </div>
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm flex items-center justify-between">
                    <div>
                        <p class="text-xs text-gray-400 font-bold uppercase">จำนวนการรับบริการ (Visits) ทั้งหมดในปีนี้</p>
                        <p class="text-3xl font-black text-gray-800 mt-1"><?php echo number_format($total_patients); ?> ครั้ง</p>
                    </div>
                    <div class="h-12 w-12 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center font-bold text-xl">👥</div>
                </div>
                <div class="bg-white rounded-2xl p-6 border border-gray-200 shadow-sm flex items-center justify-between">
                    <div>
                        <p class="text-xs text-gray-400 font-bold uppercase">จำนวนคลินิกที่เปิดให้บริการ Telemed</p>
                        <p class="text-3xl font-black text-emerald-600 mt-1"><?php echo count($clinic_stats); ?> คลินิก</p>
                    </div>
                    <div class="h-12 w-12 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center font-bold text-xl">🏥</div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                    <h3 class="font-bold text-gray-800 border-b pb-3 mb-4 flex items-center gap-2 text-sm lg:text-base">
                        <span>📊</span> แนวโน้มสถิติแยกรายปีงบประมาณ (5 ปีย้อนหลัง)
                    </h3>
                    <div class="h-64 relative">
                        <canvas id="yearlyChart"></canvas>
                    </div>
                    <div class="overflow-x-auto mt-4">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left font-bold text-gray-500">ปีงบประมาณ</th>
                                    <th class="px-4 py-2 text-right font-bold text-gray-500">จำนวนผู้ป่วย (คน)</th>
                                    <th class="px-4 py-2 text-right font-bold text-gray-500">จำนวนครั้ง (Visits)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach($yearly_stats as $row): ?>
                                    <tr class="hover:bg-gray-50 <?php echo ((int)$row['fy_year'] === $selected_fy) ? 'bg-yellow-50' : ''; ?>">
                                        <td class="px-4 py-2 font-bold text-gray-700">พ.ศ. <?php echo $row['fy_year'] + 543; ?></td>
                                        <td class="px-4 py-2 text-right font-mono"><?php echo number_format($row['total_hn']); ?></td>
                                        <td class="px-4 py-2 text-right font-mono font-bold text-indigo-600"><?php echo number_format($row['total_visit']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white rounded-2xl p-5 border border-gray-200 shadow-sm">
                    <h3 class="font-bold text-gray-800 border-b pb-3 mb-4 flex items-center gap-2 text-sm lg:text-base">
                        <span>🍕</span> สัดส่วนปริมาณผู้ป่วยแยกตามคลินิก (ปีงบ <?php echo $selected_fy + 543; ?>)
                    </h3>
                    <div class="h-64 flex justify-center relative">
                        <canvas id="clinicPieChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden mb-6">
                <div class="px-6 py-4 bg-indigo-700 text-white font-bold text-sm lg:text-base flex flex-wrap items-center justify-between gap-2">
                    <span>สถิติ Telemed แยกรายคลินิกรายวัน: <?php echo date('d/m/Y', strtotime($selected_date)); ?></span>
                    <span class="text-xs bg-white/15 px-3 py-1 rounded-full">
                        รวม <?php echo number_format($total_daily_visits); ?> visits / <?php echo number_format($total_daily_hn); ?> HN
                    </span>
                </div>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 p-5">
                    <div class="h-72 relative">
                        <canvas id="dailyClinicChart"></canvas>
                    </div>
                    <div class="overflow-x-auto max-h-72 overflow-y-auto custom-scrollbar border border-gray-100 rounded-xl">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-xs font-bold text-gray-500 uppercase sticky top-0 z-10">
                                <tr>
                                    <th class="px-4 py-3 text-left">รหัส</th>
                                    <th class="px-4 py-3 text-left">คลินิก</th>
                                    <th class="px-4 py-3 text-right">HN</th>
                                    <th class="px-4 py-3 text-right">Visits</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                <?php if (count($daily_clinic_stats) > 0): ?>
                                    <?php foreach($daily_clinic_stats as $c): ?>
                                    <tr class="hover:bg-indigo-50 transition-colors">
                                        <td class="px-4 py-3 font-mono font-bold text-gray-500"><?php echo htmlspecialchars($c['clinic_code']); ?></td>
                                        <td class="px-4 py-3 font-bold text-gray-800">
                                            <?php echo !empty($c['clinic_name']) ? decodeThai($c['clinic_name']) : 'ไม่ระบุคลินิก / ทั่วไป'; ?>
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono"><?php echo number_format($c['total_hn']); ?></td>
                                        <td class="px-4 py-3 text-right font-mono font-black text-indigo-600"><?php echo number_format($c['total_visit']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="px-6 py-10 text-center text-gray-400 font-bold">ไม่พบข้อมูล Telemed ของวันที่เลือก</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 bg-gray-800 text-white font-bold text-sm lg:text-base">
                    📌 รายละเอียดข้อมูลผู้ป่วยแยกรายคลินิก ประจำปีงบประมาณ พ.ศ. <?php echo $selected_fy + 543; ?>
                </div>
                <div class="overflow-x-auto max-h-80 overflow-y-auto custom-scrollbar">
                    <table class="min-w-full divide-y divide-gray-200 text-sm lg:text-base">
                        <thead class="bg-gray-50 text-xs font-bold text-gray-500 uppercase sticky top-0 z-10">
                            <tr>
                                <th class="px-6 py-3 text-left">รหัสคลินิก</th>
                                <th class="px-6 py-3 text-left">ชื่อคลินิกการรักษา (hos.clinic)</th>
                                <th class="px-6 py-3 text-right">จำนวนผู้ป่วยไม่ซ้ำ (คน/HN)</th>
                                <th class="px-6 py-3 text-right">จำนวนการรับบริการ (ครั้ง/Visits)</th>
                                <th class="px-6 py-3 text-right">สัดส่วนร้อยละ</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            <?php if (count($clinic_stats) > 0): ?>
                                <?php foreach($clinic_stats as $c): 
                                    $pct = $total_patients > 0 ? ($c['total_visit'] / $total_patients) * 100 : 0;
                                ?>
                                <tr class="hover:bg-blue-50 transition-colors">
                                    <td class="px-6 py-4 font-mono font-bold text-gray-500"><?php echo htmlspecialchars($c['clinic_code']); ?></td>
                                    <td class="px-6 py-4 font-bold text-gray-800">
                                        <?php echo !empty($c['clinic_name']) ? decodeThai($c['clinic_name']) : 'ไม่ระบุคลินิก / ทั่วไป'; ?>
                                    </td>
                                    <td class="px-6 py-4 text-right font-mono"><?php echo number_format($c['total_hn']); ?></td>
                                    <td class="px-6 py-4 text-right font-mono font-black text-blue-600"><?php echo number_format($c['total_visit']); ?></td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="inline-block px-2 py-1 bg-gray-100 rounded text-xs font-bold font-mono text-gray-600">
                                            <?php echo number_format($pct, 1); ?>%
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="px-6 py-10 text-center text-gray-400 font-bold">⚠️ ไม่มีข้อมูลการจัดส่งหรือบริการในคิวระบบของปีงบประมาณนี้</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        // 1. วาดกราฟแยกรายปี
        const yearlyCtx = document.getElementById('yearlyChart');
        if (yearlyCtx) {
            const yearlyRaw = <?php echo json_encode(array_reverse($yearly_stats)); ?>;
            const yearlyLabels = yearlyRaw.map(r => 'พ.ศ. ' + (parseInt(r.fy_year) + 543));
            const yearlyVisits = yearlyRaw.map(r => r.total_visit);
            const yearlyHns = yearlyRaw.map(r => r.total_hn);

            new Chart(yearlyCtx, {
                type: 'bar',
                data: {
                    labels: yearlyLabels,
                    datasets: [
                        { label: 'จำนวนครั้ง (Visits)', data: yearlyVisits, backgroundColor: '#4f46e5', borderRadius: 6 },
                        { label: 'จำนวนคน (HN)', data: yearlyHns, backgroundColor: '#06b6d4', borderRadius: 6 }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' } } }
            });
        }

        // 2. วาดกราฟวงกลมแยกคลินิก
        const clinicCtx = document.getElementById('clinicPieChart');
        if (clinicCtx) {
            // รับค่าจากตัวแปร $js_clinic_data ที่ผ่านการ decode ภาษาไทยมาแล้ว
            const clinicRaw = <?php echo json_encode($js_clinic_data); ?>;
            const clinicLabels = clinicRaw.map(c => c.clinic_name);
            const clinicVisits = clinicRaw.map(c => c.total_visit);

            new Chart(clinicCtx, {
                type: 'doughnut',
                data: {
                    labels: clinicLabels.length > 0 ? clinicLabels : ['ไม่มีข้อมูล'],
                    datasets: [{
                        data: clinicVisits.length > 0 ? clinicVisits : [0],
                        backgroundColor: ['#4f46e5', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#64748b'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, 
                    cutout: '50%',
                    plugins: { 
                        legend: { 
                            position: 'right', 
                            labels: { boxWidth: 12, font: { family: 'Sarabun' } } 
                        } 
                    } 
                }
            });
        }

        const dailyClinicCtx = document.getElementById('dailyClinicChart');
        if (dailyClinicCtx) {
            const dailyClinicRaw = <?php echo json_encode($js_daily_clinic_data, JSON_UNESCAPED_UNICODE); ?>;
            const dailyClinicLabels = dailyClinicRaw.map(c => c.clinic_name);
            const dailyClinicVisits = dailyClinicRaw.map(c => c.total_visit);
            const dailyClinicHns = dailyClinicRaw.map(c => c.total_hn);

            new Chart(dailyClinicCtx, {
                type: 'bar',
                data: {
                    labels: dailyClinicLabels.length > 0 ? dailyClinicLabels : ['ไม่มีข้อมูล'],
                    datasets: [
                        {
                            label: 'Visits',
                            data: dailyClinicVisits.length > 0 ? dailyClinicVisits : [0],
                            backgroundColor: '#4f46e5',
                            borderRadius: 6
                        },
                        {
                            label: 'HN',
                            data: dailyClinicHns.length > 0 ? dailyClinicHns : [0],
                            backgroundColor: '#10b981',
                            borderRadius: 6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: {
                        legend: { position: 'top' }
                    },
                    scales: {
                        x: { beginAtZero: true, ticks: { precision: 0 } }
                    }
                }
            });
        }
    });
    </script>
</body>
</html>
