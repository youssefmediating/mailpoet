<?php

namespace MailPoet\Test\DataFactories;

use MailPoet\DI\ContainerWrapper;
use MailPoet\Features\FeatureFlagsRepository;

class Features {

  /** @var FeatureFlagsRepository */
  private $flags;

  function __construct() {
    $this->flags = ContainerWrapper::getInstance(WP_DEBUG)->get(FeatureFlagsRepository::class);
  }

  function withFeatureEnabled($name) {
    $this->flags->createOrUpdateByName($name, true);
    return $this;
  }

  function withFeatureDisabled($name) {
    $this->flags->createOrUpdateByName($name, false);
    return $this;
  }
}
