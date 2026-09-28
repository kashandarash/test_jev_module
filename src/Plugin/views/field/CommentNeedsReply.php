<?php

declare(strict_types=1);

namespace Drupal\test_jev_module\Plugin\views\field;

use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Shows whether a comment needs a reply as "Yes" or "No".
 */
#[ViewsField('test_jev_module_comment_needs_reply')]
class CommentNeedsReply extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    // The value is computed from the loaded entity, not read from the
    // database.
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $comment = $this->getEntity($values);
    if (!$comment || !$comment->hasField('jev_needs_reply')) {
      return '';
    }

    $needs_reply = $comment->get('jev_needs_reply')->value;
    if ($needs_reply === NULL) {
      return '–';
    }
    return $needs_reply ? $this->t('Yes') : $this->t('No');
  }

}
