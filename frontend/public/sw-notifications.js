 

/**
 * Service Worker for EVE Tools push notifications.
 * Handles push events and notification clicks.
 */

self.addEventListener('push', function (event) {
  if (!event.data) return

  let payload
  try {
    payload = event.data.json()
  } catch {
    payload = { title: 'EVE Tools', body: event.data.text() }
  }

  const options = {
    body: payload.body || payload.message || '',
    icon: '/favicon.ico',
    badge: '/favicon.ico',
    tag: payload.tag || payload.id || 'eve-tools-notification',
    data: {
      route: payload.route || payload.url || '/',
    },
    requireInteraction: payload.level === 'critical' || payload.level === 'error',
  }

  event.waitUntil(
    self.registration.showNotification(payload.title || 'EVE Tools', options)
  )
})

self.addEventListener('notificationclick', function (event) {
  event.notification.close()

  const route = event.notification.data?.route || '/'

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
      // Focus an existing window if available
      for (var i = 0; i < clientList.length; i++) {
        var client = clientList[i]
        if (client.url.includes(self.location.origin) && 'focus' in client) {
          client.focus()
          client.postMessage({ type: 'NOTIFICATION_CLICK', route: route })
          return
        }
      }
      // Otherwise open a new window
      if (self.clients.openWindow) {
        return self.clients.openWindow(route)
      }
    })
  )
})
