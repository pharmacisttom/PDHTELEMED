<?php
// ป้องกันการแจ้งเตือนของ PHP ไปทำลายโครงสร้าง JSON
error_reporting(0); 

// เช็คก่อนว่ามี Session รันอยู่หรือยัง ถ้ายังค่อย start (ป้องกัน Notice)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('PDH_REQUIRE_HIS_DB', false);
include 'config.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

// ==========================================
// 1. ระบบเข้าสู่ระบบ (Login)
// ==========================================
if ($action === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        exit;
    }

    try {
        $stmt = $app_pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['role_id'] = (int)$user['role_id'];

            echo json_encode(['status' => 'success', 'message' => 'เข้าสู่ระบบสำเร็จ']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง (หรือถูกระงับ)']);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
    }
    exit;
}

// ==========================================
// 2. ระบบลงทะเบียน (Register)
// ==========================================
if ($action === 'register') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $fullname = trim($_POST['fullname'] ?? '');

    if (empty($username) || empty($password) || empty($fullname)) {
        echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        exit;
    }

    try {
        // เช็คว่ามี Username นี้ซ้ำในระบบหรือไม่
        $stmt_check = $app_pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt_check->execute([$username]);
        if ($stmt_check->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'ชื่อผู้ใช้นี้ (Username) ถูกใช้งานแล้ว']);
            exit;
        }

        // เข้ารหัสผ่าน
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // บันทึกผู้ใช้ใหม่ (ให้ค่าเริ่มต้นเป็นผู้ใช้งานทั่วไป role_id = 2, is_active = 1)
        $sql = "INSERT INTO users (username, password, fullname, role_id, is_active) VALUES (?, ?, ?, 2, 1)";
        $stmt = $app_pdo->prepare($sql);
        $stmt->execute([$username, $hashed_password, $fullname]);

        echo json_encode(['status' => 'success', 'message' => 'สมัครใช้งานสำเร็จ! กรุณาเข้าสู่ระบบ']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'DB Error: ' . $e->getMessage()]);
    }
    exit;
}

// ==========================================
// 3. ระบบออกจากระบบ (Logout)
// ==========================================
if ($action === 'logout') {
    session_destroy();
    header("Location: login.php");
    exit;
}

// ถ้าเรียกไฟล์ตรงๆ แบบไม่มี action ให้ตอบกลับเป็น JSON ปกติ
echo json_encode(['status' => 'info', 'message' => 'Auth API is working.']);
?>
