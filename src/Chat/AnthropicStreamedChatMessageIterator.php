<?php

namespace Drupal\ai_provider_universal\Chat;

use Drupal\ai\OperationType\Chat\StreamedChatMessageIterator;
use Drupal\Component\Serialization\Json;
use Psr\Http\Message\StreamInterface;

/**
 * Streams an Anthropic Messages API server-sent event response.
 *
 * The wire format differs from OpenAI's: instead of one delta object per
 * chunk, Anthropic emits typed events (message_start, content_block_delta,
 * message_delta, message_stop). Text arrives as text_delta blocks, the stop
 * reason and the output token count only in the closing message_delta, and
 * the input token count in the opening message_start.
 *
 * @see \Drupal\ai_provider_universal\Plugin\AiServerBackend\Anthropic::chat()
 */
class AnthropicStreamedChatMessageIterator extends StreamedChatMessageIterator {

  /**
   * {@inheritdoc}
   */
  public function doIterate(): \Generator {
    $input_tokens = 0;

    foreach ($this->iterator as $event) {
      $type = $event['type'] ?? '';

      if ($type === 'message_start') {
        $input_tokens = (int) ($event['message']['usage']['input_tokens'] ?? 0);
        continue;
      }

      if ($type === 'content_block_delta') {
        $delta = $event['delta'] ?? [];
        // thinking_delta and signature_delta are extended-thinking internals
        // that must not be shown as answer text.
        if (($delta['type'] ?? '') !== 'text_delta') {
          continue;
        }
        yield $this->createStreamedChatMessage('assistant', $delta['text'] ?? '', [], NULL, $event);
        continue;
      }

      if ($type === 'message_delta') {
        if ($reason = $event['delta']['stop_reason'] ?? NULL) {
          $this->setFinishReason($reason);
        }
        // The final event carries the token accounting, which the provider
        // records against the server's daily usage limits.
        $output_tokens = (int) ($event['usage']['output_tokens'] ?? 0);
        $message = $this->createStreamedChatMessage('assistant', '', $event['usage'] ?? [], NULL, $event);
        $message->setInputTokenUsage($input_tokens);
        $message->setOutputTokenUsage($output_tokens);
        $message->setTotalTokenUsage($input_tokens + $output_tokens);
        yield $message;
      }
    }
  }

  /**
   * Parses a server-sent event stream into decoded event arrays.
   *
   * Kept static and stream-based so nothing buffers the whole response: the
   * point of streaming is that the first token reaches the user immediately.
   *
   * @param \Psr\Http\Message\StreamInterface $body
   *   The response body.
   *
   * @return \Generator
   *   Yields one decoded payload per "data:" line.
   */
  public static function readEvents(StreamInterface $body): \Generator {
    $buffer = '';

    while (!$body->eof()) {
      $buffer .= $body->read(8192);

      while (($position = strpos($buffer, "\n")) !== FALSE) {
        $line = trim(substr($buffer, 0, $position));
        $buffer = substr($buffer, $position + 1);

        // Event names are redundant: every payload repeats its own type.
        if ($line === '' || !str_starts_with($line, 'data:')) {
          continue;
        }
        $payload = trim(substr($line, 5));
        if ($payload === '' || $payload === '[DONE]') {
          continue;
        }
        $decoded = Json::decode($payload);
        if (is_array($decoded)) {
          yield $decoded;
        }
      }
    }
  }

}
