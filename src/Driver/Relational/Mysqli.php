<?php

namespace Aternos\Model\Driver\Relational;

use Aternos\Model\Driver\QueryableDriverInterface;
use Aternos\Model\ModelInterface;
use Aternos\Model\Query\OrderField;
use Aternos\Model\Query\Query;
use Aternos\Model\Query\QueryResult;
use Aternos\Model\Query\SelectQuery;
use Aternos\Model\Query\WhereCondition;
use Aternos\Model\Query\WhereGroup;

/**
 * Class Mysqli
 *
 * Inherit this class, overwrite the connect function
 * and/or the protected connection specific properties
 * and register the new class in the driver factory
 * for other credentials or connect specifics
 *
 * @package Aternos\Model\Driver
 */
class Mysqli implements RelationalDriverInterface, QueryableDriverInterface
{
    /**
     * Host address
     *
     * @var string
     */
    protected $host = "";

    /**
     * Host port
     *
     * @var string
     */
    protected $port = 3306;

    /**
     * Authentication username
     *
     * @var string
     */
    protected $username = "";

    /**
     * Authentication password
     *
     * @var string
     */
    protected $password = "";

    /**
     * Socket path or pipe
     *
     * @var string
     */
    protected $socket = "";

    /**
     * Database name
     *
     * @var string
     */
    protected $database = "data";

    /**
     * @var \mysqli
     */
    protected $connection;

    /**
     * Connect to database
     */
    protected function connect()
    {
        if (!$this->connection || !@mysqli_ping($this->connection)) {
            $this->connection = mysqli_connect($this->host, $this->username, $this->password, $this->database, $this->port, $this->socket);
            if (!$this->connection) {
                throw new \Exception("Could not connect to Mysqli database. Error: " . mysqli_error($this->connection));
            }
        }
    }

    /**
     * Execute a mysql query
     *
     * @param string $query
     * @return bool|\mysqli_result
     * @throws \Exception
     */
    protected function rawQuery(string $query)
    {
        $this->connect();
        $result = mysqli_query($this->connection, $query);

        if (mysqli_error($this->connection)) {
            throw new \Exception("MySQLi Error #" . mysqli_errno($this->connection) . ": " . mysqli_error($this->connection));
        }

        return $result;
    }

    /**
     * Save the model
     *
     * @param ModelInterface $model
     * @return bool
     * @throws \Exception
     */
    public function save(ModelInterface $model): bool
    {
        $table = $model::getName();

        $modelValues = get_object_vars($model);
        $columns = [];
        $values = [];
        foreach ($modelValues as $key => $value) {
            $columns[] = "`" . $key . "`";
            if (is_numeric($value)) {
                $values[] = $value;
            } else {
                $values[] = "'" . mysqli_real_escape_string($this->connection, $value) . "'";
            }
        }

        $updates = [];
        foreach ($modelValues as $column => $modelValue) {
            if (is_numeric($modelValue)) {
                $updates[] = $column . "=" . $modelValue;
            } else {
                $updates[] = $column . "='" . mysqli_real_escape_string($this->connection, $modelValue) . "'";
            }
        }

        $query = "INSERT INTO " . $table . " (" . implode(",", $columns) . ") VALUES (" . implode(",", $values) . ") ON DUPLICATE KEY UPDATE " . implode(",", $updates);
        $this->rawQuery($query);

        return true;
    }

    /**
     * Get the model
     *
     * @param ModelInterface $model
     * @return bool
     * @throws \Exception
     */
    public function get(ModelInterface $model): bool
    {
        $table = $model::getName();

        $query = "SELECT * FROM " . $table . " WHERE " . $model->getIdField() . " = '" . $model->getId() . "'";
        $result = $this->rawQuery($query);
        if (!$result || mysqli_num_rows($result) === 0) {
            return false;
        }

        $row = mysqli_fetch_assoc($result);
        foreach ($row as $key => $value) {
            $model->{$key} = $value;
        }

        return true;
    }

    /**
     * Delete the model
     *
     * @param ModelInterface $model
     * @return bool
     * @throws \Exception
     */
    public function delete(ModelInterface $model): bool
    {
        $table = $model::getName();

        $query = "DELETE FROM " . $table . " WHERE " . $model->getIdField() . " = '" . $model->getId() . "'";
        $this->rawQuery($query);

        return true;
    }

    /**
     * Execute a SELECT, UPDATE or DELETE query
     *
     * @param Query $query
     * @return QueryResult
     * @throws \Exception
     */
    public function query(Query $query): QueryResult
    {
        $queryString = "";
        if ($query instanceof SelectQuery) {
            $queryString .= "SELECT ";
            if ($fields = $query->getFields()) {
                $queryString .= implode(", ", $fields);
            } else {
                $queryString .= "*";
            }

            $queryString .= " FROM " . $query->modelClassName::getName();
        }

        if ($where = $query->getWhere()) {
            $queryString .= " WHERE " . $this->generateWhere($where);
        }

        if ($orderFields = $query->getOrder()) {
            $queryString .= " " . $this->generateOrder($orderFields);
        }

        if ($limit = $query->getLimit()) {
            $queryString .= " LIMIT " . $limit->start . ", " . $limit->length;
        }

        $rawQueryResult = $this->rawQuery($queryString);

        $result = new QueryResult((bool)$rawQueryResult);
        while ($row = mysqli_fetch_assoc($rawQueryResult)) {
            /** @var ModelInterface $model */
            $model = new $query->modelClassName();
            foreach ($row as $key => $value) {
                $model->{$key} = $value;
            }
            $result->add($model);
        }

        return $result;
    }

    /**
     * Generate query from where conditions and groups
     *
     * @param WhereGroup|WhereCondition $where
     * @return string
     */
    private function generateWhere($where)
    {
        if ($where instanceof WhereCondition) {
            $value = mysqli_real_escape_string($this->connection, $where->value);

            if (!is_numeric($value)) {
                $value = "'" . $value . "'";
            }

            return "`" . $where->field . "` " . $where->operator . " " . $value;
        } elseif ($where instanceof WhereGroup) {
            switch ($where->conjunction) {
                case WhereGroup:: AND:
                    $conjunction = " AND ";
                    break;
                case WhereGroup:: OR:
                    $conjunction = " OR ";
                    break;
                default:
                    throw new \UnexpectedValueException("Invalid conjunction: " . $where->conjunction);
            }

            $whereStrings = [];
            foreach ($where as $wherePart) {
                $whereStrings[] = $this->generateWhere($wherePart);
            }

            return "(" . implode($conjunction, $whereStrings) . ")";
        }
    }

    /**
     * Generate query from order field definitions
     *
     * @param array $orderFields
     * @return string
     */
    private function generateOrder($orderFields)
    {
        $return = "ORDER BY";

        $formattedOrderFields = [];
        foreach ($orderFields as $orderField) {
            /** @var OrderField $orderField */
            switch ($orderField->direction) {
                case OrderField::ASCENDING:
                    $direction = "ASC";
                    break;
                case OrderField::DESCENDING:
                    $direction = "DESC";
                    break;
                default:
                    throw new \UnexpectedValueException("Invalid direction: " . $orderField->direction);
            }

            $formattedOrderFields[] = "`" . $orderField->field . "` " . $direction;
        }

        $return .= " " . implode(", ", $formattedOrderFields);

        return $return;
    }
}