<?php

namespace Drupal\mga2p2_form\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sends Web Push payloads to stored subscriptions (order status changes).
 */
final class WebPushNotifier {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected Connection $database,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Notifies subscribers when an order_mga status changes.
   *
   * Phone and name are placed first in the body (left side in LTR) so they
   * stay visible before the status line; LTR mark avoids RTL reordering digits.
   */
  public function notifyOrderStatusChange(int $nid, string $nodeTitle, string $previous, string $new, string $phone = '', string $name = ''): void {
    if ($previous === $new) {
      return;
    }
    $config = $this->configFactory->get('mga2p2_form.webpush');
    if (!$config->get('enabled')) {
      return;
    }
    $public = (string) $config->get('vapid_public');
    $private = (string) $config->get('vapid_private');
    $subject = (string) $config->get('vapid_subject') ?: 'mailto:noreply@localhost';
    if ($public === '' || $private === '') {
      return;
    }
    if (!class_exists(WebPush::class)) {
      $this->loggerFactory->get('mga2p2_form')->warning('Web Push: minishlink/web-push is not installed (composer).');
      return;
    }

    $labelPrev = $this->statusLabel($previous);
    $labelNew = $this->statusLabel($new);
    $detailUrl = $this->orderDetailAbsoluteUrl($nid);

    $phone = trim($phone);
    $name = trim($name);
    $lines = [];
    if ($phone !== '') {
      $lines[] = 'Tél. ' . $phone;
    }
    if ($name !== '') {
      $lines[] = 'Nom : ' . $name;
    }
    $statusLine = $labelPrev . ' → ' . $labelNew . ($nodeTitle !== '' ? ' — ' . $nodeTitle : '');
    $lines[] = $statusLine;
    $body = $this->bodyWithLeadingLtr(implode("\n", $lines));

    $payload = [
      'title' => 'Commande #' . $nid,
      'body' => $body,
      'url' => $detailUrl,
      'tag' => 'order-mga-' . $nid,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === FALSE) {
      $this->loggerFactory->get('mga2p2_form')->error('Web Push: json_encode failed.');
      return;
    }

    try {
      $q = $this->database->select('mga2p2_form_push_subscription', 's')
        ->fields('s', ['endpoint', 'p256dh', 'auth']);
      $rows = $q->execute()->fetchAll(\PDO::FETCH_ASSOC);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('mga2p2_form')->error('Web Push: @m', ['@m' => $e->getMessage()]);
      return;
    }
    if (!$rows) {
      return;
    }

    $webPush = new WebPush([
      'VAPID' => [
        'subject' => $subject,
        'publicKey' => $public,
        'privateKey' => $private,
      ],
    ], [], 30, NULL);

    $log = $this->loggerFactory->get('mga2p2_form');
    foreach ($rows as $row) {
      try {
        $sub = Subscription::create([
          'endpoint' => $row['endpoint'],
          'keys' => [
            'p256dh' => $row['p256dh'],
            'auth' => $row['auth'],
          ],
        ]);
        $report = $webPush->sendOneNotification($sub, $json);
        if (!$report->isSuccess()) {
          $code = 0;
          $response = $report->getResponse();
          if ($response !== NULL) {
            $code = $response->getStatusCode();
          }
          if ($code === 410 || $code === 404) {
            $this->deleteByEndpoint($row['endpoint']);
          }
        }
      }
      catch (\Throwable $e) {
        $log->error('Web Push send failed: @m', ['@m' => $e->getMessage()]);
      }
    }
  }

  /**
   * Leading U+200E (LTR mark) keeps phone / Latin name on the logical left in RTL UIs.
   */
  private function bodyWithLeadingLtr(string $body): string {
    if ($body === '') {
      return $body;
    }
    return "\u{200E}" . $body;
  }

  private function deleteByEndpoint(string $endpoint): void {
    $hash = hash('sha256', $endpoint);
    $this->database->delete('mga2p2_form_push_subscription')
      ->condition('endpoint_hash', $hash)
      ->execute();
  }

  private function statusLabel(string $status): string {
    return match ($status) {
      'paye' => 'Payé',
      'pay_en_cours' => 'Payé en cours',
      'archive' => 'Archive',
      default => 'En cours',
    };
  }

  private function orderDetailAbsoluteUrl(int $nid): string {
    try {
      return Url::fromRoute('mga2p2.spa_form.form_sub', ['subpath' => 'orders/' . $nid], ['absolute' => TRUE])->toString();
    }
    catch (\Throwable $e) {
      $base = \Drupal::request()->getSchemeAndHttpHost() . \Drupal::request()->getBasePath();
      return rtrim($base, '/') . '/form/orders/' . $nid;
    }
  }

}
