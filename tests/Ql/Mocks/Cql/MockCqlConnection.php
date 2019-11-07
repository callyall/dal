<?php
namespace Tests\Ql\Mocks\Cql;

use cassandra\Compression;
use cassandra\ConsistencyLevel;
use Packaged\Dal\Ql\Cql\CqlConnection;
use Packaged\Dal\Ql\Cql\CqlStatement;

class MockCqlConnection extends CqlConnection
{
  protected $_executeCount = 0;
  protected $_prepareCount = 0;

  protected $_newClient = null;

  public function getConfig($item)
  {
    return $this->_config()->getItem($item);
  }

  public function setClient($client)
  {
    $this->_newClient = $client;
    $this->disconnect()->connect();
  }

  public function connect()
  {
    if($this->_newClient)
    {
      $this->_client = $this->_newClient;
    }
    return parent::connect();
  }

  public function execute(
    CqlStatement $statement, array $parameters = [],
    $consistency = ConsistencyLevel::QUORUM, $retries = null
  )
  {
    $this->_executeCount++;
    return parent::execute($statement, $parameters, $consistency, $retries);
  }

  public function getExecuteCount()
  {
    return $this->_executeCount;
  }

  public function prepare(
    $query, $compression = Compression::NONE, $retries = null
  )
  {
    $this->_prepareCount++;
    return parent::prepare($query, $compression, $retries);
  }

  public function getPrepareCount()
  {
    return $this->_prepareCount;
  }

  public function resetCounts()
  {
    $this->_prepareCount = 0;
    $this->_executeCount = 0;
  }
}