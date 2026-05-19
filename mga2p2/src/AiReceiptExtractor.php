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
   * Digits-only MSISDN; strips country code 261 when present.
   *
   * Normalizes to national form with leading 0 (03X…) when the model omits it
   * after +261 (e.g. 386252137 → 0386252137).
   */
  protected function normalizeMadagascarLocalMobile(string $raw): string {
    $d = preg_replace('/\D+/', '', $raw);
    if ($d === NULL || $d === '') {
      return '';
    }
    if (str_starts_with($d, '261')) {
      $d = substr($d, 3);
    }
    if ($d === '') {
      return '';
    }
    if (str_starts_with($d, '0') && strlen($d) >= 3) {
      return $d;
    }
    // National significant number without leading 0 (e.g. 34xxxxxxxx, 38xxxxxxx).
    if (strlen($d) >= 2 && $d[0] === '3') {
      return '0' . $d;
    }
    return $d;
  }

  /**
   * Derives mobile-money label from Malagasy mobile prefix (must match vision prompt).
   */
  protected function inferMadagascarBankNameFromPhone(?string $phone): ?string {
    if ($phone === NULL || $phone === '') {
      return NULL;
    }
    $d = $this->normalizeMadagascarLocalMobile($phone);
    if (strlen($d) < 3) {
      return NULL;
    }
    $p3 = substr($d, 0, 3);
    if (in_array($p3, ['032', '037'], TRUE)) {
      return 'Orange Money';
    }
    if (in_array($p3, ['034', '038'], TRUE)) {
      return 'MVola';
    }
    return NULL;
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

TODAY ONLY RULE:
ONLY extract information from messages sent TODAY (after the "Aujourd'hui" separator).
Ignore ALL messages from previous days entirely.
If no phone number or name is found in today's messages, return "NOT_FOUND" for that field instead of null.

Look for in LEFT bubbles (today only):
- A phone number (local format, e.g. 0386252137). It may appear in its own separate bubble.
- A person's name (e.g. saholinirina Asminah). It may appear in its own separate bubble.
Phone and name are often sent as separate consecutive left-side bubbles — treat them as a group.

AMOUNT EXTRACTION RULE:
Extract the integer part only — strip decimals and all separators (commas, dots), keep digits only.
- "716,007.25" → "716007"
- "1,103,941.83" → "1103941"
Amount is taken from the header/title of the chat (e.g. "Acheter des USDT avec Ar716,007.25").

MOBILE OPERATOR DETECTION RULE (Madagascar) — apply strictly based on exact prefix:
- Starts with 032 or 037 → bank_name = "Orange Money"
- Starts with 034 or 038 → bank_name = "MVola"
- Any other prefix → bank_name = null
Example: 0386252137 starts with 038 → bank_name = "MVola"
Example: 0376981483 starts with 037 → bank_name = "Orange Money"

Return a single JSON object with these keys:
montant (string, integer digits only, no separators, no decimals, e.g. "716007"),
phone (string, phone from today's LEFT bubbles only, or "NOT_FOUND"),
name (string, name from today's LEFT bubbles only, or "NOT_FOUND"),
reference (string, most recent order reference from today, e.g. "xx3776", or "NOT_FOUND"),
bank_name (string, derived from phone prefix, or null if phone is "NOT_FOUND"),
currency (string, ISO or symbol, e.g. "MGA", "Ar", "USDT", or "NOT_FOUND").

Do not invent values; use "NOT_FOUND" when today's messages don't contain the information.
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

    // Override LLM bank_name when we can infer operator from digits (model may pick wrong bubble/UI text).
    $phoneRaw = $fields['phone'] ?? '';
    $phoneRaw = is_string($phoneRaw) || is_int($phoneRaw) || is_float($phoneRaw) ? (string) $phoneRaw : '';
    $inferredBank = $this->inferMadagascarBankNameFromPhone($phoneRaw);
    if ($inferredBank !== NULL) {
      $fields['bank_name'] = $inferredBank;
    }

    return $fields;
  }

}
