<?php
/**
 * MySQLi layer for DBO
 *
 * PHP versions 4 and 5
 *
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright 2005-2012, Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright 2005-2012, Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @package       cake
 * @subpackage    cake.cake.libs.model.datasources.dbo
 * @since         CakePHP(tm) v 1.1.4.2974
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
App::import('Datasource', 'DboMysqlBase');

/**
 * MySQLi DBO driver object
 *
 * Provides connection and SQL generation for MySQL RDMS using PHP's MySQLi Interface
 *
 * @package       cake
 * @subpackage    cake.cake.libs.model.datasources.dbo
 */
class DboMysqli extends DboMysqlBase
{
    /**
     * Datasource Description
     *
     * @var string
     */
    public $description = "Mysqli DBO Driver";

    /**
     * @var \mysqli|false
     */
    public $connection;

    /**
     * Base configuration settings for Mysqli driver
     *
     * @var array
     */
    public $_baseConfig = [
        'persistent' => true,
        'host' => 'localhost',
        'login' => 'root',
        'password' => '',
        'database' => 'cake',
        'port' => '3306',
        'socket' => null,
        'use_ssl' => false,
        'ssl_verify' => false,
        'sql_modes' => [
            'NO_ZERO_IN_DATE',
            'NO_ZERO_DATE',
            'ERROR_FOR_DIVISION_BY_ZERO',
            'NO_ENGINE_SUBSTITUTION',
            'NO_AUTO_CREATE_USER',
        ],
    ];

    /**
     * Connects to the database using options in the given configuration array.
     *
     * @return boolean True if the database could be connected, else false
     */
    public function connect(): bool
    {
        $config = $this->config;
        $this->connected = false;

        $flags = 0;

        $db = mysqli_init();
        if ($config['use_ssl']) {
            // Require SSL/TLS to be used
            $flags = $flags | MYSQLI_CLIENT_SSL;

            // Disable verification of server certificate
            if (!$config['ssl_verify']) {
                $flags = $flags | MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
            }
        }

        $host = $config['host'];

        // Prefix host with 'p:' to force a persistent connection
        if (
            ($config['persistent'] ?? true)
            // might be unix socket
            && !empty($config['host'])
            // persistent connection might already be forced
            && stripos($config['host'], 'p:') === false
        ) {
            $host = 'p:' . $host;
        }
        
        $this->connection = $db;
        $this->connected = @$db->real_connect(
            $host,
            $config['login'],
            $config['password'],
            $config['database'],
            $config['port'],
            $config['socket'],
            $flags
        );

        if (!$this->connected) {
            return false;
        }

        $this->connected = true;

        $this->_useAlias = ($this->connection->server_version >= 40100);

        if (!empty($config['encoding'])) {
            $this->setEncoding($config['encoding']);
        }

        if (!empty($config['sql_modes'])) {
            if (!$this->setSQLMode($config['sql_modes'])) {
                $this->connected = false;
                return false;
            }
        }

        return $this->connected;
    }

    /**
     * Sets the database encoding
     *
     * @param string $enc Database encoding
     */
    public function setEncoding($enc)
    {
        return $this->connection->set_charset($enc);
    }

    /**
     * Check that MySQLi is installed/enabled
     *
     * @return boolean
     */
    public function enabled()
    {
        return extension_loaded('mysqli');
    }
    /**
     * Disconnects from database.
     *
     * @return boolean True if the database could be disconnected, else false
     */
    public function disconnect()
    {
        if (isset($this->results) && is_resource($this->results)) {
            mysqli_free_result($this->results);
        }
        $this->connected = !@mysqli_close($this->connection);
        return !$this->connected;
    }

    /**
     * Sets the database SQL Mode
     *
     * @param string $mode Database encoding
     */
    public function setSQLMode(array $modes): bool
    {
        // MySQL >= 8.0 has removed support for SQL_MODE NO_AUTO_CREATE_USER
        // Remove NO_AUTO_CREATE_USER from sql_mode if present
        if (
            $this->connection->server_version >= 80000
            && in_array('NO_AUTO_CREATE_USER', $modes)
        ) {
            $modes = array_diff($modes, ['NO_AUTO_CREATE_USER']);
        }

        $mode = implode(',', array_map('trim', $modes));
        return $this->_execute('SET SQL_MODE = ' . $this->value($mode)) != false;
    }

    /**
     * Executes given SQL statement.
     *
     * @param string $sql SQL statement
     * @return resource Result resource identifier
     * @access protected
     */
    public function _execute($sql)
    {
        if (preg_match('/^\s*call/i', $sql)) {
            return $this->_executeProcedure($sql);
        }
        return mysqli_query($this->connection, $sql);
    }

    /**
     * Executes given SQL statement (procedure call).
     *
     * @param string $sql SQL statement (procedure call)
     * @return resource Result resource identifier for first recordset
     * @access protected
     */
    public function _executeProcedure($sql)
    {
        $answer = mysqli_multi_query($this->connection, $sql);

        $firstResult = mysqli_store_result($this->connection);

        if (mysqli_more_results($this->connection)) {
            while ($lastResult = mysqli_next_result($this->connection));
        }
        return $firstResult;
    }

    /**
     * Returns an array of sources (tables) in the database.
     *
     * @return array Array of tablenames in the database
     */
    public function listSources($data = null)
    {
        $cache = parent::listSources();
        if ($cache !== null) {
            return $cache;
        }
        $result = $this->_execute('SHOW TABLES FROM ' . $this->name($this->config['database']) . ';');

        if (!$result) {
            return array();
        }

        $tables = array();

        while ($line = mysqli_fetch_row($result)) {
            $tables[] = $line[0];
        }
        parent::listSources($tables);
        return $tables;
    }

    /**
     * Returns a quoted and escaped string of $data for use in an SQL statement.
     *
     * @param string $data String to be prepared for use in an SQL statement
     * @param string $column The column into which this data will be inserted
     * @param boolean $safe Whether or not numeric data should be handled automagically if no column data is provided
     * @return string Quoted and escaped data
     */
    public function value($data, $column = null, $safe = false)
    {
        $parent = parent::value($data, $column, $safe);

        if ($parent != null) {
            return $parent;
        }
        if ($data === null || (is_array($data) && empty($data))) {
            return 'NULL';
        }
        if ($data === '' && $column !== 'integer' && $column !== 'float' && $column !== 'boolean') {
            return "''";
        }
        if (empty($column)) {
            $column = $this->introspectType($data);
        }

        switch ($column) {
            case 'boolean':
                return $this->boolean((bool)$data);
                break;
            case 'integer' :
            case 'float' :
            case null :
                if ($data === '') {
                    return 'NULL';
                }
                if (is_float($data)) {
                    return str_replace(',', '.', strval($data));
                }
                if ((is_int($data) || is_float($data) || $data === '0') || (
                    is_numeric($data) && strpos($data, ',') === false &&
                    $data[0] != '0' && strpos($data, 'e') === false
                )) {
                    return $data;
                }
                // no break
            default:
                $data = "'" . mysqli_real_escape_string($this->connection, $data) . "'";
                break;
        }

        return $data;
    }

    /**
     * Returns a formatted error message from previous database operation.
     *
     * @return string Error message with error number
     */
    public function lastError()
    {
        if ($this->connection->errno) {
            return $this->connection->errno . ': ' . $this->connection->error;
        }
        return null;
    }

    /**
     * Returns number of affected rows in previous database operation. If no previous operation exists,
     * this returns false.
     *
     * @return integer Number of affected rows
     */
    public function lastAffected()
    {
        if ($this->_result) {
            return mysqli_affected_rows($this->connection);
        }
        return null;
    }

    /**
     * Returns number of rows in previous resultset. If no previous resultset exists,
     * this returns false.
     *
     * @return integer Number of rows in resultset
     */
    public function lastNumRows()
    {
        if ($this->hasResult()) {
            return mysqli_num_rows($this->_result);
        }
        return null;
    }

    /**
     * Returns the ID generated from the previous INSERT operation.
     *
     * @param unknown_type $source
     * @return in
     */
    public function lastInsertId($source = null)
    {
        $id = $this->fetchRow('SELECT LAST_INSERT_ID() AS insertID', false);
        if ($id !== false && !empty($id) && !empty($id[0]) && isset($id[0]['insertID'])) {
            return $id[0]['insertID'];
        }
        return null;
    }

    /**
     * Enter description here...
     *
     * @param unknown_type $results
     */
    public function resultSet(&$results)
    {
        if (isset($this->results) && is_resource($this->results) && $this->results != $results) {
            mysqli_free_result($this->results);
        }
        $this->results = & $results;
        $this->map = array();
        $numFields = mysqli_num_fields($results);
        $index = 0;
        $j = 0;
        while ($j < $numFields) {
            $column = mysqli_fetch_field_direct($results, $j);
            if (!empty($column->table) && strpos($column->name, $this->virtualFieldSeparator) === false) {
                $this->map[$index++] = array($column->table, $column->name);
            } else {
                $this->map[$index++] = array(0, $column->name);
            }
            $j++;
        }
    }

    /**
     * Fetches the next row from the current result set
     *
     * @return unknown
     */
    public function fetchResult()
    {
        if ($row = mysqli_fetch_row($this->results)) {
            $resultRow = array();
            foreach ($row as $index => $field) {
                $table = $column = null;
                if (count($this->map[$index]) === 2) {
                    list($table, $column) = $this->map[$index];
                }
                $resultRow[$table][$column] = $row[$index];
            }
            return $resultRow;
        }
        return false;
    }

    /**
     * Gets the database encoding
     *
     * @return string The database encoding
     */
    public function getEncoding()
    {
        if (!$this->connection) {
            return false;
        }

        return $this->connection->character_set_name();
    }

    /**
     * Query charset by collation
     *
     * @param string $name Collation name
     * @return string Character set name
     */
    public function getCharsetName($name)
    {
        if ((bool)version_compare(mysqli_get_server_info($this->connection), "5", ">=")) {
            $cols = $this->query('SELECT CHARACTER_SET_NAME FROM INFORMATION_SCHEMA.COLLATIONS WHERE COLLATION_NAME= ' . $this->value($name) . ';');
            if (isset($cols[0]['COLLATIONS']['CHARACTER_SET_NAME'])) {
                return $cols[0]['COLLATIONS']['CHARACTER_SET_NAME'];
            }
        }
        return false;
    }

    /**
     * Checks if the result is valid
     *
     * @return boolean True if the result is valid, else false
     */
    public function hasResult()
    {
        return is_object($this->_result);
    }
}
