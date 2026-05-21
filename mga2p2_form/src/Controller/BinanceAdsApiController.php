<?php

namespace Drupal\mga2p2_form\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\mga2p2_form\Service\BinanceAdsLoader;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON API: Binance C2C merchant ads.
 */
final class BinanceAdsApiController extends ControllerBase {

  private const NO_STORE = ['Cache-Control' => 'no-store, private'];

  public function __construct(
    protected BinanceAdsLoader $adsLoader,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('mga2p2_form.binance_ads_loader'),
    );
  }

  /**
   * GET ?page=1&rows=50&asset=USDT&fiat=MGA&adv_no=…
   *
   * Loads merchant ads via Binance agent API; falls back to order-history derivation.
   * When adv_no is set, returns a single matching ad (or empty + error).
   */
  public function list(Request $request): JsonResponse {
    $page = max(1, (int) $request->query->get('page', 1));
    $rows = max(1, min(100, (int) $request->query->get('rows', 50)));

    $assetParam = $request->query->get('asset', '');
    $fiatParam = $request->query->get('fiat', '');
    $asset = is_string($assetParam) && trim($assetParam) !== '' ? trim($assetParam) : NULL;
    $fiat = is_string($fiatParam) && trim($fiatParam) !== '' ? trim($fiatParam) : NULL;

    $advNoParam = $request->query->get('adv_no', '');
    $advNo = is_string($advNoParam) && trim($advNoParam) !== '' ? trim($advNoParam) : NULL;

    $result = $this->adsLoader->load($page, $rows, $asset, $fiat, $advNo);

    $payload = [
      'data' => $result['data'],
      'total' => $result['total'],
      'source' => $result['source'],
      'page' => $result['page'],
      'rows' => $result['rows'],
    ];
    if (!empty($result['error'])) {
      $payload['error'] = $result['error'];
    }

    $status = ($result['data'] === [] && !empty($result['error'])) ? 502 : 200;
    return new JsonResponse($payload, $status, self::NO_STORE);
  }

}
