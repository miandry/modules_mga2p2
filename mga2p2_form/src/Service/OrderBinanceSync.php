<?php

namespace Drupal\mga2p2_form\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\mga2p2\BinanceClient;
use Drupal\node\NodeInterface;

/**
 * Syncs active Binance C2C orders into order_binance nodes.
 */
final class OrderBinanceSync {

  private const HISTORY_PATH = '/sapi/v1/c2c/orderMatch/listUserOrderHistory';

  /**
   * Binance statuses treated as "en cours" for sync.
   *
   * @var string[]
   */
  private const ACTIVE_STATUSES = [
    'TRADING',
  ];

  public function __construct(
    private readonly BinanceClient $binanceClient,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Runs one sync batch.
   */
  public function run(): void {
    $log = $this->loggerFactory->get('mga2p2_form');
    if (!$this->binanceClient->isConfigured()) {
      return;
    }
    if (!$this->binanceClient->isPathAllowed(self::HISTORY_PATH)) {
      $log->warning('order_binance cron skipped: Binance path not allowed.');
      return;
    }
    if (!$this->entityTypeManager->getStorage('node_type')->load('order_binance')) {
      $log->warning('order_binance cron skipped: content type not installed.');
      return;
    }

    $result = $this->binanceClient->signedGet(self::HISTORY_PATH, [
      'tradeType' => 'BUY',
      'page' => 1,
      'rows' => 100,
    ]);
    if (!empty($result['error'])) {
      $log->error('order_binance cron fetch error: @m', ['@m' => (string) $result['error']]);
      return;
    }

    $decoded = json_decode((string) ($result['body'] ?? ''), TRUE);
    if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
      $log->warning('order_binance cron: invalid Binance response shape.');
      return;
    }

    $created = 0;
    $updated = 0;
    foreach ($decoded['data'] as $row) {
      if (!is_array($row)) {
        continue;
      }
      $status = strtoupper(trim((string) ($row['orderStatus'] ?? '')));
      if (!in_array($status, self::ACTIVE_STATUSES, TRUE)) {
        continue;
      }
      $orderNumber = trim((string) ($row['orderNumber'] ?? ''));
      if ($orderNumber === '') {
        continue;
      }
      if ($this->upsertOrder($row, $orderNumber)) {
        $updated++;
      }
      else {
        $created++;
      }
    }

    if ($created > 0 || $updated > 0) {
      $log->notice('order_binance cron synced. Created: @c, updated: @u.', [
        '@c' => $created,
        '@u' => $updated,
      ]);
    }
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return bool
   *   TRUE when an existing node was updated, FALSE when newly created.
   */
  private function upsertOrder(array $row, string $orderNumber): bool {
    $storage = $this->entityTypeManager->getStorage('node');
    $existing = $this->loadByOrderNumber($orderNumber);
    $isUpdate = $existing instanceof NodeInterface;
    $node = $existing ?? $storage->create([
      'type' => 'order_binance',
      'title' => 'Order Binance ' . $orderNumber,
      'status' => NodeInterface::PUBLISHED,
    ]);

    $map = [
      'field_binance_order_number' => 'orderNumber',
      'field_binance_adv_no' => 'advNo',
      'field_binance_trade_type' => 'tradeType',
      'field_binance_asset' => 'asset',
      'field_binance_fiat' => 'fiat',
      'field_binance_fiat_symbol' => 'fiatSymbol',
      'field_binance_amount' => 'amount',
      'field_binance_total_price' => 'totalPrice',
      'field_binance_unit_price' => 'unitPrice',
      'field_binance_order_status' => 'orderStatus',
      'field_binance_create_time' => 'createTime',
      'field_binance_commission' => 'commission',
      'field_binance_taker_comm_rate' => 'takerCommissionRate',
      'field_binance_taker_comm' => 'takerCommission',
      'field_binance_taker_amount' => 'takerAmount',
      'field_binance_counterpart_nick' => 'counterPartNickName',
      'field_binance_pay_method' => 'payMethodName',
      'field_binance_additional_kyc' => 'additionalKycVerify',
    ];

    foreach ($map as $field => $key) {
      if (!$node->hasField($field)) {
        continue;
      }
      $value = $row[$key] ?? NULL;
      if ($value === NULL || $value === '') {
        $node->set($field, NULL);
        continue;
      }
      if (in_array($field, ['field_binance_create_time', 'field_binance_additional_kyc'], TRUE)) {
        $node->set($field, (int) $value);
      }
      else {
        $node->set($field, (string) $value);
      }
    }

    $node->save();
    return $isUpdate;
  }

  private function loadByOrderNumber(string $orderNumber): ?NodeInterface {
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'order_binance')
      ->condition('field_binance_order_number.value', $orderNumber)
      ->range(0, 1)
      ->execute();
    if (!$nids) {
      return NULL;
    }
    $nid = (int) reset($nids);
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    return $node instanceof NodeInterface ? $node : NULL;
  }

}

