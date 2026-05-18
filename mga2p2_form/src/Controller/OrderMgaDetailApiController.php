<?php

namespace Drupal\mga2p2_form\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * JSON detail for one published order_mga node.
 */
class OrderMgaDetailApiController extends ControllerBase {

  /**
   * GET /mga2p2-form/api/order-mga/{nid}
   */
  public function getOrder(string $nid): JsonResponse {
    $nid = (int) $nid;
    if ($nid < 1) {
      return new JsonResponse(['error' => 'Invalid order id.'], 400);
    }

    $type = $this->entityTypeManager()->getStorage('node_type')->load('order_mga');
    if ($type === NULL) {
      return new JsonResponse(['error' => 'Content type order_mga is not installed.'], 503);
    }

    $node = Node::load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'order_mga' || !$node->isPublished()) {
      return new JsonResponse(['error' => 'Order not found.'], 404);
    }

    $now = (int) \Drupal::time()->getRequestTime();
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

    $data = [
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
      'user_info' => $this->fieldString($node, 'field_mga_user_info'),
      'receipt_filename' => $this->fieldString($node, 'field_mga_receipt_filename'),
      'payment_proof_url' => $this->paymentProofUrl($node),
    ];

    return new JsonResponse(['data' => $data]);
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
