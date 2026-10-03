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

  event.waitUntil(
    self.registration
      .showNotification(data.title, options)
      .then(() => self.registration.getNotifications())
      .then(list => {
        if ('setAppBadge' in navigator)
          return navigator.setAppBadge(list.length)
      })
  )
})

self.addEventListener('notificationclick', event => {
  event.notification.close()
  const urlToOpen =
    event.notification.data && event.notification.data.url
      ? event.notification.data.url
      : './owner-dashboard.php'

  event.waitUntil(
    self.registration
      .getNotifications()
      .then(list => {
        if (!('setAppBadge' in navigator)) return
        return list.length > 0
          ? navigator.setAppBadge(list.length)
          : navigator.clearAppBadge()
      })
      .then(() =>
        clients.matchAll({ type: 'window', includeUncontrolled: true })
      )
      .then(clientList => {
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

// Dipanggil dari halaman (owner-push.js) saat app dibuka, supaya badge angka
// di icon ter-reset karena notifikasi dianggap sudah dilihat.
self.addEventListener('message', event => {
  if (!event.data || event.data.type !== 'CLEAR_BADGE') return
  event.waitUntil(
    self.registration.getNotifications().then(list => {
      list.forEach(n => n.close())
      if ('setAppBadge' in navigator) return navigator.clearAppBadge()
    })
  )
})
