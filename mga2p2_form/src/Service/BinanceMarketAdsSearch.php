<?php

namespace Drupal\mga2p2_form\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\mga2p2\BinanceClient;

/**
 * Fetches competitor P2P ad prices from Binance market search.
 */
final class BinanceMarketAdsSearch {

  private const AGENT_SEARCH_PATH = '/sapi/v1/c2c/agent/ads/search';

  private const PUBLIC_SEARCH_URL = 'https://p2p.binance.com/bapi/c2c/v2/friendly/c2c/adv/search';

  public function __construct(
    private readonly BinanceClient $binanceClient,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Top 5 highest + top 5 lowest competitor prices for the same market side.
   *
   * @param string $adTradeType
   *   Your ad trade type (BUY|SELL). Competitors share the same side.
   * @param string|null $excludeAdvNo
   *   Skip your own ad number when listing competitors.
   *
   * @return array{
   *   highest: array<int, array<string, mixed>>,
   *   lowest: array<int, array<string, mixed>>,
   *   total: int,
   *   searchTradeType: string,
   *   source: string,
   *   referencePrice?: string,
   *   error?: string
   * }
   */
  public function competitorPrices(
    string $asset,
    string $fiat,
    string $adTradeType,
    ?string $excludeAdvNo = NULL,
    int $maxPages = 3,
  ): array {
    $asset = strtoupper(trim($asset));
    $fiat = strtoupper(trim($fiat));
    $adTradeType = strtoupper(trim($adTradeType));

    if ($asset === '' || $fiat === '') {
      return $this->emptyResult('Asset and fiat are required.', 'none');
    }

    // Binance market search uses the counterparty perspective.
    $searchTradeType = $adTradeType === 'SELL' ? 'BUY' : 'SELL';

    $rows = [];
    $source = 'none';
    $error = NULL;

    $maxPages = max(1, min(8, $maxPages));

    if ($this->binanceClient->isConfigured() && $this->binanceClient->isPathAllowed(self::AGENT_SEARCH_PATH)) {
      $agent = $this->searchAgent($asset, $fiat, $searchTradeType, $maxPages);
      if ($agent['rows'] !== []) {
        $rows = $agent['rows'];
        $source = 'agent';
      }
      elseif (($agent['error'] ?? '') !== '') {
        $error = $agent['error'];
      }
    }

    if ($rows === []) {
      $public = $this->searchPublic($asset, $fiat, $searchTradeType, $maxPages);
      if ($public['rows'] !== []) {
        $rows = $public['rows'];
        $source = 'public';
        $error = NULL;
      }
      elseif ($error === NULL && ($public['error'] ?? '') !== '') {
        $error = $public['error'];
      }
    }

    $excludeAdvNo = $excludeAdvNo !== NULL ? trim($excludeAdvNo) : '';
    $priced = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $normalized = $this->normalizeMarketRow($row);
      if ($normalized === NULL) {
        continue;
      }
      if ($excludeAdvNo !== '' && (string) ($normalized['advNo'] ?? '') === $excludeAdvNo) {
        continue;
      }
      $priced[] = $normalized;
    }

    usort($priced, static function (array $a, array $b): int {
      return ((float) ($a['price'] ?? 0)) <=> ((float) ($b['price'] ?? 0));
    });

    $total = count($priced);
    $lowest = array_slice($priced, 0, 5);
    $highest = array_slice(array_reverse($priced), 0, 5);

    $prices = array_map(static fn (array $r): float => (float) ($r['priceNum'] ?? 0), $priced);
    $minPrice = $prices !== [] ? min($prices) : NULL;
    $maxPrice = $prices !== [] ? max($prices) : NULL;
    $avgPrice = $prices !== [] ? array_sum($prices) / count($prices) : NULL;

    $result = [
      'data' => $priced,
      'highest' => $highest,
      'lowest' => $lowest,
      'total' => $total,
      'searchTradeType' => $searchTradeType,
      'adTradeType' => $adTradeType,
      'source' => $source,
      'minPrice' => $minPrice,
      'maxPrice' => $maxPrice,
      'avgPrice' => $avgPrice,
    ];

    if ($error !== NULL && $total === 0) {
      $result['error'] = $error;
    }

    return $result;
  }

  /**
   * @return array{rows: array<int, array<string, mixed>>, error?: string}
   */
  private function searchAgent(string $asset, string $fiat, string $searchTradeType, int $maxPages = 3): array {
    $all = [];
    $lastError = NULL;
    for ($page = 1; $page <= $maxPages; $page++) {
      $body = [
        'page' => $page,
        'rows' => 20,
        'asset' => $asset,
        'fiatUnit' => $fiat,
        'tradeType' => $searchTradeType,
      ];
      $result = $this->binanceClient->signedAgentPost(self::AGENT_SEARCH_PATH, $body);
      if (!empty($result['error'])) {
        $lastError = (string) $result['error'];
        break;
      }
      $decoded = json_decode((string) ($result['body'] ?? ''), TRUE);
      if (!is_array($decoded) || !$this->isBinanceSuccess($decoded)) {
        $lastError = (string) ($decoded['message'] ?? $decoded['msg'] ?? 'Agent search failed.');
        break;
      }
      $list = $this->extractAdList($decoded);
      if ($list === []) {
        break;
      }
      foreach ($list as $item) {
        if (is_array($item)) {
          $all[] = $item;
        }
      }
      if (count($list) < 20) {
        break;
      }
    }
    return [
      'rows' => $all,
      'error' => $all === [] ? $lastError : NULL,
    ];
  }

  /**
   * @return array{rows: array<int, array<string, mixed>>, error?: string}
   */
  private function searchPublic(string $asset, string $fiat, string $searchTradeType, int $maxPages = 3): array {
    if (!function_exists('curl_init')) {
      return ['rows' => [], 'error' => 'PHP cURL is not enabled.'];
    }

    $all = [];
    for ($page = 1; $page <= $maxPages; $page++) {
      $payload = json_encode([
        'asset' => $asset,
        'fiat' => $fiat,
        'tradeType' => $searchTradeType,
        'page' => $page,
        'rows' => 20,
        'merchantCheck' => FALSE,
        'publisherType' => NULL,
        'payTypes' => [],
      ], JSON_UNESCAPED_UNICODE);

      if ($payload === FALSE) {
        return ['rows' => [], 'error' => 'Could not encode search JSON.'];
      }

      $ch = curl_init(self::PUBLIC_SEARCH_URL);
      if ($ch === FALSE) {
        return ['rows' => [], 'error' => 'curl_init failed.'];
      }

      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_POST => TRUE,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
          'Content-Type: application/json',
          'Accept: application/json',
          'User-Agent: Mozilla/5.0 (compatible; MGA2P2Form/1.0)',
          'clientType: web',
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => TRUE,
      ]);

      $body = curl_exec($ch);
      $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $err = curl_error($ch);
      curl_close($ch);

      if ($body === FALSE) {
        return ['rows' => [], 'error' => 'Public search cURL error: ' . $err];
      }
      if ($code >= 400) {
        return ['rows' => [], 'error' => 'Public search HTTP ' . $code];
      }

      $decoded = json_decode((string) $body, TRUE);
      if (!is_array($decoded)) {
        return ['rows' => [], 'error' => 'Invalid JSON from public P2P search.'];
      }

      $list = $decoded['data'] ?? [];
      if (!is_array($list)) {
        break;
      }
      foreach ($list as $item) {
        if (is_array($item)) {
          $all[] = $item;
        }
      }
      if (count($list) < 20) {
        break;
      }
    }

    return ['rows' => $all];
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>|null
   */
  private function normalizeMarketRow(array $row): ?array {
    $adv = $row;
    if (isset($row['adv']) && is_array($row['adv'])) {
      $adv = $row['adv'];
    }

    $advNo = trim((string) ($adv['advNo'] ?? $adv['adsNo'] ?? $row['advNo'] ?? ''));
    $priceRaw = $adv['price'] ?? $row['price'] ?? NULL;
    $price = is_numeric($priceRaw) ? (float) $priceRaw : (float) preg_replace('/[^\d.]/', '', (string) $priceRaw);
    if ($price <= 0) {
      return NULL;
    }

    $merchant = trim((string) (
      $row['advertiser']['nickName']
      ?? $adv['nickName']
      ?? $row['nickName']
      ?? $row['merchantName']
      ?? ''
    ));

    return [
      'advNo' => $advNo,
      'price' => (string) $price,
      'priceNum' => $price,
      'asset' => strtoupper((string) ($adv['asset'] ?? $row['asset'] ?? '')),
      'fiat' => strtoupper((string) ($adv['fiatUnit'] ?? $adv['fiat'] ?? $row['fiat'] ?? '')),
      'surplusAmount' => (string) ($adv['surplusAmount'] ?? $adv['surplus'] ?? ''),
      'minSingleTransAmount' => (string) ($adv['minSingleTransAmount'] ?? ''),
      'maxSingleTransAmount' => (string) ($adv['maxSingleTransAmount'] ?? ''),
      'merchant' => $merchant !== '' ? $merchant : '—',
      'tradeType' => strtoupper((string) ($adv['tradeType'] ?? $row['tradeType'] ?? '')),
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
   * @param array<int, mixed> $arr
   */
  private function isListArray(array $arr): bool {
    if ($arr === []) {
      return TRUE;
    }
    return array_keys($arr) === range(0, count($arr) - 1);
  }

  /**
   * @return array{
   *   highest: array<int, array<string, mixed>>,
   *   lowest: array<int, array<string, mixed>>,
   *   total: int,
   *   searchTradeType: string,
   *   source: string,
   *   error?: string
   * }
   */
  private function emptyResult(string $error, string $source): array {
    $this->loggerFactory->get('mga2p2_form')->warning('Market ads search: @m', ['@m' => $error]);
    return [
      'data' => [],
      'highest' => [],
      'lowest' => [],
      'total' => 0,
      'searchTradeType' => '',
      'adTradeType' => '',
      'source' => $source,
      'minPrice' => NULL,
      'maxPrice' => NULL,
      'avgPrice' => NULL,
      'error' => $error,
    ];
  }

}
