/* global self, clients */
self.addEventListener('push', (event) => {
  let payload = {};
  if (event.data) {
    try {
      payload = event.data.json();
    }
    catch (e) {
      payload = { title: 'Order MGA', body: event.data.text() };
    }
  }
  const title = payload.title || 'Order MGA';
  const body = payload.body || '';
  const url = payload.url || '';
  event.waitUntil(
    self.registration.showNotification(title, {
      body,
      data: { url },
      tag: payload.tag || 'order-mga',
      renotify: true,
      dir: 'ltr',
    }),
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data && event.notification.data.url
    ? event.notification.data.url
    : '';
  if (!url) {
    return;
  }
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
      for (let i = 0; i < windowClients.length; i++) {
        const c = windowClients[i];
        if (c.url === url && 'focus' in c) {
          return c.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(url);
      }
      return undefined;
    }),
  );
});
