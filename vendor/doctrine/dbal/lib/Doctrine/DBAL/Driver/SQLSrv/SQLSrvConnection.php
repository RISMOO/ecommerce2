<?php

namespace Doctrine\DBAL\Driver\SQLSrv;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\ServerInfoAwareConnection;
use Doctrine\DBAL\ParameterType;

use function func_get_args;
use function is_float;
use function is_int;
use function sprintf;
use function sqlsrv_begin_transaction;
use function sqlsrv_commit;
use function sqlsrv_configure;
use function sqlsrv_connect;
use function sqlsrv_errors;
use function sqlsrv_query;
use function sqlsrv_rollback;
use function sqlsrv_rows_affected;
use function sqlsrv_server_info;
use function str_replace;

use const SQLSRV_ERR_ERRORS;

/**
 * SQL Server implementation for the Connection interface.
 */
class SQLSrvConnection implements Connection, ServerInfoAwareConnection
{
    /** @var resource */
    protected $conn;

    /** @var LastInsertId */
    protected $lastInsertId;

    /**
     * @param string  $serverName
     * @param mixed[] $connectionOptions
     *
     * @throws SQLSrvException
     */
    public function __construct($serverName, $connectionOptions)
    {
        if (! sqlsrv_configure('WarningsReturnAsErrors', 0)) {
            throw SQLSrvException::fromSqlSrvErrors();
        }

        $conn = sqlsrv_connect($serverName, $connectionOptions);

        if ($conn === false) {
            throw SQLSrvException::fromSqlSrvErrors();
        }

        $this->conn         = $conn;
        $this->lastInsertId = new LastInsertId();
    }

    /**
     * {@inheritdoc}
     */
    public function getServerVersion()
    {
        $serverInfo = sqlsrv_server_info($this->conn);

        return $serverInfo['SQLServerVersion'];
    }

    /**
     * {@inheritdoc}
     */
    public function requiresQueryForServerVersion()
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function prepare($sql)
    {
        return new SQLSrvStatement($this->conn, $sql, $this->lastInsertId);
    }

    /**
     * {@inheritDoc}
     */
    public function query()
    {
        $args = func_get_args();
        $sql  = $args[0];
        $stmt = $this->prepare($sql);
        $stmt->execute();

        return $stmt;
    }

    /**
     * {@inheritDoc}
     */
    public function quote($value, $type = ParameterType::STRING)
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return sprintf('%F', $value);
        }

        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * {@inheritDoc}
     */
    public function exec($sql)
    {
        $stmt = sqlsrv_query($this->conn, $sql);

        if ($stmt === false) {
            throw SQLSrvException::fromSqlSrvErrors();
        }

        $rowsAffected = sqlsrv_rows_affected($stmt);

        if ($rowsAffected === false) {
            throw SQLSrvException::fromSqlSrvErrors();
        }

        return $rowsAffected;
    }

    /**
     * {@inheritDoc}
     */
    public function lastInsertId($name = null)
    {
        if ($name !== null) {
            $stmt = $this->prepare('SELECT CONVERT(VARCHAR(MAX), current_value) FROM sys.sequences WHERE name = ?');
            $stmt->execute([$name]);
        } else {
            $stmt = $this->query('SELECT @@IDENTITY');
        }

        return $stmt->fetchColumn();
    }

    /**
     * {@inheritDoc}
     */
    public function beginTransaction()
    {
        if (! sqlsrv_begin_transaction($this->conn)) {
            throw SQLSrvException::fromSqlSrvErrors();
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function commit()
    {
        if (! sqlsrv_commit($this->conn)) {
            throw SQLSrvException::fromSqlSrvErrors();
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function rollBack()
    {
        if (! sqlsrv_rollback($this->conn)) {
            throw SQLSrvException::fromSqlSrvErrors();
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function errorCode()
    {
        $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
        if ($errors) {
            return $errors[0]['code'];
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function errorInfo()
    {
        return (array) sqlsrv_errors(SQLSRV_ERR_ERRORS);
    }
}
