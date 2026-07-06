<?php

namespace Drupal\role_switcher_session\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * @file
 * Contains \Drupal\role_switcher_session\Service\RoleSwitcherAccountProxy.
 *
 * @author Vaibhav Bargal
 * @date 2026-06-30
 */

/**
 * Decorates Drupal's core `current_user` service.
 *
 * This class is wired in role_switcher.services.yml as a service decorator
 * for the `current_user` service (Drupal\Core\Session\AccountProxy). Once
 * decorated, every part of Drupal core, contrib, and custom code that asks
 * "who is the current user / what roles do they have / can they do X" —
 * routing access checks, Views access plugins, Twig's `current_user`
 * global, custom access checkers, hook implementations, etc. — is
 * transparently routed through this class instead of the original service.
 *
 * Only role- and permission-related methods (getRoles(), hasRole(),
 * hasPermission()) are overridden to reflect the active role switch.
 * Every other property (uid, email, display name, language preference,
 * timezone, authentication state) is passed straight through to the
 * original decorated service ($this->inner) untouched — switching roles
 * never changes who the user is, only what they are currently allowed
 * to do.
 *
 * @author Vaibhav Bargal
 * @date 2026-06-30
 */
class RoleSwitcherAccountProxy implements AccountProxyInterface {

  /**
   * The original, undecorated current_user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $inner;

  /**
   * The Role Switcher manager service.
   *
   * @var \Drupal\role_switcher_session\Service\RoleSwitcherManager
   */
  protected RoleSwitcherManager $manager;

  /**
   * Constructs a new RoleSwitcherAccountProxy.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $inner
   *   The decorated (original) current_user service, injected
   *   automatically by Drupal's service container via the `decorates` /
   *   `.inner` convention defined in role_switcher.services.yml.
   * @param \Drupal\role_switcher_session\Service\RoleSwitcherManager $manager
   *   The Role Switcher manager, used to resolve the effective role list.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function __construct(AccountProxyInterface $inner, RoleSwitcherManager $manager) {
    $this->inner = $inner;
    $this->manager = $manager;
  }

  /**
   * {@inheritdoc}
   *
   * Passed straight through to the original service; setting the
   * underlying account (e.g. on login) is unrelated to role switching.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function setAccount(AccountInterface $account) {
    $this->inner->setAccount($account);
  }

  /**
   * {@inheritdoc}
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function getAccount() {
    return $this->inner->getAccount();
  }

  /**
   * {@inheritdoc}
   *
   * Identity (uid) is never affected by a role switch.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function id() {
    return $this->inner->id();
  }

  /**
   * {@inheritdoc}
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function setInitialAccountId($account_id) {
    $this->inner->setInitialAccountId($account_id);
  }

  /**
   * {@inheritdoc}
   *
   * Returns the effective role list (i.e. the active switch override, or
   * the full database-assigned role set if no override is active) rather
   * than delegating to the original service. This is the core override
   * point of the entire module — every other behavior change (hasRole(),
   * hasPermission()) is built on top of this method.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function getRoles($exclude_locked_roles = FALSE) {
    $roles = $this->manager->getEffectiveRoles($this->inner);
    if ($exclude_locked_roles) {
      $roles = array_diff($roles, [AccountInterface::AUTHENTICATED_ROLE, AccountInterface::ANONYMOUS_ROLE]);
    }
    return array_values($roles);
  }

  /**
   * {@inheritdoc}
   *
   * Delegates to self::getRoles() so role-membership checks anywhere in
   * Drupal automatically reflect the active switch override.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function hasRole($rid) {
    return in_array($rid, $this->getRoles(), TRUE);
  }

  /**
   * {@inheritdoc}
   *
   * Re-implements Drupal core's permission resolution against the
   * effective (possibly switched) role list instead of the account's full
   * role set. Anonymous users bypass this entirely and are delegated to
   * the original service, since role switching only applies to
   * authenticated users.
   *
   * Note: Drupal core treats any role flagged as the site's designated
   * "admin role" (Role::isAdmin(), e.g. the default Administrator role)
   * as an automatic bypass of all permission checks, regardless of its
   * explicit permissions list. That core behavior is intentionally
   * mirrored here — without it, switching roles would incorrectly strip
   * administrators of access even when 'administrator' is still present
   * in the effective role list.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function hasPermission($permission) {
    if ($this->inner->isAnonymous()) {
      return $this->inner->hasPermission($permission);
    }
    $roles = $this->getRoles(TRUE);
    $roles[] = AccountInterface::AUTHENTICATED_ROLE;
    $role_storage = \Drupal::entityTypeManager()->getStorage('user_role');
    foreach ($role_storage->loadMultiple($roles) as $role) {
      if ($role->isAdmin()) {
        return TRUE;
      }
      if (in_array($permission, $role->getPermissions(), TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function isAuthenticated() {
    return $this->inner->isAuthenticated();
  }

  /**
   * {@inheritdoc}
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function isAnonymous() {
    return $this->inner->isAnonymous();
  }

  /**
   * {@inheritdoc}
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function getPreferredLangcode($fallback_to_default = TRUE) {
    return $this->inner->getPreferredLangcode($fallback_to_default);
  }

  /**
   * {@inheritdoc}
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function getPreferredAdminLangcode($fallback_to_default = TRUE) {
    return $this->inner->getPreferredAdminLangcode($fallback_to_default);
  }

  /**
   * {@inheritdoc}
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function getDisplayName() {
    return $this->inner->getDisplayName();
  }

  /**
   * {@inheritdoc}
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function getEmail() {
    return $this->inner->getEmail();
  }

  /**
   * {@inheritdoc}
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function getTimeZone() {
    return $this->inner->getTimeZone();
  }

  /**
   * {@inheritdoc}
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function getLastAccessedTime() {
    return $this->inner->getLastAccessedTime();
  }

  /**
   * {@inheritdoc}
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function getAccountName() {
    return $this->inner->getAccountName();
  }

}
