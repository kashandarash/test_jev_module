<?php

declare(strict_types=1);

namespace Drupal\test_jev_module\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsButtonsWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\test_jev_module\SystemOne\SystemOneClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Check boxes/radio buttons with a "Suggest tag" button powered by SystemOne.
 *
 * The button sends the text of a source field and the available terms to
 * SystemOne's choice question, then selects the suggested term.
 */
#[FieldWidget(
  id: 'test_jev_module_suggest_tag_buttons',
  label: new TranslatableMarkup('Check boxes/radio buttons with tag suggestion'),
  field_types: ['entity_reference'],
  multiple_values: TRUE,
)]
class SuggestTagButtonsWidget extends OptionsButtonsWidget {

  /**
   * Field types that can be used as the text source.
   */
  protected const SOURCE_FIELD_TYPES = [
    'string',
    'string_long',
    'text',
    'text_long',
    'text_with_summary',
  ];

  /**
   * The entity field manager.
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityFieldManager = $container->get('entity_field.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return [
      'source_field' => 'field_body',
      'instructions' => 'Pick the tag that best describes this content.',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition): bool {
    return $field_definition->getFieldStorageDefinition()->getSetting('target_type') === 'taxonomy_term';
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $element = parent::settingsForm($form, $form_state);

    $options = [];
    $definitions = $this->entityFieldManager->getFieldDefinitions(
      $this->fieldDefinition->getTargetEntityTypeId(),
      $this->fieldDefinition->getTargetBundle(),
    );
    foreach ($definitions as $name => $definition) {
      if (in_array($definition->getType(), self::SOURCE_FIELD_TYPES, TRUE)) {
        $options[$name] = $definition->getLabel() . " ($name)";
      }
    }

    $element['source_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Text source field'),
      '#description' => $this->t('The field whose text is sent to SystemOne.'),
      '#options' => $options,
      '#default_value' => $this->getSetting('source_field'),
      '#required' => TRUE,
    ];
    $element['instructions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Instructions'),
      '#description' => $this->t('What SystemOne is asked when choosing a tag.'),
      '#default_value' => $this->getSetting('instructions'),
      '#rows' => 2,
      '#required' => TRUE,
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();
    $summary[] = $this->t('Suggest tag from: @field', ['@field' => $this->getSetting('source_field')]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function form(FieldItemListInterface $items, array &$form, FormStateInterface $form_state, $get_delta = NULL): array {
    $elements = parent::form($items, $form, $form_state, $get_delta);

    $field_name = $this->fieldDefinition->getName();
    $parents = $form['#parents'];
    $wrapper_id = Html::getId(implode('-', array_merge(['jev-suggest'], $parents, [$field_name])));

    $elements['#attributes']['id'] = $wrapper_id;
    $elements['jev_suggest'] = [
      '#type' => 'button',
      '#value' => $this->t('Suggest tag'),
      '#name' => $wrapper_id . '-button',
      // Keep the button value out of the field values.
      '#parents' => array_merge(['jev_suggest'], $parents, [$field_name]),
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => [static::class, 'suggestTagAjax'],
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Asking JEV...'),
        ],
      ],
      '#jev_wrapper_id' => $wrapper_id,
      '#jev_source_parents' => array_merge($parents, [$this->getSetting('source_field')]),
      '#jev_instructions' => $this->getSetting('instructions'),
      '#jev_options' => array_diff_key($this->getOptions($items->getEntity()), ['_none' => TRUE]),
      '#weight' => 100,
    ];
    $elements['jev_suggest_message'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jev-suggest-message']],
      '#weight' => 101,
    ];

    return $elements;
  }

  /**
   * AJAX callback: asks SystemOne for a tag and selects it.
   */
  public static function suggestTagAjax(array &$form, FormStateInterface $form_state): AjaxResponse {
    $button = $form_state->getTriggeringElement();
    $wrapper = '#' . $button['#jev_wrapper_id'];
    $response = new AjaxResponse();

    // Read the raw input so unsaved text in the source field is used.
    $items = NestedArray::getValue($form_state->getUserInput(), $button['#jev_source_parents']);
    $text = '';
    foreach (is_array($items) ? $items : [] as $item) {
      $value = is_array($item) ? ($item['value'] ?? '') : '';
      $text .= ' ' . html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5);
    }
    $text = trim($text);

    // Criteria sent to SystemOne: lowercase title => title. The chosen key is
    // mapped back to the term ID.
    $criteria = [];
    $key_to_id = [];
    foreach ($button['#jev_options'] as $id => $label) {
      $title = html_entity_decode(strip_tags((string) $label), ENT_QUOTES | ENT_HTML5);
      $key = mb_strtolower($title);
      $criteria[$key] = $title;
      $key_to_id[$key] = $id;
    }

    if ($text === '') {
      $message = t('Add some text to the source field first.');
    }
    elseif ($criteria === []) {
      $message = t('There are no tags to choose from.');
    }
    else {
      try {
        /** @var \Drupal\test_jev_module\SystemOne\SystemOneClient $client */
        $client = \Drupal::service(SystemOneClient::class);
        $answer = $client->choice($text, $button['#jev_instructions'], $criteria);
        $choice = (string) ($answer['choice'] ?? '');

        if (isset($key_to_id[$choice])) {
          $selector = $wrapper . ' input[value="' . $key_to_id[$choice] . '"]';
          $response->addCommand(new InvokeCommand($selector, 'prop', ['checked', TRUE]));
          $response->addCommand(new InvokeCommand($selector, 'trigger', ['change']));
          $message = t('Suggested tag: %tag (confidence @confidence%).', [
            '%tag' => $criteria[$choice],
            '@confidence' => round((float) ($answer['confidence'] ?? 0) * 100),
          ]);
        }
        else {
          $message = t('JEV suggested %tag, which is not one of the available tags.', ['%tag' => $choice]);
        }
      }
      catch (\Exception $e) {
        $message = t('Could not get a suggestion: @error', ['@error' => $e->getMessage()]);
      }
    }

    $response->addCommand(new HtmlCommand($wrapper . ' .jev-suggest-message', ['#markup' => $message]));
    return $response;
  }

}
