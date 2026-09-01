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
       $userRole = $_SESSION['role'] ?? '';
       $isOwnerAdmin = in_array($userRole, ['owner', 'admin', 'developer']);
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
   <?php if ($isOwnerAdmin): ?>
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
       @keyframes slideUpIn { from { opacity:0; transform:translateY(30px); } to { opacity:1; transform:translateY(0); } }
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
           setTimeout(() => { document.getElementById('pushPrompt').style.display = 'none'; }, 2500);
       } else {
           document.getElementById('pushPrompt').innerHTML = '<div style="display:flex;align-items:center;gap:10px;"><span style="font-size:1.5rem;">⚠️</span><span style="font-size:0.85rem;">' + result.message + '</span></div>';
           setTimeout(() => { document.getElementById('pushPrompt').style.display = 'none'; }, 3000);
       }
       localStorage.setItem('push_prompted', '1');
   }

   function dismissPushPrompt() {
       document.getElementById('pushPrompt').style.display = 'none';
       localStorage.setItem('push_prompted', '1');
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
