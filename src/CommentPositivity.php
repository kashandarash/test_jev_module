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
 * Rates how positive a comment is, using SystemOne's score question.
 */
class CommentPositivity {

  /**
   * The question asked about the comment text.
   */
  protected const INSTRUCTIONS = 'How positive is this comment?';

  /**
   * The score levels, from lowest to highest.
   *
   * SystemOne returns a probability-weighted score from 0 (first level) to
   * 6 (last level), which is converted to a 1–10 rating.
   */
  protected const LEVELS = [
    'Very negative',
    'Negative',
    'Somewhat negative',
    'Neutral',
    'Somewhat positive',
    'Positive',
    'Very positive',
  ];

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
      ...self::LEVELS,
      $text,
    ]));
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }

    try {
      $answer = $this->client->score($text, self::INSTRUCTIONS, self::LEVELS);
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

    if (!isset($answer['score']) || !is_numeric($answer['score'])) {
      $this->logger->warning('Could not rate comment @id: the response has no score.', [
        '@id' => $comment->id() ?? 'new',
      ]);
      return NULL;
    }

    $rating = self::toRating((float) $answer['score'], count(self::LEVELS) - 1);
    $this->cache->set($cid, $rating);
    return $rating;
  }

  /**
   * Converts a score from 0 to $max into a 1–10 rating.
   */
  public static function toRating(float $score, int $max): int {
    $fraction = max(0.0, min(1.0, $score / $max));
    return 1 + (int) round($fraction * 9);
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
