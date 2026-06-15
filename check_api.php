<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
include 'config.php';

$hn = isset($_GET['hn']) ? $_GET['hn'] : '0166198'; 

// --- ส่วนที่เพิ่มใหม่: ค้นหาวันที่มารับบริการล่าสุดของคนไข้คนนี้ ---
$found_date = "";
try {
    $stmt_v = $his_pdo->prepare("SELECT MAX(regdate) FROM opd.opd WHERE hn = ?");
    $stmt_v->execute([$hn]);
    $found_date = $stmt_v->fetchColumn();
} catch (Exception $e) {}

// ถ้าใน URL มีวันที่มาให้ ให้ใช้วันที่นั้น แต่ถ้าไม่มี ให้ใช้วันที่ค้นเจอจากระบบ
$regdate = isset($_GET['regdate']) && $_GET['regdate'] != "" ? $_GET['regdate'] : $found_date; 

echo "<!DOCTYPE html><html lang='th'><head><meta charset='UTF-8'><title>Diagnostic Tool V2</title>";
echo "<style>body{ font-family: Sarabun, sans-serif; padding: 20px; background: #f8fafc; } .card{ background:white; padding:20px; border-radius:12px; box-shadow:0 4px 6px -1px rgb(0 0 0 / 0.1); margin-bottom:20px; border:1px solid #e2e8f0; } pre{ background:#1e293b; color:#38bdf8; padding:15px; border-radius:8px; overflow-x:auto; font-size:13px; } .success{ color:#16a34a; font-weight:bold; } .error{ color:#dc2626; background:#fef2f2; padding:10px; border-radius:5px; border:1px solid #fecaca; }</style></head><body>";

echo "<h2>🔍 ระบบตรวจสอบประวัติคนไข้ (Diagnostic Tool)</h2>";

echo "<div class='card'>
        <form method='GET'>
            <strong>ระบุ HN:</strong> <input type='text' name='hn' value='{$hn}' style='padding:5px; border:1px solid #ccc; border-radius:4px;'> 
            <strong>วันที่รับบริการ:</strong> <input type='date' name='regdate' value='{$regdate}' style='padding:5px; border:1px solid #ccc; border-radius:4px;'>
            <button type='submit' style='padding:6px 20px; background:#2563eb; color:white; border:none; border-radius:6px; cursor:pointer; font-weight:bold;'>ตรวจสอบข้อมูล</button>
        </form>
        <p style='font-size:12px; color:#64748b; margin-top:10px;'>* ระบบค้นหาวันที่ล่าสุดให้โดยอัตโนมัติคือ: <b>" . ($found_date ?: "ไม่พบประวัติ") . "</b></p>
      </div>";

if ($found_date) {
    // 1. ตรวจสอบการวินิจฉัย (odiag)
    echo "<div class='card'><h3>1. การวินิจฉัย (opd.odiag)</h3>";
    try {
        $stmt = $his_pdo->prepare("SELECT diag, descrip FROM opd.odiag WHERE hn = ? AND regdate = ?");
        $stmt->execute([$hn, $regdate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            echo "<span class='success'>✅ พบข้อมูล " . count($rows) . " รายการ</span>";
            echo "<pre>" . print_r($rows, true) . "</pre>";
        } else {
            echo "⚠️ ไม่พบข้อมูลวินิจฉัยในวันที่ $regdate (ลองเปลี่ยนวันที่ด้านบน)";
        }
    } catch (Exception $e) { echo "<div class='error'>SQL Error: " . $e->getMessage() . "</div>"; }
    echo "</div>";

    // 2. ตรวจสอบรายการยา (drug_order_opd)
    echo "<div class='card'><h3>2. รายการยา (opd.drug_order_opd)</h3>";
    try {
        $stmt = $his_pdo->prepare("SELECT namedrug, amount, item_usage FROM opd.drug_order_opd WHERE hn = ? AND regdate = ?");
        $stmt->execute([$hn, $regdate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            echo "<span class='success'>✅ พบข้อมูลยา " . count($rows) . " รายการ</span>";
            echo "<pre>" . print_r($rows, true) . "</pre>";
        } else {
            echo "⚠️ ไม่พบรายการยาในวันที่ $regdate";
        }
    } catch (Exception $e) { echo "<div class='error'>SQL Error: " . $e->getMessage() . "</div>"; }
    echo "</div>";
} else {
    echo "<div class='error'>ไม่พบ HN นี้ในฐานข้อมูล HIS ของโรงพยาบาล</div>";
}

echo "</body></html>";