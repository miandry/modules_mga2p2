<?php

namespace Drupal\mga2p2_form\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Sets field_mga_status on an order_mga node (same-origin staff / form use).
 */
class OrderMgaSetStatusApiController extends ControllerBase {

  protected AccountSwitcherInterface $accountSwitcher;

  public function __construct(AccountSwitcherInterface $account_switcher) {
    $this->accountSwitcher = $account_switcher;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('account_switcher'),
    );
  }

  /**
   * POST JSON: { "nid": 123, "status": "paye" } — status: en_cours | paye | archive.
   *
   * Web Push is sent from hook_entity_update() when the status field changes.
   */
  public function setStatus(Request $request): JsonResponse {
    $raw = $request->getContent();
    $body = json_decode($raw, TRUE);
    if (!is_array($body) || !isset($body['nid'])) {
      return new JsonResponse(['error' => 'JSON body must include "nid".'], 400);
    }

    $nid = (int) $body['nid'];
    if ($nid < 1) {
      return new JsonResponse(['error' => 'Invalid nid.'], 400);
    }

    $status = isset($body['status']) && is_string($body['status'])
      ? strtolower(trim($body['status']))
      : 'paye';
    $allowed = ['en_cours', 'paye', 'archive'];
    if (!in_array($status, $allowed, TRUE)) {
      return new JsonResponse(['error' => 'Invalid status. Use: en_cours, paye, archive.'], 400);
    }

    $node = Node::load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'order_mga') {
      return new JsonResponse(['error' => 'Order not found.'], 404);
    }

    if (!$node->hasField('field_mga_status')) {
      return new JsonResponse(['error' => 'field_mga_status is not installed. Run database updates.'], 503);
    }

    $original_uid = (int) $this->currentUser()->id();
    $admin = User::load(1);
    $switched = FALSE;
    if ($admin && $original_uid !== 1) {
      $this->accountSwitcher->switchTo($admin);
      $switched = TRUE;
    }

    try {
      $node->set('field_mga_status', $status);
      $node->save();
    }
    catch (\Throwable $e) {
      $this->getLogger('mga2p2_form')->error('order_mga set status failed: @m', ['@m' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Could not update order: ' . $e->getMessage()], 500);
    }
    finally {
      if ($switched) {
        $this->accountSwitcher->switchBack();
      }
    }

    return new JsonResponse([
      'nid' => $nid,
      'status' => $status,
    ]);
  }

  /**
   * POST multipart: file field "image" — saves to field_image + field_mga_status paye.
   */
  public function markPayeWithProof(Request $request, string $nid): JsonResponse {
    $nid = (int) $nid;
    if ($nid < 1) {
      return new JsonResponse(['error' => 'Invalid order id.'], 400);
    }

    $node = Node::load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'order_mga' || !$node->isPublished()) {
      return new JsonResponse(['error' => 'Order not found.'], 404);
    }

    if (!$node->hasField('field_image')) {
      return new JsonResponse(['error' => 'field_image is not installed. Run database updates for mga2p2_form.'], 503);
    }
    if (!$node->hasField('field_mga_status')) {
      return new JsonResponse(['error' => 'field_mga_status is not installed.'], 503);
    }

    $upload = $request->files->get('image');
    if ($upload === NULL || !$upload->isValid()) {
      return new JsonResponse(['error' => 'Missing or invalid file field "image".'], 400);
    }

    $realPath = $upload->getRealPath();
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
    $allowed = [
      'image/jpeg' => 'image/jpeg',
      'image/png' => 'image/png',
      'image/webp' => 'image/webp',
      'image/gif' => 'image/gif',
    ];
    if (!isset($allowed[$mime])) {
      return new JsonResponse(['error' => 'Only JPEG, PNG, WebP or GIF images are allowed.'], 400);
    }

    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $upload->getClientOriginalName());
    if ($safe === '' || $safe === '_') {
      $safe = 'preuve.jpg';
    }

    $subdir = 'public://order-mga-payment-proof/' . date('Y-m');
    $fs = $this->getFileSystemService();
    $fs->prepareDirectory($subdir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $destination = $subdir . '/' . $safe;
    try {
      $file = $this->getFileRepository()->writeData($binary, $destination, FileSystemInterface::EXISTS_RENAME);
    }
    catch (\Throwable $e) {
      $this->getLogger('mga2p2_form')->error('Payment proof upload failed: @m', ['@m' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Could not store the image.'], 500);
    }

    $file->setPermanent();
    $file->save();

    $alt = 'Preuve de paiement — commande #' . $nid;

    $original_uid = (int) $this->currentUser()->id();
    $admin = User::load(1);
    $switched = FALSE;
    if ($admin && $original_uid !== 1) {
      $this->accountSwitcher->switchTo($admin);
      $switched = TRUE;
    }

    try {
      $node->set('field_image', [
        'target_id' => (int) $file->id(),
        'alt' => $alt,
        'title' => '',
      ]);
      $node->set('field_mga_status', 'paye');
      $node->save();
    }
    catch (\Throwable $e) {
      $this->getLogger('mga2p2_form')->error('order_mga mark paye with proof failed: @m', ['@m' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Could not update order: ' . $e->getMessage()], 500);
    }
    finally {
      if ($switched) {
        $this->accountSwitcher->switchBack();
      }
    }

    return new JsonResponse([
      'nid' => $nid,
      'status' => 'paye',
      'fid' => (int) $file->id(),
    ]);
  }

  /**
   * @return \Drupal\Core\File\FileSystemInterface
   */
  private function getFileSystemService(): FileSystemInterface {
    return \Drupal::service('file_system');
  }

  /**
   * @return \Drupal\file\FileRepositoryInterface
   */
  private function getFileRepository() {
    return \Drupal::service('file.repository');
  }

}
