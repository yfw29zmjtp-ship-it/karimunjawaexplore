/**
 * Service Worker — Owner Portal PWA
 * Minimal caching so the owner dashboard can be installed on phone.
 */
const CACHE_NAME = 'owner-portal-v1'
const APP_SHELL = ['./owner-dashboard.php']

self.addEventListener('install', event => {
  event.waitUntil(
    caches
      .open(CACHE_NAME)
      .then(cache => Promise.allSettled(APP_SHELL.map(url => cache.add(url))))
      .then(() => self.skipWaiting())
  )
})

self.addEventListener('activate', event => {
  event.waitUntil(
    caches
      .keys()
      .then(keys =>
        Promise.all(
          keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
        )
      )
      .then(() => self.clients.claim())
  )
})

self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return
  event.respondWith(
    fetch(event.request).catch(() => caches.match(event.request))
  )
})

// ── PUSH EVENT — real server push, works even when the app is closed ────
self.addEventListener('push', event => {
  let data = {
    title: 'Owner Portal',
    body: 'Ada notifikasi baru',
    icon: '/uploads/icons/favicon.png',
    badge: '/uploads/icons/favicon.png',
    tag: 'owner-notification',
    data: {}
  }

  if (event.data) {
    try {
      data = { ...data, ...event.data.json() }
    } catch (e) {
      data.body = event.data.text()
    }
  }

  const options = {
    body: data.body,
    icon: data.icon || '/uploads/icons/favicon.png',
    badge: data.badge || '/uploads/icons/favicon.png',
    tag: data.tag || 'owner-push-' + Date.now(),
    vibrate: data.vibrate || [200, 100, 200],
    requireInteraction: true,
    data: data.data || {}
  }

  event.waitUntil(self.registration.showNotification(data.title, options))
})

self.addEventListener('notificationclick', event => {
  event.notification.close()
  const urlToOpen = event.notification.data && event.notification.data.url
    ? event.notification.data.url
    : './owner-dashboard.php'

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
      for (const client of clientList) {
        if ('focus' in client) {
          client.navigate(urlToOpen)
          return client.focus()
        }
      }
      if (clients.openWindow) return clients.openWindow(urlToOpen)
    })
  )
})
