<?php

namespace Drupal\mga2p2;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Matches receipt montant to Binance C2C order history (same rules as totalPrice filter).
 *
 * Used on receipt / order save to set reference to Binance orderNumber when unambiguous.
 */
final class ReceiptC2cOrderMatcher {

  private const HISTORY_PATH = '/sapi/v1/c2c/orderMatch/listUserOrderHistory';

  /** Max pages per trade side (100 rows each). */
  private const MAX_PAGES = 5;

  public function __construct(
    protected BinanceClient $client,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * @param array<string, mixed> $merged
   *   Merged extracted + form fields (montant, reference, …).
   *
   * @return array{merged: array<string, mixed>, binance: array<string, mixed>}
   *   binance keys: status, order_number, trade_type, candidates, message.
   */
  public function resolve(array $merged): array {
    $binance = [
      'status' => 'skipped_no_client',
      'order_number' => NULL,
      'trade_type' => NULL,
      'candidates' => 0,
      'message' => NULL,
    ];

    if (!$this->client->isConfigured()) {
      return ['merged' => $merged, 'binance' => $binance];
    }

    if (!$this->client->isPathAllowed(self::HISTORY_PATH)) {
      $binance['status'] = 'skipped_no_path';
      return ['merged' => $merged, 'binance' => $binance];
    }

    $montantInt = $this->montantToTotalPriceInt($merged['montant'] ?? NULL);
    if ($montantInt === NULL || $montantInt < 1) {
      $binance['status'] = 'skipped_no_montant';
      return ['merged' => $merged, 'binance' => $binance];
    }

    $byOrderNumber = [];
    foreach (['BUY', 'SELL'] as $tradeType) {
      for ($page = 1; $page <= self::MAX_PAGES; $page++) {
        $result = $this->client->signedGet(self::HISTORY_PATH, [
          'tradeType' => $tradeType,
          'page' => $page,
          'rows' => 100,
        ]);
        if (!empty($result['error'])) {
          $binance['status'] = 'error';
          $binance['message'] = (string) $result['error'];
          $this->loggerFactory->get('mga2p2')->warning('Receipt C2C matcher: Binance error @e', ['@e' => $result['error']]);
          return ['merged' => $merged, 'binance' => $binance];
        }

        $body = json_decode((string) ($result['body'] ?? ''), TRUE);
        $data = is_array($body) && isset($body['data']) && is_array($body['data']) ? $body['data'] : [];

        foreach ($data as $row) {
          if (!is_array($row)) {
            continue;
          }
          if ($this->integerPartTotalPrice($row['totalPrice'] ?? NULL) !== $montantInt) {
            continue;
          }
          $on = isset($row['orderNumber']) ? (string) $row['orderNumber'] : '';
          if ($on === '') {
            continue;
          }
          $byOrderNumber[$on] = [
            'order' => $row,
            'trade_type' => $tradeType,
          ];
        }

        if (count($data) < 100) {
          break;
        }
      }
    }

    $list = array_values($byOrderNumber);
    $binance['candidates'] = count($list);

    if ($binance['candidates'] === 0) {
      $binance['status'] = 'none';
      $binance['message'] = 'No Binance C2C order with this total (integer part) in recent history.';
      return ['merged' => $merged, 'binance' => $binance];
    }

    $ref = isset($merged['reference']) && is_scalar($merged['reference'])
      ? trim((string) $merged['reference'])
      : '';

    $picked = NULL;
    if ($ref !== '') {
      foreach ($list as $entry) {
        $on = isset($entry['order']['orderNumber']) ? (string) $entry['order']['orderNumber'] : '';
        if ($on !== '' && $on === $ref) {
          $picked = $entry;
          break;
        }
      }
    }

    if ($picked === NULL && count($list) === 1) {
      $picked = $list[0];
    }

    if ($picked === NULL) {
      $binance['status'] = 'ambiguous';
      $binance['message'] = sprintf(
        '%d Binance C2C orders share the same fiat total (integer part); reference left unchanged.',
        count($list),
      );
      return ['merged' => $merged, 'binance' => $binance];
    }

    $orderNumber = (string) ($picked['order']['orderNumber'] ?? '');
    $merged['reference'] = $orderNumber;
    $binance['status'] = 'ok';
    $binance['order_number'] = $orderNumber;
    $binance['trade_type'] = $picked['trade_type'];
    $binance['message'] = 'Matched Binance C2C order; reference set to orderNumber.';

    return ['merged' => $merged, 'binance' => $binance];
  }

  /**
   * Parses montant (digits / optional decimal) to integer part for Binance totalPrice match.
   */
  private function montantToTotalPriceInt(mixed $montant): ?int {
    if ($montant === NULL || $montant === '') {
      return NULL;
    }
    $s = preg_replace('/[^\d.]/', '', (string) $montant);
    if ($s === '' || $s === '.') {
      return NULL;
    }
    return (int) floor((float) $s + 1e-9);
  }

  private function integerPartTotalPrice(mixed $value): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (is_int($value)) {
      return $value;
    }
    if (is_float($value)) {
      return (int) floor($value + 1e-12);
    }
    if (is_string($value) && is_numeric($value)) {
      return (int) floor((float) $value + 1e-12);
    }
    return NULL;
  }

}
