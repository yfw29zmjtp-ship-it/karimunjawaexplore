<?php

/**
 * Floating "compose" button + slide-up quick-compose panel.
 * Include this from modules/email/*.php pages (after BASE_URL is available).
 */
?>
<button id="emFabCompose" title="Tulis Email"
    style="position:fixed;right:24px;bottom:24px;width:56px;height:56px;border-radius:50%;
               background:var(--ss-ocean);color:#fff;border:none;box-shadow:0 4px 14px rgba(194,65,12,0.4);
               display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:9995;">
    <svg viewBox="0 0 24 24" width="22" height="22" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path fill="#ffffff" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z" />
    </svg>
</button>

<div id="emFabPanel" style="display:none;position:fixed;right:24px;bottom:92px;width:340px;max-width:90vw;
     background:#fff;border:1px solid var(--ss-gray-2);border-radius:10px 10px 0 0;box-shadow:0 8px 30px rgba(0,0,0,0.2);z-index:9995;overflow:hidden;">
    <div style="background:var(--ss-ocean);padding:10px 14px;display:flex;justify-content:space-between;align-items:center;">
        <span style="color:#fff !important;font-size:0.9rem;font-weight:600;">Pesan Baru</span>
        <span id="emFabClose" style="color:#fff !important;cursor:pointer;font-size:1.1rem;line-height:1;">&times;</span>
    </div>
    <div style="padding:12px;">
        <div id="emFabMsg" style="display:none;padding:8px 10px;border-radius:6px;margin-bottom:10px;font-size:0.82rem;"></div>
        <input id="emFabTo" type="email" placeholder="Kepada" autocomplete="off" name="em_to_dest" style="width:100%;padding:8px;margin-bottom:8px;border:1px solid var(--ss-gray-2);border-radius:6px;font-size:0.85rem;">
        <input id="emFabSubject" type="text" placeholder="Subjek" autocomplete="off" style="width:100%;padding:8px;margin-bottom:8px;border:1px solid var(--ss-gray-2);border-radius:6px;font-size:0.85rem;">
        <textarea id="emFabBody" placeholder="Tulis pesan..." style="width:100%;min-height:140px;padding:8px;border:1px solid var(--ss-gray-2);border-radius:6px;font-size:0.85rem;resize:vertical;"></textarea>
        <input id="emFabFiles" type="file" multiple style="width:100%;margin-top:8px;font-size:0.78rem;">
        <div id="emFabFileList" style="font-size:0.75rem;color:var(--ss-muted);margin-top:4px;"></div>
        <button id="emFabSend" style="margin-top:10px;width:100%;padding:9px;background:var(--ss-ocean);color:#ffffff !important;border:none;border-radius:6px;font-weight:600;cursor:pointer;font-size:0.85rem;">Kirim</button>
    </div>
</div>

<script>
    (function() {
        const fab = document.getElementById('emFabCompose');
        const panel = document.getElementById('emFabPanel');
        const closeBtn = document.getElementById('emFabClose');
        const sendBtn = document.getElementById('emFabSend');
        const msgBox = document.getElementById('emFabMsg');
        const filesInput = document.getElementById('emFabFiles');
        const fileList = document.getElementById('emFabFileList');

        fab.addEventListener('click', () => {
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        });
        closeBtn.addEventListener('click', () => {
            panel.style.display = 'none';
        });

        filesInput.addEventListener('change', () => {
            const names = Array.from(filesInput.files).map(f => f.name);
            fileList.textContent = names.length ? ('Lampiran: ' + names.join(', ')) : '';
        });

        sendBtn.addEventListener('click', async () => {
            const to = document.getElementById('emFabTo').value.trim();
            const subject = document.getElementById('emFabSubject').value.trim();
            const body = document.getElementById('emFabBody').value;

            if (to === '' || subject === '' || body.trim() === '') {
                msgBox.style.display = 'block';
                msgBox.textContent = 'Penerima, subjek dan isi email wajib diisi.';
                msgBox.style.background = '#fef2f2';
                msgBox.style.color = '#b91c1c';
                msgBox.style.border = '1px solid #fecaca';
                return;
            }
            if (!confirm('Kirim email ke: ' + to + ' ?')) {
                return;
            }

            msgBox.style.display = 'none';
            sendBtn.disabled = true;
            sendBtn.textContent = 'Mengirim...';

            const formData = new FormData();
            formData.append('to', to);
            formData.append('subject', subject);
            formData.append('body', body);
            Array.from(filesInput.files).forEach(f => formData.append('attachments[]', f));

            try {
                const res = await fetch('<?php echo BASE_URL; ?>/modules/email/send-ajax.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                msgBox.style.display = 'block';
                msgBox.textContent = data.message;
                msgBox.style.background = data.success ? '#f0fdf4' : '#fef2f2';
                msgBox.style.color = data.success ? '#15803d' : '#b91c1c';
                msgBox.style.border = '1px solid ' + (data.success ? '#bbf7d0' : '#fecaca');

                if (data.success) {
                    document.getElementById('emFabTo').value = '';
                    document.getElementById('emFabSubject').value = '';
                    document.getElementById('emFabBody').value = '';
                    filesInput.value = '';
                    fileList.textContent = '';
                    setTimeout(() => {
                        panel.style.display = 'none';
                        msgBox.style.display = 'none';
                    }, 1800);
                }
            } catch (e) {
                msgBox.style.display = 'block';
                msgBox.textContent = 'Gagal mengirim: ' + e.message;
                msgBox.style.background = '#fef2f2';
                msgBox.style.color = '#b91c1c';
            } finally {
                sendBtn.disabled = false;
                sendBtn.textContent = 'Kirim';
            }
        });
    })();
</script>