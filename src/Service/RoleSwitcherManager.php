<?php

namespace Drupal\role_switcher_session\Service;

use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RoleSwitcherManager {

  const SESSION_KEY = 'role_switcher_active_role';

  protected $entityTypeManager;

  public function __construct($entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  protected function getSession(): SessionInterface {
    return \Drupal::request()->getSession();
  }

  public function getSwitchableRoles(AccountInterface $account): array {
    $rids = array_diff($account->getRoles(), ['authenticated', 'administrator']);
    if (empty($rids)) {
      return [];
    }
    $role_storage = $this->entityTypeManager->getStorage('user_role');
    $options = [];
    foreach ($role_storage->loadMultiple($rids) as $rid => $role) {
      $options[$rid] = $role->label();
    }
    return $options;
  }

  public function setActiveRole(AccountInterface $account, string $rid): void {
    $allowed = $this->getSwitchableRoles($account);
    if (!isset($allowed[$rid])) {
      \Drupal::logger('role_switcher_session')->warning('User @uid attempted to switch to non-permitted role @rid.', [
        '@uid' => $account->id(),
        '@rid' => $rid,
      ]);
      throw new AccessDeniedHttpException('Invalid role switch request.');
    }
    $this->getSession()->set(self::SESSION_KEY, $rid);
  }

  public function clearActiveRole(): void {
    $session = $this->getSession();
    $session->remove(self::SESSION_KEY);
    $session->save();
  }

  public function getActiveRole(AccountInterface $account): ?string {
    $rid = $this->getSession()->get(self::SESSION_KEY);
    if (!$rid) {
      return NULL;
    }
    if (!in_array($rid, $account->getRoles(), TRUE)) {
      $this->clearActiveRole();
      return NULL;
    }
    return $rid;
  }

  public function getEffectiveRoles(AccountInterface $account): array {
    $active = $this->getActiveRole($account);
    if ($active === NULL) {
      return $account->getRoles();
    }
    $roles = ['authenticated', $active];
    if (in_array('administrator', $account->getRoles(), TRUE)) {
      $roles[] = 'administrator';
    }
    return array_values(array_unique($roles));
  }

}