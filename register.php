<?php require_once __DIR__ . '/app_version.php'; ?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมัครขอใช้งาน - PDH Telemed</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Sarabun', sans-serif; } </style>
</head>
<body class="flex items-center justify-center min-h-screen bg-gray-50 py-10 relative">

    <div class="bg-white p-8 lg:p-10 rounded-3xl shadow-xl w-full max-w-md z-10 mx-4 border border-gray-100">
        <div class="text-center mb-8">
            <h1 class="text-2xl lg:text-3xl font-bold text-gray-800">สมัครขอใช้งานระบบ</h1>
            <p class="text-gray-500 mt-2 font-medium">กรอกข้อมูลเพื่อสร้างบัญชีผู้ใช้งานใหม่</p>
        </div>

        <form id="registerForm" class="space-y-4">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">ชื่อ-นามสกุล (ผู้ใช้งาน) <span class="text-red-500">*</span></label>
                <input type="text" name="fullname" class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 outline-none transition" placeholder="เช่น ภก. สมชาย ใจดี" required>
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">ชื่อผู้ใช้ (Username) <span class="text-red-500">*</span></label>
                <input type="text" name="username" class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 outline-none transition" placeholder="ใช้สำหรับล็อกอิน (ภาษาอังกฤษ/ตัวเลข)" pattern="[a-zA-Z0-9]+" title="กรุณาใช้ภาษาอังกฤษและตัวเลขเท่านั้น" required>
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">รหัสผ่าน (Password) <span class="text-red-500">*</span></label>
                <input type="password" id="pwd" name="password" class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 outline-none transition" placeholder="กำหนดรหัสผ่านอย่างน้อย 6 ตัวอักษร" minlength="6" required>
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">ยืนยันรหัสผ่าน <span class="text-red-500">*</span></label>
                <input type="password" id="pwd_confirm" class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 outline-none transition" placeholder="กรอกรหัสผ่านอีกครั้ง" minlength="6" required>
            </div>
            
            <button type="submit" class="w-full bg-indigo-600 text-white font-bold py-3 rounded-xl hover:bg-indigo-700 shadow-lg shadow-indigo-200 transition transform hover:-translate-y-0.5 mt-6">
                ยืนยันการสมัครใช้งาน
            </button>
        </form>

        <div class="mt-8 text-center text-sm border-t border-gray-100 pt-6">
            <p class="text-gray-600">มีบัญชีผู้ใช้งานอยู่แล้ว? 
                <a href="login.php" class="font-bold text-indigo-600 hover:text-indigo-800 transition">กลับไปหน้าเข้าสู่ระบบ</a>
            </p>
            <p class="mt-4 text-xs font-bold text-gray-400"><?php echo htmlspecialchars(APP_VERSION_LABEL, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    </div>

    <script>
    document.getElementById('registerForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        
        // เช็ครหัสผ่านให้ตรงกันก่อนส่งไปเซิร์ฟเวอร์
        const pwd = document.getElementById('pwd').value;
        const confirm = document.getElementById('pwd_confirm').value;
        
        if (pwd !== confirm) {
            Swal.fire('แจ้งเตือน', 'รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน', 'warning');
            return;
        }

        const fd = new FormData(e.target);
        
        try {
            const res = await fetch('auth_action.php?action=register', { method: 'POST', body: fd });
            const data = await res.json();
            
            if(data.status === 'success') {
                Swal.fire({ icon: 'success', title: 'สำเร็จ!', text: data.message }).then(() => {
                    window.location.href = 'login.php';
                });
            } else {
                Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
            }
        } catch (err) {
            Swal.fire('ผิดพลาด', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
        }
    });
    </script>
</body>
</html>
