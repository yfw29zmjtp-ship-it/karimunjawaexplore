/**
 * ADF System - Service Worker
 * Push Notifications + Face-API Model Caching for instant Face ID
 */

const CACHE_NAME = 'adf-system-v5'
const FACE_CACHE = 'face-models-v1'

// URLs to cache for Face ID (models + library)
const FACE_URLS = [
  'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/dist/face-api.min.js',
  'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights/tiny_face_detector_model-weights_manifest.json',
  'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights/tiny_face_detector_model-shard1',
  'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights/face_landmark_68_tiny_model-weights_manifest.json',
  'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights/face_landmark_68_tiny_model-shard1',
  'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights/face_recognition_model-weights_manifest.json',
  'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights/face_recognition_model-shard1',
  'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights/face_recognition_model-shard2'
]

// Install event — pre-cache face models
self.addEventListener('install', event => {
  console.log('[SW] Installing — pre-caching face models')
  event.waitUntil(
    caches.open(FACE_CACHE).then(cache => {
      return cache.addAll(FACE_URLS).catch(err => {
        console.warn('[SW] Some face models failed to cache:', err)
      })
    })
  )
  self.skipWaiting()
})

// Activate event — clean old caches
self.addEventListener('activate', event => {
  console.log('[SW] Activated')
  event.waitUntil(
    caches
      .keys()
      .then(names => {
        return Promise.all(
          names
            .filter(n => n !== CACHE_NAME && n !== FACE_CACHE)
            .map(n => caches.delete(n))
        )
      })
      .then(() => clients.claim())
  )
})

// Fetch event — serve face models from cache (cache-first strategy)
self.addEventListener('fetch', event => {
  const url = event.request.url

  // Cache-first for face-api.js library and model weights
  if (
    url.includes('face-api') ||
    url.includes('face_landmark') ||
    url.includes('face_recognition') ||
    url.includes('tiny_face_detector') ||
    url.includes('face-weights')
  ) {
    event.respondWith(
      caches.match(event.request).then(cached => {
        if (cached) return cached
        return fetch(event.request).then(response => {
          if (response.ok) {
            const clone = response.clone()
            caches
              .open(FACE_CACHE)
              .then(cache => cache.put(event.request, clone))
          }
          return response
        })
      })
    )
    return
  }

  // All other requests — network only (no interference)
})

// ═══ PUSH EVENT — real server push via VAPID ═══
self.addEventListener('push', event => {
  console.log('[SW] Push received')

  let data = {
    title: 'ADF System',
    body: 'Ada notifikasi baru',
    icon: '/assets/img/logo.png',
    badge: '/assets/img/badge.png',
    tag: 'adf-notification',
    data: {}
  }

  if (event.data) {
    try {
      const payload = event.data.json()
      data = { ...data, ...payload }
    } catch (e) {
      data.body = event.data.text()
    }
  }

  const options = {
    body: data.body,
    icon: data.icon || '/assets/img/logo.png',
    badge: data.badge || '/assets/img/badge.png',
    tag: data.tag || 'adf-push-' + Date.now(),
    vibrate: data.vibrate || [200, 100, 200, 100, 200],
    requireInteraction: true,
    data: data.data || {},
    actions: [
      { action: 'view', title: '👁️ Lihat Detail' },
      { action: 'dismiss', title: '✖️ Tutup' }
    ]
  }

  event.waitUntil(self.registration.showNotification(data.title, options))
})

// ═══ NOTIFICATION CLICK ═══
self.addEventListener('notificationclick', event => {
  event.notification.close()

  if (event.action === 'dismiss') return

  const urlToOpen = event.notification.data?.url || '/index.php'

  event.waitUntil(
    clients
      .matchAll({ type: 'window', includeUncontrolled: true })
      .then(clientList => {
        for (const client of clientList) {
          if ('focus' in client) {
            client.navigate(urlToOpen)
            return client.focus()
          }
        }
        if (clients.openWindow) {
          return clients.openWindow(urlToOpen)
        }
      })
  )
})

// ═══ PUSH SUBSCRIPTION CHANGE (browser rotates keys) ═══
self.addEventListener('pushsubscriptionchange', event => {
  console.log('[SW] Subscription changed, re-subscribing...')
  event.waitUntil(
    self.registration.pushManager
      .subscribe(event.oldSubscription.options)
      .then(newSub => {
        return fetch('/api/push-subscription.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'subscribe',
            subscription: newSub.toJSON()
          })
        })
      })
  )
})

// Background sync for offline capability
self.addEventListener('sync', event => {
  if (event.tag === 'sync-notifications') {
    event.waitUntil(syncNotifications())
  }
})

async function syncNotifications () {
  console.log('[SW] Syncing notifications...')
}
