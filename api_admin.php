<?php
error_reporting(0);
include 'config.php';
header('Content-Type: application/json; charset=utf-8');

// ตรวจสอบว่าเป็น Admin หรือไม่ (Role ID 1)
if (!is_admin()) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่มีสิทธิ์เข้าถึง']);
    exit;
}

$action = $_GET['action'] ?? '';

// 1. อัปเดตสถานะการใช้งาน (อนุมัติ/ระงับ)
if ($action === 'update_status') {
    $id = $_POST['id'] ?? '';
    $status = $_POST['status'] ?? 0;
    try {
        $stmt = $app_pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        echo json_encode(['status' => 'success', 'message' => 'อัปเดตสถานะสำเร็จ']);
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); }
    exit;
}

// 2. เปลี่ยนสิทธิ์ผู้ใช้งาน (Role)
if ($action === 'update_role') {
    $id = $_POST['id'] ?? '';
    $role_id = $_POST['role_id'] ?? 2;
    try {
        $stmt = $app_pdo->prepare("UPDATE users SET role_id = ? WHERE id = ?");
        $stmt->execute([$role_id, $id]);
        echo json_encode(['status' => 'success', 'message' => 'เปลี่ยนสิทธิ์สำเร็จ']);
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); }
    exit;
}
?>
