<?php

namespace MailPoet\Test\Doctrine;

use MailPoetVendor\Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\Table(name="test_entity")
 */
class Entity {
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

  /**
   * @ORM\ManyToOne(targetEntity="Entity", cascade={"persist"})
   * @var Entity|null
   */
  private $cascade_parent;

  /**
   * @ORM\ManyToOne(targetEntity="Entity")
   * @var Entity|null
   */
  private $non_cascade_parent;

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

  /** @return Entity|null */
  public function getCascadeParent() {
    return $this->cascade_parent;
  }

  /** @param Entity|null $cascade_parent */
  public function setCascadeParent($cascade_parent) {
    $this->cascade_parent = $cascade_parent;
  }

  /** @return Entity|null */
  public function getNonCascadeParent() {
    return $this->non_cascade_parent;
  }

  /** @param Entity|null $non_cascade_parent */
  public function setNonCascadeParent($non_cascade_parent) {
    $this->non_cascade_parent = $non_cascade_parent;
  }
}
