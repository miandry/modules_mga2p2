<?php

namespace Drupal\mga2p2\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\mga2p2\BinanceClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps Binance C2C listUserOrderHistory with optional totalPrice (integer) filter.
 *
 * Binance does not accept totalPrice on listUserOrderHistory; this endpoint forwards
 * the usual query params, then keeps only orders whose fiat totalPrice has the same
 * integer part as the requested value (decimals stripped from both sides).
 */
final class C2cOrderHistoryFilterController extends ControllerBase {

  private const HISTORY_PATH = '/sapi/v1/c2c/orderMatch/listUserOrderHistory';

  public function __construct(
    protected BinanceClient $client,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('mga2p2.binance_client'),
    );
  }

  public function search(Request $request): Response {
    if ($request->getMethod() === 'OPTIONS') {
      return $this->withCors(new Response('', 204));
    }

    if (!$this->client->isConfigured()) {
      return $this->withCors(new JsonResponse([
        'error' => 'Binance API credentials are not configured.',
        'code' => 'mga2p2_not_configured',
      ], 503));
    }

    if (!$this->client->isPathAllowed(self::HISTORY_PATH)) {
      return $this->withCors(new JsonResponse([
        'error' => 'Path not allowed by mga2p2 whitelist: ' . self::HISTORY_PATH,
      ], 403));
    }

    $params = $this->binanceQueryParams($request);
    $filterInt = $this->parseTotalPriceFilter($request);

    $result = $this->client->signedGet(self::HISTORY_PATH, $params);
    if (!empty($result['error'])) {
      $status = (int) ($result['status'] ?? 502);
      if ($status < 400 || $status > 599) {
        $status = 502;
      }
      return $this->withCors(new JsonResponse(['error' => $result['error']], $status));
    }

    $upstream = (int) ($result['status'] ?? 502);
    if ($upstream < 100 || $upstream > 599) {
      $upstream = 502;
    }

    $body = (string) ($result['body'] ?? '');
    if ($filterInt === NULL) {
      $resp = new Response($body, $upstream);
      $resp->headers->set('Content-Type', 'application/json; charset=UTF-8');
      return $this->withCors($resp);
    }

    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      return $this->withCors(new JsonResponse([
        'error' => 'Upstream response is not JSON; cannot apply totalPrice filter.',
      ], 502));
    }

    $data = $decoded['data'] ?? NULL;
    if (!is_array($data)) {
      return $this->withCors(new JsonResponse($decoded, $upstream));
    }

    $filtered = [];
    foreach ($data as $row) {
      if (!is_array($row)) {
        continue;
      }
      $tp = $row['totalPrice'] ?? NULL;
      if ($this->integerPartTotalPrice($tp) === $filterInt) {
        $filtered[] = $row;
      }
    }

    $decoded['data'] = array_values($filtered);
    if (array_key_exists('total', $decoded)) {
      $decoded['total'] = count($filtered);
    }

    return $this->withCors(new JsonResponse($decoded, $upstream));
  }

  /**
   * Builds query params forwarded to Binance (no totalPrice / mga2p2 keys).
   *
   * @return array<string, scalar>
   */
  private function binanceQueryParams(Request $request): array {
    $out = [];
    $tradeType = $request->query->get('tradeType', 'BUY');
    $out['tradeType'] = is_string($tradeType) && $tradeType !== '' ? $tradeType : 'BUY';

    $page = max(1, (int) $request->query->get('page', 1));
    $rows = (int) $request->query->get('rows', 100);
    $rows = max(1, min(100, $rows));
    $out['page'] = $page;
    $out['rows'] = $rows;

    foreach (['startTimestamp', 'endTimestamp'] as $key) {
      if (!$request->query->has($key)) {
        continue;
      }
      $v = $request->query->get($key);
      if ($v === '' || $v === NULL) {
        continue;
      }
      if (is_numeric($v)) {
        $out[$key] = (int) $v;
      }
    }

    return $out;
  }

  /**
   * Optional ?totalPrice=12345 (integer, no decimals required). Returns NULL to skip filter.
   */
  private function parseTotalPriceFilter(Request $request): ?int {
    if (!$request->query->has('totalPrice')) {
      return NULL;
    }
    $raw = $request->query->get('totalPrice');
    if ($raw === '' || $raw === NULL) {
      return NULL;
    }
    if (!is_numeric($raw)) {
      return NULL;
    }
    return $this->integerPartTotalPrice($raw);
  }

  /**
   * Integer part of a fiat total (strip decimals), e.g. "12345.67" -> 12345.
   */
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

  private function withCors(Response $response): Response {
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With');
    $response->headers->set('Access-Control-Max-Age', '3600');
    return $response;
  }

}
