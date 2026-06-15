<?php
include 'config.php';

// บังคับเช็คสิทธิ์: หน้านี้เข้าได้เฉพาะระดับ Admin (role_id = 1) เท่านั้น
$current_page = basename($_SERVER['PHP_SELF']); 
check_permission($current_page); 

// --- รายการไฟล์ (Pages) ที่มีในระบบทั้งหมดที่ต้องควบคุมสิทธิ์ ---
$system_pages = [
    'dashboard.php' => 'หน้าแดชบอร์ดหลัก (รายการผู้ป่วย)',
    'admin_permissions.php' => 'ระบบจัดการสิทธิ์ผู้ใช้งาน (Admin Only)',
    'api_actions.php' => 'ระบบ API เบื้องหลัง (สำหรับบันทึกข้อมูล)',
    'print_label.php' => 'หน้าพิมพ์ใบปะหน้าพัสดุ (ส่งยา)',
    'clinic_dashboard.php' => 'หน้าสถิติแยกตามคลินิก',
    'clinic_manage.php' => 'หน้าบริหารจัดการคลินิก Telemed',
    'service_quality.php' => 'หน้าตรวจสอบคุณภาพการให้บริการ Telemed',
    // ท่านสามารถเพิ่มชื่อไฟล์ใหม่ๆ ที่สร้างเพิ่มในอนาคตที่นี่ได้เลยครับ
];

// --- ประมวลผลการบันทึกสิทธิ์ (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // เริ่ม Transaction เพื่อความปลอดภัย
        $app_pdo->beginTransaction();

        // 1. ล้างสิทธิ์เก่าทิ้งทั้งหมดก่อน เพื่อลงใหม่ตามที่แอดมินติ๊ก
        $app_pdo->query("DELETE FROM role_permissions");

        // 2. บันทึกสิทธิ์ใหม่ที่ได้รับจาก Form
        if (isset($_POST['perm'])) {
            $stmt = $app_pdo->prepare("INSERT INTO role_permissions (role_id, page_name) VALUES (?, ?)");
            foreach ($_POST['perm'] as $role_id => $pages) {
                foreach ($pages as $page_name) {
                    $stmt->execute([$role_id, $page_name]);
                }
            }
        }

        $app_pdo->commit();
        $msg = "บันทึกการกำหนดสิทธิ์เรียบร้อยแล้ว!";
        $msg_type = "success";
    } catch (Exception $e) {
        $app_pdo->rollBack();
        $msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
        $msg_type = "error";
    }
}

// --- ดึงข้อมูลเพื่อนำมาแสดงในตาราง ---
// 1. ดึง Roles ทั้ง 5 ระดับ
$roles = $app_pdo->query("SELECT * FROM roles ORDER BY role_id ASC")->fetchAll();

// 2. ดึงสิทธิ์ปัจจุบันมาทำเป็น Map เพื่อเช็คใน Checkbox
$perms_data = $app_pdo->query("SELECT * FROM role_permissions")->fetchAll();
$perm_map = [];
foreach ($perms_data as $p) {
    $perm_map[$p['role_id']][] = $p['page_name'];
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>จัดการสิทธิ์ - PDH Telemed Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Sarabun', sans-serif; } </style>
</head>
<body class="bg-gray-100 min-h-screen pb-10">

    <nav class="bg-gray-800 text-white p-4 shadow-lg mb-8">
        <div class="max-w-7xl mx-auto flex justify-between items-center">
            <div class="flex items-center space-x-2">
                <span class="text-xl font-bold tracking-widest text-blue-400">ADMIN PANEL</span>
                <span class="text-gray-400">|</span>
                <span>จัดการสิทธิ์การเข้าถึง (RBAC)</span>
            </div>
            <a href="dashboard.php" class="bg-gray-700 hover:bg-gray-600 px-4 py-2 rounded-lg text-sm font-bold transition">กลับหน้า Dashboard</a>
        </div>
    </nav>

    <div class="max-w-6xl mx-auto px-4">
        
        <div class="bg-white rounded-3xl shadow-xl overflow-hidden border border-gray-200">
            <div class="p-8 bg-blue-600 text-white">
                <h2 class="text-2xl font-bold">ตารางกำหนดสิทธิ์เข้าใช้งานหน้าเว็บ</h2>
                <p class="text-blue-100 text-sm mt-1">ติ๊กเลือกหน้าที่อนุญาตให้แต่ละระดับสิทธิ์เข้าถึงได้</p>
            </div>

            <form method="POST">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-gray-50 border-b">
                            <tr>
                                <th class="p-6 text-sm font-bold text-gray-600">ชื่อหน้าเว็บ / รายละเอียด</th>
                                <?php foreach($roles as $role): ?>
                                    <th class="p-4 text-center text-xs font-bold text-gray-700 uppercase border-l bg-gray-50">
                                        <?php echo $role['role_name']; ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach($system_pages as $file => $desc): ?>
                                <tr class="hover:bg-blue-50 transition-colors">
                                    <td class="p-6">
                                        <div class="font-bold text-gray-800"><?php echo $desc; ?></div>
                                        <div class="text-xs text-gray-400 font-mono"><?php echo $file; ?></div>
                                    </td>
                                    <?php foreach($roles as $role): ?>
                                        <?php 
                                            $rid = $role['role_id'];
                                            $is_checked = (isset($perm_map[$rid]) && in_array($file, $perm_map[$rid])) ? 'checked' : '';
                                        ?>
                                        <td class="p-4 text-center border-l bg-white">
                                            <input type="checkbox" 
                                                name="perm[<?php echo $rid; ?>][]" 
                                                value="<?php echo $file; ?>" 
                                                class="w-6 h-6 text-blue-600 border-gray-300 rounded focus:ring-blue-500 cursor-pointer"
                                                <?php echo $is_checked; ?>>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="p-8 bg-gray-50 flex justify-end items-center space-x-4 border-t">
                    <span class="text-sm text-gray-500 italic">* แอดมินต้องมีสิทธิ์ในทุกหน้าเพื่อป้องกันการล็อคตัวเองออกจากระบบ</span>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-10 py-3 rounded-xl font-bold shadow-lg shadow-blue-200 transition transform hover:-translate-y-0.5">
                        บันทึกการตั้งค่าทั้งหมด
                    </button>
                </div>
            </form>
        </div>

    </div>

    <?php if(isset($msg)): ?>
    <script>
        Swal.fire({
            icon: '<?php echo $msg_type; ?>',
            title: '<?php echo $msg_type == "success" ? "สำเร็จ" : "เกิดข้อผิดพลาด"; ?>',
            text: '<?php echo $msg; ?>',
            confirmButtonText: 'รับทราบ'
        });
    </script>
    <?php endif; ?>

</body>
</html>
