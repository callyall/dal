<?php
namespace Packaged\Dal\Ql;

use Packaged\Dal\DalResolver;
use Packaged\Dal\Exceptions\Connection\ConnectionException;
use Packaged\Dal\Exceptions\Connection\PdoException;
use Packaged\Helpers\Strings;
use Packaged\Helpers\ValueAs;

class PdoConnection extends DalConnection implements ILastInsertId
{
  /**
   * @var \PDO
   */
  protected $_connection;
  protected $_prepareDelayCount = [];
  protected $_lastConnectTime = 0;
  protected $_emulatedPrepares = false;
  protected $_delayedPreparesCount = null;
  protected $_inTransaction = false;
  protected $_lastRetryCount = 0;
  protected $_maxPreparedStatements = null;

  protected $_host;
  protected $_port;
  protected $_username;
  protected static $_pdoCache = [];

  /**
   * Open the connection
   *
   * @return static
   *
   * @throws ConnectionException
   */
  public function connect()
  {
    if(!$this->isConnected())
    {
      $this->_clearStmtCache();

      $this->_host = null;
      $this->_username = $this->_config()->getItem('username', 'root');

      $dsn = $this->_config()->getItem('dsn', null);
      if($dsn === null)
      {
        $this->_host = $this->_config()->getItem('hostname', '127.0.0.1');
        $this->_port = $this->_config()->getItem('port', 3306);

        $dsn = sprintf(
          "mysql:host=%s;port=%d",
          $this->_host,
          $this->_port
        );
      }

      $options = array_replace(
        $this->_defaultOptions(),
        ValueAs::arr($this->_config()->getItem('options'))
      );

      $remainingAttempts = ((int)$this->_config()->getItem(
        'connect_retries',
        3
      ));

      while(($remainingAttempts > 0) && ($this->_connection === null))
      {
        try
        {
          $wasCached = false;
          $cachedConnection = $this->_getCachedConnection($options);
          if($cachedConnection && ($cachedConnection instanceof \PDO))
          {
            $this->_connection = $cachedConnection;
            $wasCached = true;
          }
          else
          {
            $this->_connection = new \PDO(
              $dsn,
              $this->_username,
              $this->_config()->getItem('password', ''),
              $options
            );
          }

          if(isset($options[\PDO::ATTR_EMULATE_PREPARES]))
          {
            $this->_emulatedPrepares = $options[\PDO::ATTR_EMULATE_PREPARES];
          }
          else
          {
            $serverVersion = $this->_connection->getAttribute(
              \PDO::ATTR_SERVER_VERSION
            );
            $this->_emulatedPrepares = version_compare(
              $serverVersion,
              '5.1.17',
              '<'
            );
            $this->_connection->setAttribute(
              \PDO::ATTR_EMULATE_PREPARES,
              $this->_emulatedPrepares
            );
          }

          if(!$wasCached)
          {
            $this->_storeCachedConnection($this->_connection, $options);
          }

          $this->_switchDatabase(
            null,
            empty($options[\PDO::ATTR_PERSISTENT])
          );

          $remainingAttempts = 0;
        }
        catch(\Exception $e)
        {
          $remainingAttempts--;
          $this->_connection = null;
          if($remainingAttempts > 0)
          {
            usleep(mt_rand(1000, 5000));
          }
          else
          {
            throw new ConnectionException(
              "Failed to connect to PDO: " . $e->getMessage(),
              $e->getCode(), $e
            );
          }
        }
      }
      $this->_lastConnectTime = time();
    }
    return $this;
  }

  /**
   * Default options for the PDO Connection
   *
   * @return array
   */
  protected function _defaultOptions()
  {
    return [
      \PDO::ATTR_PERSISTENT => false,
      \PDO::ATTR_ERRMODE    => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_TIMEOUT    => 5,
    ];
  }

  /**
   * Get the key for the 'current database' cache used for auto-switching DB
   *
   * @return null|string
   */
  protected function _getConnectionId()
  {
    if($this->_host)
    {
      return $this->_host . '|' . $this->_port . '|' . $this->_username;
    }
    else
    {
      return null;
    }
  }

  protected function _getDatabaseName()
  {
    return $this->_config()->getItem('database');
  }

  /**
   * Select a database
   *
   * @param string $database
   *
   * @throws PdoException
   */
  protected function _selectDatabase($database)
  {
    if($this->_inTransaction)
    {
      throw new PdoException('Cannot switch database while in a transaction');
    }
    if($database && $this->isConnected())
    {
      $this->_connection->exec(sprintf('USE `%s`', $database));
    }
  }

  /**
   * Check to see if the connection is already open
   *
   * @return bool
   */
  public function isConnected()
  {
    return $this->_connection !== null;
  }

  /**
   * Disconnect the open connection
   *
   * @return static
   *
   * @throws ConnectionException
   */
  public function disconnect()
  {
    $this->_connection = null;
    return parent::disconnect();
  }

  /**
   * Execute a query
   *
   * @param       $query
   * @param array $values
   *
   * @return int number of affected rows
   *
   * @throws ConnectionException
   */
  public function runQuery($query, array $values = null)
  {
    $perfId = $this->getResolver()->startPerformanceMetric(
      $this,
      DalResolver::MODE_WRITE,
      $query
    );
    $stmt = $this->_runQuery($query, $values);
    $rowCount = $stmt->rowCount();
    $stmt->closeCursor();
    $this->getResolver()->closePerformanceMetric($perfId);
    return $rowCount;
  }

  /**
   * Fetch the results of the query
   *
   * @param       $query
   * @param array $values
   *
   * @return array
   *
   * @throws ConnectionException
   */
  public function fetchQueryResults($query, array $values = null)
  {
    $perfId = $this->getResolver()->startPerformanceMetric(
      $this,
      DalResolver::MODE_READ,
      $query
    );
    $result = $this->_runQuery($query, $values)->fetchAll(\PDO::FETCH_ASSOC);
    $this->getResolver()->closePerformanceMetric($perfId);
    return $result;
  }

  /**
   * Start a transaction
   */
  public function startTransaction()
  {
    $this->_switchDatabase();

    return $this->_performWithRetries(
      function ()
      {
        $result = $this->_connection->beginTransaction();
        $this->_inTransaction = true;
        return $result;
      }
    );
  }

  /**
   * Commit the current transaction
   */
  public function commit()
  {
    if($this->_inTransaction)
    {
      try
      {
        $result = $this->_connection->commit();
        return $result;
      }
      catch(\Exception $e)
      {
        try
        {
          $this->rollback();
        }
        catch(\Exception $e)
        {
        }
      }
      finally
      {
        $this->_inTransaction = false;
      }
    }
    throw new PdoException('Not currently in a transaction');
  }

  /**
   * Roll back the current transaction
   */
  public function rollback()
  {
    if($this->_inTransaction)
    {
      try
      {
        $result = $this->_connection->rollBack();
        return $result;
      }
      finally
      {
        $this->_inTransaction = false;
      }
    }
    throw new PdoException('Not currently in a transaction');
  }

  protected function _getDelayedPreparesCount()
  {
    if($this->_delayedPreparesCount === null)
    {
      $value = $this->_config()->getItem('delayed_prepares', 1);
      if(is_numeric($value))
      {
        $this->_delayedPreparesCount = (int)$value;
      }
      else
      {
        if(in_array($value, ['true', true, '1', 1], true))
        {
          $this->_delayedPreparesCount = 1;
        }
        else if(in_array($value, ['false', false, '0', 0], true))
        {
          $this->_delayedPreparesCount = 0;
        }
      }
    }
    return $this->_delayedPreparesCount;
  }

  /**
   * @param $query
   *
   * @return \PDOStatement
   */
  protected function _getStatement($query)
  {
    $this->_switchDatabase();

    $cacheKey = $this->_stmtCacheKey($query);
    $cached = $this->_getCachedStmt($cacheKey);
    if($cached)
    {
      return $cached;
    }

    $stmt = false;

    // Delay preparing the statement for the configured number of calls
    if(!$this->_emulatedPrepares)
    {
      $delayCount = $this->_getDelayedPreparesCount();

      if($delayCount > 0)
      {
        if((!isset($this->_prepareDelayCount[$cacheKey]))
          || ($this->_prepareDelayCount[$cacheKey] < $delayCount)
        )
        {
          // perform an emulated prepare
          $this->_connection->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
          try
          {
            $stmt = $this->_connection->prepare($query);
          }
          finally
          {
            $this->_connection->setAttribute(
              \PDO::ATTR_EMULATE_PREPARES,
              false
            );
          }
          if(isset($this->_prepareDelayCount[$cacheKey]))
          {
            $this->_prepareDelayCount[$cacheKey]++;
          }
          else
          {
            $this->_prepareDelayCount[$cacheKey] = 1;
          }
        }
      }
    }

    if(!$stmt)
    {
      // Do a real prepare and cache the statement
      $stmt = $this->_connection->prepare($query);
      $this->_addCachedStmt($cacheKey, $stmt);
    }

    return $stmt;
  }

  /**
   * @param $query
   *
   * @return string
   */
  protected function _stmtCacheKey($query)
  {
    return md5($query) . strlen($query);
  }

  /**
   * @param string        $cacheKey
   * @param \PDOStatement $statement
   */
  protected function _addCachedStmt($cacheKey, $statement)
  {
    if($this->_maxPreparedStatements === null)
    {
      $this->_maxPreparedStatements = $this->_config()
        ->getItem('max_prepared_statements', 10);
    }

    if($this->_maxPreparedStatements > 0)
    {
      parent::_addCachedStmt($cacheKey, $statement);

      $connId = $this->_getConnectionId();
      if(isset(self::$_stmtCache[$connId]))
      {
        while(count(self::$_stmtCache[$connId]) > $this->_maxPreparedStatements)
        {
          array_shift(self::$_stmtCache[$connId]);
        }
      }
    }
  }

  protected function _recycleConnectionIfRequired()
  {
    if($this->isConnected())
    {
      if(($this->_lastConnectTime > 0) && (!$this->_inTransaction))
      {
        $recycleTime = (int)$this->_config()
          ->getItem('connection_recycle_time', 900);

        if(($recycleTime > 0)
          && ((time() - $this->_lastConnectTime) >= $recycleTime)
        )
        {
          $this->disconnect()->connect();
        }
      }
    }
    else
    {
      $this->connect();
    }
  }

  /**
   * @param            $query
   * @param array|null $values
   * @param null       $retries
   *
   * @return \PDOStatement
   * @throws PdoException
   */
  protected function _runQuery($query, array $values = null, $retries = null)
  {
    $this->_switchDatabase();

    return $this->_performWithRetries(
      function () use ($query, $values)
      {
        $stmt = $this->_getStatement($query);
        if($values)
        {
          $this->_bindValues($stmt, $values);
        }
        $stmt->execute();
        return $stmt;
      },
      function () use ($query)
      {
        $this->_deleteCachedStmt($this->_stmtCacheKey($query));
      },
      $retries
    );
  }

  /**
   * @param callable      $func
   * @param callable|null $onError
   * @param int|null      $retryCount
   *
   * @return mixed
   * @throws ConnectionException
   * @throws PdoException
   * @throws null
   */
  protected function _performWithRetries(
    callable $func, callable $onError = null, $retryCount = null
  )
  {
    $this->_lastRetryCount = 0;
    $this->_recycleConnectionIfRequired();

    if($retryCount === null)
    {
      $retryCount = (int)$this->_config()->getItem('retries', 2);
    }

    /** @var null|PdoException $exception */
    $exception = null;
    $retries = $retryCount;
    do
    {
      try
      {
        $this->_lastRetryCount++;
        return $func();
      }
      catch(\PDOException $sourceException)
      {
        if($onError)
        {
          $onError();
        }

        $exception = PdoException::from($sourceException);
        if($retries > 0 && $this->_isRecoverableException($exception))
        {
          if($this->_shouldReconnectAfterException($exception))
          {
            if($this->_inTransaction)
            {
              error_log(
                'PdoConnection error during transaction: '
                . '(' . $exception->getCode() . ') ' . $exception->getMessage()
              );
              throw $exception;
            }

            $this->disconnect()->connect();
          }
          else if($this->_shouldDelayAfterException($exception))
          {
            // Sleep for between 0.1 and 3 milliseconds
            usleep(mt_rand(100, 3000));
          }
        }
        else
        {
          error_log(
            'PdoConnection Error: (' . $exception->getCode() . ') '
            . $exception->getMessage()
          );
          throw $exception;
        }
      }
      $retries--;
    }
    while($retries > 0);

    if($exception)
    {
      throw $exception;
    }
    else
    {
      throw new PdoException(
        'An unknown error occurred performing a PDO operation. '
        . 'The operation failed after ' . $retryCount . ' retries'
      );
    }
  }

  /**
   * @param PdoException $e
   *
   * @return bool
   */
  private function _isRecoverableException(PdoException $e)
  {
    $code = $e->getPrevious()->getCode();
    if(($code === 0) || Strings::startsWith($code, 42))
    {
      return false;
    }
    return true;
  }

  /**
   * Should we delay for a random time before retrying this query?
   *
   * @param PdoException $e
   *
   * @return bool
   */
  private function _shouldDelayAfterException(PdoException $e)
  {
    // Deadlock errors: MySQL error 1213, SQLSTATE code 40001
    $codes = ['1213', '40001'];
    $p = $e->getPrevious();
    return
      in_array((string)$e->getCode(), $codes, true)
      || in_array((string)$p->getCode(), $codes, true);
  }

  /**
   * Should we reconnect to the database after this sort of error?
   *
   * @param PdoException $e
   *
   * @return bool
   */
  private function _shouldReconnectAfterException(PdoException $e)
  {
    // 2006  = MySQL server has gone away
    // 1047  = ER_UNKNOWN_COM_ERROR - happens when a PXC node is resyncing:
    //          "WSREP has not yet prepared node for application use"
    // HY000 = General SQL error
    $codes = ['2006', '1047', 'HY000'];
    $p = $e->getPrevious();
    return
      in_array((string)$e->getCode(), $codes, true)
      || ($p && in_array((string)$p->getCode(), $codes, true));
  }

  protected function _bindValues(\PDOStatement $stmt, array $values)
  {
    $i = 1;
    foreach($values as $value)
    {
      $type = $this->_pdoTypeForPhpVar($value);
      $stmt->bindValue($i, $value, $type);
      $i++;
    }
  }

  private function _pdoTypeForPhpVar(&$var)
  {
    $type = \PDO::PARAM_STR;
    if($var === null)
    {
      $type = \PDO::PARAM_NULL;
    }
    else if(is_bool($var))
    {
      $var = $var ? 1 : 0;
      $type = \PDO::PARAM_INT;
    }
    else if(is_int($var))
    {
      if($var >= pow(2, 31))
      {
        $type = \PDO::PARAM_STR;
      }
      else
      {
        $type = \PDO::PARAM_INT;
      }
    }
    return $type;
  }

  /**
   * Retrieve the last inserted ID
   *
   * @param string $name Name of the sequence object from which the ID should
   *                     be returned.
   *
   * @return mixed
   */
  public function getLastInsertId($name = null)
  {
    return $this->_connection->lastInsertId($name);
  }
}
