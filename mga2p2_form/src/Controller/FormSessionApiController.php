<?php

namespace Drupal\mga2p2_form\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\mga2p2_form\Access\FormAdministratorAccess;
use Drupal\user\Entity\User;
use Drupal\user\UserAuthInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON session + login/logout for the /form Vue app (Drupal session cookie).
 */
final class FormSessionApiController extends ControllerBase {

  private const NO_STORE = ['Cache-Control' => 'no-store, private'];

  public function __construct(
    protected UserAuthInterface $userAuth,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('user.auth'),
    );
  }

  /**
   * GET — current session (same-origin cookie).
   */
  public function session(): JsonResponse {
    $account = $this->currentUser();
    if ($account->isAuthenticated()) {
      return new JsonResponse([
        'logged_in' => TRUE,
        'user' => FormAdministratorAccess::userPayload($account),
      ], 200, self::NO_STORE);
    }
    return new JsonResponse(['logged_in' => FALSE], 200, self::NO_STORE);
  }

  /**
   * POST JSON: { "name": "…", "pass": "…" } or "password" instead of pass.
   */
  public function login(Request $request): JsonResponse {
    if ($this->currentUser()->isAuthenticated()) {
      $account = $this->currentUser();
      return new JsonResponse([
        'logged_in' => TRUE,
        'user' => FormAdministratorAccess::userPayload($account),
        'message' => 'Already logged in.',
      ], 200, self::NO_STORE);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['error' => 'Invalid JSON body.', 'logged_in' => FALSE], 400, self::NO_STORE);
    }

    $name = isset($data['name']) ? trim((string) $data['name']) : '';
    $pass = $data['pass'] ?? ($data['password'] ?? '');
    $pass = is_string($pass) ? $pass : '';

    if ($name === '' || $pass === '') {
      return new JsonResponse(['error' => 'Missing name or password.', 'logged_in' => FALSE], 400, self::NO_STORE);
    }

    $uid = (int) $this->userAuth->authenticate($name, $pass);
    if ($uid < 1) {
      return new JsonResponse(['error' => 'Invalid credentials.', 'logged_in' => FALSE], 401, self::NO_STORE);
    }

    $user = User::load($uid);
    if ($user === NULL || !$user->isActive()) {
      return new JsonResponse(['error' => 'Account unavailable.', 'logged_in' => FALSE], 403, self::NO_STORE);
    }

    user_login_finalize($user);

    return new JsonResponse([
      'logged_in' => TRUE,
      'user' => FormAdministratorAccess::userPayload($user),
    ], 200, self::NO_STORE);
  }

  /**
   * POST — destroy session (must be logged in for meaningful effect).
   */
  public function logout(): JsonResponse {
    if ($this->currentUser()->isAuthenticated()) {
      user_logout();
    }
    return new JsonResponse(['logged_in' => FALSE], 200, self::NO_STORE);
  }

}
