/**
 * Owner Portal - Web Push subscription
 * Registers the browser for push notifications so the owner keeps getting
 * alerts (e.g. booking baru dikonfirmasi) even when the app is closed.
 */
(function () {
    var PUSH_API = '../../api/push-subscription.php';

    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(base64);
        var arr = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; ++i) arr[i] = raw.charCodeAt(i);
        return arr;
    }

    async function subscribePush(reg, vapidKey) {
        try {
            var sub = await reg.pushManager.getSubscription();
            if (!sub) {
                sub = await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: vapidKey
                });
            }
            await fetch(PUSH_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'subscribe', subscription: sub.toJSON() })
            });
        } catch (e) {
            console.warn('[OwnerPush] Subscribe failed:', e);
        }
    }

    function showPushPrompt(reg, vapidKey) {
        if (localStorage.getItem('owner_push_prompted')) return;
        var el = document.createElement('div');
        el.id = 'ownerPushPrompt';
        el.innerHTML =
            '<div style="position:fixed;bottom:86px;left:50%;transform:translateX(-50%);z-index:10000;background:#0369A1;color:#fff;padding:14px 18px;border-radius:14px;box-shadow:0 8px 32px rgba(3,105,161,.4);max-width:320px;width:90%;font-size:13px;">' +
            '<div style="display:flex;align-items:flex-start;gap:10px;">' +
            '<span style="font-size:20px;">\uD83D\uDD14</span>' +
            '<div style="flex:1;">' +
            '<div style="font-weight:700;margin-bottom:3px;">Aktifkan Notifikasi?</div>' +
            '<div style="font-size:12px;opacity:.9;margin-bottom:10px;">Dapat notif booking baru walau app tidak dibuka.</div>' +
            '<div style="display:flex;gap:8px;">' +
            '<button id="ownerPushYes" style="padding:5px 14px;background:#fff;color:#0369A1;border:none;border-radius:8px;font-weight:700;font-size:12px;cursor:pointer;">Aktifkan</button>' +
            '<button id="ownerPushNo" style="padding:5px 10px;background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:8px;font-size:12px;cursor:pointer;">Nanti</button>' +
            '</div></div>' +
            '<span id="ownerPushClose" style="cursor:pointer;opacity:.7;font-size:16px;">&times;</span>' +
            '</div></div>';
        document.body.appendChild(el);

        function dismiss() {
            el.remove();
            localStorage.setItem('owner_push_prompted', '1');
        }
        document.getElementById('ownerPushNo').onclick = dismiss;
        document.getElementById('ownerPushClose').onclick = dismiss;
        document.getElementById('ownerPushYes').onclick = async function () {
            var perm = await Notification.requestPermission();
            if (perm === 'granted') await subscribePush(reg, vapidKey);
            dismiss();
        };
    }

    // Di iPhone/iPad, Safari HANYA mengirim push notification di background kalau
    // situs sudah di-"Add to Home Screen" dan dibuka sebagai app (standalone) -
    // dibuka sebagai tab Safari biasa TIDAK akan pernah dapat notif walau sudah izinkan.
    function showInstallFirstPrompt() {
        if (localStorage.getItem('owner_push_install_prompted')) return;
        var el = document.createElement('div');
        el.id = 'ownerPushInstallPrompt';
        el.innerHTML =
            '<div style="position:fixed;bottom:86px;left:50%;transform:translateX(-50%);z-index:10000;background:#B45309;color:#fff;padding:14px 18px;border-radius:14px;box-shadow:0 8px 32px rgba(180,83,9,.4);max-width:320px;width:90%;font-size:13px;">' +
            '<div style="display:flex;align-items:flex-start;gap:10px;">' +
            '<span style="font-size:20px;">\uD83D\uDCF2</span>' +
            '<div style="flex:1;">' +
            '<div style="font-weight:700;margin-bottom:3px;">Install Dulu untuk Notifikasi</div>' +
            '<div style="font-size:12px;opacity:.95;">Di iPhone, notifikasi hanya jalan kalau app ini di-install: tap ikon Share (kotak+panah) di Safari &rarr; "Add to Home Screen", lalu buka lagi dari icon di layar utama.</div>' +
            '</div>' +
            '<span id="ownerPushInstallClose" style="cursor:pointer;opacity:.7;font-size:16px;">&times;</span>' +
            '</div></div>';
        document.body.appendChild(el);
        document.getElementById('ownerPushInstallClose').onclick = function () {
            el.remove();
            localStorage.setItem('owner_push_install_prompted', '1');
        };
    }

    async function initOwnerPush() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

        var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        var isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

        // iOS wajib "Add to Home Screen" dulu - kalau belum, subscribe akan gagal/tidak
        // pernah kirim notif di background, jadi tuntun user install dulu, jangan minta izin.
        if (isIOS && !isStandalone) {
            setTimeout(showInstallFirstPrompt, 4000);
            return;
        }

        try {
            var reg = await navigator.serviceWorker.ready;
            var resp = await fetch(PUSH_API + '?action=vapid-public-key');
            var kd = await resp.json();
            if (!kd.success || !kd.publicKey) return;
            var vapidKey = urlBase64ToUint8Array(kd.publicKey);

            if (Notification.permission === 'granted') {
                await subscribePush(reg, vapidKey);
            } else if (Notification.permission === 'default') {
                setTimeout(function () { showPushPrompt(reg, vapidKey); }, 4000);
            }
        } catch (e) {
            console.warn('[OwnerPush] Init error:', e);
        }
    }

    setTimeout(initOwnerPush, 1500);
})();
