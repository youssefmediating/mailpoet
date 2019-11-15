<?php

namespace MailPoet\Test\Doctrine;

use Codeception\Stub;
use MailPoet\Doctrine\Annotations\AnnotationReaderProvider;
use MailPoet\Doctrine\ConfigurationFactory;
use MailPoet\Doctrine\CreateOrUpdateManager;
use MailPoet\Doctrine\EntityManagerFactory;
use MailPoet\Doctrine\EventListeners\TimestampListener;
use MailPoet\Doctrine\EventListeners\ValidationListener;
use MailPoet\Doctrine\Validator\ValidatorFactory;
use MailPoet\WP\Functions as WPFunctions;
use MailPoetVendor\Doctrine\Common\Cache\ArrayCache;
use MailPoetVendor\Doctrine\ORM\EntityRepository as DoctrineEntityRepository;

require_once __DIR__ . '/Entity.php';
require_once __DIR__ . '/EntityWithConstructor.php';

class CreateOrUpdateManagerTest extends \MailPoetTest {
  /** @var string */
  private $table_name;

  /** @var string */
  private $with_constructor_entity_table_name;

  /** @var DoctrineEntityRepository */
  private $doctrine_repository;

  function _before() {
    $this->entity_manager = $this->createEntityManager();
    $this->table_name = $this->entity_manager->getClassMetadata(Entity::class)->getTableName();
    $this->doctrine_repository = new DoctrineEntityRepository(
      $this->entity_manager,
      $this->entity_manager->getClassMetadata(Entity::class)
    );

    $this->connection->executeUpdate("DROP TABLE IF EXISTS $this->table_name");
    $this->connection->executeUpdate("
      CREATE TABLE $this->table_name (
        id int(11) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name varchar(255) NOT NULL UNIQUE,
        cascade_parent_id int(11) unsigned NULL,
        non_cascade_parent_id int(11) unsigned NULL
      )
    ");

    $this->with_constructor_entity_table_name = $this->entity_manager->getClassMetadata(EntityWithConstructor::class)->getTableName();
    $this->connection->executeUpdate("DROP TABLE IF EXISTS $this->with_constructor_entity_table_name");
    $this->connection->executeUpdate("
      CREATE TABLE $this->with_constructor_entity_table_name (
        id int(11) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name varchar(255) NOT NULL UNIQUE
      )
    ");
  }

  function testItCreatesEntityByCallback() {
    $name = 'Entity name';
    $create_called = false;
    $update_called = false;
    $create_entity = null;
    $update_entity = null;

    $create_or_update_manager = new CreateOrUpdateManager($this->entity_manager, $this->doctrine_repository);
    $returned_entity = $create_or_update_manager->createOrUpdate(
      ['name' => $name],
      function (Entity $entity) use (&$update_called, &$update_entity) {
        $update_called = true;
        $update_entity = $entity;
      },
      function () use ($name, &$create_called, &$create_entity) {
        $create_called = true;
        $create_entity = new Entity();
        $create_entity->setName($name);
        return $create_entity;
      }
    );

    $db_entity = $this->entity_manager->find(Entity::class, $returned_entity->getId());

    expect($create_called)->true();
    expect($update_called)->true();
    expect($db_entity)->isInstanceOf(Entity::class);
    expect($create_entity)->same($update_entity);
  }

  function testItCreatesEntityByConstructor() {
    $name = 'Entity name';
    $update_called = false;

    $create_or_update_manager = new CreateOrUpdateManager($this->entity_manager, $this->doctrine_repository);
    $returned_entity = $create_or_update_manager->createOrUpdate(
      ['name' => $name],
      function (Entity $entity) use ($name, &$update_called, &$update_entity) {
        $entity->setName($name);
        $update_called = true;
        $update_entity = $entity;
      }
    );

    $db_entity = $this->entity_manager->find(Entity::class, $returned_entity->getId());

    expect($update_called)->true();
    expect($db_entity)->isInstanceOf(Entity::class);
    expect($returned_entity)->same($db_entity);
  }

  function testItUpdatesEntity() {
    // existing entity
    $name = 'Entity name';
    $entity = new Entity();
    $entity->setName($name);
    $this->entity_manager->persist($entity);
    $this->entity_manager->flush();

    $create_called = false;
    $update_called = false;
    $update_entity = null;

    $create_or_update_manager = new CreateOrUpdateManager($this->entity_manager, $this->doctrine_repository);
    $returned_entity = $create_or_update_manager->createOrUpdate(
      ['name' => $name],
      function (Entity $entity) use (&$update_called, &$update_entity) {
        $update_called = true;
        $entity->setName('New name');
        $update_entity = $entity;
      },
      function () use (&$create_called) {
        $create_called = false;
      }
    );

    $db_entity = $this->entity_manager->find(Entity::class, $returned_entity->getId());

    expect($db_entity->getName())->same('New name');
    expect($create_called)->false();
    expect($update_called)->true();
    expect($update_entity)->same($entity);
    expect($returned_entity)->same($entity);
    expect($db_entity)->same($entity);
  }

  function testItUpdatesEntityOnCreateRaceCondition() {
    // existing entity
    $name = 'Entity name';
    $entity = new Entity();
    $entity->setName($name);
    $this->entity_manager->persist($entity);
    $this->entity_manager->flush();

    // on first run return null (attempts create), on second run return the existing entity
    $doctrine_repository = $this->make(DoctrineEntityRepository::class, [
      'getClassName' => Entity::class,
      'findOneBy' => Stub::consecutive(null, $entity),
    ]);
    $create_or_update_manager = new CreateOrUpdateManager($this->entity_manager, $doctrine_repository);

    // insert entity with conflicting name
    $create_called = false;
    $update_called = false;
    $create_entity = null;
    $update_entity = null;

    $create_or_update_manager->createOrUpdate(
      ['name' => $name],
      function (Entity $entity) use (&$update_called, &$update_entity) {
        $update_called = true;
        $update_entity = $entity;
      },
      function () use ($name, &$create_called, &$create_entity) {
        $create_called = true;
        $create_entity = new Entity();
        $create_entity->setName($name);
        return $create_entity;
      }
    );

    expect($create_called)->true();
    expect($update_called)->true();
    expect($create_entity)->notSame($update_entity);
    expect($create_entity)->notSame($entity);
    expect($update_entity)->same($entity);
  }

  function testItScreamsWhenEntityNotReturnedFromCreateCallback() {
    $name = 'Entity name';

    $entity_name = Entity::class;
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage("Create callback did not return entity of type '$entity_name'");

    $create_or_update_manager = new CreateOrUpdateManager($this->entity_manager, $this->doctrine_repository);
    $create_or_update_manager->createOrUpdate(
      ['name' => $name],
      function (Entity $entity) {
      },
      function () use ($name) {
        $create_entity = new Entity();
        $create_entity->setName($name);
        // missing return
      }
    );
  }

  function testItScreamsWhenItNeedsCreateCallback() {
    $name = 'Entity name';

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageRegExp("/^Can't automatically create '[^']+' because it has required constructor arguments\./");

    $repository = new DoctrineEntityRepository(
      $this->entity_manager,
      $this->entity_manager->getClassMetadata(EntityWithConstructor::class)
    );

    $create_or_update_manager = new CreateOrUpdateManager($this->entity_manager, $repository);
    $create_or_update_manager->createOrUpdate(
      ['name' => $name],
      function (EntityWithConstructor $entity) {
      }
    );
  }

  function testItScreamsWhenSideEffectFoundThroughCascadeRelation() {
    $name = 'Entity name';

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageRegExp('/^Create callback has database side-effects/');

    $create_or_update_manager = new CreateOrUpdateManager($this->entity_manager, $this->doctrine_repository);
    $create_or_update_manager->createOrUpdate(
      ['name' => $name],
      function (Entity $entity) {
      },
      function () use ($name) {
        $create_entity = new Entity();
        $create_entity->setCascadeParent(new Entity());
        $create_entity->setName($name);
        return $create_entity;
      }
    );
  }

  function testItScreamsWhenSideEffectFoundThroughNonCascadeRelation() {
    $name = 'Entity name';

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageRegExp('/^Create callback has database side-effects/');

    $create_or_update_manager = new CreateOrUpdateManager($this->entity_manager, $this->doctrine_repository);
    $create_or_update_manager->createOrUpdate(
      ['name' => $name],
      function (Entity $entity) {
      },
      function () use ($name) {
        $create_entity = new Entity();
        $create_entity->setNonCascadeParent(new Entity());
        $create_entity->setName($name);
        return $create_entity;
      }
    );
  }

  function _after() {
    $this->connection->executeUpdate("DROP TABLE IF EXISTS $this->table_name");
    $this->connection->executeUpdate("DROP TABLE IF EXISTS $this->with_constructor_entity_table_name");
  }

  private function createEntityManager() {
    $annotation_reader_provider = new AnnotationReaderProvider();
    $configuration_factory = new ConfigurationFactory(false, $annotation_reader_provider);
    $configuration = $configuration_factory->createConfiguration();

    $metadata_driver = $configuration->newDefaultAnnotationDriver([__DIR__], false);
    $configuration->setMetadataDriverImpl($metadata_driver);
    $configuration->setMetadataCacheImpl(new ArrayCache());

    $validator_factory = new ValidatorFactory($annotation_reader_provider);
    $timestamp_listener = new TimestampListener(new WPFunctions());
    $validation_listener = new ValidationListener($validator_factory->createValidator());
    $entity_manager_factory = new EntityManagerFactory($this->connection, $configuration, $timestamp_listener, $validation_listener);
    return $entity_manager_factory->createEntityManager();
  }
}
