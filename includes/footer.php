           </div>
           <!-- End Page Content -->

           <!-- Footer -->
           <?php
            // Get custom footer text from settings
            $footerCopyrightSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'footer_copyright'");
            $footerCopyright = $footerCopyrightSetting['setting_value'] ?? ('© ' . APP_YEAR . ' ' . APP_NAME . '. All rights reserved.');

            $footerVersionSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'footer_version'");
            $footerVersion = $footerVersionSetting['setting_value'] ?? ('Version ' . APP_VERSION);
            ?>
           <footer style="margin-top: 3rem; padding: 2rem 0; border-top: 1px solid var(--bg-tertiary); text-align: center; color: var(--text-muted);">
               <p><?php echo htmlspecialchars($footerCopyright); ?></p>
               <p style="font-size: 0.875rem; margin-top: 0.5rem;"><?php echo htmlspecialchars($footerVersion); ?></p>
           </footer>
           </main>
           </div>

           <!-- Main JavaScript -->
           <script src="<?php echo BASE_URL; ?>/assets/js/main.js?v=<?php echo time(); ?>"></script>

           <!-- Initialize Feather Icons -->
           <script>
               // Replace feather icons and reinitialize dropdowns
               if (typeof feather !== 'undefined') {
                   feather.replace();
               }
           </script>

           <!-- Push Notifications -->
           <script src="<?php echo BASE_URL; ?>/assets/js/notifications.js?v=<?php echo time(); ?>"></script>
           <script>
               // Initialize notification polling for owner/admin
               <?php
                $userRole = strtolower((string)($_SESSION['role'] ?? ''));
                $isOwnerAdmin = in_array($userRole, ['owner', 'admin', 'developer', 'manager'], true);

                $canStaffChatAccess = $isOwnerAdmin;
                $canStaffChatSend = $isOwnerAdmin;
                $canStaffChatDelete = $isOwnerAdmin;
                if (!$canStaffChatAccess && isset($auth) && method_exists($auth, 'hasPermission')) {
                    $canStaffChatAccess = $auth->hasPermission('staff_chat') || $auth->hasPermission('staff_messages');
                }
                if (!$canStaffChatSend && isset($auth) && method_exists($auth, 'canCreate')) {
                    $canStaffChatSend = $auth->canCreate('staff_chat') || $auth->canCreate('staff_messages');
                }
                if (!$canStaffChatDelete && isset($auth) && method_exists($auth, 'canDelete')) {
                    $canStaffChatDelete = $auth->canDelete('staff_chat') || $auth->canDelete('staff_messages');
                }
                ?>
               <?php if ($isOwnerAdmin): ?>
                       (function() {
                           let lastNotificationCount = 0;

                           // Check for new notifications every 30 seconds
                           async function checkNotifications() {
                               try {
                                   const response = await fetch('<?php echo BASE_URL; ?>/api/get-notifications.php');
                                   const data = await response.json();

                                   if (data.success && data.unread_count > lastNotificationCount) {
                                       // New notification arrived
                                       const newNotifs = data.notifications.slice(0, data.unread_count - lastNotificationCount);

                                       for (const notif of newNotifs) {
                                           if (window.NotificationManager && window.NotificationManager.isEnabled()) {
                                               await window.NotificationManager.showNotification(notif.title, {
                                                   body: notif.message,
                                                   tag: 'notif-' + notif.id,
                                                   data: notif.data
                                               });
                                           }
                                       }

                                       // Update badge
                                       updateNotificationBadge(data.unread_count);
                                   }

                                   lastNotificationCount = data.unread_count;
                               } catch (e) {
                                   console.log('Notification check failed:', e);
                               }
                           }

                           function updateNotificationBadge(count) {
                               const badge = document.getElementById('notification-badge');
                               if (badge) {
                                   badge.textContent = count;
                                   badge.style.display = count > 0 ? 'inline-block' : 'none';
                               }
                           }

                           // Check every 30 seconds
                           setInterval(checkNotifications, 30000);

                           // Initial check
                           setTimeout(checkNotifications, 2000);
                       })();
               <?php endif; ?>
           </script>

           <!-- Push Notification Enable Prompt -->
           <?php if ($canStaffChatAccess): ?>
               <div id="pushPrompt" style="display:none;position:fixed;bottom:24px;right:24px;z-index:9999;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:16px 20px;border-radius:14px;box-shadow:0 8px 32px rgba(102,126,234,.4);max-width:340px;font-size:0.9rem;animation:slideUpIn .4s ease;">
                   <div style="display:flex;align-items:flex-start;gap:12px;">
                       <span style="font-size:1.5rem;line-height:1;">🔔</span>
                       <div style="flex:1;">
                           <div style="font-weight:700;margin-bottom:4px;">Aktifkan Push Notification?</div>
                           <div style="font-size:0.8rem;opacity:.9;margin-bottom:12px;">Terima notifikasi real-time saat ada check-in, check-out, pengajuan cuti & lembur.</div>
                           <div style="display:flex;gap:8px;">
                               <button onclick="activatePush()" style="padding:6px 16px;background:#fff;color:#764ba2;border:none;border-radius:8px;font-weight:700;font-size:0.8rem;cursor:pointer;">Aktifkan</button>
                               <button onclick="dismissPushPrompt()" style="padding:6px 12px;background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:8px;font-size:0.8rem;cursor:pointer;">Nanti</button>
                           </div>
                       </div>
                       <span onclick="dismissPushPrompt()" style="cursor:pointer;opacity:.7;font-size:1.2rem;line-height:1;">&times;</span>
                   </div>
               </div>
               <style>
                   @keyframes slideUpIn {
                       from {
                           opacity: 0;
                           transform: translateY(30px);
                       }

                       to {
                           opacity: 1;
                           transform: translateY(0);
                       }
                   }
               </style>
               <script>
                   (function() {
                       const prompted = localStorage.getItem('push_prompted');
                       const nm = window.NotificationManager;
                       // Show prompt if: never prompted, permission not yet granted, and push is supported
                       if (!prompted && nm && nm.isPushSupported && Notification.permission !== 'granted' && Notification.permission !== 'denied') {
                           setTimeout(() => {
                               document.getElementById('pushPrompt').style.display = 'block';
                           }, 3000);
                       }
                       // If permission already granted but not yet subscribed to push, auto-subscribe silently
                       if (nm && nm.isPushSupported && Notification.permission === 'granted') {
                           // init() already handles this, just make sure
                           nm.init();
                       }
                   })();

                   async function activatePush() {
                       const result = await window.NotificationManager.requestPermission();
                       if (result.success) {
                           document.getElementById('pushPrompt').innerHTML = '<div style="display:flex;align-items:center;gap:10px;"><span style="font-size:1.5rem;">✅</span><span style="font-weight:600;">Push notification aktif!</span></div>';
                           setTimeout(() => {
                               document.getElementById('pushPrompt').style.display = 'none';
                           }, 2500);
                       } else {
                           document.getElementById('pushPrompt').innerHTML = '<div style="display:flex;align-items:center;gap:10px;"><span style="font-size:1.5rem;">⚠️</span><span style="font-size:0.85rem;">' + result.message + '</span></div>';
                           setTimeout(() => {
                               document.getElementById('pushPrompt').style.display = 'none';
                           }, 3000);
                       }
                       localStorage.setItem('push_prompted', '1');
                   }

                   function dismissPushPrompt() {
                       document.getElementById('pushPrompt').style.display = 'none';
                       localStorage.setItem('push_prompted', '1');
                   }
               </script>
           <?php endif; ?>

           <!-- Chat / Pengumuman FAB (admin -> staff broadcast) -->
           <?php if ($isOwnerAdmin): ?>
               <style>
                   .admin-chat-fab {
                       position: fixed;
                       bottom: 24px;
                       right: 24px;
                       z-index: 9999;
                       display: inline-flex;
                       align-items: center;
                       justify-content: center;
                       gap: 8px;
                       padding: 0.7rem 1rem;
                       border-radius: 10px;
                       border: none;
                       background: linear-gradient(145deg, #2563eb 0%, #1d4ed8 100%);
                       color: #ffffff;
                       font-size: 0.82rem;
                       font-weight: 700;
                       box-shadow: 0 10px 22px rgba(37, 99, 235, 0.28);
                       cursor: pointer;
                       transition: transform .16s ease, box-shadow .16s ease, filter .16s ease;
                   }

                   .admin-chat-fab,
                   .admin-chat-fab *,
                   body[data-theme="light"] .admin-chat-fab,
                   body[data-theme="light"] .admin-chat-fab * {
                       color: #ffffff !important;
                   }

                   .admin-chat-fab .fab-chat-icon {
                       width: 18px;
                       height: 18px;
                       display: inline-flex;
                       align-items: center;
                       justify-content: center;
                       color: #ffffff;
                       flex-shrink: 0;
                   }

                   .admin-chat-fab .fab-chat-icon svg {
                       width: 18px;
                       height: 18px;
                       fill: currentColor;
                       display: block;
                   }

                   .admin-chat-fab:hover {
                       transform: translateY(-1px);
                       filter: brightness(1.04);
                       box-shadow: 0 14px 28px rgba(37, 99, 235, 0.32);
                   }

                   .admin-chat-fab:active {
                       transform: translateY(0);
                   }

                   .admin-chat-panel {
                       position: fixed;
                       bottom: 92px;
                       right: 24px;
                       width: 356px;
                       max-width: calc(100vw - 48px);
                       max-height: 60vh;
                       background: #ffffff;
                       border-radius: 18px;
                       border: 1px solid #dbe3f1;
                       box-shadow: 0 20px 40px rgba(15, 23, 42, 0.22);
                       z-index: 9999;
                       display: none;
                       flex-direction: column;
                       overflow: hidden;
                   }

                   .admin-chat-panel.open {
                       display: flex;
                   }

                   .admin-chat-panel .acp-head {
                       padding: 14px 16px;
                       background: #f8fbff;
                       border-bottom: 1px solid #e2e8f0;
                       color: #0f172a;
                       font-weight: 700;
                       font-size: 14px;
                       display: flex;
                       align-items: center;
                       justify-content: space-between;
                       flex-shrink: 0;
                   }

                   .admin-chat-panel .acp-head-title {
                       display: flex;
                       align-items: center;
                       gap: 8px;
                       color: #0f172a;
                   }

                   .admin-chat-panel .acp-head-icon {
                       width: 24px;
                       height: 24px;
                       border-radius: 999px;
                       display: inline-flex;
                       align-items: center;
                       justify-content: center;
                       background: #dbeafe;
                       color: #1d4ed8;
                       font-size: 13px;
                       line-height: 1;
                   }

                   .admin-chat-panel .acp-close {
                       cursor: pointer;
                       opacity: .65;
                       font-size: 18px;
                       color: #475569;
                       transition: opacity .15s ease;
                   }

                   .admin-chat-panel .acp-close:hover {
                       opacity: 1;
                   }

                   .admin-chat-panel .acp-compose {
                       padding: 12px;
                       border-bottom: 1px solid #e2e8f0;
                       background: #ffffff;
                       flex-shrink: 0;
                   }

                   .admin-chat-panel .acp-compose textarea {
                       width: 100%;
                       resize: vertical;
                       min-height: 74px;
                       border: 1px solid #cbd5e1;
                       border-radius: 10px;
                       padding: 10px 12px;
                       font-size: 14px;
                       line-height: 1.4;
                       color: #0f172a;
                       font-family: inherit;
                       box-sizing: border-box;
                   }

                   .admin-chat-panel .acp-compose textarea::placeholder {
                       color: #64748b;
                       opacity: 1;
                   }

                   .admin-chat-panel .acp-compose textarea:focus {
                       outline: none;
                       border-color: #60a5fa;
                       box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.16);
                   }

                   .admin-chat-panel .acp-compose button {
                       margin-top: 10px;
                       width: 100%;
                       padding: 10px;
                       border: none;
                       border-radius: 10px;
                       background: linear-gradient(145deg, #2563eb 0%, #1d4ed8 100%);
                       color: #fff;
                       font-weight: 700;
                       font-size: 13px;
                       cursor: pointer;
                       transition: transform .16s ease, box-shadow .16s ease;
                   }

                   .admin-chat-panel .acp-compose button:hover {
                       transform: translateY(-1px);
                       box-shadow: 0 10px 20px rgba(37, 99, 235, 0.25);
                   }

                   .admin-chat-panel .acp-list {
                       padding: 10px 12px;
                       overflow-y: auto;
                       flex: 1;
                       background: #f8fafc;
                   }

                   .admin-chat-panel .acp-msg {
                       background: #ffffff;
                       border: 1px solid #e2e8f0;
                       border-radius: 12px;
                       padding: 9px 11px;
                       margin-bottom: 8px;
                       position: relative;
                   }

                   .admin-chat-panel .acp-msg .acp-meta {
                       font-size: 10px;
                       color: #64748b;
                       font-weight: 700;
                       text-transform: uppercase;
                       margin-bottom: 3px;
                   }

                   .admin-chat-panel .acp-msg .acp-text {
                       font-size: 13.5px;
                       color: #0f172a;
                       white-space: pre-wrap;
                       line-height: 1.48;
                       padding-right: 18px;
                   }

                   .admin-chat-panel .acp-msg .acp-del {
                       position: absolute;
                       top: 6px;
                       right: 8px;
                       cursor: pointer;
                       color: #dc2626;
                       font-size: 13px;
                       opacity: .6;
                   }

                   .admin-chat-panel .acp-msg .acp-del:hover {
                       opacity: 1;
                   }

                   .admin-chat-panel .acp-empty {
                       padding: 20px;
                       text-align: center;
                       color: #64748b;
                       font-size: 12px;
                   }

                   @media (max-width: 640px) {
                       .admin-chat-fab {
                           bottom: 18px;
                           right: 16px;
                           padding: 0.7rem 0.9rem;
                           font-size: 0.76rem;
                       }

                       .admin-chat-panel {
                           right: 12px;
                           left: 12px;
                           bottom: 82px;
                           width: auto;
                           max-width: none;
                       }
                   }
               </style>
               <div class="admin-chat-fab" onclick="toggleAdminChat()" title="Pesan Staff" aria-label="Buka pesan staff">
                   <span class="fab-chat-icon" aria-hidden="true">
                       <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true">
                           <path d="M4 4.75C4 3.78 4.78 3 5.75 3h12.5C19.22 3 20 3.78 20 4.75v8.5c0 .97-.78 1.75-1.75 1.75H11.8l-3.22 3a.75.75 0 0 1-1.26-.55V15H5.75A1.75 1.75 0 0 1 4 13.25v-8.5z" />
                       </svg>
                   </span>
                   <span>Pesan Staff</span>
               </div>
               <div class="admin-chat-panel" id="adminChatPanel">
                   <div class="acp-head">
                       <span class="acp-head-title"><span class="acp-head-icon">&#128172;</span><span>Pesan ke Staff</span></span>
                       <span class="acp-close" onclick="toggleAdminChat()">✕</span>
                   </div>
                   <?php if ($canStaffChatSend): ?>
                       <div class="acp-compose">
                           <textarea id="adminChatText" placeholder="Tulis pesan untuk semua staff..."></textarea>
                           <button onclick="sendAdminChat()">Kirim Pesan</button>
                       </div>
                   <?php else: ?>
                       <div class="acp-compose" style="padding:10px 12px;color:#64748b;font-size:12px;">
                           Anda hanya bisa melihat pengumuman.
                       </div>
                   <?php endif; ?>
                   <div class="acp-list" id="adminChatList">
                       <div class="acp-empty">Memuat...</div>
                   </div>
               </div>
               <script>
                   const ADMIN_CHAT_CAN_SEND = <?php echo $canStaffChatSend ? 'true' : 'false'; ?>;
                   const ADMIN_CHAT_CAN_DELETE = <?php echo $canStaffChatDelete ? 'true' : 'false'; ?>;
                   let adminChatOpen = false;

                   function toggleAdminChat() {
                       adminChatOpen = !adminChatOpen;
                       const panel = document.getElementById('adminChatPanel');
                       if (adminChatOpen) {
                           panel.classList.add('open');
                           loadAdminChatList();
                       } else {
                           panel.classList.remove('open');
                       }
                   }
                   async function loadAdminChatList() {
                       const listEl = document.getElementById('adminChatList');
                       try {
                           const res = await fetch('<?php echo BASE_URL; ?>/api/staff-chat.php?action=list');
                           const data = await res.json();
                           const msgs = data.data || [];
                           if (msgs.length === 0) {
                               listEl.innerHTML = '<div class="acp-empty">Belum ada pengumuman</div>';
                           } else {
                               listEl.innerHTML = msgs.map(m => `
                   <div class="acp-msg">
                               ${ADMIN_CHAT_CAN_DELETE ? `<span class="acp-del" onclick="deleteAdminChat(${m.id})">✕</span>` : ''}
                       <div class="acp-meta">${m.created_by_name || 'Admin'} · ${m.created_at}</div>
                       <div class="acp-text">${(m.message || '').replace(/</g, '&lt;')}</div>
                   </div>`).join('');
                           }
                       } catch (e) {
                           listEl.innerHTML = '<div class="acp-empty">Gagal memuat</div>';
                       }
                   }
                   async function sendAdminChat() {
                       if (!ADMIN_CHAT_CAN_SEND) return;
                       const textEl = document.getElementById('adminChatText');
                       if (!textEl) return;
                       const message = textEl.value.trim();
                       if (!message) return;
                       try {
                           const res = await fetch('<?php echo BASE_URL; ?>/api/staff-chat.php?action=send', {
                               method: 'POST',
                               headers: {
                                   'Content-Type': 'application/x-www-form-urlencoded'
                               },
                               body: 'message=' + encodeURIComponent(message)
                           });
                           const data = await res.json();
                           if (data.success) {
                               textEl.value = '';
                               loadAdminChatList();
                           } else {
                               alert(data.message || 'Gagal mengirim pengumuman');
                           }
                       } catch (e) {
                           alert('Gagal mengirim pengumuman');
                       }
                   }
                   async function deleteAdminChat(id) {
                       if (!ADMIN_CHAT_CAN_DELETE) return;
                       if (!confirm('Hapus pengumuman ini?')) return;
                       try {
                           await fetch('<?php echo BASE_URL; ?>/api/staff-chat.php?action=delete', {
                               method: 'POST',
                               headers: {
                                   'Content-Type': 'application/x-www-form-urlencoded'
                               },
                               body: 'id=' + encodeURIComponent(id)
                           });
                           loadAdminChatList();
                       } catch (e) {}
                   }
               </script>
           <?php endif; ?>

           <!-- End Shift Feature -->
           <script>
               // Inject BASE_URL for end-shift.js (define once)
               if (typeof BASE_URL === 'undefined') {
                   window.BASE_URL = '<?php echo BASE_URL; ?>';
               }
               console.log('BASE_URL set to:', window.BASE_URL);
               // Expose current user name for UI messages
               window.APP_USER_NAME = '<?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User', ENT_QUOTES); ?>';
           </script>
           <script src="<?php echo BASE_URL; ?>/assets/js/end-shift.js?v=<?php echo time(); ?>"></script>


           <!-- Attach End Shift Button Event -->
           <script>
               // Check if DOM already loaded
               if (document.readyState === 'loading') {
                   document.addEventListener('DOMContentLoaded', attachEndShiftHandler);
               } else {
                   // DOM already loaded, attach immediately
                   attachEndShiftHandler();
               }

               function attachEndShiftHandler() {
                   console.log('🔧 Attaching End Shift handler...');
                   console.log('🔍 window.initiateEndShift:', typeof window.initiateEndShift);
                   console.log('🔍 initiateEndShift:', typeof initiateEndShift);

                   const endShiftBtn = document.getElementById('endShiftButton');
                   console.log('🔍 End Shift Button found:', endShiftBtn);

                   if (endShiftBtn) {
                       // Use window.initiateEndShift explicitly
                       if (typeof window.initiateEndShift === 'function') {
                           endShiftBtn.addEventListener('click', function(e) {
                               console.log('🎯 End Shift button clicked!');
                               console.log('🎯 Event:', e);
                               try {
                                   window.initiateEndShift();
                               } catch (error) {
                                   console.error('❌ Error calling initiateEndShift:', error);
                               }
                           });
                           console.log('✅ End Shift handler attached successfully');
                       } else {
                           console.error('❌ window.initiateEndShift function not found!');
                           console.log('Available window functions:', Object.keys(window).filter(k => typeof window[k] === 'function').slice(0, 20));
                       }
                   } else {
                       console.error('❌ End Shift button not found!');
                   }
               }
           </script>

           <!-- Initialize Feather Icons & Setup -->
           <script>
               // Initialize Feather Icons
               feather.replace();

               // Real-time clock update
               function updateClock() {
                   const now = new Date();

                   // Update time (HH:MM:SS)
                   const hours = String(now.getHours()).padStart(2, '0');
                   const minutes = String(now.getMinutes()).padStart(2, '0');
                   const seconds = String(now.getSeconds()).padStart(2, '0');
                   const timeString = `${hours}:${minutes}:${seconds}`;

                   const timeElement = document.getElementById('currentTime');
                   if (timeElement) {
                       timeElement.textContent = timeString;
                   }

                   // Update date at midnight
                   const dateElement = document.getElementById('currentDate');
                   if (dateElement && now.getHours() === 0 && now.getMinutes() === 0 && now.getSeconds() === 0) {
                       location.reload(); // Reload to update date
                   }
               }

               // Update clock every second
               setInterval(updateClock, 1000);
               updateClock(); // Initial call
           </script>

           <!-- html2pdf.js Library for PDF Export -->
           <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
           <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

           <!-- Additional JavaScript -->
           <?php if (isset($additionalJS)): ?>
               <?php foreach ($additionalJS as $js): ?>
                   <script src="<?php echo BASE_URL . '/' . $js; ?>"></script>
               <?php endforeach; ?>
           <?php endif; ?>

           <!-- Inline Scripts -->
           <?php if (isset($inlineScript)): ?>
               <script>
                   <?php echo $inlineScript; ?>
               </script>
           <?php endif; ?>
           </body>

           </html>