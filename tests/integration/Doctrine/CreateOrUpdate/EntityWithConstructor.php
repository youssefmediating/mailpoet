<?php

namespace MailPoet\Test\Doctrine;

use MailPoetVendor\Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\Table(name="test_entity_with_constructor")
 */
class EntityWithConstructor {
  /**
   * @ORM\Column(type="integer")
   * @ORM\Id
   * @ORM\GeneratedValue
   * @var int|null
   */
  private $id;

  /**
   * @ORM\Column(type="string")
   * @var string
   */
  private $name;

  function __construct($id, $name) {
    $this->id = $id;
    $this->name = $name;
  }

  /**
   * @return int|null
   */
  function getId() {
    return $this->id;
  }

  /** @return string */
  function getName() {
    return $this->name;
  }
  /** @param string $name */
  function setName($name) {
    $this->name = $name;
  }
}
