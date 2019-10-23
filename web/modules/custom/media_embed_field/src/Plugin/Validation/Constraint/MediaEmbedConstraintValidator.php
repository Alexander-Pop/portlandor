<?php

namespace Drupal\media_embed_field\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\media_embed_field\ProviderManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the media embed providers.
 */
class MediaEmbedConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Media embed provider manager service.
   *
   * @var \Drupal\media_embed_field\ProviderManagerInterface
   */
  protected $providerManager;

  /**
   * Create an instance of the validator.
   *
   * @param \Drupal\media_embed_field\ProviderManagerInterface $provider_manager
   *   The provider manager service.
   */
  public function __construct(ProviderManagerInterface $provider_manager) {
    $this->providerManager = $provider_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('media_embed_field.provider_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate($field, Constraint $constraint) {
    if (!isset($field->value)) {
      return NULL;
    }

    $allowed_providers = $field->getFieldDefinition()->getSetting('allowed_providers');
    $allowed_provider_definitions = $this->providerManager->loadDefinitionsFromOptionList($allowed_providers);

    if (FALSE === $this->providerManager->filterApplicableDefinitions($allowed_provider_definitions, $field->value)) {
      $this->context->addViolation($constraint->message);
    }
  }

}
