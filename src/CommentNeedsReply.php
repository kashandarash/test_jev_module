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
 * Decides whether a comment needs a reply, using SystemOne's noul question.
 */
class CommentNeedsReply {

  /**
   * The question asked about the comment text.
   */
  protected const INSTRUCTIONS = 'Does this comment need a reply from the author?';

  /**
   * What counts as "yes".
   */
  protected const TRUE_CRITERIA = 'Yes: the comment asks the author a question, or asks for a clarification, correction or follow-up.';

  /**
   * What counts as "no".
   */
  protected const FALSE_CRITERIA = 'No: the comment only shares an opinion, praise, criticism or thanks, and does not ask for anything.';

  /**
   * Probability above which a comment needs a reply.
   */
  public const THRESHOLD = 0.7;

  public function __construct(
    protected SystemOneClient $client,
    #[Autowire(service: 'cache.default')]
    protected CacheBackendInterface $cache,
    #[Autowire(service: 'logger.channel.test_jev_module')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * Returns whether the comment needs a reply.
   *
   * The probability is cached by comment text and question, so the API is
   * only called again when either changes.
   *
   * @return bool|null
   *   TRUE if it needs a reply, FALSE if not, or NULL if the comment has no
   *   text or the API call failed.
   */
  public function needsReply(CommentInterface $comment): ?bool {
    $text = $this->getText($comment);
    if ($text === '') {
      return NULL;
    }

    $cid = 'test_jev_module:comment_needs_reply:' . hash('sha256', implode("\n", [
      self::INSTRUCTIONS,
      self::TRUE_CRITERIA,
      self::FALSE_CRITERIA,
      $text,
    ]));
    if ($cached = $this->cache->get($cid)) {
      return $cached->data > self::THRESHOLD;
    }

    try {
      $probability = $this->client->noul($text, self::INSTRUCTIONS, self::TRUE_CRITERIA, self::FALSE_CRITERIA);
    }
    catch (SystemOneException $e) {
      // The client already logged the details. Failures are not cached, so
      // the next load tries again.
      $this->logger->warning('Could not check whether comment @id needs a reply: @error', [
        '@id' => $comment->id() ?? 'new',
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }

    // Cache the probability, not the result, so the threshold can change
    // without new API calls.
    $this->cache->set($cid, $probability);
    return $probability > self::THRESHOLD;
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
