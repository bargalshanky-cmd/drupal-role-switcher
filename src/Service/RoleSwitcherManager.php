<?php

namespace Drupal\role_switcher_session\Service;

use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @file
 * Contains \Drupal\role_switcher_session\Service\RoleSwitcherManager.
 *
 * @author Vaibhav Bargal
 * @date 2026-06-30
 */

/**
 * Core business logic for the Role Switcher module.
 *
 * This service is the single source of truth for:
 *  - which roles a user is allowed to switch into;
 *  - persisting the chosen "active role" for the current browser session;
 *  - reverting to the user's full, database-assigned role set; and
 *  - computing the effective role list that the rest of the module
 *    (RoleSwitcherAccountProxy) exposes to the rest of Drupal.
 *
 * Important: this service never modifies the user entity or the
 * `users_roles` database table. All state is held in the PHP session,
 * scoped per browser/session, never per user account. Logging out, or
 * opening a different browser, returns the user to their full set of
 * database-assigned roles.
 *
 * @author Vaibhav Bargal
 * @date 2026-06-30
 */
class RoleSwitcherManager {

  /**
   * The session key under which the active override role id is stored.
   *
   * @var string
   */
  const SESSION_KEY = 'role_switcher_active_role';

  /**
   * The entity type manager, used to load user_role entities.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new RoleSwitcherManager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service, used to load and label user roles.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function __construct($entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Returns the session for the current request.
   *
   * The session is intentionally fetched fresh on every call (rather than
   * injected once via the constructor) to guarantee it is always the exact
   * same session instance the rest of Drupal's request lifecycle (cache
   * contexts, the kernel's session-save listener, etc.) is reading from and
   * writing to. Holding a stale injected reference was found to cause the
   * active-role override to appear "stuck" even after being cleared.
   *
   * @return \Symfony\Component\HttpFoundation\Session\SessionInterface
   *   The current request's session object.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  protected function getSession(): SessionInterface {
    return \Drupal::request()->getSession();
  }

  /**
   * Returns the roles the given account is allowed to switch into.
   *
   * The built-in 'authenticated' role is always excluded (every logged-in
   * user has it implicitly, so offering it as a choice is meaningless).
   * The 'administrator' role is always excluded from the selectable list
   * as a safety measure â€” administrators can still switch between their
   * other roles, but 'administrator' itself is never relinquished via this
   * UI, preventing an admin from accidentally locking themselves out.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to inspect.
   *
   * @return array
   *   An associative array of role id => human-readable role label,
   *   suitable for use directly as Form API '#options'. Empty if the
   *   account has zero or one switchable role.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
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

  /**
   * Validates and activates a single role override for the current session.
   *
   * Server-side ownership validation is always performed here, regardless
   * of what the calling code (e.g. a submitted form value) claims â€” this
   * is the security boundary that prevents a user from switching into a
   * role they do not actually hold, even if the request were tampered
   * with client-side.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account performing the switch.
   * @param string $rid
   *   The role id to activate. Must be one of the account's own roles, as
   *   returned by self::getSwitchableRoles().
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If $rid is not a role the account actually holds (or is one of the
   *   always-excluded roles). The attempt is also logged as a warning.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
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

  /**
   * Clears any active role override, reverting to the account's full roles.
   *
   * The session is explicitly saved (rather than left for Drupal's kernel
   * response listener to persist later) so that the change is guaranteed
   * to be visible to any code that reads the session again within the
   * same request, or to a rapid follow-up AJAX request.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function clearActiveRole(): void {
    $session = $this->getSession();
    $session->remove(self::SESSION_KEY);
    $session->save();
  }

  /**
   * Returns the currently active override role id for an account, if any.
   *
   * Ownership is re-validated on every call (not just when the override
   * was first set). If an administrator revokes the role from the user
   * while the override is still active in their session, this method
   * detects the mismatch, clears the now-invalid override automatically,
   * and returns NULL â€” preventing a stale, no-longer-held role from
   * continuing to grant access.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to check.
   *
   * @return string|null
   *   The active role id, or NULL if no override is set, or the override
   *   was invalidated because the account no longer holds that role.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function getActiveRole(AccountInterface $account): ?string {
    $rid = $this->getSession()->get(self::SESSION_KEY);
    if (!$rid) {
      return NULL;
    }
    if (!in_array($rid, $account->getRoles(), TRUE)) {
      // Role was revoked from the user since the override was set.
      $this->clearActiveRole();
      return NULL;
    }
    return $rid;
  }

  /**
   * Computes the effective role list for an account, applying any override.
   *
   * This is the method consumed by RoleSwitcherAccountProxy and is what
   * ultimately drives every permission check across the site once an
   * override is active. Behavior:
   *  - No override active: returns the account's full database role set,
   *    completely unmodified.
   *  - Override active: returns only ['authenticated', $active_role] â€”
   *    i.e. the selected role fully replaces all other roles for the
   *    duration of the override.
   *  - Safety exception: if the account genuinely holds 'administrator'
   *    in the database, it is always re-added to the effective list even
   *    while an override is active, so a user can never switch themselves
   *    out of administrative access.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account to compute effective roles for.
   *
   * @return string[]
   *   The list of role ids that should be treated as "active" for
   *   permission-checking purposes.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function getEffectiveRoles(AccountInterface $account): array {
    $active = $this->getActiveRole($account);
    if ($active === NULL) {
      return $account->getRoles();
    }
    $roles = ['authenticated', $active];
    // Never strip 'administrator' away from someone who actually holds it.
    if (in_array('administrator', $account->getRoles(), TRUE)) {
      $roles[] = 'administrator';
    }
    return array_values(array_unique($roles));
  }

}
