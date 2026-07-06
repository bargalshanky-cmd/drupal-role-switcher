<?php

namespace Drupal\role_switcher_session\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @file
 * Contains \Drupal\role_switcher_session\Plugin\Block\RoleSwitcherBlock.
 *
 * @author Vaibhav Bargal
 * @date 2026-06-30
 */

/**
 * Provides the "Role Switcher" block.
 *
 * Renders the role-switching dropdown (RoleSwitchForm) and is
 * automatically placed in the active theme's header/navigation region by
 * role_switcher_install() when the module is first enabled â€” no manual
 * Block Layout configuration is required. The block is automatically
 * hidden for any user who has fewer than two switchable roles, since
 * there would be nothing meaningful to switch between.
 *
 * @Block(
 *   id = "role_switcher_block",
 *   admin_label = @Translation("Role Switcher"),
 *   category = @Translation("User")
 * )
 *
 * @author Vaibhav Bargal
 * @date 2026-06-30
 */
class RoleSwitcherBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * The Role Switcher manager service.
   *
   * @var \Drupal\role_switcher_session\Service\RoleSwitcherManager
   */
  protected $roleSwitcherManager;

  /**
   * {@inheritdoc}
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->formBuilder = $container->get('form_builder');
    $instance->roleSwitcherManager = $container->get('role_switcher.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * Renders the RoleSwitchForm as the block's content.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function build() {
    return $this->formBuilder->getForm('Drupal\role_switcher_session\Form\RoleSwitchForm');
  }

  /**
   * {@inheritdoc}
   *
   * Visible to any authenticated user who has more than one switchable
   * role. No dedicated permission is required â€” access is determined
   * entirely by whether switching would be meaningful for that user. The
   * 'user.roles' and 'session' cache contexts ensure the block is
   * re-evaluated (not served from a stale cache) whenever the user's
   * roles or active session-level override change.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  protected function blockAccess(AccountInterface $account) {
    if (!$account->isAuthenticated()) {
      return AccessResult::forbidden();
    }
    $options = $this->roleSwitcherManager->getSwitchableRoles($account);
    // At least one switchable role is enough for the dropdown to be
    // useful â€” e.g. an administrator who also holds 'csr_admin' can still
    // meaningfully switch into that single role (with 'administrator'
    // safety-netted back in by getEffectiveRoles()), even though
    // 'administrator' itself never appears as a switchable option.
    return AccessResult::allowedIf(count($options) >= 1)
      ->addCacheContexts(['user.roles', 'session']);
  }

  /**
   * {@inheritdoc}
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function getCacheContexts() {
    return array_merge(parent::getCacheContexts(), ['user.roles', 'session']);
  }

  /**
   * {@inheritdoc}
   *
   * The block must never be cached (max age 0): it always needs to
   * reflect the user's live, current-session role-switch state.
   *
   * @author Vaibhav Bargal
   * @date 2026-06-30
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
