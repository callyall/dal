<?php
namespace Packaged\Dal\Ql;

use Packaged\Dal\Collections\IAggregateDaoCollection;
use Packaged\Dal\Foundation\DaoCollection;
use Packaged\QueryBuilder\Builder\Traits\HavingTrait;
use Packaged\QueryBuilder\Builder\Traits\JoinTrait;
use Packaged\QueryBuilder\Builder\Traits\LimitTrait;
use Packaged\QueryBuilder\Builder\Traits\OrderByTrait;
use Packaged\QueryBuilder\Builder\Traits\WhereTrait;
use Packaged\QueryBuilder\Clause\IClause;
use Packaged\QueryBuilder\Clause\SelectClause;
use Packaged\QueryBuilder\SelectExpression\AllSelectExpression;
use Packaged\QueryBuilder\SelectExpression\AverageSelectExpression;
use Packaged\QueryBuilder\SelectExpression\CountSelectExpression;
use Packaged\QueryBuilder\SelectExpression\MaxSelectExpression;
use Packaged\QueryBuilder\SelectExpression\MinSelectExpression;
use Packaged\QueryBuilder\SelectExpression\SumSelectExpression;
use Packaged\QueryBuilder\Statement\IStatement;
use Packaged\QueryBuilder\Statement\IStatementSegment;
use Packaged\QueryBuilder\Statement\QueryStatement;

/**
 * @method QlDao createNewDao
 */
class QlDaoCollection extends DaoCollection
  implements IAggregateDaoCollection, IStatement
{
  /**
   * @var QueryStatement
   */
  protected $_query;

  protected $_isLoaded;

  use JoinTrait;
  use WhereTrait;
  use OrderByTrait;
  use LimitTrait;
  use HavingTrait;

  protected function _init()
  {
    $this->resetQuery();
  }

  /**
   * Reset the query to a single select * FROM table
   * @return $this
   */
  public function resetQuery()
  {
    $this->_query = new QueryStatement();
    $dao          = $this->createNewDao(false);
    $select       = new SelectClause();
    $select->addExpression(AllSelectExpression::create($dao->getTableName()));
    $this->_query->addClause($select);
    $this->_query->from($dao->getTableName());
    return $this;
  }

  /**
   * @return QueryStatement
   */
  public function getQuery()
  {
    return $this->_query;
  }

  public function addClause(IClause $clause)
  {
    $this->_query->addClause($clause);
    $this->clear();
    return $this;
  }

  public function getClause($action)
  {
    return $this->_query->getClause($action);
  }

  public function hasClause($action)
  {
    return $this->_query->hasClause($action);
  }

  public function removeClause($action)
  {
    return $this->_query->removeClause($action);
  }

  /**
   * @return IStatementSegment[]
   */
  public function getSegments()
  {
    return $this->_query->getSegments();
  }

  /**
   * @return QlDataStore
   * @throws \Exception
   * @throws \Packaged\Dal\Exceptions\DalResolver\DataStoreNotFoundException
   */
  protected function _getDataStore()
  {
    return $this->createNewDao(false)->getDataStore();
  }

  protected function _prepareDaos()
  {
    if(!$this->_isLoaded)
    {
      $this->load();
    }
    parent::_prepareDaos();
  }

  public function clear()
  {
    $this->_isLoaded = false;
    parent::clear();
  }

  /**
   * @return $this
   */
  public function loadWhere(...$params)
  {
    $this->resetQuery();
    $this->clear();

    if(func_num_args() > 0)
    {
      $this->_query->where(...$params);
    }
    $this->load();
    return $this;
  }

  public function load()
  {
    $dao     = $this->createNewDao(false);
    $results = $dao->getDataStore()->getData($this);
    foreach($results as $result)
    {
      $this->_daos[] = $this->createNewDao()->hydrateDao($result, true);
    }
    $this->_isLoaded = true;
    return $this;
  }

  /**
   * Retrieve the first available dao
   *
   * @param mixed $default
   *
   * @return QlDao
   */
  public function first($default = null)
  {
    if(!$this->isEmpty())
    {
      return parent::first();
    }
    $limit = $this->getClause('LIMIT');
    $this->limitWithOffset(0, 1);
    $this->load();
    $this->removeClause('LIMIT');
    if($limit !== null)
    {
      $this->_query->addClause($limit);
    }
    if(!$this->isEmpty())
    {
      $dao = head($this->_daos);
      $this->clear();
      return $dao;
    }
    return $default;
  }

  public function min($property = 'id')
  {
    if($this->isEmpty())
    {
      $this->_query->addClause(
        (new SelectClause())->addExpression(
          MinSelectExpression::create($property)
        )
      );
      return head(head($this->_getDataStore()->getData($this->_query)));
    }
    return min(ppull($this->_daos, $property));
  }

  public function max($property = 'id')
  {
    if($this->isEmpty())
    {
      $this->_query->addClause(
        (new SelectClause())->addExpression(
          MaxSelectExpression::create($property)
        )
      );
      return head(head($this->_getDataStore()->getData($this->_query)));
    }
    return max(ppull($this->_daos, $property));
  }

  public function avg($property = 'id')
  {
    if($this->isEmpty())
    {
      $this->_query->addClause(
        (new SelectClause())->addExpression(
          AverageSelectExpression::create($property)
        )
      );
      return head(head($this->_getDataStore()->getData($this->_query)));
    }
    $values = ppull($this->_daos, $property);
    return array_sum($values) / count($values);
  }

  public function sum($property = 'id')
  {
    if($this->isEmpty())
    {
      $this->_query->addClause(
        (new SelectClause())->addExpression(
          SumSelectExpression::create($property)
        )
      );
      return head(head($this->_getDataStore()->getData($this->_query)));
    }
    return array_sum(ppull($this->_daos, $property));
  }

  public function count()
  {
    if($this->isEmpty())
    {
      $this->_query->addClause(
        (new SelectClause())->addExpression(
          new CountSelectExpression()
        )
      );
      return head(head($this->_getDataStore()->getData($this->_query)));
    }
    return parent::count();
  }

  /**
   * Find all distinct values of a property in the collection
   *
   * @param $property
   *
   * @return array
   */
  public function distinct($property)
  {
    if($this->isEmpty())
    {
      $select = new SelectClause();
      $select->setDistinct(true);
      $select->addField($property);
      $this->_query->addClause($select);
      $results = $this->_getDataStore()->getData($this->_query);
      if(empty($results))
      {
        return [];
      }
      return ipull($results, $property);
    }
    return parent::distinct($property);
  }
}
