<div id="thailandPostModal" class="fixed inset-0 z-50 hidden overflow-y-auto bg-black bg-opacity-60 backdrop-blur-sm flex justify-center items-center p-4">
    <div class="bg-white rounded-2xl overflow-hidden shadow-2xl w-full max-w-2xl border border-gray-200 mx-auto">
        <div class="bg-gradient-to-r from-red-700 to-red-900 px-6 py-4 flex justify-between items-center text-white">
            <div class="flex items-center gap-2">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path>
                </svg>
                <h2 class="text-lg lg:text-xl font-bold">ลงทะเบียนพัสดุ Thailand Post</h2>
            </div>
            <button type="button" onclick="closeThailandPostModal()" class="text-white hover:text-red-200 text-3xl font-bold transition">&times;</button>
        </div>

        <div class="p-4 lg:p-6 bg-gray-50 max-h-[85vh] overflow-y-auto">
            <form id="thailandPostForm">
                <input type="hidden" id="tp_hn" name="hn">
                <input type="hidden" id="tp_regdate" name="regdate">
                <input type="hidden" id="tp_fullname" name="fullname">

                <div class="mb-6 pb-4 border-b border-gray-200">
                    <p class="text-xs text-gray-500 font-bold uppercase tracking-wider">ข้อมูลผู้ป่วย</p>
                    <p id="tp_pt_info" class="text-lg lg:text-xl font-black text-red-900 mt-1">HN: -</p>
                </div>

                <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm space-y-4 mb-6">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">
                            เลขพัสดุ Thailand Post <span class="text-red-600">*</span>
                        </label>
                        <div class="flex flex-col sm:flex-row gap-2">
                            <input type="text"
                                   id="tp_tracking_no"
                                   name="tracking_no"
                                   placeholder="เช่น EV123456789TH"
                                   class="flex-1 px-4 py-3 border-2 border-gray-300 rounded-lg focus:border-red-500 focus:ring-2 focus:ring-red-200 outline-none transition font-mono uppercase"
                                   autocomplete="off"
                                   required>
                            <button type="button"
                                    onclick="validateThailandPostTracking()"
                                    class="px-4 py-3 bg-slate-700 hover:bg-slate-800 text-white rounded-lg font-bold transition shadow-md">
                                ตรวจสอบ
                            </button>
                        </div>
                        <p id="tp_validation_msg" class="text-xs text-gray-500 mt-2 hidden"></p>
                    </div>

                    <div id="tp_link_container" class="hidden">
                        <a id="tp_track_link"
                           href="#"
                           target="_blank"
                           rel="noopener"
                           class="inline-flex items-center gap-2 px-4 py-2 bg-red-100 hover:bg-red-200 text-red-700 rounded-lg font-bold transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                            </svg>
                            เปิดหน้าติดตามบน Thailand Post
                        </a>
                    </div>
                </div>

                <div id="tp_status_info" class="bg-white p-4 rounded-xl border border-gray-200 shadow-sm space-y-4 mb-6 hidden">
                    <div class="flex items-start gap-2 pb-3 border-b border-gray-100">
                        <svg class="w-5 h-5 text-green-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                        </svg>
                        <div>
                            <p class="text-xs font-bold text-gray-500 uppercase">สถานะล่าสุด</p>
                            <p id="tp_status_display" class="text-sm font-bold text-gray-900 mt-1">-</p>
                            <p id="tp_status_meta" class="text-xs text-gray-500 mt-1">-</p>
                        </div>
                    </div>

                    <div id="tp_status_details" class="space-y-2 text-xs text-gray-600"></div>
                </div>

                <div class="bg-blue-50 p-4 rounded-xl border border-blue-200">
                    <p class="text-xs font-bold text-blue-700 uppercase">การทำงานของระบบ</p>
                    <div class="mt-2 space-y-1 text-xs text-blue-900 font-medium">
                        <p>ตรวจรูปแบบเลขพัสดุ ก่อนเรียก Track & Trace API</p>
                        <p>บันทึกเลขพัสดุ สถานะล่าสุด ประวัติการซิงก์ และผู้บันทึกลงระบบ</p>
                        <p>ถ้าเลขยังไม่เข้าระบบไปรษณีย์ จะยังบันทึกเป็น “รอจัดส่ง” ได้จากหน้าติดตามปกติ</p>
                    </div>
                </div>
            </form>
        </div>

        <div class="bg-gray-100 px-6 py-3 flex justify-end gap-3 border-t border-gray-200">
            <button type="button" onclick="closeThailandPostModal()"
                    class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg font-bold transition">
                ยกเลิก
            </button>
            <button type="button" onclick="submitThailandPost()"
                    id="tp_submit_btn"
                    class="px-6 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-bold transition shadow-md flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                <span id="tp_submit_text">บันทึกและซิงก์</span>
            </button>
        </div>
    </div>
</div>

<script>
function setThailandPostMessage(text, colorClass) {
    const msg = document.getElementById('tp_validation_msg');
    msg.textContent = text;
    msg.className = `text-xs mt-2 ${colorClass}`;
    msg.classList.remove('hidden');
}

function openThailandPostModal(hn, fullname, regdate, trackingNo = '') {
    document.getElementById('tp_hn').value = hn;
    document.getElementById('tp_regdate').value = regdate;
    document.getElementById('tp_fullname').value = fullname;
    document.getElementById('tp_pt_info').textContent = `HN: ${hn} | ${fullname} (${regdate})`;
    document.getElementById('tp_tracking_no').value = trackingNo && trackingNo !== '-' ? trackingNo : '';
    document.getElementById('tp_link_container').classList.add('hidden');
    document.getElementById('tp_status_info').classList.add('hidden');
    document.getElementById('tp_validation_msg').classList.add('hidden');
    document.getElementById('thailandPostModal').classList.remove('hidden');
}

function closeThailandPostModal() {
    document.getElementById('thailandPostModal').classList.add('hidden');
}

async function validateThailandPostTracking() {
    const trackingNo = document.getElementById('tp_tracking_no').value.trim().toUpperCase();
    document.getElementById('tp_tracking_no').value = trackingNo;

    if (!trackingNo) {
        setThailandPostMessage('กรุณาระบุเลขพัสดุ', 'text-red-600');
        return false;
    }

    const fd = new FormData();
    fd.append('tracking_no', trackingNo);
    const validateRes = await fetch('api_thailand_post.php?action=validate_tracking_no', { method: 'POST', body: fd });
    const validateData = await validateRes.json();

    if (!validateData.valid) {
        setThailandPostMessage(validateData.message || 'รูปแบบเลขพัสดุไม่ถูกต้อง', 'text-red-600');
        return false;
    }

    setThailandPostMessage('รูปแบบเลขพัสดุถูกต้อง กำลังดึงสถานะ...', 'text-green-600');
    displayThailandPostLink(trackingNo);

    const statusRes = await fetch(`api_thailand_post.php?action=get_status&tracking_no=${encodeURIComponent(trackingNo)}`);
    const statusData = await statusRes.json();

    if (statusData.status === 'success') {
        displayThailandPostStatus(statusData.data);
        setThailandPostMessage('ดึงสถานะจาก Thailand Post สำเร็จ', 'text-green-600');
        return true;
    }

    setThailandPostMessage(statusData.message || 'ยังไม่พบสถานะจาก Thailand Post', 'text-orange-600');
    return true;
}

function displayThailandPostStatus(data) {
    const statusInfo = document.getElementById('tp_status_info');
    const statusDisplay = document.getElementById('tp_status_display');
    const statusMeta = document.getElementById('tp_status_meta');
    const statusDetails = document.getElementById('tp_status_details');

    statusDisplay.textContent = data.status || 'ไม่พบข้อมูลสถานะ';
    statusMeta.textContent = `${data.location || '-'} | ${data.date || '-'}`;

    const rows = Array.isArray(data.history) ? data.history.slice(0, 5) : [];
    statusDetails.innerHTML = rows.length
        ? rows.map(item => `
            <div class="flex items-start gap-2">
                <span class="text-gray-400 mt-0.5">-</span>
                <div>
                    <p class="font-medium text-gray-900">${item.status || '-'}</p>
                    <p class="text-gray-500">${item.location || '-'} | ${item.date || '-'}</p>
                </div>
            </div>
        `).join('')
        : '<p class="text-gray-500">ยังไม่พบประวัติการเดินทางของพัสดุ</p>';

    statusInfo.classList.remove('hidden');
}

function displayThailandPostLink(trackingNo) {
    const linkContainer = document.getElementById('tp_link_container');
    const trackLink = document.getElementById('tp_track_link');
    trackLink.href = `https://track.thailandpost.co.th/?trackNumber=${encodeURIComponent(trackingNo)}`;
    linkContainer.classList.remove('hidden');
}

async function submitThailandPost() {
    const trackingNo = document.getElementById('tp_tracking_no').value.trim().toUpperCase();
    if (!trackingNo) {
        Swal.fire('ข้อมูลไม่ครบ', 'กรุณาระบุเลขพัสดุ', 'warning');
        return;
    }

    const btn = document.getElementById('tp_submit_btn');
    const btnText = document.getElementById('tp_submit_text');
    btn.disabled = true;
    btnText.textContent = 'กำลังบันทึก...';

    try {
        const fd = new FormData(document.getElementById('thailandPostForm'));
        fd.set('tracking_no', trackingNo);

        const res = await fetch('api_thailand_post.php?action=sync_tracking_status', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.status === 'success') {
            Swal.fire('สำเร็จ', data.message || 'บันทึกข้อมูล Thailand Post เรียบร้อยแล้ว', 'success').then(() => {
                closeThailandPostModal();
                location.reload();
            });
        } else {
            Swal.fire('บันทึกไม่สำเร็จ', data.message || 'ไม่สามารถซิงก์ข้อมูลได้', 'error');
        }
    } catch (err) {
        Swal.fire('ข้อผิดพลาด', err.message, 'error');
    } finally {
        btn.disabled = false;
        btnText.textContent = 'บันทึกและซิงก์';
    }
}
</script>
