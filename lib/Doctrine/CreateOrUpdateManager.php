<?php

namespace MailPoet\Doctrine;

use MailPoetVendor\Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MailPoetVendor\Doctrine\ORM\EntityManager;
use MailPoetVendor\Doctrine\ORM\EntityRepository;

class CreateOrUpdateManager {
  /** @var EntityManager */
  private $entity_manager;

  /** @var EntityRepository */
  private $doctrine_repository;

  function __construct(EntityManager $entity_manager, EntityRepository $entity_repository) {
    $this->entity_manager = $entity_manager;
    $this->doctrine_repository = $entity_repository;
  }

  function createOrUpdate(array $find_by_criteria, callable $update_callback, callable $create_callback = null) {
    $entity_name = $this->doctrine_repository->getClassName();
    $entity = $this->doctrine_repository->findOneBy($find_by_criteria);

    // create
    if (!$entity) {
      $entity = $create_callback
        ? $this->createEntityByCallback($entity_name, $create_callback)
        : $this->createEntityByConstructor($entity_name);
      $update_callback($entity);

      try {
        $this->save($entity);
        $needs_update = false;
      } catch (UniqueConstraintViolationException $e) {
        // race condition - entity already exists, we will refetch it below & update
        $needs_update = true;
      }

      // refetch entity using the original entity manager
      $entity = $this->doctrine_repository->findOneBy($find_by_criteria);
      if (!is_object($entity) || get_class($entity) !== $entity_name) {
        throw new \InvalidArgumentException("Find-by criteria did not find an entity of type '$entity_name'");
      }

      if (!$needs_update) {
        return $entity;
      }
    }

    // update
    $update_callback($entity);
    $this->entity_manager->flush();
    return $entity;
  }

  private function createEntityByConstructor($entity_name) {
    $constructor = $this->entity_manager->getClassMetadata($entity_name)
      ->getReflectionClass()
      ->getConstructor();

    if ($constructor && $constructor->getNumberOfRequiredParameters() > 0) {
      throw new \InvalidArgumentException(
        "Can't automatically create '$entity_name' because it has required constructor arguments. "
        . "Please provide '\$create_callback'."
      );
    }
    return new $entity_name();
  }

  private function createEntityByCallback($entity_name, callable $create_callback) {
    $entity = $create_callback();
    if (!is_object($entity) || get_class($entity) !== $entity_name) {
      throw new \InvalidArgumentException("Create callback did not return entity of type '$entity_name'");
    }
    return $entity;
  }

  private function save($entity) {
    // save entity using a new entity manger instance because an exception would close it
    $new_entity_manager = $this->entity_manager->create(
      $this->entity_manager->getConnection(),
      $this->entity_manager->getConfiguration()
    );
    $new_entity_manager->persist($entity);
    $new_entity_manager->flush();
  }
}
