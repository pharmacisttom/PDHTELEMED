<?php
include 'config.php'; // เรียกใช้การเชื่อมต่อฐานข้อมูล

echo "<h2>ระบบรีเซ็ตแอดมิน - PDH Telemed</h2>";

try {
    // 1. ตั้งค่า Username และ Password ที่ต้องการ
    $username = 'admin';
    $password = '123456';
    
    // 2. ให้ PHP ทำการเข้ารหัส (Hash) รหัสผ่านให้ใหม่
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // 3. ลบแอดมินตัวเก่าที่มีปัญหาทิ้ง
    $app_pdo->exec("DELETE FROM users WHERE username = 'admin'");

    // 4. บันทึกแอดมินตัวใหม่ลงไป
    $sql = "INSERT INTO users (username, password, fullname, role_id, is_active) 
            VALUES (:user, :pass, 'ผู้ดูแลระบบสูงสุด', 1, 1)";
            
    $stmt = $app_pdo->prepare($sql);
    $stmt->execute([
        'user' => $username,
        'pass' => $hashed_password
    ]);

    echo "<p style='color: green; font-weight: bold;'>✅ สร้างบัญชีแอดมินสำเร็จแล้ว!</p>";
    echo "<ul>";
    echo "<li><b>Username:</b> {$username}</li>";
    echo "<li><b>Password:</b> {$password}</li>";
    echo "</ul>";
    echo "<br><a href='login.php' style='padding: 10px; background: blue; color: white; text-decoration: none; border-radius: 5px;'>กลับไปหน้า Login</a>";

} catch (Exception $e) {
    echo "<p style='color: red;'>❌ เกิดข้อผิดพลาด: " . $e->getMessage() . "</p>";
}
?>