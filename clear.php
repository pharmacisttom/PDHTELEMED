<?php
session_start();

// 1. ล้างค่า Session ทั้งหมด
$_SESSION = array();

// 2. สั่งลบ Cookie ของ Session ทิ้ง (ตั้งเวลาให้หมดอายุไปในอดีต)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. ทำลาย Session ทิ้ง
session_destroy();

// 4. พากลับไปที่หน้าเข้าสู่ระบบ
header("Location: login.php");
exit;
?>