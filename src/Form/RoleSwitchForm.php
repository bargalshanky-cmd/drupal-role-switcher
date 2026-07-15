<?php

namespace Drupal\role_switcher_session\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\role_switcher_session\Service\RoleSwitcherManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @file
 * Contains \Drupal\role_switcher_session\Form\RoleSwitchForm.
 *
 * @author Vaibhav Bargal
 * @date 2026-06-30
 */

/**
 * Provides the single-select "Acting as" role-switcher dropdown.
 *
 * Rendered inside the Role Switcher block (see
 * \Drupal\role_switcher_session\Plugin\Block\RoleSwitcherBlock) and also reachable
 * directly at the `/user/role-switch` route. Selecting a role from the
 * dropdown submits the form via AJAX (no full page reload) and activates
 * that role for the remainder of the current session. Selecting the
 * "- All my original roles -" option clears the override and restores
 * the user's full, database-assigned role set.
 *
 * @author Vaibhav Bargal
 * @date 2026-06-30
 */
class RoleSwitchForm extends FormBase {

  /**
   * The Role Switcher manager service.
   *
   * @var \Drupal\role_switcher_session\Service\RoleSwitcherManager
   */
  protected RoleSwitcherManager $manager;

  /**
   * The current user (decorated current_user service).
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * {@inheritdoc}
   *
   * Injects the role_switcher.manager and current_user services via the
   * Drupal service container, following standard Form API dependency
   * injection conventions.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->manager = $container->get('role_switcher_session.manager');
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function getFormId() {
    return 'role_switcher_form';
  }

  /**
   * {@inheritdoc}
   *
   * Builds the "Acting as" dropdown. If the current user has fewer than
   * two switchable roles there is nothing meaningful to switch between,
   * so an empty form array is returned and the block effectively renders
   * nothing (see RoleSwitcherBlock::blockAccess() for the access-level
   * equivalent of this same check).
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $account = $this->currentUser->getAccount();
    $options = $this->manager->getSwitchableRoles($account);

    // Zero switchable roles â€” nothing to switch (e.g. user holds only
    // 'administrator', which never appears as a switchable option).
    if (count($options) < 1) {
      return $form;
    }

    $active = $this->manager->getActiveRole($account);

    $form['#attributes']['class'][] = 'role-switcher-form';
    $form['#prefix'] = '<div id="role-switcher-form-wrapper">';
    $form['#suffix'] = '</div>';
    $form['#attached']['library'][] = 'role_switcher_session/role_switcher_session';

    $form['active_role'] = [
      '#type' => 'select',
      '#title' => $this->t('Acting as'),
      '#title_display' => 'before',
      '#options' => $options,
      '#empty_option' => $this->t('- All my original roles -'),
      '#empty_value' => '',
      '#default_value' => $active ?? '',
      '#ajax' => [
        'callback' => '::ajaxSwitch',
        'wrapper' => 'role-switcher-form-wrapper',
        'event' => 'change',
      ],
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Switch'),
    ];

    return $form;
  }

  /**
   * AJAX callback invoked when the "Acting as" select value changes.
   *
   * Performs the actual role switch (or clears it) immediately on
   * selection change, without requiring the user to click the "Switch"
   * button â€” the button remains as a progressive-enhancement fallback for
   * environments where JavaScript/AJAX is unavailable.
   *
   * @param array $form
   *   The form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state, containing the submitted role selection.
   *
   * @return array
   *   The form render array, with status/error messages appended, to be
   *   used as the AJAX response content.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function ajaxSwitch(array &$form, FormStateInterface $form_state) {
    $this->doSwitch($form_state);
    $form['status_messages'] = ['#type' => 'status_messages'];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Non-AJAX fallback submit handler; delegates to self::doSwitch().
   *
   * After switching, the user is redirected to the front page rather
   * than back to the page they submitted from. This matters because the
   * block is rendered on every page, including pages that custom access
   * checks (e.g. a project's own AccessCheck service) restrict to a
   * specific role. If the user switches away from the role that was
   * granting them access to that page, redirecting back to it would
   * immediately hit a 403 (access denied) â€” which is technically correct
   * post-switch, but a confusing place to land right after a successful
   * switch. The front page is always safe to land on regardless of which
   * role is currently active.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->doSwitch($form_state);
    $form_state->setRedirect('<current>');
  }

  /**
   * Applies the submitted role selection.
   *
   * If the submitted value is empty (the "- All my original roles -"
   * option), any active override is cleared via
   * RoleSwitcherManager::clearActiveRole(), restoring the user's full
   * database-assigned role set. Otherwise, the selected role id is
   * validated and activated via RoleSwitcherManager::setActiveRole().
   * A user-facing status or error message is set in both cases.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state, containing the submitted 'active_role'
   *   value.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  protected function doSwitch(FormStateInterface $form_state): void {
    $rid = $form_state->getValue('active_role');

    if (empty($rid)) {
      $this->manager->clearActiveRole();
      $this->messenger()->addStatus($this->t('Switched back to your original roles.'));
      return;
    }

    try {
      $this->manager->setActiveRole($this->currentUser->getAccount(), $rid);
      $this->messenger()->addStatus($this->t('Switched active role successfully.'));
    }
    catch (AccessDeniedHttpException $e) {
      $this->messenger()->addError($this->t('You are not permitted to switch to that role.'));
    }
  }

}
