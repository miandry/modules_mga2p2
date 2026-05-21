<?php

namespace Drupal\mga2p2_form\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\mga2p2\BinanceClient;

/**
 * Executes Binance C2C order status actions (mark paid, release, cancel).
 */
final class C2cOrderActionService {

  private const MARK_PAID_PATH = '/sapi/v1/c2c/orderMatch/markOrderAsPaid';

  private const RELEASE_PATH = '/sapi/v1/c2c/orderMatch/releaseCoin';

  private const CANCEL_PATH = '/sapi/v1/c2c/orderMatch/cancelOrder';

  public function __construct(
    private readonly BinanceClient $binanceClient,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * @return array{ok: bool, message: string, raw?: array<string, mixed>}
   */
  public function execute(string $orderNo, string $action): array {
    $orderNo = trim($orderNo);
    $action = strtolower(trim($action));

    if ($orderNo === '') {
      return ['ok' => FALSE, 'message' => 'orderNo is required.'];
    }

    $path = match ($action) {
      'mark_paid', 'mark-paid', 'paid' => self::MARK_PAID_PATH,
      'release', 'release_coin' => self::RELEASE_PATH,
      'cancel' => self::CANCEL_PATH,
      default => NULL,
    };

    if ($path === NULL) {
      return ['ok' => FALSE, 'message' => 'Invalid action. Use: mark_paid, release, cancel.'];
    }

    if (!$this->binanceClient->isConfigured()) {
      return ['ok' => FALSE, 'message' => 'Binance API credentials are not configured.'];
    }

    if (!$this->binanceClient->isPathAllowed($path)) {
      return ['ok' => FALSE, 'message' => 'Path not allowed: ' . $path];
    }

    $result = $this->binanceClient->signedPost($path, ['orderNo' => $orderNo]);
    if (!empty($result['error'])) {
      return ['ok' => FALSE, 'message' => (string) $result['error']];
    }

    $decoded = json_decode((string) ($result['body'] ?? ''), TRUE);
    if (!is_array($decoded)) {
      return ['ok' => FALSE, 'message' => 'Invalid JSON from Binance.'];
    }

    if (!$this->isBinanceSuccess($decoded)) {
      $msg = (string) ($decoded['message'] ?? $decoded['msg'] ?? 'Binance action failed.');
      $this->loggerFactory->get('mga2p2_form')->warning('C2C order @action @ord: @m', [
        '@action' => $action,
        '@ord' => $orderNo,
        '@m' => $msg,
      ]);
      return ['ok' => FALSE, 'message' => $msg, 'raw' => $decoded];
    }

    $label = match ($action) {
      'mark_paid', 'mark-paid', 'paid' => 'Ordre marqué comme payé.',
      'release', 'release_coin' => 'Crypto libérée.',
      'cancel' => 'Ordre annulé.',
      default => 'Action effectuée.',
    };

    return [
      'ok' => TRUE,
      'message' => $label,
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
