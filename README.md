# Role Switcher

**Author:** Vaibhav Bargal
**Date:** 2026-06-30
**Compatibility:** Drupal 10.x and Drupal 11.x (`core_version_requirement: ^10 || ^11`)

A lightweight Drupal module that lets any logged-in user who holds more
than one custom role pick which single role is "active" for their current
browser session — without ever modifying the user's actual role
assignment in the database.

---

## 1. The Problem It Solves

Some users in this site legitimately hold multiple roles at once, for
example:

- `csr_admin`
- `volunteer`
- `trustee`

By default, Drupal applies the **union** of all permissions granted by
every role a user holds, all the time. There is no built-in way for the
user to say "for the next few minutes, only treat me as a Volunteer" —
useful for testing, support, or simply reducing the chance of taking an
action under the wrong hat.

Role Switcher adds a simple dropdown ("Acting as") that lets the user
pick **one** role to be fully active. Picking a different role completely
replaces the active permission set; selecting "All my original roles"
reverts to the user's full, unmodified role set, exactly as it was at
login.

---

## 2. Design Principles

| Principle | How it's implemented |
|---|---|
| **Never touch the database** | Role assignment in `users_roles` is never changed. The active selection is stored only in the PHP session. |
| **Per-session, not per-account** | Switching in one browser/session has no effect on the same user logged in elsewhere (a different browser, device, or incognito window). Each session is independent. |
| **No admin setup required** | No permission needs to be granted, no block needs to be manually placed. Installing the module is the only step. |
| **Self-correcting** | If a role is revoked from the user by an admin while an override referencing it is still active, the override is automatically detected as invalid and cleared. |
| **Admin lockout-proof** | A user who genuinely holds the `administrator` role can never switch themselves out of it, even while a different role is active. |
| **Zero extra permission surface** | The `administrator` role itself is never offered as a switch target (it doesn't need to be — it's always implicitly retained, see above), reducing the risk surface of the dropdown. |

---

## 3. Installation

1. Copy the entire `role_switcher` folder into your Drupal site's custom
   modules directory:
   ```
   <drupal_root>/modules/custom/role_switcher
   ```
2. Enable the module:
   ```
   drush en role_switcher -y
   ```
   or via the UI: **Admin → Extend**, search for "Role Switcher", enable.
3. That's it. On enable, the module automatically places its block in
   the active theme's header/navigation region — no manual Block Layout
   configuration is required.

### Uninstalling

```
drush pmu role_switcher -y
```

This removes the auto-placed block. Any session-level overrides simply
stop having any effect the moment the module's service decorator is
removed from the container; no further cleanup is necessary.

---

## 4. How To Use It (End User)

1. Log in as a user who holds two or more custom roles (e.g. `csr_admin`
   and `volunteer`).
2. An "Acting as" dropdown appears automatically near the top of every
   page. (If you only hold one role, or zero switchable roles, the
   dropdown does not appear — there is nothing to switch between.)
3. Select a role from the dropdown and click **Switch**. The page reloads
   and a confirmation message appears.
4. From that point on, only the selected role's permissions are active —
   your other roles are completely overridden, not merged.
5. To go back to normal, select **"- All my original roles -"** from the
   dropdown. All of your originally assigned roles become active again,
   exactly as they were before you switched.
6. Logging out and back in always resets you to your full role set
   automatically, regardless of what was selected in a previous session.

---

## 5. File-by-File Reference

```
role_switcher/
├── role_switcher.info.yml          Module metadata
├── role_switcher.routing.yml       The /user/role-switch route
├── role_switcher.services.yml      Service container wiring (see §6)
├── role_switcher.install           hook_install() / hook_uninstall()
├── role_switcher.module            hook_user_login()
└── src/
    ├── Service/
    │   ├── RoleSwitcherManager.php       Core business logic
    │   └── RoleSwitcherAccountProxy.php  current_user service decorator
    ├── Form/
    │   └── RoleSwitchForm.php            The "Acting as" dropdown form
    └── Plugin/Block/
        └── RoleSwitcherBlock.php         Renders the form as a block
```

### `role_switcher.info.yml`
Standard Drupal module metadata. `core_version_requirement: ^10 || ^11`
makes the module installable on both Drupal 10 and Drupal 11 without
modification.

### `role_switcher.routing.yml`
Defines `/user/role-switch`, a standalone page rendering the same form
that appears in the block — useful as a fallback for non-JavaScript
browsers or screen-reader users, and as a direct deep link. Access only
requires the user to be logged in (`_user_is_logged_in: 'TRUE'`); there
is no dedicated permission, since merely *viewing* the form is harmless —
the real security boundary is enforced server-side inside
`RoleSwitcherManager::setActiveRole()`.

### `role_switcher.services.yml`
The most architecturally important file. Defines two services:

1. **`role_switcher.manager`** — an ordinary service wrapping
   `RoleSwitcherManager`, with Drupal's `entity_type.manager` injected so
   it can load and label `user_role` entities.

2. **`role_switcher.current_user`** — decorates (`decorates:
   current_user`) Drupal core's built-in `current_user` service. Once
   decorated, **every** part of Drupal — routing access checks, Views
   access plugins, Twig's `current_user` global, custom access checkers,
   `\Drupal::currentUser()` calls anywhere in custom code — is
   transparently routed through `RoleSwitcherAccountProxy` instead of the
   original service, with zero changes required in any of that other
   code.

### `role_switcher.install`
- `role_switcher_install()` — runs once, when the module is enabled.
  Finds the site's active theme, picks a sensible header-style region,
  and programmatically creates a block instance for `role_switcher_block`
  in it. This is what makes the dropdown appear automatically with no
  manual Block Layout step.
- `role_switcher_uninstall()` — removes that same block instance when
  the module is uninstalled, leaving no orphaned configuration behind.

### `role_switcher.module`
- `role_switcher_user_login()` — implements `hook_user_login()`. Clears
  any session-level role override the instant a user logs in, so a
  switch made in a previous session can never silently carry forward
  into a brand new login.

### `src/Service/RoleSwitcherManager.php`
The single source of truth for all role-switching business logic.

| Method | Responsibility |
|---|---|
| `getSession()` | Always fetches the session fresh from the current request, avoiding stale-reference bugs. |
| `getSwitchableRoles($account)` | Returns the account's roles minus `authenticated` and `administrator`, sorted by role weight (configurable via Admin → People → Roles), as `role_id => label` suitable for a Form API `#options` array. |
| `setActiveRole($account, $rid)` | Re-validates server-side that `$rid` is actually one of the account's own roles (never trusts client input blindly), then writes it to the session. Throws `AccessDeniedHttpException` and logs a warning on any mismatch. |
| `clearActiveRole()` | Removes the override from the session and forces an immediate save, so the change is visible right away rather than only after the response finishes. |
| `getActiveRole($account)` | Reads the override from the session, but re-validates on every single call that the account still actually holds that role — self-healing if an admin revokes it mid-session. |
| `getEffectiveRoles($account)` | The method everything else is built on. No override → returns the account's full role list, untouched. Override active → returns only `['authenticated', $active_role]`, with `administrator` force-included if the account genuinely holds it (lockout prevention). |

### `src/Service/RoleSwitcherAccountProxy.php`
Implements `AccountProxyInterface` and wraps the original `current_user`
service (`$this->inner`). Only role/permission-related methods are
overridden:

- `getRoles()` — returns `RoleSwitcherManager::getEffectiveRoles()`
  instead of the account's raw roles.
- `hasRole($rid)` — checks membership against the overridden `getRoles()`.
- `hasPermission($permission)` — re-implemented against the effective
  role list. Mirrors Drupal core's "admin role" bypass
  (`Role::isAdmin()`) so a role flagged as the site's designated admin
  role (typically `administrator`) still grants full access even though
  it may carry no explicit permissions list of its own.

Every other method (`id()`, `getDisplayName()`, `getEmail()`,
`isAuthenticated()`, `getTimeZone()`, etc.) delegates straight through to
the original service — switching roles never changes *who* the user is,
only *what they're currently allowed to do*.

### `src/Form/RoleSwitchForm.php`
A standard Drupal `FormBase` providing the "Acting as" `<select>`
dropdown.

- `buildForm()` — builds the dropdown from
  `getSwitchableRoles()`; returns an empty form if the user has fewer
  than two switchable roles. Includes a "- All my original roles -"
  empty option for reverting.
- `submitForm()` — applies the role switch and reloads the current page.
- `doSwitch()` — shared logic for both paths: empty selection clears the
  override; a real role id is passed to `setActiveRole()`, with a
  try/catch to surface a friendly error if validation fails.

### `src/Plugin/Block/RoleSwitcherBlock.php`
A standard block plugin that simply renders `RoleSwitchForm`.
`blockAccess()` hides the block entirely for users with fewer than two
switchable roles. `getCacheMaxAge() = 0` and the `user.roles` / `session`
cache contexts ensure the block is always re-evaluated fresh and never
served stale from cache after a switch.

---

## 6. Request Flow (End-to-End Example)

```
User has roles: csr_admin, volunteer  (in the database, users_roles table)

1. LOGIN
   role_switcher_user_login() → manager->clearActiveRole()
   → no override → getEffectiveRoles() returns [csr_admin, volunteer] (full)

2. USER SELECTS "csr_admin" IN THE DROPDOWN
   RoleSwitchForm::doSwitch()
     → RoleSwitcherManager::setActiveRole($account, 'csr_admin')
       → validates 'csr_admin' is genuinely one of the account's roles
       → session['role_switcher_active_role'] = 'csr_admin'

3. USER VISITS ANY PAGE (e.g. an admin route)
   Drupal's routing layer calls \Drupal::currentUser()->hasPermission(...)
     → resolves to RoleSwitcherAccountProxy::hasPermission()
       → getRoles(TRUE) → manager->getEffectiveRoles()
         → session has 'csr_admin' → returns ['csr_admin']
   → only csr_admin's permissions apply; volunteer's are not active

4. USER SELECTS "- All my original roles -"
   doSwitch() → empty value → manager->clearActiveRole()
     → session key removed + session->save() (persists immediately)
   → next request: getActiveRole() = NULL
     → getEffectiveRoles() returns [csr_admin, volunteer] again (full)
```

---

## 7. Integrating With Custom Access Checkers

Because the module decorates the `current_user` service itself, **no
changes are required** in custom code that already type-hints
`AccountInterface $account` and calls `$account->hasRole(...)` or
`$account->hasPermission(...)` — Drupal's argument resolver
automatically injects the decorated proxy for any `AccountInterface`
parameter sourced from `current_user`. This includes:

- Custom `_custom_access` route callbacks
- Views access plugins
- `hook_node_access()` and similar hooks
- Twig's `current_user` global

If a part of your codebase instead loads a `User` entity directly (e.g.
`User::load($uid)->getRoles()`), that call bypasses the proxy entirely by
design — it reflects the true database state, which is exactly what you
want when, for example, checking *ownership* (`uid`) rather than the
*currently active permission set*.

---

## 8. Security Notes

- All switch requests are re-validated server-side against the account's
  actual database roles on every single call — a tampered client-side
  request to switch into an unowned role is rejected with
  `AccessDeniedHttpException` and logged via `\Drupal::logger('role_switcher')`.
- The `administrator` role can never be relinquished by a user who
  genuinely holds it, preventing accidental self-lockout.
- The override is strictly session-scoped; it cannot be made to apply to
  another user, another browser, or another device.
- Logging out (or a fresh login) always resets to the full, correct role
  set, regardless of what was active before.

---

## 9. Known Limitations

- The switch is single-role only by design (one role active at a time,
  plus the implicit `authenticated` / always-retained `administrator`).
  Multi-role combination switching is intentionally not supported, to
  keep the active permission set unambiguous.
- The override does not propagate across different browsers/devices for
  the same user — this is intentional (see §2, "Per-session, not
  per-account").
