<?php

namespace Drupal\mga2p2\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\mga2p2\BinanceClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proxies signed requests to api.binance.com.
 *
 * The browser calls /binance-proxy?path=/api/... (GET) or POST with the same
 * query param plus a JSON body for POST fields (e.g. markOrderAsPaid).
 */
class BinanceProxyController extends ControllerBase {

  protected BinanceClient $client;

  public function __construct(BinanceClient $client) {
    $this->client = $client;
  }

  public static function create(ContainerInterface $container) {
    return new static($container->get('mga2p2.binance_client'));
  }

  public function proxy(Request $request): Response {
    try {
      return $this->doProxy($request);
    }
    catch (\Throwable $e) {
      $this->getLogger('mga2p2')->error('Binance proxy exception: @msg @trace', [
        '@msg' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);
      $safe = substr($e->getMessage(), 0, 500);
      return $this->withCors(new JsonResponse([
        'error' => 'Internal proxy error: ' . $safe,
        'code' => 'mga2p2_exception',
      ], 500));
    }
  }

  private function doProxy(Request $request): Response {
    // CORS preflight.
    if ($request->getMethod() === 'OPTIONS') {
      return $this->withCors(new Response('', 204));
    }

    $path = (string) $request->query->get('path', '');
    if ($path === '' || !preg_match('#^/(api|sapi)/[a-zA-Z0-9/_\-.]+$#', $path)) {
      return $this->withCors(new JsonResponse(['error' => 'Invalid or missing "path" parameter.'], 400));
    }

    if (!$this->client->isPathAllowed($path)) {
      return $this->withCors(new JsonResponse(['error' => 'Path not in whitelist: ' . $path], 403));
    }

    if (!$this->client->isConfigured()) {
      return $this->withCors(new JsonResponse([
        'error' => 'Binance API credentials are not configured. Visit /admin/config/system/mga2p2.',
        'code' => 'mga2p2_not_configured',
      ], 503));
    }

    $params = $request->query->all();
    unset($params['path']);

    if ($request->getMethod() === 'POST') {
      // Use strpos (not str_contains) so MAMP / Drupal 9 on PHP 7.4 does not fatal.
      $contentType = strtolower((string) $request->headers->get('Content-Type'));
      if (strpos($contentType, 'application/json') !== FALSE) {
        $raw = $request->getContent();
        if ($raw !== '') {
          $decoded = json_decode($raw, TRUE);
          if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->withCors(new JsonResponse([
              'error' => 'Invalid JSON body: ' . json_last_error_msg(),
              'code' => 'mga2p2_json',
            ], 400));
          }
          if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
              if (is_string($key) && $key !== '' && !array_key_exists($key, $params)) {
                $params[$key] = $value;
              }
            }
          }
        }
      }
      else {
        foreach ($request->request->all() as $key => $value) {
          if (!array_key_exists($key, $params)) {
            $params[$key] = $value;
          }
        }
      }
      if (substr($path, -15) === 'markOrderAsPaid') {
        // Binance C2C EP-17 expects orderNo (not orderNumber from list history).
        $orderRef = (string) ($params['orderNo'] ?? $params['orderNumber'] ?? '');
        if ($orderRef === '') {
          return $this->withCors(new JsonResponse([
            'error' => 'Missing orderNo or orderNumber in POST body (JSON or form fields).',
            'code' => 'mga2p2_params',
          ], 400));
        }
        unset($params['orderNumber'], $params['orderNo']);
        $params['orderNo'] = $orderRef;
      }
      $result = $this->client->signedPost($path, $params);
    }
    elseif ($request->getMethod() === 'GET') {
      $result = $this->client->signedGet($path, $params);
    }
    else {
      return $this->withCors(new JsonResponse(['error' => 'Method not allowed.'], 405));
    }

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

    $resp = new Response((string) ($result['body'] ?? ''), $upstream);
    $resp->headers->set('Content-Type', 'application/json; charset=UTF-8');
    return $this->withCors($resp);
  }

  /**
   * Adds CORS headers so the SPA can call this from any origin.
   */
  private function withCors(Response $response): Response {
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With');
    $response->headers->set('Access-Control-Max-Age', '3600');
    return $response;
  }

}
