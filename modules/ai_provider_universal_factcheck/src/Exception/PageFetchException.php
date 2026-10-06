<?php

namespace Drupal\ai_provider_universal_factcheck\Exception;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A page could not be fetched; carries a translated message for the user.
 */
class PageFetchException extends \RuntimeException {

  public function __construct(
    public readonly TranslatableMarkup $userMessage,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($userMessage->getUntranslatedString(), 0, $previous);
  }

  /**
   * The URL is not http(s), or has no host.
   */
  public static function notPublic(): static {
    return new static(new TranslatableMarkup('Only public http(s) URLs can be scanned.'));
  }

  /**
   * The host resolves to a loopback, private or reserved address.
   */
  public static function internalAddress(): static {
    return new static(new TranslatableMarkup('The URL resolves to a private or reserved address and cannot be scanned.'));
  }

  /**
   * The request itself failed.
   */
  public static function fetchFailed(string $url, \Throwable $previous): static {
    return new static(new TranslatableMarkup('Could not fetch %url: @message', [
      '%url' => $url,
      '@message' => $previous->getMessage(),
    ]), $previous);
  }

}
