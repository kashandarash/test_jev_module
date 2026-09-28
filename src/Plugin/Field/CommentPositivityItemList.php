<?php

declare(strict_types=1);

namespace Drupal\test_jev_module\Plugin\Field;

use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;
use Drupal\test_jev_module\CommentPositivity;

/**
 * Computes the jev_positivity field: a 1–10 rating of how positive a comment is.
 */
class CommentPositivityItemList extends FieldItemList {

  use ComputedItemListTrait;

  /**
   * {@inheritdoc}
   */
  protected function computeValue(): void {
    /** @var \Drupal\comment\CommentInterface $comment */
    $comment = $this->getEntity();
    $rating = \Drupal::service(CommentPositivity::class)->rate($comment);
    if ($rating !== NULL) {
      $this->list[0] = $this->createItem(0, $rating);
    }
  }

}
