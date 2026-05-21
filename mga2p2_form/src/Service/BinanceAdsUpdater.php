<?php

namespace Drupal\mga2p2_form\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\mga2p2\BinanceClient;

/**
 * Updates Binance C2C merchant ads via agent API.
 */
final class BinanceAdsUpdater {

  private const UPDATE_PATH = '/sapi/v1/c2c/agent/ads/update';

  public function __construct(
    private readonly BinanceClient $binanceClient,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly BinanceAdsLoader $adsLoader,
  ) {}

  /**
   * @return array{ok: bool, message: string, ad?: array<string, mixed>, raw?: array<string, mixed>}
   */
  public function updatePrice(string $advNo, string $price, ?string $asset = NULL, ?string $fiat = NULL): array {
    $advNo = trim($advNo);
    $price = trim($price);

    if ($advNo === '') {
      return ['ok' => FALSE, 'message' => 'advNo is required.'];
    }
    if ($price === '' || !is_numeric($price) || (float) $price <= 0) {
      return ['ok' => FALSE, 'message' => 'Invalid price.'];
    }

    if (!$this->binanceClient->isConfigured()) {
      return ['ok' => FALSE, 'message' => 'Binance API credentials are not configured.'];
    }

    if (!$this->binanceClient->isPathAllowed(self::UPDATE_PATH)) {
      return ['ok' => FALSE, 'message' => 'Path not allowed: ' . self::UPDATE_PATH];
    }

    $body = [
      'advNo' => $advNo,
      'price' => $price,
    ];

    $result = $this->binanceClient->signedAgentPost(self::UPDATE_PATH, $body);
    if (!empty($result['error'])) {
      return ['ok' => FALSE, 'message' => (string) $result['error']];
    }

    $decoded = json_decode((string) ($result['body'] ?? ''), TRUE);
    if (!is_array($decoded)) {
      return ['ok' => FALSE, 'message' => 'Invalid JSON from Binance update API.'];
    }

    if (!$this->isBinanceSuccess($decoded)) {
      $msg = (string) ($decoded['message'] ?? $decoded['msg'] ?? 'Binance update failed.');
      $this->loggerFactory->get('mga2p2_form')->warning('Binance ad price update: @m', ['@m' => $msg]);
      return ['ok' => FALSE, 'message' => $msg, 'raw' => $decoded];
    }

    $reload = $this->adsLoader->loadOne($advNo, $asset, $fiat);
    $ad = $reload['data'][0] ?? NULL;

    return [
      'ok' => TRUE,
      'message' => 'Prix mis à jour.',
      'ad' => is_array($ad) ? $ad : NULL,
      'raw' => $decoded,
    ];
  }

  /**
   * @param array<string, mixed> $decoded
   */
  private function isBinanceSuccess(array $decoded): bool {
    if (isset($decoded['success']) && $decoded['success'] === FALSE) {
      return FALSE;
    }
    if (isset($decoded['code'])) {
      $code = (string) $decoded['code'];
      return $code === '000000' || $code === '0';
    }
    return TRUE;
  }

}
