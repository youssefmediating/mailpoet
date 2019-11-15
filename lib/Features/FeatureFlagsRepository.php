<?php

namespace MailPoet\Features;

use MailPoet\Doctrine\Repository;
use MailPoet\Entities\FeatureFlagEntity;

/**
 * @method FeatureFlagEntity[] findAll()
 * @method FeatureFlagEntity|null findOneBy(array $criteria, array $order_by = null)
 * @method void persist(FeatureFlagEntity $entity)
 * @method void remove(FeatureFlagEntity $entity)
 */
class FeatureFlagsRepository extends Repository {
  protected function getEntityClassName() {
    return FeatureFlagEntity::class;
  }

  function createOrUpdateByName($name, $value) {
    return $this->createOrUpdate(
      ['name' => $name],
      function (FeatureFlagEntity $feature_flag) use ($value) {
        $feature_flag->setValue($value);
      },
      function () use ($name) {
        $feature_flag = new FeatureFlagEntity($name);
        $feature_flag->setName($name);
        return $feature_flag;
      }
    );
  }
}
