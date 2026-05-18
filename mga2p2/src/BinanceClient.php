<?php

namespace Drupal\mga2p2;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Service that signs Binance API requests and forwards them via cURL.
 *
 * Keeps the HMAC secret on the server, validates path prefixes against the
 * whitelist, and returns the raw upstream JSON body + HTTP status code.
 */
class BinanceClient {

  const API_HOST = 'https://api.binance.com';

  /**
   * Used when config "allowed_path_prefixes" is empty (avoid locking out API).
   */
  const DEFAULT_ALLOWED_PREFIXES = [
    '/api/v3/',
    '/sapi/v1/',
  ];

  protected ConfigFactoryInterface $configFactory;
  protected LoggerChannelInterface $logger;

  public function __construct(ConfigFactoryInterface $configFactory, LoggerChannelFactoryInterface $loggerFactory) {
    $this->configFactory = $configFactory;
    $this->logger = $loggerFactory->get('mga2p2');
  }

  /**
   * Returns true when both API key and secret are configured.
   */
  public function isConfigured(): bool {
    $config = $this->configFactory->get('mga2p2.settings');
    return !empty($config->get('api_key')) && !empty($config->get('secret_key'));
  }

  /**
   * Returns configured prefixes, or sane defaults if none are set.
   *
   * @return string[]
   */
  public function getAllowedPrefixes(): array {
    $raw = $this->configFactory->get('mga2p2.settings')->get('allowed_path_prefixes');
    if (!is_array($raw)) {
      return self::DEFAULT_ALLOWED_PREFIXES;
    }
    $prefixes = array_values(array_filter(array_map('trim', $raw)));
    return $prefixes !== [] ? $prefixes : self::DEFAULT_ALLOWED_PREFIXES;
  }

  /**
   * Returns true if the path is allowed by the whitelist.
   */
  public function isPathAllowed(string $path): bool {
    foreach ($this->getAllowedPrefixes() as $prefix) {
      if ($prefix === '') {
        continue;
      }
      if (strpos($path, $prefix) === 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Sends a signed GET request to Binance.
   *
   * @param string $path
   *   The API path, e.g. "/api/v3/account".
   * @param array $params
   *   Query params; timestamp + signature are appended automatically.
   *
   * @return array{status:int, body:string, error:?string}
   */
  public function signedGet(string $path, array $params = []): array {
    if (!function_exists('curl_init')) {
      $this->logger->error('PHP cURL extension is not enabled.');
      return ['status' => 503, 'body' => '', 'error' => 'PHP cURL extension is not enabled on this server (enable php-curl in MAMP).'];
    }

    $config = $this->configFactory->get('mga2p2.settings');
    $apiKey    = (string) $config->get('api_key');
    $secretKey = (string) $config->get('secret_key');
    $recvWindow = (int) ($config->get('recv_window') ?? 10000);

    if ($apiKey === '' || $secretKey === '') {
      return ['status' => 503, 'body' => '', 'error' => 'Binance API credentials are not configured. Visit /admin/config/system/mga2p2.'];
    }

    // Only scalar query values (Symfony can pass arrays for duplicate keys).
    $params = $this->filterScalarParams($params);
    $params['recvWindow'] = $recvWindow;
    $params['timestamp']  = (int) round(microtime(TRUE) * 1000);

    // Binance verifies HMAC over the exact query string; sort keys for a
    // stable payload (matches common client implementations).
    ksort($params);

    $query     = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $signature = hash_hmac('sha256', $query, $secretKey);
    $url       = self::API_HOST . $path . '?' . $query . '&signature=' . $signature;

    $ch = curl_init($url);
    if ($ch === FALSE) {
      return ['status' => 503, 'body' => '', 'error' => 'curl_init() failed.'];
    }

    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_HTTPHEADER     => $this->binanceRequestHeaders($apiKey, $path),
      CURLOPT_TIMEOUT        => 25,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_FOLLOWLOCATION => FALSE,
      // MAMP / local stacks sometimes lack CA bundle; still verify when possible.
      CURLOPT_SSL_VERIFYPEER => TRUE,
    ]);

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === FALSE) {
      $this->logger->error('Binance cURL failure: @err path=@path', ['@err' => $err, '@path' => $path]);
      return ['status' => 502, 'body' => '', 'error' => 'Upstream cURL error: ' . $err];
    }

    // Binance sometimes returns HTTP 200 with an error JSON body; still log non-2xx.
    if ($code >= 400) {
      $this->logger->warning('Binance HTTP @code @path err=@err body=@body', [
        '@code' => $code,
        '@path' => $path,
        '@err' => $err,
        '@body' => substr((string) $body, 0, 800),
      ]);
    }

    return ['status' => $code, 'body' => (string) $body, 'error' => NULL];
  }

  /**
   * Sends a signed POST to Binance (SAPI / Spot style: params on query string).
   *
   * For C2C markOrderAsPaid (BUY): Binance requires payId (payment method id).
   * listUserOrderHistory often omits it, which produces generic -1000. When
   * payId is missing we call getUserOrderDetail first and merge payId.
   *
   * @param string $path
   *   The API path, e.g. "/sapi/v1/c2c/orderMatch/markOrderAsPaid".
   * @param array $params
   *   Fields (e.g. orderNo, payId); timestamp + recvWindow + signature are added.
   *
   * @return array{status:int, body:string, error:?string}
   */
  public function signedPost(string $path, array $params = []): array {
    if (!function_exists('curl_init')) {
      $this->logger->error('PHP cURL extension is not enabled.');
      return ['status' => 503, 'body' => '', 'error' => 'PHP cURL extension is not enabled on this server (enable php-curl in MAMP).'];
    }

    $config = $this->configFactory->get('mga2p2.settings');
    $apiKey    = (string) $config->get('api_key');
    $secretKey = (string) $config->get('secret_key');
    $recvWindow = (int) ($config->get('recv_window') ?? 10000);

    if ($apiKey === '' || $secretKey === '') {
      return ['status' => 503, 'body' => '', 'error' => 'Binance API credentials are not configured. Visit /admin/config/system/mga2p2.'];
    }

    $params = $this->filterScalarParams($params);

    if ($path === '/sapi/v1/c2c/orderMatch/markOrderAsPaid') {
      $orderRef = (string) ($params['orderNo'] ?? $params['orderNumber'] ?? '');
      unset($params['orderNumber']);
      if ($orderRef !== '') {
        $params['orderNo'] = $orderRef;
      }
      $payIdPresent = isset($params['payId']) && is_numeric($params['payId']) && (float) $params['payId'] > 0;
      if (!$payIdPresent && $orderRef !== '') {
        $fetched = $this->fetchC2CPayIdForOrder($apiKey, $secretKey, $recvWindow, $orderRef);
        if ($fetched !== NULL) {
          $params['payId'] = $fetched;
          $this->logger->notice('mga2p2: resolved payId=@pay for C2C order @ord via getUserOrderDetail.', [
            '@pay' => $fetched,
            '@ord' => $orderRef,
          ]);
        }
        else {
          $this->logger->warning('mga2p2: markOrderAsPaid without payId; getUserOrderDetail did not return payId for order @ord.', [
            '@ord' => $orderRef,
          ]);
        }
      }
    }

    $params['recvWindow'] = $recvWindow;
    $params['timestamp']  = (int) round(microtime(TRUE) * 1000);
    ksort($params);

    $query     = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $signature = hash_hmac('sha256', $query, $secretKey);
    $url       = self::API_HOST . $path . '?' . $query . '&signature=' . $signature;

    $ch = curl_init($url);
    if ($ch === FALSE) {
      return ['status' => 503, 'body' => '', 'error' => 'curl_init() failed.'];
    }

    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_POST           => TRUE,
      CURLOPT_POSTFIELDS     => '',
      CURLOPT_HTTPHEADER     => $this->binanceRequestHeaders($apiKey, $path),
      CURLOPT_TIMEOUT        => 25,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_FOLLOWLOCATION => FALSE,
      CURLOPT_SSL_VERIFYPEER => TRUE,
    ]);

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === FALSE) {
      $this->logger->error('Binance cURL POST failure: @err path=@path', ['@err' => $err, '@path' => $path]);
      return ['status' => 502, 'body' => '', 'error' => 'Upstream cURL error: ' . $err];
    }

    if ($code >= 400) {
      $this->logger->warning('Binance POST HTTP @code @path err=@err body=@body', [
        '@code' => $code,
        '@path' => $path,
        '@err' => $err,
        '@body' => substr((string) $body, 0, 800),
      ]);
    }

    return ['status' => $code, 'body' => (string) $body, 'error' => NULL];
  }

  /**
   * Calls getUserOrderDetail to obtain payId for markOrderAsPaid (BUY flow).
   */
  private function fetchC2CPayIdForOrder(string $apiKey, string $secretKey, int $recvWindow, string $orderRef): ?int {
    $detailPath = '/sapi/v1/c2c/orderMatch/getUserOrderDetail';
    foreach (['orderNo', 'orderNumber'] as $key) {
      $params = [$key => $orderRef];
      $params['recvWindow'] = $recvWindow;
      $params['timestamp']  = (int) round(microtime(TRUE) * 1000);
      ksort($params);
      $query     = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
      $signature = hash_hmac('sha256', $query, $secretKey);
      $url       = self::API_HOST . $detailPath . '?' . $query . '&signature=' . $signature;

      $ch = curl_init($url);
      if ($ch === FALSE) {
        continue;
      }
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_POST           => TRUE,
        CURLOPT_POSTFIELDS     => '',
        CURLOPT_HTTPHEADER     => $this->binanceRequestHeaders($apiKey, $detailPath),
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => FALSE,
        CURLOPT_SSL_VERIFYPEER => TRUE,
      ]);
      $body = curl_exec($ch);
      curl_close($ch);
      if ($body === FALSE || $body === '') {
        continue;
      }
      $payId = $this->parsePayIdFromC2COrderDetail((string) $body);
      if ($payId !== NULL) {
        return $payId;
      }
    }
    return NULL;
  }

  /**
   * Extracts payId / selectedPayId from getUserOrderDetail JSON (C2C wrapper).
   */
  private function parsePayIdFromC2COrderDetail(string $body): ?int {
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      return NULL;
    }
    $code = $decoded['code'] ?? NULL;
    if ($code !== NULL && (string) $code !== '000000' && (int) $code !== 0) {
      return NULL;
    }
    $data = $decoded['data'] ?? NULL;
    if (!is_array($data)) {
      return NULL;
    }
    return $this->walkFindC2CPayId($data, 0);
  }

  /**
   * @param array<string, mixed> $node
   */
  private function walkFindC2CPayId(array $node, int $depth): ?int {
    if ($depth > 12) {
      return NULL;
    }
    foreach ($node as $key => $value) {
      if (is_string($key)) {
        $lk = strtolower($key);
        if (($lk === 'payid' || $lk === 'selectedpayid') && is_numeric($value) && (float) $value > 0) {
          return (int) $value;
        }
      }
    }
    foreach ($node as $value) {
      if (is_array($value)) {
        $found = $this->walkFindC2CPayId($value, $depth + 1);
        if ($found !== NULL) {
          return $found;
        }
      }
    }
    return NULL;
  }

  /**
   * C2C SAPI expects a web client hint on many orderMatch routes (undocumented).
   *
   * @return string[]
   */
  private function binanceRequestHeaders(string $apiKey, string $path): array {
    $headers = ['X-MBX-APIKEY: ' . $apiKey];
    if (strpos($path, '/sapi/v1/c2c/') === 0) {
      $headers[] = 'clientType: web';
    }
    return $headers;
  }

  /**
   * @param array<string, mixed> $params
   *
   * @return array<string, string|int|float>
   */
  private function filterScalarParams(array $params): array {
    $out = [];
    foreach ($params as $key => $value) {
      if (!is_string($key) || $key === '') {
        continue;
      }
      if (is_bool($value)) {
        $out[$key] = $value ? 'true' : 'false';
      }
      elseif (is_int($value) || is_float($value)) {
        $out[$key] = $value;
      }
      elseif (is_string($value)) {
        $out[$key] = $value;
      }
      // Skip arrays/objects.
    }
    return $out;
  }

}
