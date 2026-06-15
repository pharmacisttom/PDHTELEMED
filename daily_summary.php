<?php
include 'config.php';
header('Content-Type: text/html; charset=UTF-8');

$selected_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = date('Y-m-d');
}

$selected_hns = $_GET['hns'] ?? [];
if (!is_array($selected_hns)) {
    $selected_hns = [$selected_hns];
}
$selected_hns = array_values(array_unique(array_filter(array_map('trim', $selected_hns))));
$has_selection = count($selected_hns) > 0;

try {
    $sql = "SELECT o.hn, o.fullname, o.regdate, o.timereg
            FROM opd.opd o
            INNER JOIN hos.codeinhos c ON (o.comein = c.code OR CONCAT('IN', o.comein) = c.code)
            WHERE c.code = 'IN10' AND o.regdate = :selected_date
            ORDER BY o.timereg ASC";

    $stmt = $his_pdo->prepare($sql);
    $stmt->execute(['selected_date' => $selected_date]);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt_column = $app_pdo->query("SHOW COLUMNS FROM telemed_delivery LIKE 'pickup_self'");
    if (!$stmt_column->fetch()) {
        $app_pdo->exec("ALTER TABLE telemed_delivery ADD COLUMN pickup_self TINYINT(1) NOT NULL DEFAULT 0 AFTER phone");
    }

    $stmt_trk = $app_pdo->prepare("SELECT tracking_no, followup_status, send_date FROM telemed_tracking WHERE hn = ? AND regdate = ?");
    $stmt_deliv = $app_pdo->prepare("SELECT address, phone, COALESCE(pickup_self, 0) AS pickup_self FROM telemed_delivery WHERE hn = ?");

    foreach ($patients as $key => $pt) {
        $stmt_trk->execute([$pt['hn'], $pt['regdate']]);
        $trk = $stmt_trk->fetch(PDO::FETCH_ASSOC);

        $stmt_deliv->execute([$pt['hn']]);
        $deliv = $stmt_deliv->fetch(PDO::FETCH_ASSOC);

        $patients[$key]['tracking_no'] = $trk['tracking_no'] ?? '';
        $patients[$key]['followup_status'] = $trk['followup_status'] ?? 'รอจัดส่ง';
        $patients[$key]['send_date'] = $trk['send_date'] ?? '';
        $patients[$key]['address'] = $deliv['address'] ?? '';
        $patients[$key]['phone'] = $deliv['phone'] ?? '-';
        $patients[$key]['pickup_self'] = (int)($deliv['pickup_self'] ?? 0);
        $patients[$key]['can_mail'] = $patients[$key]['pickup_self'] !== 1 && trim((string)$patients[$key]['address']) !== '';
    }
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$print_patients = array_values(array_filter($patients, function ($pt) use ($has_selection, $selected_hns) {
    if ($has_selection) {
        return in_array((string)$pt['hn'], $selected_hns, true);
    }
    return !empty($pt['can_mail']);
}));

$thai_months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
$date_parts = explode('-', $selected_date);
$thai_date_text = (int)$date_parts[2] . " " . $thai_months[(int)$date_parts[1]] . " " . ((int)$date_parts[0] + 543);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ใบนำส่งพัสดุไปรษณีย์ - <?php echo htmlspecialchars($selected_date); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background-color: #f3f4f6; }
        .page-container {
            width: 297mm;
            min-height: 210mm;
            background: white;
            margin: 20px auto;
            padding: 12mm;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .summary-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            font-size: 11px;
            line-height: 1.28;
        }
        .summary-table th,
        .summary-table td {
            border: 1px solid #111827;
            padding: 4px 5px;
            vertical-align: top;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .summary-table th {
            background: #f3f4f6;
            text-align: center;
            font-weight: 700;
        }
        @page { size: A4 landscape; margin: 8mm; }
        @media print {
            body { background-color: #ffffff; }
            .no-print { display: none !important; }
            .page-container {
                box-shadow: none !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                min-height: auto !important;
            }
            .summary-table { font-size: 10px; }
            .summary-table th { background: #f3f4f6 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>

    <div class="no-print bg-gray-900 text-white p-4 sticky top-0 z-50 shadow-md">
        <div class="max-w-7xl mx-auto flex flex-col gap-4">
            <div class="flex flex-wrap justify-between items-center gap-3">
                <div class="flex items-center gap-4">
                    <a href="tracking.php" class="text-gray-300 hover:text-white flex items-center transition">
                        <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                        กลับหน้า Tracking
                    </a>
                    <form method="GET" class="flex items-center gap-2">
                        <label class="text-sm font-bold">วันที่รับบริการ:</label>
                        <input type="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>" class="px-2 py-1 rounded text-black font-bold outline-none">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 px-3 py-1 rounded font-bold text-sm transition">ค้นหา</button>
                    </form>
                </div>
                <button onclick="window.print()" class="bg-green-500 hover:bg-green-600 px-4 py-2 rounded-lg font-bold flex items-center shadow-lg transition">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                    พิมพ์ใบนำส่ง A4
                </button>
            </div>

            <form method="GET" class="bg-white text-gray-900 rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <input type="hidden" name="date" value="<?php echo htmlspecialchars($selected_date); ?>">
                <div class="px-4 py-3 bg-gray-100 border-b border-gray-200 flex flex-wrap justify-between items-center gap-2">
                    <div class="font-bold">เลือกรายการผู้ป่วยที่จะส่งยาทางไปรษณีย์</div>
                    <div class="flex gap-2">
                        <button type="button" onclick="toggleMailChecks(true)" class="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-xs font-bold">เลือกทั้งหมด</button>
                        <button type="button" onclick="toggleMailChecks(false)" class="px-3 py-1.5 bg-gray-600 text-white rounded-lg text-xs font-bold">ล้างเลือก</button>
                        <button type="submit" class="px-3 py-1.5 bg-emerald-600 text-white rounded-lg text-xs font-bold">แสดงรายการที่เลือก</button>
                    </div>
                </div>
                <div class="max-h-72 overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-gray-50 text-gray-500">
                            <tr>
                                <th class="p-2 text-center w-12">เลือก</th>
                                <th class="p-2 text-left w-24">HN</th>
                                <th class="p-2 text-left">ชื่อ-สกุล</th>
                                <th class="p-2 text-left">เบอร์โทร</th>
                                <th class="p-2 text-left">ที่อยู่</th>
                                <th class="p-2 text-center w-32">สถานะรับยา</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($patients as $pt): ?>
                                <?php
                                    $checked = $has_selection
                                        ? in_array((string)$pt['hn'], $selected_hns, true)
                                        : !empty($pt['can_mail']);
                                    $disabled = (int)$pt['pickup_self'] === 1;
                                ?>
                                <tr class="<?php echo $disabled ? 'bg-amber-50 text-amber-900' : 'hover:bg-blue-50'; ?>">
                                    <td class="p-2 text-center">
                                        <input type="checkbox" name="hns[]" value="<?php echo htmlspecialchars($pt['hn']); ?>" class="mail-check h-4 w-4" <?php echo $checked ? 'checked' : ''; ?> <?php echo $disabled ? 'disabled' : ''; ?>>
                                    </td>
                                    <td class="p-2 font-bold text-blue-700"><?php echo htmlspecialchars($pt['hn']); ?></td>
                                    <td class="p-2 font-semibold"><?php echo decodeThai($pt['fullname']); ?></td>
                                    <td class="p-2"><?php echo htmlspecialchars($pt['phone']); ?></td>
                                    <td class="p-2 text-xs"><?php echo htmlspecialchars($pt['address'] ?: 'ยังไม่มีที่อยู่จัดส่ง'); ?></td>
                                    <td class="p-2 text-center text-xs font-bold">
                                        <?php echo $disabled ? 'มารับยาเอง' : 'ส่งไปรษณีย์'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    </div>

    <div class="page-container">
        <div class="text-center mb-4">
            <h1 class="text-xl font-bold text-gray-900 mb-1">ใบสรุปรายวัน นำส่งพัสดุยาทางไปรษณีย์ (Telemedicine)</h1>
            <h2 class="text-base font-semibold text-gray-700">กลุ่มงานเภสัชกรรมชุมชน โรงพยาบาลปลวกแดง</h2>
            <p class="text-sm font-bold text-gray-600 mt-2">
                ประจำวันที่รับบริการ: <span class="text-blue-700"><?php echo htmlspecialchars($thai_date_text); ?></span>
                (รวมทั้งสิ้น <?php echo count($print_patients); ?> รายการ)
            </p>
        </div>

        <table class="summary-table">
            <thead>
                <tr>
                    <th style="width: 34px;">ลำดับ</th>
                    <th style="width: 66px;">HN</th>
                    <th style="width: 118px;">ชื่อ-นามสกุลผู้ป่วย</th>
                    <th style="width: 82px;">เบอร์โทรศัพท์</th>
                    <th>ที่อยู่สำหรับจัดส่งยา</th>
                    <th style="width: 102px;">เลขพัสดุ<br>(Tracking)</th>
                    <th style="width: 76px;">สถานะ</th>
                    <th style="width: 90px;">หมายเหตุ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($print_patients) > 0): ?>
                    <?php foreach($print_patients as $index => $pt): ?>
                    <tr>
                        <td class="text-center"><?php echo $index + 1; ?></td>
                        <td class="text-center font-bold"><?php echo htmlspecialchars($pt['hn']); ?></td>
                        <td><?php echo decodeThai($pt['fullname']); ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($pt['phone']); ?></td>
                        <td><?php echo htmlspecialchars($pt['address'] ?: 'ยังไม่มีที่อยู่จัดส่ง'); ?></td>
                        <td class="text-center font-mono"><?php echo htmlspecialchars($pt['tracking_no']); ?></td>
                        <td class="text-center"><?php echo htmlspecialchars($pt['followup_status']); ?></td>
                        <td></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="text-center text-gray-500 font-bold" style="padding: 24px;">ไม่พบรายการที่เลือกสำหรับนำส่งไปรษณีย์</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="mt-8 flex justify-between items-end px-10 text-xs">
            <div class="text-center">
                <p class="mb-7">ผู้ส่งมอบพัสดุ (ห้องยา)</p>
                <div class="border-b border-gray-800 w-48 mx-auto mb-2"></div>
                <p>( .............................................................. )</p>
                <p class="mt-1">วันที่ ....... / ....... / ...........</p>
            </div>
            <div class="text-center">
                <p class="mb-7">ผู้รับมอบพัสดุ (งานธุรการ/สารบรรณ)</p>
                <div class="border-b border-gray-800 w-48 mx-auto mb-2"></div>
                <p>( .............................................................. )</p>
                <p class="mt-1">วันที่ ....... / ....... / ...........</p>
            </div>
        </div>
    </div>

    <script>
    function toggleMailChecks(checked) {
        document.querySelectorAll('.mail-check:not(:disabled)').forEach(input => {
            input.checked = checked;
        });
    }
    </script>
</body>
</html>
