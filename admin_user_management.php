<?php
include 'config.php';

// ตรวจสอบสิทธิ์: อนุญาตเฉพาะ Admin (Role ID 1) เท่านั้น
if (!is_admin()) {
    echo "<div style='text-align:center; margin-top:50px; font-family:sans-serif;'>
            <h1 style='color:red;'>Access Denied</h1>
            <p>หน้าต่างนี้สำหรับผู้ดูแลระบบ (Admin) เท่านั้น</p>
            <a href='dashboard.php' style='display:inline-block; margin-top:10px; padding:10px 20px; background:#2563eb; color:white; text-decoration:none; border-radius:5px;'>กลับหน้าหลัก</a>
          </div>";
    exit;
}

try {
    // ดึงรายชื่อผู้ใช้ทั้งหมด พร้อมชื่อ Role จากฐานข้อมูล APP (240)
    $sql = "SELECT u.*, r.role_name 
            FROM users u 
            LEFT JOIN roles r ON u.role_id = r.role_id 
            ORDER BY u.is_active ASC, u.id DESC";
    $users = $app_pdo->query($sql)->fetchAll();

    // ดึงรายการ Role ทั้งหมดมาเตรียมไว้ใน Dropdown
    $roles = $app_pdo->query("SELECT * FROM roles")->fetchAll();
} catch (Exception $e) { 
    die("Error: " . $e->getMessage()); 
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการผู้ใช้งาน - PDH Telemed</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Sarabun', sans-serif; } </style>
</head>
<body class="bg-gray-100 flex h-screen overflow-hidden relative">

    <?php include 'sidebar.php'; ?>

    <div class="flex-1 flex flex-col h-screen overflow-hidden w-full">
        <header class="h-16 bg-white shadow-sm border-b flex items-center justify-between px-4 lg:px-6 z-10">
            <div class="flex items-center">
                <button onclick="toggleSidebar()" class="lg:hidden text-gray-600 hover:text-blue-600 focus:outline-none mr-4 bg-gray-100 p-2 rounded-lg">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                </button>
                <h1 class="font-bold text-gray-800 text-lg sm:text-xl">ระบบอนุมัติผู้ใช้งานใหม่</h1>
            </div>
            <div class="flex items-center gap-3">
                <span class="hidden rounded-full bg-blue-100 px-3 py-1 text-xs font-bold text-blue-700 md:inline-flex">
                    <?php echo htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <span class="text-sm font-bold text-blue-600">Admin: <?php echo htmlspecialchars($_SESSION['fullname']); ?></span>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-4 lg:p-6 custom-scrollbar">
            <div class="bg-white rounded-2xl shadow-sm border overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase text-center">ชื่อ-นามสกุล</th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase text-center">ชื่อผู้ใช้</th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase text-center">ระดับสิทธิ์ (Role)</th>
                            <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">สถานะ</th>
                            <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100 text-sm">
                        <?php foreach($users as $u): ?>
                        <tr class="hover:bg-gray-50 transition">
                            <td class="px-6 py-4 font-bold text-gray-800"><?php echo htmlspecialchars($u['fullname']); ?></td>
                            <td class="px-6 py-4 text-gray-600 text-center"><?php echo htmlspecialchars($u['username']); ?></td>
                            <td class="px-6 py-4 text-center">
                                <select onchange="updateRole(<?php echo $u['id']; ?>, this.value)" class="border rounded-md px-2 py-1 outline-none focus:ring-1 ring-blue-500 font-semibold text-gray-700">
                                    <?php foreach($roles as $r): ?>
                                    <option value="<?php echo $r['role_id']; ?>" <?php echo ($u['role_id'] == $r['role_id']) ? 'selected' : ''; ?>>
                                        <?php echo $r['role_name']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <?php if($u['is_active'] == 1): ?>
                                    <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full font-bold text-xs border border-green-200">อนุมัติแล้ว</span>
                                <?php else: ?>
                                    <span class="px-3 py-1 bg-yellow-100 text-yellow-700 rounded-full font-bold text-xs animate-pulse border border-yellow-200">รอการอนุมัติ</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <?php if($u['is_active'] == 0): ?>
                                    <button onclick="updateStatus(<?php echo $u['id']; ?>, 1)" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-bold text-xs shadow transition">อนุมัติการใช้งาน</button>
                                <?php else: ?>
                                    <button onclick="updateStatus(<?php echo $u['id']; ?>, 0)" class="text-red-500 hover:text-red-700 font-bold text-xs underline">ระงับการใช้งาน</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <script>
    async function updateStatus(id, status) {
        const fd = new FormData(); fd.append('id', id); fd.append('status', status);
        try {
            const res = await fetch('api_admin.php?action=update_status', { method: 'POST', body: fd });
            const data = await res.json();
            if(data.status === 'success') {
                Swal.fire({ icon: 'success', title: 'อัปเดตเรียบร้อย', timer: 1000, showConfirmButton: false }).then(() => location.reload());
            } else { Swal.fire('ผิดพลาด', data.message, 'error'); }
        } catch(e) { Swal.fire('ผิดพลาด', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้', 'error'); }
    }

    async function updateRole(id, roleId) {
        const fd = new FormData(); fd.append('id', id); fd.append('role_id', roleId);
        try {
            const res = await fetch('api_admin.php?action=update_role', { method: 'POST', body: fd });
            const data = await res.json();
            if(data.status === 'success') {
                Swal.fire({ icon: 'success', title: 'เปลี่ยนสิทธิ์สำเร็จ', timer: 1000, showConfirmButton: false });
            } else { Swal.fire('ผิดพลาด', data.message, 'error'); }
        } catch(e) { Swal.fire('ผิดพลาด', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้', 'error'); }
    }
    </script>
</body>
</html>
