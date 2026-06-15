<?php
// ไฟล์ test_db.php - สำหรับทดสอบการเชื่อมต่อฐานข้อมูลโดยเฉพาะ
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Connection Test</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Sarabun', sans-serif; background-color: #f3f4f6; } </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">

    <div class="max-w-2xl w-full bg-white rounded-2xl shadow-xl overflow-hidden border border-gray-200">
        <div class="bg-gray-800 p-6 text-center">
            <h1 class="text-2xl font-bold text-white tracking-widest">SYSTEM DIAGNOSTIC</h1>
            <p class="text-gray-300 mt-1">ระบบทดสอบการเชื่อมต่อฐานข้อมูล PDH Telemed</p>
        </div>

        <div class="p-8 space-y-8">

            <div>
                <h2 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>
                    1. ฐานข้อมูลแอปพลิเคชัน (APP DB)
                </h2>
                <div class="p-4 rounded-xl border-l-4 shadow-sm text-sm font-mono 
                <?php
                    $server_ips = ['192.168.111.240'];
                    $current_host = $_SERVER['HTTP_HOST'] ?? '';
                    $current_server_addr = $_SERVER['SERVER_ADDR'] ?? ($_SERVER['LOCAL_ADDR'] ?? '');
                    $is_server = in_array($current_server_addr, $server_ips, true)
                        || strpos($current_host, '192.168.111.240') === 0;

                    $app_db = 'pdhtawan';
                    if ($is_server) {
                        $app_host = 'localhost';
                        $app_user = 'webtomdb';
                        $app_pass = '@TOM$DataBase10832';
                    } else {
                        $app_host = '192.168.111.240';
                        $app_user = 'tomwebdbnavicat';
                        $app_pass = '@TOM$NavicatDB10832';
                    }
                    try {
                        $app_pdo = new PDO("mysql:host=$app_host;dbname=$app_db;charset=utf8mb4", $app_user, $app_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                        echo "bg-green-50 border-green-500 text-green-800'>✅ <strong>SUCCESS:</strong> เชื่อมต่อ {$app_host} (DB: {$app_db}) สำเร็จ!";
                    } catch (PDOException $e) {
                        echo "bg-red-50 border-red-500 text-red-800'>❌ <strong>FAILED:</strong> " . htmlspecialchars($e->getMessage());
                    }
                ?>
                </div>
            </div>

            <div>
                <h2 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
                    <svg class="w-5 h-5 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                    2. ฐานข้อมูลโรงพยาบาล (HIS DB)
                </h2>
                <div class="p-4 rounded-xl border-l-4 shadow-sm text-sm font-mono 
                <?php
                    $his_host = '192.168.111.251';
                    $his_db   = 'hos';
                    $his_user = 'web_ptom';
                    $his_pass = '@TOM$HimproDataBase10832';
                    try {
                        $his_pdo = new PDO("mysql:host=$his_host;dbname=$his_db;charset=tis620", $his_user, $his_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                        echo "bg-green-50 border-green-500 text-green-800'>✅ <strong>SUCCESS:</strong> เชื่อมต่อ {$his_host} (DB: {$his_db}) สำเร็จ!";
                    } catch (PDOException $e) {
                        echo "bg-red-50 border-red-500 text-red-800'>❌ <strong>FAILED:</strong> " . htmlspecialchars($e->getMessage());
                    }
                ?>
                </div>
            </div>

        </div>

        <div class="p-6 bg-gray-50 border-t flex justify-center">
            <a href="login.php" class="bg-gray-800 hover:bg-black text-white px-8 py-3 rounded-xl font-bold transition shadow-lg">
                กลับไปหน้าเข้าสู่ระบบ (Login)
            </a>
        </div>
    </div>

</body>
</html>
