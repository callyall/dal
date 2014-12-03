<?php
namespace Packaged\Dal\Ql\Cql;

use Packaged\Dal\Ql\Cql\DataType\BooleanType;
use Packaged\Dal\Ql\Cql\DataType\DoubleType;
use Packaged\Dal\Ql\Cql\DataType\FloatType;
use Packaged\Dal\Ql\Cql\DataType\IntegerType;
use Packaged\Dal\Ql\Cql\DataType\LongType;
use Packaged\Dal\Ql\QlDao;
use Packaged\DocBlock\DocBlockParser;

abstract class CqlDao extends QlDao
{
  public function getTtl()
  {
    return null;
  }

  protected function _configure()
  {
    parent::_configure();
    foreach($this->getDaoProperties() as $property)
    {
      $docblock = DocBlockParser::fromProperty($this, $property);
      if($this->_hasAnyTag($docblock, ['int', 'smallint', 'integer']))
      {
        $this->_addCustomSerializer(
          $property,
          'type',
          [IntegerType::class, 'pack'],
          [IntegerType::class, 'unpack']
        );
      }
      else if($this->_hasAnyTag($docblock, ['double']))
      {
        $this->_addCustomSerializer(
          $property,
          'type',
          [DoubleType::class, 'pack'],
          [DoubleType::class, 'unpack']
        );
      }
      else if($this->_hasAnyTag($docblock, ['bigint', 'counter', 'timestamp']))
      {
        $this->_addCustomSerializer(
          $property,
          'type',
          [LongType::class, 'pack'],
          [LongType::class, 'unpack']
        );
      }
      else if($this->_hasAnyTag($docblock, ['float']))
      {
        $this->_addCustomSerializer(
          $property,
          'type',
          [FloatType::class, 'pack'],
          [FloatType::class, 'unpack']
        );
      }
      else if($this->_hasAnyTag($docblock, ['bool']))
      {
        $this->_addCustomSerializer(
          $property,
          'type',
          [BooleanType::class, 'pack'],
          [BooleanType::class, 'unpack']
        );
      }
    }
  }

  protected function _hasAnyTag(DocBlockParser $block, array $tags)
  {
    foreach($tags as $tag)
    {
      if($block->hasTag($tag))
      {
        return true;
      }
    }
    return false;
  }

  /**
   * @return CqlDaoCollection
   */
  protected static function _createCollection()
  {
    return CqlDaoCollection::create(get_called_class());
  }
}