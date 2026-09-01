/**
 * ADF System - Push Notification Manager
 * Handles browser notifications + real Web Push subscriptions via VAPID
 */

class NotificationManager {
  constructor() {
    this.isSupported = "Notification" in window;
    this.isServiceWorkerSupported = "serviceWorker" in navigator;
    this.isPushSupported =
      this.isServiceWorkerSupported && "PushManager" in window;
    this.permission = this.isSupported ? Notification.permission : "denied";
    this.swRegistration = null;
    this.vapidPublicKey = null;
  }

  /**
   * Initialize notification system
   */
  async init() {
    if (!this.isSupported) {
      console.warn("Browser tidak mendukung notifikasi");
      return false;
    }

    // Register service worker
    if (this.isServiceWorkerSupported) {
      try {
        this.swRegistration = await navigator.serviceWorker.register("/sw.js");
        console.log("[Push] Service Worker registered");
      } catch (error) {
        console.error("[Push] SW registration failed:", error);
      }
    }

    // Fetch VAPID public key
    try {
      const resp = await fetch(
        "/api/push-subscription.php?action=vapid-public-key",
      );
      const data = await resp.json();
      if (data.success) {
        this.vapidPublicKey = data.publicKey;
      }
    } catch (e) {
      console.warn("[Push] Failed to fetch VAPID key:", e);
    }

    // Auto-subscribe if permission already granted
    if (this.permission === "granted" && this.vapidPublicKey) {
      await this.subscribePush();
    }

    return true;
  }

  /**
   * Request notification permission and subscribe to push
   */
  async requestPermission() {
    if (!this.isSupported) {
      return { success: false, message: "Browser tidak mendukung notifikasi" };
    }

    if (this.permission === "granted") {
      const subResult = await this.subscribePush();
      return {
        success: true,
        message: "Notifikasi sudah diaktifkan",
        subscribed: subResult,
      };
    }

    if (this.permission === "denied") {
      return {
        success: false,
        message: "Notifikasi diblokir. Silakan aktifkan di pengaturan browser.",
      };
    }

    try {
      const permission = await Notification.requestPermission();
      this.permission = permission;

      if (permission === "granted") {
        const subResult = await this.subscribePush();
        this.showNotification("🔔 Notifikasi Aktif", {
          body: "Anda akan menerima notifikasi push secara real-time.",
          icon: "/assets/img/logo.png",
        });
        return {
          success: true,
          message: "Notifikasi berhasil diaktifkan!",
          subscribed: subResult,
        };
      }

      return { success: false, message: "Izin notifikasi ditolak" };
    } catch (error) {
      return {
        success: false,
        message: "Gagal meminta izin: " + error.message,
      };
    }
  }

  /**
   * Subscribe to real push notifications via VAPID
   */
  async subscribePush() {
    if (!this.isPushSupported || !this.swRegistration || !this.vapidPublicKey) {
      console.warn("[Push] Push not available");
      return false;
    }

    try {
      // Check if already subscribed
      let subscription =
        await this.swRegistration.pushManager.getSubscription();

      if (!subscription) {
        // Create new subscription
        const applicationServerKey = this._urlBase64ToUint8Array(
          this.vapidPublicKey,
        );
        subscription = await this.swRegistration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: applicationServerKey,
        });
        console.log("[Push] New subscription created");
      }

      // Send subscription to server
      const response = await fetch("/api/push-subscription.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "subscribe",
          subscription: subscription.toJSON(),
        }),
      });

      const result = await response.json();
      console.log("[Push] Subscription saved:", result.success);
      return result.success;
    } catch (error) {
      console.error("[Push] Subscribe failed:", error);
      return false;
    }
  }

  /**
   * Subscribe to push with employee_id (for Staff Portal)
   */
  async subscribePushAsEmployee(employeeId) {
    if (!this.isPushSupported || !this.swRegistration || !this.vapidPublicKey) {
      return false;
    }

    try {
      let subscription =
        await this.swRegistration.pushManager.getSubscription();

      if (!subscription) {
        const applicationServerKey = this._urlBase64ToUint8Array(
          this.vapidPublicKey,
        );
        subscription = await this.swRegistration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: applicationServerKey,
        });
      }

      const response = await fetch("/api/push-subscription.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "subscribe",
          subscription: subscription.toJSON(),
          employee_id: employeeId,
        }),
      });

      const result = await response.json();
      return result.success;
    } catch (error) {
      console.error("[Push] Employee subscribe failed:", error);
      return false;
    }
  }

  /**
   * Unsubscribe from push
   */
  async unsubscribePush() {
    if (!this.swRegistration) return false;

    try {
      const subscription =
        await this.swRegistration.pushManager.getSubscription();
      if (subscription) {
        // Notify server
        await fetch("/api/push-subscription.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            action: "unsubscribe",
            endpoint: subscription.endpoint,
          }),
        });

        await subscription.unsubscribe();
        console.log("[Push] Unsubscribed");
      }
      return true;
    } catch (error) {
      console.error("[Push] Unsubscribe failed:", error);
      return false;
    }
  }

  /**
   * Show notification (local, not push)
   */
  async showNotification(title, options = {}) {
    if (this.permission !== "granted") {
      console.warn("Notification permission not granted");
      return false;
    }

    const defaultOptions = {
      icon: "/assets/img/logo.png",
      badge: "/assets/img/badge.png",
      vibrate: [200, 100, 200],
      requireInteraction: true,
      tag: "adf-notification-" + Date.now(),
      ...options,
    };

    try {
      if (this.swRegistration) {
        await this.swRegistration.showNotification(title, defaultOptions);
      } else {
        new Notification(title, defaultOptions);
      }
      return true;
    } catch (error) {
      console.error("Failed to show notification:", error);
      return false;
    }
  }

  /**
   * Show end-shift notification
   */
  async showEndShiftNotification(data) {
    const title = "📊 Laporan End Shift";
    const options = {
      body:
        `${data.cashier_name} telah selesai shift\n` +
        `Total Penjualan: Rp ${this.formatNumber(data.total_sales || 0)}\n` +
        `Kas Akhir: Rp ${this.formatNumber(data.ending_cash || 0)}`,
      icon: "/assets/img/logo.png",
      badge: "/assets/img/badge.png",
      tag: "end-shift-" + (data.shift_id || Date.now()),
      data: {
        url: "/modules/reports/shift-report.php?id=" + (data.shift_id || ""),
        type: "end-shift",
      },
      actions: [
        { action: "view", title: "📋 Lihat Laporan" },
        { action: "dismiss", title: "✖️ Tutup" },
      ],
      requireInteraction: true,
      vibrate: [300, 100, 300, 100, 300],
    };

    return this.showNotification(title, options);
  }

  /**
   * Show new booking notification
   */
  async showBookingNotification(data) {
    const title = "🏨 Reservasi Baru";
    const options = {
      body:
        `Tamu: ${data.guest_name}\n` +
        `Kamar: ${data.room_number}\n` +
        `Check-in: ${data.check_in_date}`,
      icon: "/assets/img/logo.png",
      tag: "booking-" + (data.booking_id || Date.now()),
      data: {
        url: "/modules/frontdesk/index.php",
        type: "booking",
      },
    };

    return this.showNotification(title, options);
  }

  /**
   * Show income notification
   */
  async showIncomeNotification(data) {
    const title = "💰 Pendapatan Baru";
    const options = {
      body:
        `${data.description}\n` +
        `Jumlah: Rp ${this.formatNumber(data.amount || 0)}`,
      icon: "/assets/img/logo.png",
      tag: "income-" + Date.now(),
      data: {
        url: "/modules/cashbook/",
        type: "income",
      },
    };

    return this.showNotification(title, options);
  }

  /**
   * Format number with thousand separator
   */
  formatNumber(num) {
    return new Intl.NumberFormat("id-ID").format(num);
  }

  /**
   * Check if notifications are enabled
   */
  isEnabled() {
    return this.permission === "granted";
  }

  /**
   * Get notification status
   */
  getStatus() {
    return {
      supported: this.isSupported,
      serviceWorkerSupported: this.isServiceWorkerSupported,
      pushSupported: this.isPushSupported,
      permission: this.permission,
      enabled: this.permission === "granted",
    };
  }

  /**
   * Convert URL-safe base64 to Uint8Array (for applicationServerKey)
   */
  _urlBase64ToUint8Array(base64String) {
    const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding)
      .replace(/-/g, "+")
      .replace(/_/g, "/");
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
  }
}

// Create global instance
window.NotificationManager = new NotificationManager();

// Auto-initialize when DOM ready
document.addEventListener("DOMContentLoaded", () => {
  window.NotificationManager.init();
});

// Helper function to enable notifications
async function enableNotifications() {
  const result = await window.NotificationManager.requestPermission();
  return result;
}

// Helper function to send end-shift notification
async function sendEndShiftNotification(data) {
  return await window.NotificationManager.showEndShiftNotification(data);
}
