<?php

declare(strict_types=1);

namespace Drupal\test_jev_module\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;

/**
 * Stores the JEV API key in state.
 *
 * State is used instead of config so the key is never exported with
 * configuration, but persists for the current environment.
 */
final class JevApiSettingsForm extends FormBase {

  /**
   * The state key that holds the JEV API key.
   */
  public const STATE_KEY = 'test_jev_module.api_key';

  public function __construct(
    protected StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'test_jev_module_api_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $api_key = (string) $this->state->get(self::STATE_KEY, '');

    $form['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('JEV API key'),
      '#description' => $api_key !== ''
        ? $this->t('A key is saved (ending in %suffix). Leave empty to keep it.', ['%suffix' => substr($api_key, -4)])
        : $this->t('No key saved yet.'),
      '#attributes' => ['autocomplete' => 'off'],
    ];

    if ($api_key !== '') {
      $form['delete'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Delete the saved key'),
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getValue('delete')) {
      $this->state->delete(self::STATE_KEY);
      $this->messenger()->addStatus($this->t('The JEV API key has been deleted.'));
      return;
    }

    $api_key = trim((string) $form_state->getValue('api_key'));
    if ($api_key === '') {
      $this->messenger()->addStatus($this->t('No changes made.'));
      return;
    }

    $this->state->set(self::STATE_KEY, $api_key);
    $this->messenger()->addStatus($this->t('The JEV API key has been saved.'));
  }

}
