<?php

namespace Drupal\mga2p2_form\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Stores or removes a Web Push subscription (PushManager JSON).
 */
class PushSubscribeApiController extends ControllerBase {

  public function subscribe(Request $request): JsonResponse {
    $config = $this->config('mga2p2_form.webpush');
    if (!(bool) $config->get('enabled') || (string) $config->get('vapid_public') === '') {
      return new JsonResponse(['error' => 'Web push is not configured.'], 503);
    }

    $raw = $request->getContent();
    $body = json_decode($raw, TRUE);
    if (!is_array($body) || empty($body['endpoint']) || !is_string($body['endpoint'])) {
      return new JsonResponse(['error' => 'Invalid subscription JSON (endpoint required).'], 400);
    }
    $keys = isset($body['keys']) && is_array($body['keys']) ? $body['keys'] : [];
    $p256dh = isset($keys['p256dh']) && is_string($keys['p256dh']) ? $keys['p256dh'] : '';
    $auth = isset($keys['auth']) && is_string($keys['auth']) ? $keys['auth'] : '';
    if ($p256dh === '' || $auth === '') {
      return new JsonResponse(['error' => 'Invalid subscription keys (p256dh, auth).'], 400);
    }

    $endpoint = $body['endpoint'];
    $hash = hash('sha256', $endpoint);
    $now = (int) \Drupal::time()->getRequestTime();

    $schema = \Drupal::database()->schema();
    if (!$schema->tableExists('mga2p2_form_push_subscription')) {
      return new JsonResponse(['error' => 'Push storage is not installed. Run database updates.'], 503);
    }

    \Drupal::database()->merge('mga2p2_form_push_subscription')
      ->key('endpoint_hash', $hash)
      ->fields([
        'endpoint_hash' => $hash,
        'endpoint' => $endpoint,
        'p256dh' => $p256dh,
        'auth' => $auth,
        'created' => $now,
      ])
      ->execute();

    return new JsonResponse(['ok' => TRUE]);
  }

  public function unsubscribe(Request $request): JsonResponse {
    $raw = $request->getContent();
    $body = json_decode($raw, TRUE);
    if (!is_array($body) || empty($body['endpoint']) || !is_string($body['endpoint'])) {
      return new JsonResponse(['error' => 'JSON body must include "endpoint".'], 400);
    }
    $hash = hash('sha256', $body['endpoint']);
    $schema = \Drupal::database()->schema();
    if (!$schema->tableExists('mga2p2_form_push_subscription')) {
      return new JsonResponse(['ok' => TRUE]);
    }
    \Drupal::database()->delete('mga2p2_form_push_subscription')
      ->condition('endpoint_hash', $hash)
      ->execute();
    return new JsonResponse(['ok' => TRUE]);
  }

}
