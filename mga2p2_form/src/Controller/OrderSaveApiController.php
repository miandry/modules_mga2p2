<?php

namespace Drupal\mga2p2_form\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Url;
use Drupal\mga2p2\ReceiptC2cOrderMatcher;
use Drupal\node\Entity\Node;
use Drupal\node\NodeTypeInterface;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Creates an order_mga node from the same JSON payload as the receipt form API.
 */
class OrderSaveApiController extends ControllerBase {

  protected AccountSwitcherInterface $accountSwitcher;

  public function __construct(AccountSwitcherInterface $account_switcher) {
    $this->accountSwitcher = $account_switcher;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('account_switcher'),
    );
  }

  private function c2cMatcher(): ?ReceiptC2cOrderMatcher {
    if (!\Drupal::hasService('mga2p2.receipt_c2c_order_matcher')) {
      return NULL;
    }
    return \Drupal::service('mga2p2.receipt_c2c_order_matcher');
  }

  /**
   * POST JSON: extracted, filename, form — same shape as mga2p2 receipt-save.
   */
  public function save(Request $request): JsonResponse {
    $type = $this->entityTypeManager()->getStorage('node_type')->load('order_mga');
    if (!$type instanceof NodeTypeInterface) {
      return new JsonResponse(['error' => 'Content type order_mga is not installed. Enable mga2p2_form and run database updates.'], 503);
    }

    $raw = $request->getContent();
    $body = json_decode($raw, TRUE);
    if (!is_array($body) || !isset($body['extracted']) || !is_array($body['extracted'])) {
      return new JsonResponse(['error' => 'JSON body must include an "extracted" object.'], 400);
    }

    $extracted = $body['extracted'];
    $form = isset($body['form']) && is_array($body['form']) ? $body['form'] : [];
    $filename = isset($body['filename']) && is_string($body['filename'])
      ? trim($body['filename'])
      : '';

    $merged = $this->mergeFormIntoExtracted($extracted, $form);
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
    $title = $this->buildTitle($merged, $filename);

    // Same spirit as the receipt JSON API: /form works for anonymous users.
    // Anonymous authors cannot be uid 0 on all sites; use uid 1 (admin) as technical owner if needed.
    $uid = (int) $this->currentUser()->id();
    if ($uid < 1) {
      $uid = 1;
    }

    $values = [
      'type' => 'order_mga',
      'title' => $title,
      'uid' => $uid,
      'status' => 1,
      'field_mga_montant' => $this->scalarOrNull($merged['montant'] ?? NULL),
      'field_mga_phone' => $this->scalarOrNull($merged['phone'] ?? NULL),
      'field_mga_nom' => $this->scalarOrNull($merged['name'] ?? NULL),
      'field_mga_reference' => $this->scalarOrNull($merged['reference'] ?? NULL),
      'field_mga_currency' => $this->scalarOrNull($merged['currency'] ?? NULL),
      'field_mga_bank_name' => $this->scalarOrNull($merged['bank_name'] ?? NULL),
      'field_mga_remain_minutes' => $this->normalizeRemainMinutes($merged['remain_minutes'] ?? ($form['remain_minutes'] ?? 20)),
      'field_mga_receipt_filename' => $filename !== '' ? $filename : NULL,
      'field_mga_status' => $this->normalizeOrderMgaStatus($form['status'] ?? 'en_cours'),
    ];

    $pt = $this->normalizePaymentType($form['payment_type'] ?? '');
    if ($pt !== '') {
      $values['field_mga_payment_type'] = $pt;
    }

    $userInfo = isset($form['user_info']) && is_string($form['user_info']) ? trim($form['user_info']) : '';
    if ($userInfo !== '') {
      $values['field_mga_user_info'] = $userInfo;
    }

    // Anonymous users on /form cannot create nodes as themselves; save as uid 1
    // while temporarily switching account so entity access checks succeed.
    $original_uid = (int) $this->currentUser()->id();
    $uid = $original_uid > 0 ? $original_uid : 1;
    $switched = FALSE;
    if ($original_uid === 0) {
      $admin = User::load(1);
      if ($admin) {
        $this->accountSwitcher->switchTo($admin);
        $switched = TRUE;
      }
    }

    try {
      $node = Node::create($values);
      $node->save();
    }
    catch (\Throwable $e) {
      $this->getLogger('mga2p2_form')->error('order_mga save failed: @m', ['@m' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Could not create order: ' . $e->getMessage()], 500);
    }
    finally {
      if ($switched) {
        $this->accountSwitcher->switchBack();
      }
    }

    $nid = (int) $node->id();
    $path = '/node/' . $nid;
    try {
      $path = Url::fromRoute('entity.node.canonical', ['node' => $nid], ['absolute' => FALSE])->toString();
    }
    catch (\Throwable $e) {
      // Keep /node/N fallback.
    }

    return new JsonResponse([
      'nid' => $nid,
      'path' => $path,
      'title' => $node->getTitle(),
      'binance' => $binance,
    ]);
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

  /**
   * @param mixed $value
   */
  private function normalizeOrderMgaStatus($value): string {
    if (!is_string($value)) {
      return 'en_cours';
    }
    $v = strtolower(trim($value));
    return in_array($v, ['en_cours', 'paye', 'pay_en_cours', 'archive'], TRUE) ? $v : 'en_cours';
  }

  private function normalizeRemainMinutes($value): int {
    $n = is_numeric($value) ? (int) $value : 20;
    return max(1, min(600, $n));
  }

  /**
   * @param mixed $v
   */
  private function scalarOrNull($v): ?string {
    if ($v === NULL) {
      return NULL;
    }
    if (!is_scalar($v)) {
      return NULL;
    }
    $s = trim((string) $v);
    return $s === '' ? NULL : $s;
  }

  /**
   * @param array<string, mixed> $merged
   */
  private function buildTitle(array $merged, string $filename): string {
    $parts = [];
    if (!empty($merged['reference'])) {
      $parts[] = (string) $merged['reference'];
    }
    elseif (!empty($merged['montant'])) {
      $parts[] = (string) $merged['montant'];
    }
    elseif ($filename !== '') {
      $parts[] = pathinfo($filename, PATHINFO_FILENAME) ?: $filename;
    }
    $suffix = $parts ? implode(' — ', $parts) : 'Sans titre';
    $base = 'Reçu ' . $suffix;
    return strlen($base) > 250 ? substr($base, 0, 247) . '…' : $base;
  }

}
