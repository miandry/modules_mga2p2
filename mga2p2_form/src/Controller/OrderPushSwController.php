<?php

namespace Drupal\mga2p2_form\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the service worker for order status Web Push.
 */
class OrderPushSwController extends ControllerBase {

  /**
   * GET — JavaScript service worker (max scope via Service-Worker-Allowed).
   */
  public function serve(): Response {
    $module_path = \Drupal::service('extension.list.module')->getPath('mga2p2_form');
    $path = $module_path . '/js/order-push-sw.js';
    if (!is_readable($path)) {
      return new Response('// service worker missing', 404, ['Content-Type' => 'application/javascript; charset=utf-8']);
    }
    $body = (string) file_get_contents($path);
    return new Response($body, 200, [
      'Content-Type' => 'application/javascript; charset=utf-8',
      'Service-Worker-Allowed' => '/',
      'Cache-Control' => 'no-cache, no-store, must-revalidate',
    ]);
  }

}
