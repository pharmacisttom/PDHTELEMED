<?php
require_once __DIR__ . '/app_version.php';
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php"); // ถ้าล็อกอินแล้วให้ข้ามไปหน้า Dashboard เลย
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - PDH Telemed</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Sarabun', sans-serif; background-color: #f0fdf4; } </style>
</head>
<body class="flex items-center justify-center h-screen bg-gradient-to-br from-blue-900 to-indigo-800 relative overflow-hidden">
    
    <div class="absolute -top-20 -left-20 w-72 h-72 bg-blue-500 rounded-full mix-blend-multiply filter blur-2xl opacity-50 animate-blob"></div>
    <div class="absolute -bottom-20 -right-20 w-72 h-72 bg-purple-500 rounded-full mix-blend-multiply filter blur-2xl opacity-50 animate-blob animation-delay-2000"></div>

    <div class="bg-white p-8 lg:p-10 rounded-3xl shadow-2xl w-full max-w-md z-10 mx-4 border border-white/20">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-blue-100 text-blue-600 mb-4">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
            </div>
            <h1 class="text-2xl lg:text-3xl font-bold text-gray-800">เข้าสู่ระบบ</h1>
            <p class="text-gray-500 mt-2 font-medium">ระบบข้อมูลจัดส่งยา Telemedicine</p>
        </div>

        <form id="loginForm" class="space-y-5">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">ชื่อผู้ใช้งาน (Username)</label>
                <input type="text" name="username" class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition" placeholder="ระบุชื่อผู้ใช้งาน" required>
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">รหัสผ่าน (Password)</label>
                <input type="password" name="password" class="w-full px-4 py-3 rounded-xl border border-gray-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition" placeholder="ระบุรหัสผ่าน" required>
            </div>
            
            <button type="submit" id="loginBtn" class="w-full bg-blue-600 text-white font-bold py-3 rounded-xl hover:bg-blue-700 shadow-lg shadow-blue-200 transition transform hover:-translate-y-0.5 mt-4 flex items-center justify-center gap-2">
                <span id="btnText">เข้าสู่ระบบ (Login)</span>
                <svg id="spinner" class="hidden w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </button>
        </form>

        <div class="mt-8 text-center text-sm">
            <p class="text-gray-600">ยังไม่มีบัญชีผู้ใช้งาน? 
                <a href="register.php" class="font-bold text-blue-600 hover:text-blue-800 transition underline">สมัครขอใช้งานระบบ</a>
            </p>
            <p class="mt-4 text-xs font-bold text-gray-400"><?php echo htmlspecialchars(APP_VERSION_LABEL, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    </div>

    <script>
    document.getElementById('loginForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const loginBtn = document.getElementById('loginBtn');
        const btnText = document.getElementById('btnText');
        const spinner = document.getElementById('spinner');
        
        // แสดง loading state
        loginBtn.disabled = true;
        btnText.textContent = 'อยู่ระหว่างการเข้าใช้งาน';
        spinner.classList.remove('hidden');
        
        try {
            const res = await fetch('auth_action.php?action=login', { method: 'POST', body: fd });
            const data = await res.json();
            
            if(data.status === 'success') {
                Swal.fire({ icon: 'success', title: 'สำเร็จ!', text: data.message, timer: 1500, showConfirmButton: false }).then(() => {
                    window.location.href = 'dashboard.php';
                });
            } else {
                Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                // รีเซ็ต button สถานะ
                loginBtn.disabled = false;
                btnText.textContent = 'เข้าสู่ระบบ (Login)';
                spinner.classList.add('hidden');
            }
        } catch (err) {
            Swal.fire('ผิดพลาด', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
            // รีเซ็ต button สถานะ
            loginBtn.disabled = false;
            btnText.textContent = 'เข้าสู่ระบบ (Login)';
            spinner.classList.add('hidden');
        }
    });
    </script>
</body>
</html>
