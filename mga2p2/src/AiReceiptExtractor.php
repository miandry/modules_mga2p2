<?php

namespace Drupal\mga2p2;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Calls an OpenAI-compatible vision API and returns decoded JSON fields.
 */
class AiReceiptExtractor {

  protected ConfigFactoryInterface $configFactory;
  protected ClientInterface $httpClient;
  protected LoggerChannelInterface $logger;

  public function __construct(ConfigFactoryInterface $config_factory, ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('mga2p2');
  }

  /**
   * Whether API key is configured.
   */
  public function isConfigured(): bool {
    return $this->resolveOpenAiApiKey() !== '';
  }

  /**
   * API key: $settings['mga2p2_openai_api_key'] → config → env MGA2P2_OPENAI_API_KEY / OPENAI_API_KEY.
   */
  protected function resolveOpenAiApiKey(): string {
    $fromSettings = trim((string) Settings::get('mga2p2_openai_api_key', ''));
    if ($fromSettings !== '') {
      return $fromSettings;
    }
    $fromConfig = trim((string) $this->configFactory->get('mga2p2.settings')->get('openai_api_key'));
    if ($fromConfig !== '') {
      return $fromConfig;
    }
    $env = getenv('MGA2P2_OPENAI_API_KEY');
    if ($env === FALSE || $env === '') {
      $env = getenv('OPENAI_API_KEY');
    }
    return trim((string) ($env !== FALSE ? $env : ''));
  }

  /**
   * Analyse image bytes and return associative array of extracted fields.
   *
   * @return array<string, mixed>
   *   Keys: montant, phone, name, reference, bank_name, currency (+ any extras).
   *
   * @throws \RuntimeException
   */
  public function extractFromImage(string $binary, string $mime, string $filename = 'upload'): array {
    $config = $this->configFactory->get('mga2p2.settings');
    $apiKey = $this->resolveOpenAiApiKey();
    if ($apiKey === '') {
      throw new \RuntimeException('OpenAI API key is not configured. Set it under /admin/config/system/mga2p2 (Receipt AI), or $settings[\'mga2p2_openai_api_key\'] in settings.php, or $config[\'mga2p2.settings\'][\'openai_api_key\'], or MGA2P2_OPENAI_API_KEY / OPENAI_API_KEY for the web server.');
    }

    $base = rtrim((string) ($config->get('openai_base_url') ?: 'https://api.openai.com/v1'), '/');
    $model = trim((string) ($config->get('openai_model') ?: 'gpt-4o-mini'));
    $url = $base . '/chat/completions';

    $b64 = base64_encode($binary);
    $dataUrl = 'data:' . $mime . ';base64,' . $b64;

    $instruction = <<<'PROMPT'
You analyse P2P crypto trading chat screenshots (e.g. Binance P2P, OKX P2P).

IMPORTANT RULE: The buyer's payment details (phone number and name) are ALWAYS sent by the LEFT-side message bubbles (the counterpart/seller messages). Ignore any right-side bubbles for extracting phone and name.

Look for:
- A phone number (local or international format, e.g. 0376981483)
- A person's name (e.g. Ynnocente, Marie, Jean)
These will appear together in a left-side message, often as the last message in the chat.

AMOUNT EXTRACTION RULE:
Extract the integer part only — strip decimals and all separators (commas, dots), keep digits only.
- "1,103,941.83" → "1103941"
- "500,000.50" → "500000"
- "2,000.00" → "2000"

MOBILE OPERATOR DETECTION RULE (Madagascar):
Derive bank_name automatically from the first 3 digits of the phone number found:
- Starts with 032 or 037 → bank_name = "Orange Money"
- Starts with 034 or 038 → bank_name = "MVola"
- Any other prefix → bank_name = null

Return a single JSON object with these keys (use null if not visible):
montant (string, integer digits only, no separators, no decimals, e.g. "1103941"),
phone (string, phone number from LEFT bubble only),
name (string, payer/beneficiary name from LEFT bubble only),
reference (string, order/transaction reference, e.g. "xx0288"),
bank_name (string, derived from phone prefix as per rule above),
currency (string, ISO or symbol, e.g. "MGA", "Ar", "USDT").

Do not invent values; use null when unsure.
PROMPT;

    $payload = [
      'model' => $model,
      'messages' => [
        [
          'role' => 'user',
          'content' => [
            ['type' => 'text', 'text' => $instruction],
            [
              'type' => 'image_url',
              'image_url' => ['url' => $dataUrl],
            ],
          ],
        ],
      ],
      'response_format' => ['type' => 'json_object'],
      'max_tokens' => 1024,
    ];

    try {
      $response = $this->httpClient->request('POST', $url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $apiKey,
          'Content-Type' => 'application/json',
        ],
        'body' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'timeout' => 120,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->error('OpenAI request failed: @m', ['@m' => $e->getMessage()]);
      throw new \RuntimeException('Vision API request failed: ' . $e->getMessage(), 0, $e);
    }

    $body = (string) $response->getBody();
    $decoded = json_decode($body, TRUE);
    if (!is_array($decoded)) {
      throw new \RuntimeException('Invalid JSON from vision API.');
    }

    if (!empty($decoded['error']['message'])) {
      throw new \RuntimeException((string) $decoded['error']['message']);
    }

    $content = $decoded['choices'][0]['message']['content'] ?? '';
    if (!is_string($content) || $content === '') {
      throw new \RuntimeException('Empty model response.');
    }

    $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/\s*```$/', '', $content);
    $fields = json_decode(trim($content), TRUE);
    if (!is_array($fields)) {
      $this->logger->warning('Model returned non-JSON content: @c', ['@c' => substr($content, 0, 500)]);
      throw new \RuntimeException('Model did not return valid JSON.');
    }

    return $fields;
  }

}
