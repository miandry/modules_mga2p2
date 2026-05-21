<?php

namespace Drupal\mga2p2_form\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\mga2p2_form\Access\FormAdministratorAccess;
use Drupal\mga2p2_form\Service\C2cOrderActionService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON API: change Binance C2C order status (mark paid, release, cancel).
 */
final class BinanceC2cOrderActionApiController extends ControllerBase {

  private const NO_STORE = ['Cache-Control' => 'no-store, private'];

  public function __construct(
    protected C2cOrderActionService $orderAction,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('mga2p2_form.c2c_order_action'),
    );
  }

  /**
   * POST JSON: { "orderNo": "…", "action": "mark_paid"|"release"|"cancel" }.
   */
  public function action(Request $request): JsonResponse {
    if ($denied = FormAdministratorAccess::denyUnlessAdministrator($this->currentUser())) {
      return $denied;
    }

    $raw = $request->getContent();
    $body = json_decode($raw, TRUE);
    if (!is_array($body)) {
      return new JsonResponse(['error' => 'Invalid JSON body.'], 400, self::NO_STORE);
    }

    $orderNo = isset($body['orderNo']) && is_string($body['orderNo']) ? trim($body['orderNo']) : '';
    $action = isset($body['action']) && is_string($body['action']) ? trim($body['action']) : '';

    if ($orderNo === '') {
      return new JsonResponse(['error' => 'orderNo is required.'], 400, self::NO_STORE);
    }
    if ($action === '') {
      return new JsonResponse(['error' => 'action is required.'], 400, self::NO_STORE);
    }

    $result = $this->orderAction->execute($orderNo, $action);

    if (!$result['ok']) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => $result['message'],
      ], 502, self::NO_STORE);
    }

    return new JsonResponse([
      'ok' => TRUE,
      'message' => $result['message'],
    ], 200, self::NO_STORE);
  }

}
