<?php

declare(strict_types=1);

namespace Drupal\test_jev_module\Plugin\views\field;

use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Shows a comment's computed JEV positivity rating as "N/10".
 */
#[ViewsField('test_jev_module_comment_positivity')]
class CommentPositivity extends FieldPluginBase {

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
    if (!$comment || !$comment->hasField('jev_positivity')) {
      return '';
    }

    $rating = $comment->get('jev_positivity')->value;
    if ($rating === NULL) {
      return '–';
    }
    return $this->t('@rating/10', ['@rating' => $rating]);
  }

}
