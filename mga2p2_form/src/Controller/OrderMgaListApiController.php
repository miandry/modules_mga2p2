<?php

namespace Drupal\mga2p2_form\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON list of published order_mga nodes with payment window deadline.
 */
class OrderMgaListApiController extends ControllerBase {

  /**
   * GET ?limit=5&offset=0&status=en_cours|paye|pay_en_cours|archive&search=… — newest first.
   *
   * Pagination: use offset with limit (default 5, max 100). Response includes has_more
   * (true when another page exists). One extra row is queried to compute has_more.
   *
   * Search matches (substring, case-insensitive) nom, téléphone, montant, type de paiement.
   */
  public function index(Request $request): JsonResponse {
    $type = $this->entityTypeManager()->getStorage('node_type')->load('order_mga');
    if ($type === NULL) {
      return new JsonResponse([
        'error' => 'Content type order_mga is not installed.',
        'data' => [],
        'mobile_ussd' => $this->mobileUssdPatternsForClient(),
      ], 503);
    }

    $limit = (int) $request->query->get('limit', 5);
    $limit = max(1, min(100, $limit));

    $offset = (int) $request->query->get('offset', 0);
    $offset = max(0, min(5000, $offset));

    $statusParam = $request->query->get('status', '');
    $statusFilter = is_string($statusParam) ? strtolower(trim($statusParam)) : '';
    if ($statusFilter !== '' && !in_array($statusFilter, ['en_cours', 'paye', 'pay_en_cours', 'archive'], TRUE)) {
      $statusFilter = '';
    }

    $searchParam = $request->query->get('search', '');
    $search = is_string($searchParam) ? trim($searchParam) : '';
    if (strlen($search) > 200) {
      $search = substr($search, 0, 200);
    }

    $query = $this->entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'order_mga')
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort('created', 'DESC');

    $hasStatusField = (bool) FieldStorageConfig::loadByName('node', 'field_mga_status');
    if ($hasStatusField && $statusFilter !== '') {
      $this->applyStatusFilter($query, $statusFilter);
    }

    if ($search !== '') {
      $this->applySearchOrGroup($query, $search);
    }

    // Request one extra nid so has_more is accurate when the total is a multiple of limit.
    $query->range($offset, $limit + 1);
    $nids = $query->execute();

    $now = (int) \Drupal::time()->getRequestTime();
    $items = [];
    $hasMore = FALSE;

    if ($nids) {
      $orderedNids = array_values($nids);
      $hasMore = count($orderedNids) > $limit;
      if ($hasMore) {
        $orderedNids = array_slice($orderedNids, 0, $limit);
      }
      $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($orderedNids);
      foreach ($orderedNids as $nid) {
        $node = $nodes[$nid] ?? NULL;
        if (!$node instanceof NodeInterface) {
          continue;
        }
        $items[] = $this->buildListItem($node, $now);
      }
    }

    return new JsonResponse([
      'data' => $items,
      'has_more' => $hasMore,
      'mobile_ussd' => $this->mobileUssdPatternsForClient(),
    ]);
  }

  /**
   * GET ?montant_int=150000 — order_mga en_cours whose montant integer part matches.
   */
  public function matchMontant(Request $request): JsonResponse {
    $type = $this->entityTypeManager()->getStorage('node_type')->load('order_mga');
    if ($type === NULL) {
      return new JsonResponse([
        'error' => 'Content type order_mga is not installed.',
        'data' => [],
      ], 503);
    }

    $raw = $request->query->get('montant_int', '');
    if (!is_numeric($raw)) {
      return new JsonResponse(['error' => 'Query parameter montant_int is required.'], 400);
    }
    $target = (int) floor((float) $raw + 1e-9);
    if ($target < 1) {
      return new JsonResponse(['error' => 'montant_int must be a positive integer.'], 400);
    }

    $query = $this->entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'order_mga')
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort('created', 'DESC')
      ->range(0, 500);

    if ((bool) FieldStorageConfig::loadByName('node', 'field_mga_status')) {
      $this->applyStatusFilter($query, 'en_cours');
    }

    $nids = $query->execute();
    $now = (int) \Drupal::time()->getRequestTime();
    $items = [];

    if ($nids) {
      $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($nids);
      foreach (array_values($nids) as $nid) {
        $node = $nodes[$nid] ?? NULL;
        if (!$node instanceof NodeInterface) {
          continue;
        }
        $montantInt = $this->montantToInteger($this->fieldString($node, 'field_mga_montant'));
        if ($montantInt === NULL || $montantInt !== $target) {
          continue;
        }
        if ($this->orderMgaStatusValue($node) !== 'en_cours') {
          continue;
        }
        $items[] = $this->buildListItem($node, $now);
      }
    }

    return new JsonResponse([
      'data' => $items,
      'montant_int' => $target,
      'count' => count($items),
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  private function buildListItem(NodeInterface $node, int $now): array {
    $created = (int) $node->getCreatedTime();
    $remainMin = 20;
    if ($node->hasField('field_mga_remain_minutes') && !$node->get('field_mga_remain_minutes')->isEmpty()) {
      $remainMin = max(1, min(600, (int) $node->get('field_mga_remain_minutes')->value));
    }
    $deadline = $created + $remainMin * 60;
    $remaining = max(0, $deadline - $now);

    $path = '/node/' . $node->id();
    try {
      $path = Url::fromRoute('entity.node.canonical', ['node' => $node->id()], ['absolute' => FALSE])->toString();
    }
    catch (\Throwable $e) {
      // Keep /node/N.
    }

    return [
      'nid' => (int) $node->id(),
      'title' => $node->getTitle(),
      'path' => $path,
      'created' => $created,
      'remain_minutes' => $remainMin,
      'deadline' => $deadline,
      'remaining_seconds' => $remaining,
      'expired' => $remaining <= 0,
      'montant' => $this->fieldString($node, 'field_mga_montant'),
      'phone' => $this->fieldString($node, 'field_mga_phone'),
      'nom' => $this->fieldString($node, 'field_mga_nom'),
      'reference' => $this->fieldString($node, 'field_mga_reference'),
      'currency' => $this->fieldString($node, 'field_mga_currency'),
      'bank_name' => $this->fieldString($node, 'field_mga_bank_name'),
      'payment_type' => $this->fieldString($node, 'field_mga_payment_type'),
      'status' => $this->orderMgaStatusValue($node),
      'payment_proof_url' => $this->paymentProofUrl($node),
    ];
  }

  private function montantToInteger(?string $montant): ?int {
    if ($montant === NULL || $montant === '') {
      return NULL;
    }
    $s = preg_replace('/[^\d.]/', '', $montant);
    if ($s === '' || $s === '.') {
      return NULL;
    }
    return (int) floor((float) $s + 1e-9);
  }

  /**
   * @return array{mvola_pattern: string, orange_pattern: string}
   */
  private function mobileUssdPatternsForClient(): array {
    $config = $this->config('mga2p2_form.mobile_ussd');
    $mvola = trim((string) $config->get('mvola_pattern'));
    $orange = trim((string) $config->get('orange_pattern'));
    if ($mvola === '') {
      $mvola = '#111*1*3*3*1*NUM*MONTANT*1#';
    }
    if ($orange === '') {
      $orange = '#144*1*1*NUMÉRO*MONTANT#';
    }
    return [
      'mvola_pattern' => $mvola,
      'orange_pattern' => $orange,
    ];
  }

  private function applyStatusFilter(QueryInterface $query, string $statusFilter): void {
    if ($statusFilter === 'en_cours') {
      $or = $query->orConditionGroup();
      $or->condition('field_mga_status.value', 'en_cours');
      $or->condition('field_mga_status.value', NULL, 'IS NULL');
      $query->condition($or);
    }
    elseif (in_array($statusFilter, ['paye', 'pay_en_cours', 'archive'], TRUE)) {
      $query->condition('field_mga_status.value', $statusFilter);
    }
  }

  /**
   * OR group: any of nom / phone / montant / payment_type contains search (LIKE).
   */
  private function applySearchOrGroup(QueryInterface $query, string $search): void {
    $db = \Drupal::database();
    $like = '%' . $db->escapeLike($search) . '%';
    $or = $query->orConditionGroup();
    $or->condition('field_mga_nom.value', $like, 'LIKE');
    $or->condition('field_mga_phone.value', $like, 'LIKE');
    $or->condition('field_mga_montant.value', $like, 'LIKE');
    $or->condition('field_mga_payment_type.value', $like, 'LIKE');
    $query->condition($or);
  }

  private function fieldString(NodeInterface $node, string $field): ?string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return NULL;
    }
    $v = $node->get($field)->value;
    if ($v === NULL || $v === '') {
      return NULL;
    }
    return is_scalar($v) ? (string) $v : NULL;
  }

  private function orderMgaStatusValue(NodeInterface $node): string {
    $v = $this->fieldString($node, 'field_mga_status');
    return $v !== NULL ? $v : 'en_cours';
  }

  /**
   * Public URL path for field_image (payment proof), if present.
   */
  private function paymentProofUrl(NodeInterface $node): ?string {
    if (!$node->hasField('field_image') || $node->get('field_image')->isEmpty()) {
      return NULL;
    }
    $file = $node->get('field_image')->entity;
    if (!$file instanceof FileInterface) {
      return NULL;
    }
    return \Drupal::service('file_url_generator')->generateString($file->getFileUri());
  }

}
