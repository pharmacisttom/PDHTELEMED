<?php
include 'config.php';

$hn = $_GET['hn'] ?? '';
if (empty($hn)) die("กรุณาระบุ HN");

try {
    // 1. ดึงชื่อ-นามสกุลจาก HIS
    $stmt_his = $his_pdo->prepare("SELECT fullname FROM opd.opd WHERE hn = :hn ORDER BY regdate DESC LIMIT 1");
    $stmt_his->execute(['hn' => $hn]);
    $pt = $stmt_his->fetch();
    $patient_name = $pt ? decodeThai($pt['fullname']) : 'ไม่พบข้อมูลผู้ป่วย';

    // 2. ดึงที่อยู่จัดส่งและเบอร์โทร จาก APP DB
    $stmt_app = $app_pdo->prepare("SELECT * FROM telemed_delivery WHERE hn = :hn");
    $stmt_app->execute(['hn' => $hn]);
    $delivery = $stmt_app->fetch();

    $address = $delivery ? $delivery['address'] : 'ยังไม่ได้ระบุที่อยู่จัดส่ง';
    $phone = $delivery ? $delivery['phone'] : 'ไม่ระบุเบอร์โทร';
    $pickup_self = $delivery && isset($delivery['pickup_self']) && (int)$delivery['pickup_self'] === 1;
    if ($pickup_self) {
        $address = 'ผู้ป่วยประสงค์มารับยาเอง';
    }

    // แยกรหัสไปรษณีย์มาแสดงเด่นๆ (ถ้ามี 5 ตัวท้าย)
    $zipcode = '';
    if (preg_match('/(\d{5})$/', $address, $matches)) {
        $zipcode = $matches[1];
        $address = preg_replace('/\s*\d{5}$/', '', $address);
    }

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ใบจ่าหน้าพัสดุยา - HN: <?php echo htmlspecialchars($hn); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* บังคับให้การคำนวณ Padding ไม่ทำให้กล่องใหญ่ขึ้น (แก้ปัญหาเส้นประล้น) */
        *, *::before, *::after {
            box-sizing: border-box;
        }
        
        body { 
            font-family: 'Sarabun', sans-serif; 
            background-color: #e2e8f0; 
            margin: 0; 
            padding: 0; 
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        
        /* ตั้งค่าหน้ากระดาษ A5 แนวนอน สำหรับปริ้นท์ */
        @page { size: A5 landscape; margin: 0; }
        
        @media print {
            body { background-color: #ffffff; }
            .no-print { display: none !important; }
            .page-a5 { 
                width: 210mm !important; 
                height: 148mm !important; 
                margin: 0 !important; 
                box-shadow: none !important; 
                border: none !important;
                page-break-after: avoid;
            }
        }
        
        .page-a5 {
            width: 210mm;
            height: 148mm;
            background: white;
            margin: 20px auto;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            position: relative;
            padding: 8mm; /* ลดขอบนอกลงนิดหน่อย */
            overflow: hidden; /* ป้องกันเนื้อหาล้นออกนอกกระดาษ */
        }

        .border-dashed-cut {
            border: 2px dashed #94a3b8;
            width: 100%;
            height: 100%;
            padding: 6mm; /* ลดขอบในลงนิดหน่อย */
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border-radius: 8px;
        }
    </style>
</head>
<body>

    <div class="no-print fixed top-5 right-5 flex flex-col gap-3 z-50">
        <button onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl font-bold shadow-2xl flex items-center text-lg transition">
            <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
            พิมพ์ใบปะหน้า (A5)
        </button>
        <button onclick="window.close()" class="bg-gray-700 hover:bg-gray-800 text-white px-6 py-2 rounded-xl font-bold shadow-xl text-center transition">
            ปิดหน้าต่าง
        </button>
    </div>

    <div class="page-a5">
        <div class="border-dashed-cut">
            
            <div class="flex justify-between items-start">
                <div class="w-1/2 pr-2">
                    <div class="inline-block border-b-2 border-gray-800 mb-1 pb-1">
                        <h2 class="text-base font-extrabold text-gray-800">ผู้ส่ง (Sender)</h2>
                    </div>
                    <div class="text-[13px] text-gray-800 font-semibold leading-relaxed">
                        <p class="font-bold text-sm text-black">กลุ่มงานเภสัชกรรมชุมชนและคุ้มครองผู้บริโภค ฯ</p>
                        <p>โรงพยาบาลปลวกแดง</p>
                        <p>272 ม.1 ต.ปลวกแดง อ.ปลวกแดง</p>
                        <p>จังหวัดระยอง 21140</p>
                        <p class="flex items-center mt-1">
                            <svg class="w-4 h-4 mr-1 text-gray-600" fill="currentColor" viewBox="0 0 20 20"><path d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 5V3z"></path></svg>
                            โทร: 091-222-4932
                        </p>
                    </div>
                </div>

                <div class="border-[3px] border-red-600 text-red-600 text-lg font-black px-3 py-1 transform rotate-12 bg-white rounded-lg whitespace-nowrap shadow-sm">
                    ยารักษาโรค
                </div>

                <div class="mt-2 px-3 py-2 bg-gray-50 border-2 border-gray-300 rounded-lg text-center">
                    <p class="text-[12px] text-gray-800 leading-relaxed font-semibold">
                        <span class="block font-bold text-gray-900">ชำระค่าฝากส่งเป็นรายเดือน</span>
                        <span class="block font-bold text-gray-900">ใบอนุญาตที่ ๑๕/๒๕๔๔</span>
                        <span class="block font-bold text-gray-900">ปณ.ปลวกแดง</span>
                    </p>
                </div>
            </div>

            <div class="w-[85%] self-end mt-1 bg-blue-50/30 p-4 border-2 border-gray-800 rounded-2xl relative shadow-sm">

                <div class="inline-block border-b-2 border-gray-800 mb-2 pb-1">
                    <h1 class="text-xl font-extrabold text-gray-800">กรุณาส่ง (Recipient)</h1>
                </div>
                
                <h2 class="text-2xl font-extrabold text-black mb-1 leading-tight">
                    <?php echo htmlspecialchars($patient_name); ?>
                </h2>
                
                <p class="text-lg text-gray-800 font-bold leading-snug whitespace-pre-line mb-3">
                    <?php echo htmlspecialchars($address); ?>
                </p>

                <div class="flex justify-between items-end">
                    <div class="flex gap-1.5">
                        <?php 
                        $zip_digits = str_split(str_pad($zipcode, 5, ' ', STR_PAD_LEFT));
                        foreach($zip_digits as $digit): 
                        ?>
                            <div class="w-9 h-11 border-2 border-gray-800 flex items-center justify-center text-2xl font-black bg-gray-50 rounded shadow-sm">
                                <?php echo $digit; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="bg-gray-800 text-white px-3 py-1.5 rounded-lg text-right shadow-md">
                        <div class="text-lg font-bold flex items-center justify-end mb-0.5">
                            <svg class="w-4 h-4 mr-1.5" fill="currentColor" viewBox="0 0 20 20"><path d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 5V3z"></path></svg>
                            <?php echo htmlspecialchars($phone); ?>
                        </div>
                        <div class="text-xs font-bold text-gray-300">
                            HN: <?php echo htmlspecialchars($hn); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-center mt-2 text-gray-500 font-bold flex justify-between items-center text-[11px]">
                <span>ระบบ PDH Telemed | พิมพ์เมื่อ: <?php echo date('d/m/Y H:i'); ?></span>
                <span class="text-sm text-red-600 border-2 border-red-600 px-2 py-0.5 rounded font-black bg-red-50">โปรดระวังการกระแทก ความร้อน และความชื้น</span>
            </div>

        </div>
    </div>

</body>
</html>