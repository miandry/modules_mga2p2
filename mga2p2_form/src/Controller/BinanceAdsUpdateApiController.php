<?php

namespace Drupal\mga2p2_form\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\mga2p2_form\Service\BinanceAdsUpdater;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON API: update Binance C2C ad price.
 */
final class BinanceAdsUpdateApiController extends ControllerBase {

  private const NO_STORE = ['Cache-Control' => 'no-store, private'];

  public function __construct(
    protected BinanceAdsUpdater $adsUpdater,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('mga2p2_form.binance_ads_updater'),
    );
  }

  /**
   * POST JSON: { "advNo": "…", "price": "4600", "asset": "USDT", "fiat": "MGA" }.
   */
  public function updatePrice(Request $request): JsonResponse {
    $raw = $request->getContent();
    $body = json_decode($raw, TRUE);
    if (!is_array($body)) {
      return new JsonResponse(['error' => 'Invalid JSON body.'], 400, self::NO_STORE);
    }

    $advNo = isset($body['advNo']) && is_string($body['advNo']) ? trim($body['advNo']) : '';
    $price = isset($body['price']) ? trim((string) $body['price']) : '';

    if ($advNo === '') {
      return new JsonResponse(['error' => 'advNo is required.'], 400, self::NO_STORE);
    }
    if ($price === '') {
      return new JsonResponse(['error' => 'price is required.'], 400, self::NO_STORE);
    }

    $asset = isset($body['asset']) && is_string($body['asset']) && trim($body['asset']) !== ''
      ? trim($body['asset']) : NULL;
    $fiat = isset($body['fiat']) && is_string($body['fiat']) && trim($body['fiat']) !== ''
      ? trim($body['fiat']) : NULL;

    $result = $this->adsUpdater->updatePrice($advNo, $price, $asset, $fiat);

    if (!$result['ok']) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => $result['message'],
      ], 502, self::NO_STORE);
    }

    $payload = [
      'ok' => TRUE,
      'message' => $result['message'],
    ];
    if (!empty($result['ad'])) {
      $payload['ad'] = $result['ad'];
    }

    return new JsonResponse($payload, 200, self::NO_STORE);
  }

}
