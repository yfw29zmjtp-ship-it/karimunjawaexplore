/* ============================================
   ADF System - Global Loading Indicator
   Auto-shows a top progress bar for background
   fetch/AJAX calls, and a full-screen overlay
   (spinner + ADF logo) for page navigation and
   form submits, so the app feels more responsive
   and professional while waiting.
   ============================================ */
;(function () {
  'use strict'

  if (window.__adfLoaderInit) return
  window.__adfLoaderInit = true

  // Resolve logo path relative to this script's own location so it works
  // no matter which BASE_URL / subfolder the page is served from.
  var LOGO_SRC = (function () {
    var scripts = document.getElementsByTagName('script')
    for (var i = 0; i < scripts.length; i++) {
      var src = scripts[i].getAttribute('src') || ''
      if (src.indexOf('global-loader.js') !== -1) {
        return src.replace(
          /assets\/js\/global-loader\.js.*$/,
          'assets/img/developer-logo.png'
        )
      }
    }
    return '/assets/img/developer-logo.png'
  })()

  // Background fetch/XHR requests matching any of these should stay silent
  // (frequent polling endpoints, service worker, etc.) to avoid flicker.
  var SILENT_URL_PATTERN =
    /get-notifications\.php|staff-chat\.php\?action=list|check-session|sw\.js|manifest\.php|heartbeat/i

  var bar = null
  var overlay = null
  var barTimer = null
  var barShowTimeout = null
  var overlayShowTimeout = null
  var activeRequests = 0
  var barProgress = 0

  function injectDom () {
    bar = document.createElement('div')
    bar.id = 'adf-loader-bar'
    document.body.appendChild(bar)

    overlay = document.createElement('div')
    overlay.id = 'adf-loader-overlay'
    overlay.innerHTML =
      '<div class="adf-loader-box">' +
      '<div class="adf-loader-spinner"><img class="adf-loader-logo" src="' +
      LOGO_SRC +
      '" alt="Loading"></div>' +
      '<div class="adf-loader-text">Memuat...</div>' +
      '</div>'
    document.body.appendChild(overlay)
  }

  function ensureCss () {
    if (document.getElementById('adf-loader-css')) return
    var scripts = document.getElementsByTagName('script')
    var cssHref = '/assets/css/global-loader.css'
    for (var i = 0; i < scripts.length; i++) {
      var src = scripts[i].getAttribute('src') || ''
      if (src.indexOf('global-loader.js') !== -1) {
        cssHref = src.replace(
          /assets\/js\/global-loader\.js.*$/,
          'assets/css/global-loader.css'
        )
        break
      }
    }
    var link = document.createElement('link')
    link.id = 'adf-loader-css'
    link.rel = 'stylesheet'
    link.href = cssHref
    document.head.appendChild(link)
  }

  // ---------- Top progress bar (subtle, for background AJAX) ----------
  function startBar () {
    if (!bar) return
    clearInterval(barTimer)
    barProgress = 10
    bar.style.width = barProgress + '%'
    bar.classList.add('adf-active')
    barTimer = setInterval(function () {
      // Ease towards 90%, never completes on its own.
      barProgress += (90 - barProgress) * 0.08
      bar.style.width = barProgress + '%'
    }, 200)
  }

  function finishBar () {
    if (!bar) return
    clearInterval(barTimer)
    bar.style.width = '100%'
    setTimeout(function () {
      bar.classList.remove('adf-active')
      setTimeout(function () {
        bar.style.width = '0%'
      }, 250)
    }, 200)
  }

  function showBarDelayed () {
    if (barShowTimeout || (bar && bar.classList.contains('adf-active'))) return
    barShowTimeout = setTimeout(function () {
      barShowTimeout = null
      startBar()
    }, 150)
  }

  function hideBarDelayed () {
    if (barShowTimeout) {
      clearTimeout(barShowTimeout)
      barShowTimeout = null
      return
    }
    finishBar()
  }

  // ---------- Full-screen overlay (page navigation / heavy actions) ----------
  function showOverlay () {
    if (!overlay) return
    clearTimeout(overlayShowTimeout)
    overlayShowTimeout = setTimeout(function () {
      overlay.classList.add('adf-active')
    }, 80)
  }

  function hideOverlay () {
    clearTimeout(overlayShowTimeout)
    if (overlay) overlay.classList.remove('adf-active')
  }

  // ---------- Public API ----------
  window.ADFLoader = {
    show: showOverlay,
    hide: hideOverlay,
    showBar: startBar,
    hideBar: finishBar
  }

  // ---------- Patch fetch() ----------
  var originalFetch = window.fetch
  if (originalFetch) {
    window.fetch = function (input, init) {
      var url = typeof input === 'string' ? input : (input && input.url) || ''
      var silent = SILENT_URL_PATTERN.test(url) || (init && init.loaderSilent)

      if (!silent) {
        activeRequests++
        showBarDelayed()
      }

      var done = function () {
        if (silent) return
        activeRequests--
        if (activeRequests <= 0) {
          activeRequests = 0
          hideBarDelayed()
        }
      }

      var promise = originalFetch.apply(this, arguments)
      promise.then(done, done)
      return promise
    }
  }

  // ---------- Patch XMLHttpRequest (legacy AJAX calls) ----------
  var OriginalXHROpen = XMLHttpRequest.prototype.open
  var OriginalXHRSend = XMLHttpRequest.prototype.send

  XMLHttpRequest.prototype.open = function (method, url) {
    this.__adfLoaderSilent = SILENT_URL_PATTERN.test(url || '')
    return OriginalXHROpen.apply(this, arguments)
  }

  XMLHttpRequest.prototype.send = function () {
    if (!this.__adfLoaderSilent) {
      activeRequests++
      showBarDelayed()
      var finalize = function () {
        activeRequests--
        if (activeRequests <= 0) {
          activeRequests = 0
          hideBarDelayed()
        }
      }
      this.addEventListener('loadend', finalize)
    }
    return OriginalXHRSend.apply(this, arguments)
  }

  // ---------- Full page navigation via links ----------
  document.addEventListener(
    'click',
    function (e) {
      if (
        e.defaultPrevented ||
        e.button !== 0 ||
        e.metaKey ||
        e.ctrlKey ||
        e.shiftKey ||
        e.altKey
      )
        return

      var link = e.target.closest ? e.target.closest('a[href]') : null
      if (!link) return

      var href = link.getAttribute('href') || ''
      if (
        href === '' ||
        href.charAt(0) === '#' ||
        href.indexOf('javascript:') === 0 ||
        href.indexOf('mailto:') === 0 ||
        href.indexOf('tel:') === 0 ||
        link.hasAttribute('download') ||
        (link.target && link.target !== '' && link.target !== '_self') ||
        link.hasAttribute('data-no-loader')
      )
        return

      // Only for same-origin navigation (external links open/behave differently).
      try {
        var target = new URL(href, window.location.href)
        if (target.origin !== window.location.origin) return
        if (target.href === window.location.href) return
      } catch (err) {
        return
      }

      showOverlay()
    },
    true
  )

  // ---------- Full page navigation via form submits ----------
  document.addEventListener(
    'submit',
    function (e) {
      var form = e.target
      if (!form || form.hasAttribute('data-no-loader') || e.defaultPrevented)
        return
      // AJAX forms usually call e.preventDefault() themselves; by the time this
      // listener runs (bubble phase) that flag is already set, so we naturally
      // skip those and only show the overlay for real full-page submits.
      showOverlay()
    },
    false
  )

  // Make sure a stuck overlay never blocks the UI if navigation gets cancelled
  // (e.g. back/forward cache restore).
  window.addEventListener('pageshow', function (e) {
    hideOverlay()
    finishBar()
  })

  window.addEventListener('pagehide', function () {
    // Let the overlay stay visible during the actual unload transition.
  })

  function init () {
    ensureCss()
    injectDom()
  }

  if (document.body) {
    init()
  } else {
    document.addEventListener('DOMContentLoaded', init)
  }
})()
