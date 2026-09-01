/**
 * Service Worker - Buyer Portal PWA
 */

const CACHE_NAME = 'pwf-buyer-portal-v8'
const APP_SHELL = [
  './customer-portal.php',
  './customer-portal.php?',
  'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap'
]

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
          keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
        )
      )
      .then(() => self.clients.claim())
  )
})

self.addEventListener('fetch', event => {
  const req = event.request
  const url = new URL(req.url)

  if (req.method !== 'GET') {
    return
  }

  const isBuyerPortalRoute =
    url.pathname.includes('customer-portal.php') ||
    url.pathname.includes('customer-manifest.php')

  if (isBuyerPortalRoute) {
    event.respondWith(networkFirst(req))
    return
  }

  if (
    url.hostname.includes('fonts.googleapis.com') ||
    url.hostname.includes('fonts.gstatic.com')
  ) {
    event.respondWith(cacheFirst(req))
    return
  }

  // Do not intercept admin/internal pages outside buyer portal routes.
  // Let browser handle network request normally.
  return
})

async function cacheFirst (request) {
  const cached = await caches.match(request)
  if (cached) return cached

  try {
    const response = await fetch(request)
    if (response && response.ok) {
      const cache = await caches.open(CACHE_NAME)
      cache.put(request, response.clone())
    }
    return response
  } catch (error) {
    return new Response('Offline', { status: 503, statusText: 'Offline' })
  }
}

async function networkFirst (request) {
  try {
    const response = await fetch(request)
    if (response && response.ok) {
      const cache = await caches.open(CACHE_NAME)
      cache.put(request, response.clone())
    }
    return response
  } catch (error) {
    const cached = await caches.match(request)
    if (cached) return cached

    if (request.mode !== 'navigate') {
      return new Response('Offline', { status: 503, statusText: 'Offline' })
    }

    return new Response(
      "<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>Offline</title></head><body style='font-family:sans-serif;padding:24px'><h3>Offline</h3><p>Koneksi internet terputus. Silakan coba lagi.</p></body></html>",
      { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
    )
  }
}
