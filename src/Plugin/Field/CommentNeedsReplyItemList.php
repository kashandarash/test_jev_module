<?php

declare(strict_types=1);

namespace Drupal\test_jev_module\Plugin\Field;

use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;
use Drupal\test_jev_module\CommentNeedsReply;

/**
 * Computes the jev_needs_reply field: whether a comment needs a reply.
 */
class CommentNeedsReplyItemList extends FieldItemList {

  use ComputedItemListTrait;

  /**
   * {@inheritdoc}
   */
  protected function computeValue(): void {
    /** @var \Drupal\comment\CommentInterface $comment */
    $comment = $this->getEntity();
    $needs_reply = \Drupal::service(CommentNeedsReply::class)->needsReply($comment);
    if ($needs_reply !== NULL) {
      $this->list[0] = $this->createItem(0, $needs_reply);
    }
  }

}
