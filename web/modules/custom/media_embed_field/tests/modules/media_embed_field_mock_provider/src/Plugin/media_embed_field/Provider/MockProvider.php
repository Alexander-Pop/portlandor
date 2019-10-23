<?php

namespace Drupal\media_embed_field_mock_provider\Plugin\media_embed_field\Provider;

use Drupal\media_embed_field\ProviderPluginInterface;

/**
 * A mock media provider for use in tests.
 *
 * @MediaEmbedProvider(
 *   id = "mock",
 *   title = @Translation("Mock Provider")
 * )
 */
class MockProvider implements ProviderPluginInterface {

  /**
   * {@inheritdoc}
   */
  public static function isApplicable($input) {
    return strpos($input, 'example.com') !== FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function renderThumbnail($image_style, $link_url) {
    return [
      '#markup' => 'Mock provider thumbnail.',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function renderEmbedCode($width, $height, $autoplay) {
    return [
      '#markup' => 'Mock provider embed code.',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRemoteThumbnailUrl() {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getLocalThumbnailUri() {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function downloadThumbnail() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public static function getIdFromInput($input) {
    return $input;
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return 'Media Name';
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginId() {
    return 'foo';
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginDefinition() {
    return [];
  }

}
