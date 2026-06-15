<?php
/**
 * ไฟล์ index.php - จุดเริ่มต้นของระบบ pdhtelemed
 * พัฒนาโดย: Phartomcodephp (Expert HIS Developer)
 */

// 1. นำเข้าไฟล์ตั้งค่าระบบและเริ่ม Session
require_once 'config.php';

// 2. ตรวจสอบสถานะการล็อกอิน
if (!isset($_SESSION['user_id'])) {
    // กรณีที่ยังไม่ได้เข้าสู่ระบบ -> ส่งไปหน้า Login
    header("Location: login.php");
    exit();
} else {
    // กรณีที่เข้าสู่ระบบแล้ว -> ส่งไปหน้า Dashboard
    header("Location: dashboard.php");
    exit();
}

/**
 * หมายเหตุ: 
 * ไฟล์นี้ไม่ต้องมีการแสดงผล HTML เพราะทำหน้าที่เพียงแค่ Redirect (เปลี่ยนเส้นทาง) 
 * เพื่อความปลอดภัยและความลื่นไหลในการใช้งานระบบ
 */
?>