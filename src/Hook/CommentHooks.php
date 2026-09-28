<?php

declare(strict_types=1);

namespace Drupal\test_jev_module\Hook;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\test_jev_module\Plugin\Field\CommentNeedsReplyItemList;
use Drupal\test_jev_module\Plugin\Field\CommentPositivityItemList;

/**
 * Adds the computed JEV fields to comments.
 */
class CommentHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_entity_base_field_info().
   */
  #[Hook('entity_base_field_info')]
  public function entityBaseFieldInfo(EntityTypeInterface $entity_type): array {
    if ($entity_type->id() !== 'comment') {
      return [];
    }

    return [
      'jev_positivity' => BaseFieldDefinition::create('integer')
        ->setLabel($this->t('JEV positivity'))
        ->setDescription($this->t('How positive the comment is, from 1 to 10, rated by JEV AI.'))
        ->setComputed(TRUE)
        ->setReadOnly(TRUE)
        ->setClass(CommentPositivityItemList::class)
        ->setSetting('min', 1)
        ->setSetting('max', 10)
        ->setSetting('suffix', '/10')
        ->setDisplayConfigurable('view', TRUE),
      'jev_needs_reply' => BaseFieldDefinition::create('boolean')
        ->setLabel($this->t('JEV needs reply'))
        ->setDescription($this->t('Whether the comment asks the author something and needs a reply, decided by JEV AI.'))
        ->setComputed(TRUE)
        ->setReadOnly(TRUE)
        ->setClass(CommentNeedsReplyItemList::class)
        ->setSetting('on_label', $this->t('Yes'))
        ->setSetting('off_label', $this->t('No'))
        ->setDisplayConfigurable('view', TRUE),
    ];
  }

  /**
   * Implements hook_views_data_alter().
   */
  #[Hook('views_data_alter')]
  public function viewsDataAlter(array &$data): void {
    $data['comment_field_data']['jev_positivity'] = [
      'title' => $this->t('JEV positivity'),
      'help' => $this->t('How positive the comment is, shown as a 1–10 rating. Calls the JEV API for comments that are not rated yet.'),
      'field' => [
        'id' => 'test_jev_module_comment_positivity',
      ],
    ];
    $data['comment_field_data']['jev_needs_reply'] = [
      'title' => $this->t('JEV needs reply'),
      'help' => $this->t('Whether the comment needs a reply, shown as Yes/No. Calls the JEV API for comments that are not checked yet.'),
      'field' => [
        'id' => 'test_jev_module_comment_needs_reply',
      ],
    ];
  }

}
