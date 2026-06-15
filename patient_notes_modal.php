<div id="patientNoteModal" class="fixed inset-0 z-[70] hidden overflow-y-auto bg-black bg-opacity-60 backdrop-blur-sm flex justify-center items-center p-4">
    <div class="bg-white rounded-2xl overflow-hidden shadow-2xl w-full max-w-2xl border border-gray-200 mx-auto">
        <div class="bg-slate-800 px-6 py-4 flex justify-between items-center text-white">
            <div>
                <h2 class="text-lg lg:text-xl font-bold">หมายเหตุผู้ป่วย</h2>
                <p id="patientNoteTitle" class="text-sm text-slate-200 mt-1 font-semibold">HN: -</p>
            </div>
            <button onclick="closePatientNoteModal()" class="text-white hover:text-red-300 text-3xl font-bold transition">&times;</button>
        </div>

        <div class="p-4 lg:p-6 bg-gray-50 max-h-[82vh] overflow-y-auto custom-scrollbar">
            <input type="hidden" id="note_hn">
            <input type="hidden" id="note_regdate">

            <div class="bg-white rounded-xl border border-gray-200 p-4 shadow-sm mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-2">เพิ่มหมายเหตุ</label>
                <textarea id="note_text" rows="4" class="w-full p-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-slate-500 outline-none text-sm leading-relaxed" placeholder="บันทึกข้อมูลสำคัญ เช่น ติดต่อญาติแล้ว, ขอเลื่อนส่งยา, ต้องระวังเรื่องที่อยู่, ผู้ป่วยมีข้อจำกัดเฉพาะ"></textarea>
                <div class="flex justify-end gap-2 mt-3">
                    <button type="button" onclick="closePatientNoteModal()" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-800 rounded-lg text-sm font-bold transition">ปิด</button>
                    <button type="button" onclick="savePatientNote()" class="px-5 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-lg text-sm font-bold shadow transition">บันทึกหมายเหตุ</button>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100 font-bold text-gray-800">ประวัติหมายเหตุ</div>
                <div id="patientNoteList" class="divide-y divide-gray-100">
                    <div class="px-4 py-8 text-center text-gray-400 font-bold">กำลังโหลดข้อมูล...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let patientNoteBusy = false;

function openPatientNoteModal(hn, fullname, regdate) {
    document.getElementById('note_hn').value = hn;
    document.getElementById('note_regdate').value = regdate || '';
    document.getElementById('note_text').value = '';
    document.getElementById('patientNoteTitle').textContent = `HN: ${hn} | ${fullname || '-'}${regdate ? ' (วันที่รับบริการ: ' + regdate + ')' : ''}`;
    document.getElementById('patientNoteModal').classList.remove('hidden');
    loadPatientNotes();
}

function closePatientNoteModal() {
    document.getElementById('patientNoteModal').classList.add('hidden');
}

function renderPatientNotes(notes) {
    const list = document.getElementById('patientNoteList');
    if (!notes || notes.length === 0) {
        list.innerHTML = '<div class="px-4 py-8 text-center text-gray-400 font-bold">ยังไม่มีหมายเหตุ</div>';
        return;
    }

    list.innerHTML = notes.map(item => {
        const note = String(item.note || '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
        const recorder = String(item.created_name || item.created_by || 'System').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
        const createdAt = item.created_at || '-';
        const visitDate = item.regdate ? `วันที่รับบริการ ${item.regdate}` : 'หมายเหตุทั่วไปของ HN';
        return `
            <div class="p-4">
                <div class="text-sm text-gray-800 leading-relaxed whitespace-pre-wrap">${note}</div>
                <div class="mt-3 flex flex-wrap gap-2 text-[11px] font-bold text-gray-500">
                    <span class="px-2 py-1 bg-gray-100 rounded-md">${visitDate}</span>
                    <span class="px-2 py-1 bg-blue-50 text-blue-700 rounded-md">${recorder}</span>
                    <span class="px-2 py-1 bg-gray-100 rounded-md">${createdAt}</span>
                </div>
            </div>
        `;
    }).join('');
}

async function loadPatientNotes() {
    const hn = document.getElementById('note_hn').value;
    const regdate = document.getElementById('note_regdate').value;
    const list = document.getElementById('patientNoteList');
    list.innerHTML = '<div class="px-4 py-8 text-center text-gray-400 font-bold">กำลังโหลดข้อมูล...</div>';

    const fd = new FormData();
    fd.append('hn', hn);
    fd.append('regdate', regdate);

    try {
        const res = await fetch('api_patient_notes.php?action=list', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status === 'success') {
            renderPatientNotes(data.notes);
            return;
        }
        if (data.status === 'setup_required') {
            list.innerHTML = '<div class="px-4 py-8 text-center text-red-500 font-bold">ยังไม่ได้สร้างตาราง telemed_patient_notes ในฐานข้อมูล</div>';
            return;
        }
        list.innerHTML = `<div class="px-4 py-8 text-center text-red-500 font-bold">${data.message || 'โหลดหมายเหตุไม่สำเร็จ'}</div>`;
    } catch (e) {
        list.innerHTML = '<div class="px-4 py-8 text-center text-red-500 font-bold">เชื่อมต่อ API หมายเหตุไม่สำเร็จ</div>';
    }
}

async function savePatientNote() {
    if (patientNoteBusy) return;

    const hn = document.getElementById('note_hn').value;
    const regdate = document.getElementById('note_regdate').value;
    const note = document.getElementById('note_text').value.trim();
    if (!note) {
        Swal.fire('แจ้งเตือน', 'กรุณากรอกหมายเหตุก่อนบันทึก', 'warning');
        return;
    }

    patientNoteBusy = true;
    const fd = new FormData();
    fd.append('hn', hn);
    fd.append('regdate', regdate);
    fd.append('note', note);

    try {
        const res = await fetch('api_patient_notes.php?action=save', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status === 'success') {
            document.getElementById('note_text').value = '';
            await loadPatientNotes();
            Swal.fire({ icon: 'success', title: 'บันทึกหมายเหตุสำเร็จ', timer: 1300, showConfirmButton: false });
        } else if (data.status === 'setup_required') {
            Swal.fire('ต้องสร้างตารางก่อน', 'กรุณารัน SQL สร้างตาราง telemed_patient_notes บน server', 'warning');
        } else {
            Swal.fire('ผิดพลาด', data.message || 'บันทึกหมายเหตุไม่สำเร็จ', 'error');
        }
    } catch (e) {
        Swal.fire('ผิดพลาด', 'เชื่อมต่อ API หมายเหตุไม่สำเร็จ', 'error');
    } finally {
        patientNoteBusy = false;
    }
}
</script>
