<?php

namespace MailPoet\Doctrine;

use MailPoetVendor\Doctrine\Common\Cache\ArrayCache;
use MailPoetVendor\Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MailPoetVendor\Doctrine\ORM\EntityManager;
use MailPoetVendor\Doctrine\ORM\EntityRepository;
use MailPoetVendor\Doctrine\ORM\ORMInvalidArgumentException;

class CreateOrUpdateManager {
  /** @var ConfigurationFactory */
  private $configuration_factory;

  /** @var EntityManager */
  private $entity_manager;

  function __construct(
    ConfigurationFactory $configuration_factory,
    EntityManager $entity_manager
  ) {
    $this->entity_manager = $entity_manager;
    $this->configuration_factory = $configuration_factory;
  }

  function createOrUpdate(EntityRepository $doctrine_repository, array $find_by_criteria, callable $update_callback, callable $create_callback = null) {
    $entity_name = $doctrine_repository->getClassName();
    $entity = $doctrine_repository->findOneBy($find_by_criteria);

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
      $entity = $doctrine_repository->findOneBy($find_by_criteria);
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
    $cache = new ArrayCache();
    $configuration = $this->configuration_factory->createConfiguration();
    $configuration->setQueryCacheImpl($cache);
    $configuration->setResultCacheImpl($cache);
    $new_entity_manager = $this->entity_manager->create(
      $this->entity_manager->getConnection(),
      $configuration
    );
    $new_entity_manager->persist($entity);

    // ensure unwanted side effects with 'cascade' (i.e. 'persist') weren't introduced
    $insertions = $new_entity_manager->getUnitOfWork()->getScheduledEntityInsertions();
    $unrelated_changes_count =
      count($new_entity_manager->getUnitOfWork()->getScheduledEntityUpdates())
      + count($new_entity_manager->getUnitOfWork()->getScheduledEntityDeletions())
      + count($new_entity_manager->getUnitOfWork()->getScheduledCollectionDeletions())
      + count($new_entity_manager->getUnitOfWork()->getScheduledCollectionUpdates());

    if (count($insertions) !== 1 || reset($insertions) !== $entity || $unrelated_changes_count > 0) {
      $this->throwSideEffectsException(get_class($entity));
    }

    try {
      $new_entity_manager->flush();
    } catch (ORMInvalidArgumentException $e) {
      // ensure unwanted side effects without 'cascade' (i.e. 'persist') weren't introduced
      $prefix = 'A new entity was found through the relationship';
      if (substr($e->getMessage(), 0, strlen($prefix))) {
        $this->throwSideEffectsException(get_class($entity));
      }
      throw $e;
    }
  }

  private function throwSideEffectsException($entity_name) {
    throw new \InvalidArgumentException(
      "Create callback has database side-effects other than creating an entity of class '$entity_name'. "
      . 'This can be (unintentionally) caused by passing other entities outside of the callback context '
      . 'to relations of the created entity. Please fetch all necessary relations from the DB inside '
      . 'the create callback or pass them using their identifiers - see EntityManager::getReference().'
    );
  }
}
