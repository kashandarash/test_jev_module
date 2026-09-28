<?php

declare(strict_types=1);

namespace Drupal\test_jev_module\SystemOne;

use Drupal\Core\State\StateInterface;
use Drupal\test_jev_module\Form\JevApiSettingsForm;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Client for the TypeSafe SystemOne endpoint.
 *
 * @see https://docs.typesafe.ai/api
 */
class SystemOneClient {

  /**
   * The SystemOne endpoint.
   */
  public const ENDPOINT = 'https://api.typesafe.ai/v1/systemone';

  /**
   * The model used when none is given.
   */
  public const DEFAULT_MODEL = 'jev-latest';

  /**
   * How many times a rate-limited or overloaded request is attempted.
   */
  protected const MAX_ATTEMPTS = 3;

  /**
   * HTTP status codes that are retried with exponential backoff.
   */
  protected const RETRY_STATUSES = [429, 529];

  public function __construct(
    protected ClientInterface $httpClient,
    protected StateInterface $state,
    #[Autowire(service: 'logger.channel.test_jev_module')]
    protected LoggerInterface $logger,
  ) {}

  /**
   * Asks a yes/no question.
   *
   * @param string|array $state
   *   The content to evaluate: text or structured data.
   * @param string|array $instructions
   *   The question to answer about the state.
   * @param string|array|null $true
   *   Optional description of what counts as "yes".
   * @param string|array|null $false
   *   Optional description of what counts as "no".
   * @param string $model
   *   The model identifier.
   *
   * @return float
   *   Probability from 0 to 1 that the answer is "yes".
   */
  public function noul(string|array $state, string|array $instructions, string|array|null $true = NULL, string|array|null $false = NULL, string $model = self::DEFAULT_MODEL): float {
    $question = [
      'type' => 'noul',
      'instructions' => $instructions,
    ];
    if ($true !== NULL || $false !== NULL) {
      $question['criteria'] = array_filter(['true' => $true, 'false' => $false], static fn ($value) => $value !== NULL);
    }

    $answer = $this->askOne($state, $question, $model);
    if (!isset($answer['noul']) || !is_numeric($answer['noul'])) {
      throw new SystemOneException('SystemOne response has no "noul" value.');
    }
    return (float) $answer['noul'];
  }

  /**
   * Picks one option from a list.
   *
   * @param string|array $state
   *   The content to evaluate: text or structured data.
   * @param string|array $instructions
   *   What to choose.
   * @param array $options
   *   Either a list of option titles, sent as a map of lowercase title to
   *   title (e.g. ['sport' => 'Sport']), or a map of option key to a
   *   description of when to pick it. 1–255 items.
   * @param string $model
   *   The model identifier.
   *
   * @return array
   *   The answer, with keys:
   *   - choice: the chosen option key (the lowercase title for a list).
   *   - probabilities: probability per option key.
   *   - confidence: 0–1.
   */
  public function choice(string|array $state, string|array $instructions, array $options, string $model = self::DEFAULT_MODEL): array {
    if (array_is_list($options)) {
      $options = array_combine(array_map('mb_strtolower', $options), $options);
    }
    if ($options === [] || count($options) > 255) {
      throw new \InvalidArgumentException('A choice question needs between 1 and 255 options.');
    }

    return $this->askOne($state, [
      'type' => 'choice',
      'instructions' => $instructions,
      'criteria' => $options,
    ], $model);
  }

  /**
   * Rates the state on an ordered scale.
   *
   * @param string|array $state
   *   The content to evaluate: text or structured data.
   * @param string|array $instructions
   *   What to rate.
   * @param string[] $levels
   *   2–10 level descriptions, ordered from lowest to highest.
   * @param string $model
   *   The model identifier.
   *
   * @return array
   *   The answer, with keys:
   *   - score: the probability-weighted value.
   *   - legend: map of score value to level.
   *   - probabilities: probability per level.
   *   - confidence: 0–1.
   */
  public function score(string|array $state, string|array $instructions, array $levels, string $model = self::DEFAULT_MODEL): array {
    $levels = array_values($levels);
    if (count($levels) < 2 || count($levels) > 10) {
      throw new \InvalidArgumentException('A score question needs between 2 and 10 levels.');
    }

    return $this->askOne($state, [
      'type' => 'score',
      'instructions' => $instructions,
      'criteria' => $levels,
    ], $model);
  }

  /**
   * Sends several named questions about the same state in one request.
   *
   * @param string|array $state
   *   The content to evaluate: text or structured data.
   * @param array $questions
   *   Question definitions keyed by question ID.
   * @param string $model
   *   The model identifier.
   *
   * @return array
   *   The decoded response: model, answers (keyed by question ID) and usage.
   */
  public function ask(string|array $state, array $questions, string $model = self::DEFAULT_MODEL): array {
    $api_key = (string) $this->state->get(JevApiSettingsForm::STATE_KEY, '');
    if ($api_key === '') {
      $this->logger->error('SystemOne request not sent: the JEV API key is not set.');
      throw new SystemOneException('The JEV API key is not set. Add it at /admin/config/jev/settings.');
    }

    $payload = [
      'state' => $state,
      'model' => $model,
      'questions' => $questions,
    ];
    $options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
        'Accept' => 'application/json',
      ],
      'json' => $payload,
      'timeout' => 60,
    ];

    // The API key is only in the headers, so the payload is safe to log.
    $this->logger->debug('SystemOne request with model @model. Questions: @questions.<pre>@payload</pre>', [
      '@questions' => implode(', ', array_map(
        static fn ($id, $question) => $id . ' (' . ($question['type'] ?? '?') . ')',
        array_keys($questions),
        $questions,
      )),
      '@model' => $model,
      '@payload' => $this->toJson($payload),
    ]);

    $start = hrtime(TRUE);
    for ($attempt = 1; ; $attempt++) {
      try {
        $response = $this->httpClient->request('POST', self::ENDPOINT, $options);
        break;
      }
      catch (RequestException $e) {
        $status = $e->getResponse()?->getStatusCode();
        if (in_array($status, self::RETRY_STATUSES, TRUE) && $attempt < self::MAX_ATTEMPTS) {
          // Exponential backoff: 1s, 2s, 4s...
          $delay = 2 ** ($attempt - 1);
          $this->logger->warning('SystemOne returned HTTP @status on attempt @attempt of @max. Retrying in @delay s.', [
            '@status' => $status,
            '@attempt' => $attempt,
            '@max' => self::MAX_ATTEMPTS,
            '@delay' => $delay,
          ]);
          sleep($delay);
          continue;
        }
        throw $this->requestFailed($e, $status);
      }
      catch (GuzzleException $e) {
        throw $this->requestFailed($e, NULL);
      }
    }
    $duration = round((hrtime(TRUE) - $start) / 1e6);

    $body = (string) $response->getBody();
    $data = json_decode($body, TRUE);
    if (!is_array($data) || !isset($data['answers']) || !is_array($data['answers'])) {
      $this->logger->error('SystemOne returned an invalid response (HTTP @status, @duration ms).<pre>@body</pre>', [
        '@status' => $response->getStatusCode(),
        '@duration' => $duration,
        '@body' => $body,
      ]);
      throw new SystemOneException('SystemOne returned an invalid response.');
    }

    $this->logger->debug('SystemOne response: HTTP @status in @duration ms, @attempts attempt(s), tokens in/out @in/@out.<pre>@response</pre>', [
      '@status' => $response->getStatusCode(),
      '@duration' => $duration,
      '@attempts' => $attempt,
      '@in' => $data['usage']['input_tokens'] ?? '?',
      '@out' => $data['usage']['output_tokens'] ?? '?',
      '@response' => $this->toJson($data),
    ]);

    return $data;
  }

  /**
   * Sends a single question and returns its answer.
   */
  protected function askOne(string|array $state, array $question, string $model): array {
    $answers = $this->ask($state, ['answer' => $question], $model)['answers'];
    if (!isset($answers['answer']) || !is_array($answers['answer'])) {
      throw new SystemOneException('SystemOne response is missing the answer.');
    }
    return $answers['answer'];
  }

  /**
   * Logs a failed request and builds the exception to throw.
   */
  protected function requestFailed(\Throwable $e, ?int $status): SystemOneException {
    $message = match ($status) {
      401 => 'The JEV API key is missing or invalid.',
      422 => 'SystemOne rejected the request as invalid.',
      429 => 'SystemOne rate limit exceeded.',
      529 => 'SystemOne is temporarily overloaded.',
      default => 'SystemOne request failed.',
    };

    $body = $e instanceof RequestException ? (string) $e->getResponse()?->getBody() : '';
    $this->logger->error('@message HTTP status: @status. Error: @error<pre>@body</pre>', [
      '@message' => $message,
      '@status' => $status ?? 'none',
      '@error' => $e->getMessage(),
      '@body' => $body,
    ]);

    return new SystemOneException($message, $status ?? 0, $e);
  }

  /**
   * Pretty-prints data for log messages.
   */
  protected function toJson(mixed $data): string {
    return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

}
