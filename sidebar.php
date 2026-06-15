<?php
require_once __DIR__ . '/app_version.php';
// ตรวจสอบว่าอยู่หน้าไหน เพื่อนำไปทำ Active Menu (ปุ่มสีน้ำเงิน)
$current_page = basename($_SERVER['PHP_SELF']);
$current_clinic_code = trim((string)($_GET['clinic'] ?? ''));
$telemed_sidebar_clinics = [];

try {
    $sidebar_clinic_stmt = $his_pdo->query(
        "SELECT DISTINCT
                TRIM(o.clinic) AS clinic_code,
                cl.NAME AS clinic_name
         FROM opd.opd o
         LEFT JOIN hos.clinic cl ON cl.code = o.clinic
         WHERE o.comein IN ('10', 'IN10')
           AND TRIM(o.clinic) <> ''
         ORDER BY cl.NAME ASC, clinic_code ASC"
    );
    $telemed_sidebar_clinics = $sidebar_clinic_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $telemed_sidebar_clinics = [];
}

// ฟังก์ชันช่วยสลับคลาส CSS ถ้าหน้านั้นตรงกับเมนู
function getMenuClass($page_name, $current_page) {
    if ($page_name === $current_page) {
        return "bg-blue-600 text-white shadow-lg"; // สีตอน Active
    } else {
        return "text-gray-300 hover:bg-gray-800 hover:text-white"; // สีตอนปกติ
    }
}
?>

<div id="sidebarOverlay" onclick="toggleSidebar()" class="fixed inset-0 bg-gray-900 bg-opacity-50 z-20 hidden lg:hidden transition-opacity"></div>

<aside id="sidebar" class="absolute inset-y-0 left-0 w-64 bg-gray-900 text-white flex flex-col shadow-2xl z-30 transform -translate-x-full lg:relative lg:translate-x-0 transition-transform duration-300 ease-in-out">
    
    <div class="h-16 flex items-center justify-between px-4 bg-gray-950 border-b border-gray-800">
        <div class="flex items-center justify-center w-full">
            <span class="text-xl font-bold tracking-wider">PDH<span class="text-blue-500">Telemed</span></span>
        </div>
        <button onclick="toggleSidebar()" class="lg:hidden text-gray-400 hover:text-white">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
    </div>
    
    <div class="p-4 flex flex-col flex-1 overflow-y-auto custom-scrollbar">
        <p class="text-xs text-gray-500 font-bold uppercase tracking-wider mb-4">เมนูหลัก</p>
        
        <a href="dashboard.php" class="flex items-center px-4 py-3 rounded-xl mb-2 transition <?php echo getMenuClass('dashboard.php', $current_page); ?>">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
            ภาพรวม (Dashboard)
        </a>
        <a href="clinic_dashboard.php" class="flex items-center px-4 py-3 rounded-xl transition font-medium gap-3 <?php echo getMenuClass('clinic_dashboard.php', $current_page); ?>">
          <span>📊</span> <span>สถิติแยกตามคลินิก</span>
       </a>
        
        <?php if (count($telemed_sidebar_clinics) > 0): ?>
        <p class="text-xs text-gray-500 font-bold uppercase tracking-wider mb-2 mt-5">คลินิก Telemed</p>
        <div class="space-y-1 mb-3">
            <?php
            $clinic_colors = ['bg-indigo-500', 'bg-emerald-500', 'bg-amber-500', 'bg-red-500', 'bg-violet-500', 'bg-pink-500', 'bg-slate-500'];
            foreach ($telemed_sidebar_clinics as $clinic_index => $sidebar_clinic):
                $sidebar_clinic_code = (string)$sidebar_clinic['clinic_code'];
                $sidebar_clinic_name = !empty($sidebar_clinic['clinic_name'])
                    ? decodeThai($sidebar_clinic['clinic_name'])
                    : 'คลินิก ' . $sidebar_clinic_code;
                $is_clinic_active = $current_page === 'clinic_manage.php' && $current_clinic_code === $sidebar_clinic_code;
            ?>
            <a href="clinic_manage.php?clinic=<?php echo rawurlencode($sidebar_clinic_code); ?>"
               class="flex items-start gap-3 rounded-xl px-4 py-2.5 text-sm transition <?php echo $is_clinic_active ? 'bg-blue-600 text-white shadow-lg' : 'text-gray-300 hover:bg-gray-800 hover:text-white'; ?>">
                <span class="mt-1 h-2.5 w-2.5 flex-none rounded-sm <?php echo $clinic_colors[$clinic_index % count($clinic_colors)]; ?>"></span>
                <span class="leading-snug"><?php echo htmlspecialchars($sidebar_clinic_name, ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <a href="tracking.php" class="flex items-center px-4 py-3 rounded-xl mb-2 transition <?php echo getMenuClass('tracking.php', $current_page); ?>">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17l6-6-6-6"></path></svg> 
            ติดตามการส่งยา (Tracking)
        </a>

        <a href="service_quality.php" class="flex items-center px-4 py-3 rounded-xl mb-2 transition <?php echo getMenuClass('service_quality.php', $current_page); ?>">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6M7 4h10a2 2 0 012 2v12a2 2 0 01-2 2H7a2 2 0 01-2-2V6a2 2 0 012-2z"></path></svg>
            ตรวจสอบคุณภาพบริการ
        </a>

        <?php if(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1): ?>
        <p class="text-xs text-gray-500 font-bold uppercase tracking-wider mb-4 mt-6">สำหรับแอดมิน</p>
        
        <a href="admin_user_management.php" class="flex items-center px-4 py-3 rounded-xl mb-2 transition <?php echo getMenuClass('admin_user_management.php', $current_page); ?>">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
            อนุมัติ / จัดการผู้ใช้งาน
        </a>

        <a href="admin_permissions.php" class="flex items-center px-4 py-3 rounded-xl mb-2 transition <?php echo getMenuClass('admin_permissions.php', $current_page); ?>">
            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            กำหนดสิทธิ์การใช้งาน
        </a>
        <?php endif; ?>
    </div>

    <div class="p-4 border-t border-gray-800">
        <div class="flex items-center mb-4 px-2">
            <div class="bg-blue-600 h-10 w-10 rounded-full flex items-center justify-center font-bold text-white mr-3 shadow-inner uppercase">
                <?php echo substr($_SESSION['username'] ?? 'U', 0, 1); ?>
            </div>
            <div class="overflow-hidden">
                <p class="text-sm font-bold truncate"><?php echo htmlspecialchars($_SESSION['fullname'] ?? 'Unknown'); ?></p>
                <p class="text-xs text-gray-500 uppercase tracking-tighter">Role ID: <?php echo $_SESSION['role_id'] ?? '-'; ?></p>
            </div>
        </div>
        <div class="mb-4 px-2">
            <span class="inline-flex rounded-full border border-blue-500/30 bg-blue-500/10 px-3 py-1 text-[11px] font-bold text-blue-300">
                <?php echo htmlspecialchars(APP_VERSION_LABEL, ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>
        <button onclick="confirmLogout()" class="w-full flex items-center justify-center px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition font-bold text-sm">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
            ออกจากระบบ
        </button>
    </div>
</aside>

<script>
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('-translate-x-full');
        document.getElementById('sidebarOverlay').classList.toggle('hidden');
    }

    function confirmLogout() {
        Swal.fire({
            title: 'ต้องการออกจากระบบ?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'ออกจากระบบ',
            cancelButtonText: 'ยกเลิก'
        }).then((res) => { 
            if (res.isConfirmed) {
                window.location.href = 'auth_action.php?action=logout'; 
            }
        });
    }
</script>
