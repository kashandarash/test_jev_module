<?php

declare(strict_types=1);

namespace Drupal\test_jev_module;

use Drupal\comment\CommentInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\test_jev_module\SystemOne\SystemOneClient;
use Drupal\test_jev_module\SystemOne\SystemOneException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Rates how positive a comment is, using SystemOne's noul question.
 */
class CommentPositivity {

  /**
   * The question asked about the comment text.
   */
  protected const INSTRUCTIONS = 'Is this comment positive?';

  /**
   * What counts as "yes".
   *
   * The "no clear opinion" sentence keeps neutral comments near the middle
   * (about 5).
   * Without it, SystemOne treats neutral as "not positive" and rates it 3.
   */
  protected const TRUE_CRITERIA = 'Positive, or neutral leaning positive. A comment with no clear opinion is equally positive and negative.';

  /**
   * What counts as "no".
   */
  protected const FALSE_CRITERIA = 'Negative, or neutral leaning negative. A comment with no clear opinion is equally positive and negative.';

  public function __construct(
    protected SystemOneClient $client,
    #[Autowire(service: 'cache.default')]
    protected CacheBackendInterface $cache,
    #[Autowire(service: 'logger.channel.test_jev_module')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * Returns the comment's positivity rating.
   *
   * Results are cached by comment text and question, so the API is only
   * called again when either changes.
   *
   * @return int|null
   *   A rating from 1 (negative) to 10 (positive), or NULL if the comment has
   *   no text or the API call failed.
   */
  public function rate(CommentInterface $comment): ?int {
    $text = $this->getText($comment);
    if ($text === '') {
      return NULL;
    }

    $cid = 'test_jev_module:comment_positivity:' . hash('sha256', implode("\n", [
      self::INSTRUCTIONS,
      self::TRUE_CRITERIA,
      self::FALSE_CRITERIA,
      $text,
    ]));
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }

    try {
      $probability = $this->client->noul($text, self::INSTRUCTIONS, self::TRUE_CRITERIA, self::FALSE_CRITERIA);
    }
    catch (SystemOneException $e) {
      // The client already logged the details. Failures are not cached, so
      // the next load tries again.
      $this->logger->warning('Could not rate comment @id: @error', [
        '@id' => $comment->id() ?? 'new',
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }

    $rating = self::toRating($probability);
    $this->cache->set($cid, $rating);
    return $rating;
  }

  /**
   * Converts a 0–1 probability to a 1–10 rating.
   */
  public static function toRating(float $probability): int {
    $probability = max(0.0, min(1.0, $probability));
    return 1 + (int) round($probability * 9);
  }

  /**
   * Returns the comment's plain text: its body, or the subject if empty.
   */
  protected function getText(CommentInterface $comment): string {
    $text = '';
    if ($comment->hasField('comment_body')) {
      foreach ($comment->get('comment_body') as $item) {
        $text .= ' ' . (string) $item->value;
      }
    }
    if (trim(strip_tags($text)) === '') {
      $text = (string) $comment->getSubject();
    }
    return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5));
  }

}
