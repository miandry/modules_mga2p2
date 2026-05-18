<?php

namespace Drupal\mga2p2_form\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Public VAPID key + enabled flag for browser subscription.
 */
class PushConfigApiController extends ControllerBase {

  public function index(): JsonResponse {
    $config = $this->config('mga2p2_form.webpush');
    $enabled = (bool) $config->get('enabled');
    $public = (string) $config->get('vapid_public');
    if ($public === '') {
      $enabled = FALSE;
    }
    return new JsonResponse([
      'enabled' => $enabled,
      'publicKey' => $public !== '' ? $public : NULL,
    ]);
  }

}
