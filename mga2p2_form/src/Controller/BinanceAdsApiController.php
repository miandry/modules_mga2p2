<?php

namespace Drupal\mga2p2_form\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\mga2p2_form\Access\FormAdministratorAccess;
use Drupal\mga2p2_form\Service\BinanceAdsLoader;
use Drupal\mga2p2_form\Service\BinanceMarketAdsSearch;
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
    protected BinanceMarketAdsSearch $marketSearch,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('mga2p2_form.binance_ads_loader'),
      $container->get('mga2p2_form.binance_market_ads_search'),
    );
  }

  /**
   * GET ?page=1&rows=50&asset=USDT&fiat=MGA&adv_no=…
   *
   * Loads merchant ads via Binance agent API; falls back to order-history derivation.
   * When adv_no is set, returns a single matching ad (or empty + error).
   */
  public function list(Request $request): JsonResponse {
    if ($denied = FormAdministratorAccess::denyUnlessAdministrator($this->currentUser())) {
      return $denied;
    }

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

  /**
   * GET ?asset=USDT&fiat=MGA&tradeType=SELL&adv_no=…
   *
   * Returns top 5 highest + top 5 lowest competitor P2P prices for repricing.
   */
  public function marketPrices(Request $request): JsonResponse {
    if ($denied = FormAdministratorAccess::denyUnlessAdministrator($this->currentUser())) {
      return $denied;
    }

    $assetParam = $request->query->get('asset', '');
    $fiatParam = $request->query->get('fiat', '');
    $tradeParam = $request->query->get('tradeType', '');
    $advNoParam = $request->query->get('adv_no', '');

    $asset = is_string($assetParam) ? trim($assetParam) : '';
    $fiat = is_string($fiatParam) ? trim($fiatParam) : '';
    $tradeType = is_string($tradeParam) ? trim($tradeParam) : '';
    $advNo = is_string($advNoParam) ? trim($advNoParam) : NULL;

    if ($asset === '' || $fiat === '') {
      return new JsonResponse(['error' => 'asset and fiat are required.'], 400, self::NO_STORE);
    }
    if ($tradeType === '') {
      $tradeType = 'BUY';
    }

    $pages = max(3, min(8, (int) $request->query->get('pages', 5)));

    $result = $this->marketSearch->competitorPrices($asset, $fiat, $tradeType, $advNo, $pages);

    $payload = [
      'data' => $result['data'] ?? [],
      'highest' => $result['highest'],
      'lowest' => $result['lowest'],
      'total' => $result['total'],
      'searchTradeType' => $result['searchTradeType'],
      'adTradeType' => $result['adTradeType'] ?? $tradeType,
      'source' => $result['source'],
      'minPrice' => $result['minPrice'] ?? NULL,
      'maxPrice' => $result['maxPrice'] ?? NULL,
      'avgPrice' => $result['avgPrice'] ?? NULL,
    ];
    if (!empty($result['error'])) {
      $payload['error'] = $result['error'];
    }

    $status = ($result['total'] === 0 && !empty($result['error'])) ? 502 : 200;
    return new JsonResponse($payload, $status, self::NO_STORE);
  }

}
