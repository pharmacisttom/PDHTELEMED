<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/app_version.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$require_app_db = !defined('PDH_REQUIRE_APP_DB') || PDH_REQUIRE_APP_DB;
$require_his_db = !defined('PDH_REQUIRE_HIS_DB') || PDH_REQUIRE_HIS_DB;

// --- APP DB ---
// Server 192.168.111.240 uses local MySQL account.
// Developer/XAMPP machines connect to the server MySQL by IP.
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

// --- HIS DB ---
$his_host = '192.168.111.251';
$his_db   = 'hos';
$his_user = 'web_ptom';
$his_pass = '@TOM$HimproDataBase10832';

try {
    if ($require_app_db) {
        $app_pdo = new PDO("mysql:host=$app_host;dbname=$app_db;charset=utf8mb4", $app_user, $app_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $app_pdo->exec("SET time_zone = '+07:00'");
    }

    if ($require_his_db) {
        $his_pdo = new PDO("mysql:host=$his_host;dbname=$his_db;charset=tis620", $his_user, $his_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $his_pdo->exec("SET NAMES tis620");
        $his_pdo->exec("SET time_zone = '+07:00'");
    }
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

function decodeThai($str) {
    if (!$str) {
        return "";
    }

    $decoded = @iconv("TIS-620", "UTF-8//IGNORE", $str);
    if (!$decoded) {
        $decoded = @iconv("Windows-874", "UTF-8//IGNORE", $str);
    }

    return $decoded ? $decoded : $str;
}

function get_current_role_id(): ?int {
    global $app_pdo;

    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    if (isset($_SESSION['role_id']) && $_SESSION['role_id'] !== '') {
        return (int) $_SESSION['role_id'];
    }

    $stmt = $app_pdo->prepare("SELECT role_id, username, fullname FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['role_id'] === null || $user['role_id'] === '') {
        return null;
    }

    $_SESSION['role_id'] = (int) $user['role_id'];
    $_SESSION['username'] = $_SESSION['username'] ?? $user['username'];
    $_SESSION['fullname'] = $_SESSION['fullname'] ?? $user['fullname'];

    return (int) $user['role_id'];
}

function is_admin(): bool {
    return get_current_role_id() === 1;
}

function render_access_denied(string $message = 'ขออภัย ท่านไม่มีสิทธิ์เข้าใช้งานหน้านี้ กรุณาติดต่อผู้ดูแลระบบ (Admin)'): void {
    die('
        <div style="font-family: sans-serif; text-align: center; margin-top: 50px; background-color: #fef2f2; padding: 40px; border-radius: 10px; max-width: 600px; margin-left: auto; margin-right: auto; border: 1px solid #f87171;">
            <h1 style="color: #dc2626; font-size: 24px; font-weight: bold;">Access Denied</h1>
            <p style="color: #4b5563; margin-top: 10px; margin-bottom: 30px;">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>
            <a href="javascript:history.back()" style="padding: 10px 20px; background: #2563eb; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;">กลับหน้าเดิม</a>
        </div>
    ');
}

function check_permission($current_page) {
    global $app_pdo;

    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }

    $role_id = get_current_role_id();
    if ($role_id === null) {
        session_unset();
        session_destroy();
        header("Location: login.php");
        exit;
    }

    if ($role_id === 1 || $current_page === 'dashboard.php') {
        return;
    }

    $stmt = $app_pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND page_name = ?");
    $stmt->execute([$role_id, $current_page]);
    $has_access = (int) $stmt->fetchColumn();

    if ($has_access === 0) {
        render_access_denied();
    }
}
?>
