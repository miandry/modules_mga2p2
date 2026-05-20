<?php

namespace Drupal\mga2p2\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\mga2p2\AiReceiptExtractor;
use Drupal\mga2p2\ReceiptC2cOrderMatcher;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON API for receipt upload, AI extraction, and listing saved rows.
 */
class ReceiptFormApiController extends ControllerBase {

  protected Connection $database;
  protected AiReceiptExtractor $extractor;

  public function __construct(Connection $database, AiReceiptExtractor $extractor) {
    $this->database = $database;
    $this->extractor = $extractor;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('mga2p2.ai_receipt_extractor'),
    );
  }

  /**
   * Binance C2C matcher (save only). Not injected in create() so preview works before cache rebuild.
   */
  private function c2cMatcher(): ?ReceiptC2cOrderMatcher {
    if (!\Drupal::hasService('mga2p2.receipt_c2c_order_matcher')) {
      return NULL;
    }
    return \Drupal::service('mga2p2.receipt_c2c_order_matcher');
  }

  /**
   * POST multipart field "image" — AI only, no database write.
   */
  public function preview(Request $request): JsonResponse {
    if (!$this->extractor->isConfigured()) {
      return new JsonResponse([
        'error' => 'OpenAI API key is not configured. Add it at /admin/config/system/mga2p2 (Receipt AI), or set MGA2P2_OPENAI_API_KEY / OPENAI_API_KEY for PHP, or $settings[\'mga2p2_openai_api_key\'] in settings.php. Run drush updatedb if you just deployed the Receipt AI fields.',
      ], 503);
    }

    $parsed = $this->parseUploadedImage($request);
    if ($parsed instanceof JsonResponse) {
      return $parsed;
    }

    try {
      $fields = $this->extractor->extractFromImage($parsed['binary'], $parsed['mime'], $parsed['filename']);
    }
    catch (\Throwable $e) {
      return new JsonResponse(['error' => $e->getMessage()], 502);
    }

    return new JsonResponse([
      'extracted' => $fields,
      'filename' => $parsed['filename'],
    ]);
  }

  /**
   * POST JSON: extracted (AI), optional filename, optional form (user edits).
   *
   * Form keys: montant, phone, name, payment_type (mvola|orange), remain_minutes, user_info.
   */
  public function save(Request $request): JsonResponse {
    if (!$this->database->schema()->tableExists('mga2p2_receipt_extractions')) {
      return new JsonResponse([
        'error' => 'Receipt storage is not installed. Run database updates for the mga2p2 module (e.g. drush updatedb -y or /update.php).',
      ], 503);
    }

    $raw = $request->getContent();
    $body = json_decode($raw, TRUE);
    if (!is_array($body) || !isset($body['extracted']) || !is_array($body['extracted'])) {
      return new JsonResponse(['error' => 'JSON body must include an "extracted" object.'], 400);
    }

    $fields = $body['extracted'];
    $form = isset($body['form']) && is_array($body['form']) ? $body['form'] : [];

    $filename = isset($body['filename']) && is_string($body['filename'])
      ? $body['filename']
      : 'upload';

    $filenameStored = $this->truncateFilenameForStorage($filename);
    $existing = $this->findFirstReceiptByFilename($filenameStored);
    if ($existing !== NULL) {
      return new JsonResponse(array_merge([
        'error' => 'Duplicate image filename already saved.',
        'duplicate' => TRUE,
      ], $this->duplicateFirstSavedPayload($existing)), 409);
    }

    $merged = $this->mergeFormIntoExtracted($fields, $form);
    $binance = [
      'status' => 'skipped_no_service',
      'order_number' => NULL,
      'trade_type' => NULL,
      'candidates' => 0,
      'message' => NULL,
    ];
    $matcher = $this->c2cMatcher();
    if ($matcher !== NULL) {
      $resolved = $matcher->resolve($merged);
      $merged = $resolved['merged'];
      $binance = $resolved['binance'];
    }
    $merged['binance_match'] = $binance;

    $paymentType = $this->normalizePaymentType($form['payment_type'] ?? '');
    $remainMinutes = $this->normalizeRemainMinutes($form['remain_minutes'] ?? 20);
    $userInfo = isset($form['user_info']) && is_string($form['user_info'])
      ? trim($form['user_info'])
      : '';
    $userInfo = $userInfo === '' ? NULL : $userInfo;

    $bankName = $this->bankNameFromPaymentType($paymentType, $merged['bank_name'] ?? NULL);

    $now = \Drupal::time()->getRequestTime();
    $row = [
      'montant' => $this->truncate($merged['montant'] ?? NULL, 128),
      'phone' => $this->truncate($merged['phone'] ?? NULL, 64),
      'name' => $this->truncate($merged['name'] ?? NULL, 255),
      'reference' => $this->truncate($merged['reference'] ?? NULL, 128),
      'bank_name' => $this->truncate($bankName, 255),
      'currency' => $this->truncate($merged['currency'] ?? NULL, 16),
      'raw_json' => json_encode($merged, JSON_UNESCAPED_UNICODE),
      'filename' => $filenameStored,
      'created' => $now,
      'payment_type' => $paymentType === '' ? NULL : $paymentType,
      'remain_minutes' => $remainMinutes,
      'user_info' => $userInfo,
    ];

    $schema = $this->database->schema();
    if ($schema->fieldExists('mga2p2_receipt_extractions', 'binance_order_number')) {
      $on = $binance['order_number'] ?? NULL;
      $row['binance_order_number'] = is_string($on) && $on !== ''
        ? $this->truncate($on, 64)
        : NULL;
      $row['binance_match_status'] = $this->truncate((string) ($binance['status'] ?? ''), 32);
    }

    try {
      $id = (int) $this->database->insert('mga2p2_receipt_extractions')
        ->fields($row)
        ->execute();
    }
    catch (\Throwable $e) {
      \Drupal::logger('mga2p2')->error('Receipt save failed: @m', ['@m' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Could not save to the database.'], 500);
    }

    return new JsonResponse([
      'id' => $id,
      'saved' => $row,
      'extracted' => $merged,
      'binance' => $binance,
    ]);
  }

  /**
   * GET recent saved extractions (newest first).
   */
  public function listReceipts(Request $request): JsonResponse {
    if (!$this->database->schema()->tableExists('mga2p2_receipt_extractions')) {
      return new JsonResponse([
        'data' => [],
        'warning' => 'Receipt storage table is missing. Run database updates for the mga2p2 module (e.g. drush updatedb -y).',
      ]);
    }

    $limit = (int) $request->query->get('limit', 50);
    $limit = max(1, min(100, $limit));

    $q = $this->database->select('mga2p2_receipt_extractions', 'r')
      ->fields('r')
      ->orderBy('id', 'DESC')
      ->range(0, $limit);

    $rows = $q->execute()->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
      if (!empty($r['raw_json'])) {
        $decoded = json_decode($r['raw_json'], TRUE);
        $r['raw_json'] = is_array($decoded) ? $decoded : $r['raw_json'];
      }
    }

    return new JsonResponse(['data' => $rows]);
  }

  /**
   * GET ?filename=… — whether this filename already exists (first row by id).
   */
  public function duplicateCheck(Request $request): JsonResponse {
    if (!$this->database->schema()->tableExists('mga2p2_receipt_extractions')) {
      return new JsonResponse(['duplicate' => FALSE, 'table_missing' => TRUE]);
    }
    $fn = $request->query->get('filename', '');
    $fn = is_string($fn) ? trim($fn) : '';
    if ($fn === '') {
      return new JsonResponse(['duplicate' => FALSE]);
    }
    $stored = $this->truncateFilenameForStorage($fn);
    $existing = $this->findFirstReceiptByFilename($stored);
    if ($existing === NULL) {
      return new JsonResponse(['duplicate' => FALSE]);
    }
    return new JsonResponse(array_merge([
      'duplicate' => TRUE,
    ], $this->duplicateFirstSavedPayload($existing)));
  }

  /**
   * Same normalization as used when inserting a row.
   */
  private function truncateFilenameForStorage(string $filename): string {
    $t = $this->truncate($filename, 255);
    return $t !== NULL && $t !== '' ? $t : 'upload';
  }

  /**
   * @return array<string, mixed>|null
   *   Row keys: id, created, montant, phone, name.
   */
  private function findFirstReceiptByFilename(string $filenameStored): ?array {
    try {
      $row = $this->database->select('mga2p2_receipt_extractions', 'r')
        ->fields('r', ['id', 'created', 'montant', 'phone', 'name'])
        ->condition('filename', $filenameStored)
        ->orderBy('id', 'ASC')
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();
      return $row === FALSE ? NULL : $row;
    }
    catch (\Throwable $e) {
      return NULL;
    }
  }

  /**
   * @param array<string, mixed> $existing
   *
   * @return array<string, mixed>
   */
  private function duplicateFirstSavedPayload(array $existing): array {
    return [
      'first_id' => (int) $existing['id'],
      'first_created' => (int) $existing['created'],
      'first_montant' => $this->nullableDbString($existing['montant'] ?? NULL),
      'first_phone' => $this->nullableDbString($existing['phone'] ?? NULL),
      'first_name' => $this->nullableDbString($existing['name'] ?? NULL),
    ];
  }

  /**
   * @param mixed $value
   */
  private function nullableDbString($value): ?string {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    return is_scalar($value) ? (string) $value : NULL;
  }

  /**
   * @return array{binary: string, mime: string, filename: string}|JsonResponse
   */
  private function parseUploadedImage(Request $request) {
    $file = $request->files->get('image');
    if ($file === NULL || !$file->isValid()) {
      return new JsonResponse(['error' => 'Missing or invalid file field "image".'], 400);
    }

    $allowed = [
      'image/jpeg' => 'image/jpeg',
      'image/png' => 'image/png',
      'image/webp' => 'image/webp',
    ];
    $realPath = $file->getRealPath();
    if ($realPath === FALSE || !is_readable($realPath)) {
      return new JsonResponse(['error' => 'Could not read upload.'], 400);
    }

    $binary = file_get_contents($realPath);
    if ($binary === FALSE) {
      return new JsonResponse(['error' => 'Could not read upload.'], 400);
    }

    if (strlen($binary) > 8 * 1024 * 1024) {
      return new JsonResponse(['error' => 'Image too large (max 8 MB).'], 400);
    }

    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($realPath) ?: 'application/octet-stream';
    if (!isset($allowed[$mime])) {
      return new JsonResponse(['error' => 'Only JPEG, PNG, or WebP images are allowed.'], 400);
    }

    return [
      'binary' => $binary,
      'mime' => $mime,
      'filename' => $file->getClientOriginalName(),
    ];
  }

  private function truncate($value, int $max): ?string {
    if ($value === NULL) {
      return NULL;
    }
    $s = is_scalar($value) ? (string) $value : json_encode($value);
    if (strlen($s) <= $max) {
      return $s;
    }
    return substr($s, 0, $max);
  }

  /**
   * @param array<string, mixed> $extracted
   * @param array<string, mixed> $form
   *
   * @return array<string, mixed>
   */
  private function mergeFormIntoExtracted(array $extracted, array $form): array {
    $out = $extracted;
    foreach (['montant', 'phone', 'name', 'reference', 'currency', 'bank_name'] as $key) {
      if (!array_key_exists($key, $form)) {
        continue;
      }
      $v = $form[$key];
      if (!is_scalar($v) && $v !== NULL) {
        continue;
      }
      $s = $v === NULL ? '' : trim((string) $v);
      $out[$key] = $s === '' ? NULL : $s;
    }
    $pt = $this->normalizePaymentType($form['payment_type'] ?? '');
    $out['payment_type'] = $pt === '' ? NULL : $pt;
    $out['remain_minutes'] = $this->normalizeRemainMinutes($form['remain_minutes'] ?? 20);
    if (isset($form['user_info']) && is_string($form['user_info'])) {
      $u = trim($form['user_info']);
      $out['user_info'] = $u === '' ? NULL : $u;
    }
    return $out;
  }

  private function normalizePaymentType($value): string {
    if (!is_string($value)) {
      return '';
    }
    $v = strtolower(trim($value));
    return in_array($v, ['mvola', 'orange'], TRUE) ? $v : '';
  }

  private function normalizeRemainMinutes($value): int {
    $n = is_numeric($value) ? (int) $value : 20;
    return max(1, min(600, $n));
  }

  /**
   * @param mixed $fallbackFromAi
   */
  private function bankNameFromPaymentType(string $paymentType, $fallbackFromAi): ?string {
    if ($paymentType === 'mvola') {
      return 'MVola';
    }
    if ($paymentType === 'orange') {
      return 'Orange Money';
    }
    if ($fallbackFromAi === NULL || $fallbackFromAi === '') {
      return NULL;
    }
    return is_scalar($fallbackFromAi) ? (string) $fallbackFromAi : NULL;
  }

}
