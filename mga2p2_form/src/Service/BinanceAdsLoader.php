<?php

namespace Drupal\mga2p2_form\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\mga2p2\BinanceClient;

/**
 * Loads Binance C2C ads (merchant list API with order-history fallback).
 */
final class BinanceAdsLoader {

  private const LIST_PATH = '/sapi/v1/c2c/agent/ads/listWithPagination';

  private const HISTORY_PATH = '/sapi/v1/c2c/orderMatch/listUserOrderHistory';

  public function __construct(
    private readonly BinanceClient $binanceClient,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * @return array{
   *   data: array<int, array<string, mixed>>,
   *   total: int,
   *   source: string,
   *   page: int,
   *   rows: int,
   *   error?: string
   * }
   */
  public function load(int $page = 1, int $rows = 50, ?string $asset = NULL, ?string $fiat = NULL, ?string $advNo = NULL): array {
    $advNo = $advNo !== NULL ? trim($advNo) : '';
    if ($advNo !== '') {
      return $this->loadOne($advNo, $asset, $fiat);
    }

    $page = max(1, $page);
    $rows = max(1, min(100, $rows));

    if (!$this->binanceClient->isConfigured()) {
      return [
        'data' => [],
        'total' => 0,
        'source' => 'none',
        'page' => $page,
        'rows' => $rows,
        'error' => 'Binance API credentials are not configured.',
      ];
    }

    if ($this->binanceClient->isPathAllowed(self::LIST_PATH)) {
      $agent = $this->loadFromAgentApi($page, $rows, $asset, $fiat);
      if ($agent['data'] !== [] || ($agent['error'] ?? '') === '') {
        return $agent;
      }
    }

    if ($this->binanceClient->isPathAllowed(self::HISTORY_PATH)) {
      return $this->loadDerivedFromHistory($rows, $asset, $fiat);
    }

    return [
      'data' => [],
      'total' => 0,
      'source' => 'none',
      'page' => $page,
      'rows' => $rows,
      'error' => 'Binance paths are not allowed by whitelist.',
    ];
  }

  /**
   * @return array{
   *   data: array<int, array<string, mixed>>,
   *   total: int,
   *   source: string,
   *   page: int,
   *   rows: int,
   *   error?: string
   * }
   */
  private function loadFromAgentApi(int $page, int $rows, ?string $asset, ?string $fiat): array {
    $body = [
      'page' => $page,
      'rows' => $rows,
    ];
    if ($asset !== NULL && $asset !== '') {
      $body['asset'] = strtoupper($asset);
    }
    if ($fiat !== NULL && $fiat !== '') {
      $body['fiatUnit'] = strtoupper($fiat);
    }

    $result = $this->binanceClient->signedAgentPost(self::LIST_PATH, $body);
    if (!empty($result['error'])) {
      return $this->emptyResult($page, $rows, 'agent', (string) $result['error']);
    }

    $decoded = json_decode((string) ($result['body'] ?? ''), TRUE);
    if (!is_array($decoded)) {
      return $this->emptyResult($page, $rows, 'agent', 'Invalid JSON from Binance ads API.');
    }

    if (!$this->isBinanceSuccess($decoded)) {
      $msg = (string) ($decoded['message'] ?? $decoded['msg'] ?? 'Binance ads API error.');
      return $this->emptyResult($page, $rows, 'agent', $msg);
    }

    $list = $this->extractAdList($decoded);
    $total = $this->extractTotal($decoded, count($list));
    $items = [];
    foreach ($list as $row) {
      if (!is_array($row)) {
        continue;
      }
      $normalized = $this->normalizeAgentAd($row);
      if ($normalized !== NULL) {
        $items[] = $normalized;
      }
    }

    return [
      'data' => $items,
      'total' => $total,
      'source' => 'agent',
      'page' => $page,
      'rows' => $rows,
    ];
  }

  /**
   * Load a single ad by advNo (agent API scan, then derived fallback).
   *
   * @return array{
   *   data: array<int, array<string, mixed>>,
   *   total: int,
   *   source: string,
   *   page: int,
   *   rows: int,
   *   error?: string
   * }
   */
  public function loadOne(string $advNo, ?string $asset = NULL, ?string $fiat = NULL): array {
    $advNo = trim($advNo);
    if ($advNo === '') {
      return [
        'data' => [],
        'total' => 0,
        'source' => 'none',
        'page' => 1,
        'rows' => 1,
        'error' => 'advNo is required.',
      ];
    }

    if (!$this->binanceClient->isConfigured()) {
      return [
        'data' => [],
        'total' => 0,
        'source' => 'none',
        'page' => 1,
        'rows' => 1,
        'error' => 'Binance API credentials are not configured.',
      ];
    }

    if ($this->binanceClient->isPathAllowed(self::LIST_PATH)) {
      $maxPages = 20;
      $rows = 50;
      for ($page = 1; $page <= $maxPages; $page++) {
        $agent = $this->loadFromAgentApi($page, $rows, $asset, $fiat);
        foreach ($agent['data'] as $item) {
          if (is_array($item) && (string) ($item['advNo'] ?? '') === $advNo) {
            return [
              'data' => [$item],
              'total' => 1,
              'source' => 'agent',
              'page' => 1,
              'rows' => 1,
            ];
          }
        }
        if ($agent['data'] === [] || count($agent['data']) < $rows) {
          break;
        }
        if ($page >= $maxPages) {
          break;
        }
        if (isset($agent['total']) && $page * $rows >= (int) $agent['total']) {
          break;
        }
      }
    }

    if ($this->binanceClient->isPathAllowed(self::HISTORY_PATH)) {
      $derived = $this->loadDerivedFromHistory(100, $asset, $fiat);
      foreach ($derived['data'] as $item) {
        if (is_array($item) && (string) ($item['advNo'] ?? '') === $advNo) {
          return [
            'data' => [$item],
            'total' => 1,
            'source' => 'derived',
            'page' => 1,
            'rows' => 1,
          ];
        }
      }
      if (!empty($derived['error']) && $derived['data'] === []) {
        return [
          'data' => [],
          'total' => 0,
          'source' => 'derived',
          'page' => 1,
          'rows' => 1,
          'error' => $derived['error'],
        ];
      }
    }

    return [
      'data' => [],
      'total' => 0,
      'source' => 'none',
      'page' => 1,
      'rows' => 1,
      'error' => 'Annonce introuvable.',
    ];
  }

  /**
   * Derive pseudo-ads from recent C2C order history (grouped by advNo).
   *
   * @return array{
   *   data: array<int, array<string, mixed>>,
   *   total: int,
   *   source: string,
   *   page: int,
   *   rows: int,
   *   error?: string
   * }
   */
  private function loadDerivedFromHistory(int $rows, ?string $asset, ?string $fiat): array {
    $buys = $this->binanceClient->signedGet(self::HISTORY_PATH, [
      'tradeType' => 'BUY',
      'page' => 1,
      'rows' => $rows,
    ]);
    $sells = $this->binanceClient->signedGet(self::HISTORY_PATH, [
      'tradeType' => 'SELL',
      'page' => 1,
      'rows' => $rows,
    ]);

    $orders = [];
    foreach ([$buys, $sells] as $result) {
      if (!empty($result['error'])) {
        continue;
      }
      $decoded = json_decode((string) ($result['body'] ?? ''), TRUE);
      if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
        continue;
      }
      foreach ($decoded['data'] as $row) {
        if (is_array($row)) {
          $orders[] = $row;
        }
      }
    }

    if ($orders === []) {
      return [
        'data' => [],
        'total' => 0,
        'source' => 'derived',
        'page' => 1,
        'rows' => $rows,
        'error' => 'Could not load order history for derived ads.',
      ];
    }

    $assetFilter = $asset !== NULL && $asset !== '' ? strtoupper($asset) : NULL;
    $fiatFilter = $fiat !== NULL && $fiat !== '' ? strtoupper($fiat) : NULL;

    $byAdv = [];
    foreach ($orders as $o) {
      $advNo = trim((string) ($o['advNo'] ?? ''));
      if ($advNo === '') {
        continue;
      }
      if ($assetFilter !== NULL && strtoupper((string) ($o['asset'] ?? '')) !== $assetFilter) {
        continue;
      }
      if ($fiatFilter !== NULL && strtoupper((string) ($o['fiat'] ?? '')) !== $fiatFilter) {
        continue;
      }
      $byAdv[$advNo][] = $o;
    }

    $items = [];
    foreach ($byAdv as $advNo => $list) {
      $items[] = $this->normalizeDerivedAd($advNo, $list);
    }

    usort($items, static function (array $a, array $b): int {
      return (int) ($b['lastSeen'] ?? 0) <=> (int) ($a['lastSeen'] ?? 0);
    });

    return [
      'data' => $items,
      'total' => count($items),
      'source' => 'derived',
      'page' => 1,
      'rows' => $rows,
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

  /**
   * @param array<string, mixed> $decoded
   *
   * @return array<int, mixed>
   */
  private function extractAdList(array $decoded): array {
    $data = $decoded['data'] ?? NULL;
    if (is_array($data) && isset($data['list']) && is_array($data['list'])) {
      return $data['list'];
    }
    if (is_array($data) && $this->isListArray($data)) {
      return $data;
    }
    if (isset($decoded['list']) && is_array($decoded['list'])) {
      return $decoded['list'];
    }
    return [];
  }

  /**
   * @param array<string, mixed> $decoded
   */
  private function extractTotal(array $decoded, int $fallback): int {
    $data = $decoded['data'] ?? NULL;
    if (is_array($data) && isset($data['total']) && is_numeric($data['total'])) {
      return (int) $data['total'];
    }
    if (isset($decoded['total']) && is_numeric($decoded['total'])) {
      return (int) $decoded['total'];
    }
    return $fallback;
  }

  /**
   * @param array<int, mixed> $arr
   */
  private function isListArray(array $arr): bool {
    if ($arr === []) {
      return TRUE;
    }
    return array_keys($arr) === range(0, count($arr) - 1);
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>|null
   */
  private function normalizeAgentAd(array $row): ?array {
    $advNo = trim((string) ($row['advNo'] ?? $row['adsNo'] ?? ''));
    if ($advNo === '') {
      return NULL;
    }

    $statusCode = (int) ($row['status'] ?? 0);
    $tradeType = strtoupper(trim((string) ($row['tradeType'] ?? '')));
    $asset = strtoupper(trim((string) ($row['asset'] ?? '')));
    $fiat = strtoupper(trim((string) ($row['fiatUnit'] ?? $row['fiat'] ?? '')));

    return [
      'advNo' => $advNo,
      'tradeType' => $tradeType,
      'asset' => $asset,
      'fiat' => $fiat,
      'fiatSymbol' => (string) ($row['currencySymbol'] ?? $row['fiatSymbol'] ?? ''),
      'price' => (string) ($row['price'] ?? ''),
      'priceType' => (string) ($row['priceType'] ?? ''),
      'surplusAmount' => (string) ($row['surplusAmount'] ?? $row['surplus'] ?? ''),
      'initAmount' => (string) ($row['initAmount'] ?? ''),
      'minSingleTransAmount' => (string) ($row['minSingleTransAmount'] ?? ''),
      'maxSingleTransAmount' => (string) ($row['maxSingleTransAmount'] ?? ''),
      'status' => $statusCode,
      'statusLabel' => $this->statusLabel($statusCode),
      'remarks' => (string) ($row['remarks'] ?? ''),
      'autoReplyMsg' => (string) ($row['autoReplyMsg'] ?? $row['autoReply'] ?? ''),
      'payTimeLimit' => isset($row['payTimeLimit']) ? (int) $row['payTimeLimit'] : NULL,
      'paymentMethods' => $this->extractPaymentMethods($row),
    ];
  }

  /**
   * @param array<int, array<string, mixed>> $orders
   *
   * @return array<string, mixed>
   */
  private function normalizeDerivedAd(string $advNo, array $orders): array {
    $first = $orders[0];
    $prices = [];
    $volume = 0.0;
    $fiatTotal = 0.0;
    $completed = 0;
    $cancelled = 0;
    $times = [];
    $payments = [];

    foreach ($orders as $o) {
      $prices[] = (float) ($o['unitPrice'] ?? 0);
      $volume += (float) ($o['amount'] ?? 0);
      $fiatTotal += (float) ($o['totalPrice'] ?? 0);
      $st = strtoupper((string) ($o['orderStatus'] ?? ''));
      if ($st === 'COMPLETED') {
        $completed++;
      }
      if (str_starts_with($st, 'CANCELLED')) {
        $cancelled++;
      }
      $times[] = (int) ($o['createTime'] ?? 0);
      $pm = trim((string) ($o['payMethodName'] ?? ''));
      if ($pm !== '') {
        $payments[$pm] = TRUE;
      }
    }

    $count = count($orders);
    $avgPrice = $count > 0 ? array_sum($prices) / $count : 0.0;

    return [
      'advNo' => $advNo,
      'tradeType' => strtoupper((string) ($first['tradeType'] ?? '')),
      'asset' => strtoupper((string) ($first['asset'] ?? '')),
      'fiat' => strtoupper((string) ($first['fiat'] ?? '')),
      'fiatSymbol' => (string) ($first['fiatSymbol'] ?? ''),
      'price' => (string) $avgPrice,
      'priceType' => 'DERIVED',
      'surplusAmount' => '',
      'initAmount' => (string) $volume,
      'minSingleTransAmount' => '',
      'maxSingleTransAmount' => '',
      'status' => 0,
      'statusLabel' => 'Derived',
      'remarks' => '',
      'autoReplyMsg' => '',
      'orderCount' => $count,
      'completedCount' => $completed,
      'cancelledCount' => $cancelled,
      'completionRate' => $count > 0 ? round($completed / $count, 4) : 0,
      'totalVolume' => $volume,
      'totalFiat' => $fiatTotal,
      'paymentMethods' => array_keys($payments),
      'firstSeen' => $times !== [] ? min($times) : 0,
      'lastSeen' => $times !== [] ? max($times) : 0,
    ];
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return string[]
   */
  private function extractPaymentMethods(array $row): array {
    $methods = [];
    if (isset($row['tradeMethods']) && is_array($row['tradeMethods'])) {
      foreach ($row['tradeMethods'] as $m) {
        if (is_array($m)) {
          $name = trim((string) ($m['tradeMethodName'] ?? $m['identifier'] ?? ''));
          if ($name !== '') {
            $methods[] = $name;
          }
        }
        elseif (is_string($m) && $m !== '') {
          $methods[] = $m;
        }
      }
    }
    $single = trim((string) ($row['tradeMethodName'] ?? ''));
    if ($single !== '') {
      $methods[] = $single;
    }
    return array_values(array_unique($methods));
  }

  private function statusLabel(int $code): string {
    return match ($code) {
      1 => 'Online',
      2 => 'Offline',
      4 => 'Closed',
      default => 'Unknown',
    };
  }

  /**
   * @return array{
   *   data: array<int, array<string, mixed>>,
   *   total: int,
   *   source: string,
   *   page: int,
   *   rows: int,
   *   error?: string
   * }
   */
  private function emptyResult(int $page, int $rows, string $source, string $error): array {
    $this->loggerFactory->get('mga2p2_form')->warning('Binance ads: @m', ['@m' => $error]);
    return [
      'data' => [],
      'total' => 0,
      'source' => $source,
      'page' => $page,
      'rows' => $rows,
      'error' => $error,
    ];
  }

}
