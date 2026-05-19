<?php

namespace Drupal\mga2p2_form\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * GET/POST JSON for MVola / Orange Money USSD string patterns (form app settings).
 *
 * Used by the Vue route /form/settings/mobile-ussd. Persists to config
 * `mga2p2_form.mobile_ussd`. Requires permission
 * `manage_mga2p2_form_mobile_ussd_settings`. POST requires header
 * `X-CSRF-Token` (same value as `csrf_token` from GET).
 *
 * Routes: GET/POST `/mga2p2-form/api/mobile-ussd-settings` and alias
 * GET/POST `/mga2p2-form/api/form/settings/mobile-ussd`.
 */
class MobileUssdSettingsApiController extends ControllerBase {

  private const DEFAULT_MVOLA = '#111*1*3*3*1*NUM*MONTANT*1#';

  private const DEFAULT_ORANGE = '#144*1*1*NUMÉRO*MONTANT#';

  private const MAX_LEN = 512;

  /** @see \Drupal\Core\Form\FormBuilderInterface::buildForm() token id pattern */
  public const CSRF_ID = 'mga2p2_form_mobile_ussd_settings';

  public function __construct(
    protected CsrfTokenGenerator $csrfToken,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('csrf_token'),
    );
  }

  /**
   * GET — current patterns (defaults if config missing) + CSRF token for POST.
   */
  public function get(Request $request): JsonResponse {
    unset($request);
    $config = $this->config('mga2p2_form.mobile_ussd');
    $mvola = trim((string) $config->get('mvola_pattern'));
    $orange = trim((string) $config->get('orange_pattern'));
    if ($mvola === '') {
      $mvola = self::DEFAULT_MVOLA;
    }
    if ($orange === '') {
      $orange = self::DEFAULT_ORANGE;
    }
    return new JsonResponse([
      'mvola_pattern' => $mvola,
      'orange_pattern' => $orange,
      'csrf_token' => $this->csrfToken->get(self::CSRF_ID),
    ]);
  }

  /**
   * POST JSON { mvola_pattern, orange_pattern } — saves config.
   */
  public function post(Request $request): JsonResponse {
    $headerToken = $request->headers->get('X-CSRF-Token');
    if (!is_string($headerToken) || $headerToken === '' || !$this->csrfToken->validate($headerToken, self::CSRF_ID)) {
      return new JsonResponse(['error' => 'Missing or invalid CSRF token (use csrf_token from GET in header X-CSRF-Token).'], 403);
    }

    $raw = $request->getContent();
    $body = json_decode($raw, TRUE);
    if (!is_array($body)) {
      return new JsonResponse(['error' => 'JSON body required.'], 400);
    }

    $mvola = isset($body['mvola_pattern']) && is_string($body['mvola_pattern'])
      ? trim($body['mvola_pattern']) : '';
    $orange = isset($body['orange_pattern']) && is_string($body['orange_pattern'])
      ? trim($body['orange_pattern']) : '';

    $err = $this->validatePattern($mvola, 'mvola_pattern');
    if ($err !== NULL) {
      return new JsonResponse(['error' => $err], 400);
    }
    $err = $this->validatePattern($orange, 'orange_pattern');
    if ($err !== NULL) {
      return new JsonResponse(['error' => $err], 400);
    }

    $this->configFactory()->getEditable('mga2p2_form.mobile_ussd')
      ->set('mvola_pattern', $mvola)
      ->set('orange_pattern', $orange)
      ->save();

    return new JsonResponse([
      'ok' => TRUE,
      'mvola_pattern' => $mvola,
      'orange_pattern' => $orange,
      'csrf_token' => $this->csrfToken->get(self::CSRF_ID),
    ]);
  }

  private function validatePattern(string $value, string $field): ?string {
    if ($value === '') {
      return $field . ' must be a non-empty string.';
    }
    if (mb_strlen($value) > self::MAX_LEN) {
      return $field . ' exceeds ' . self::MAX_LEN . ' characters.';
    }
    if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)) {
      return $field . ' contains invalid control characters.';
    }
    return NULL;
  }

}
