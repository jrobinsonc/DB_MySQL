<?php
namespace jrdev;

class DB_MySQL extends \MySQLi
{
    private $db_config = array();

    private $connected = false;

    private $last_error = '';

    private $tables = [];


    public function __construct()
    {
        $this->db_config = func_get_args();

        // The application does not connect to MySQL unless necessary to make a query.
        if (count($this->db_config) < 4)
            $this->error('Invalid number of connection parameters', true);

        if (! isset($this->db_config[4]))
            $this->db_config[4] = 3306;
    }

    private function _connect()
    {
        if (true === $this->connected)
            return true;

        list($host, $user, $pass, $database, $port) = $this->db_config;

        @parent::__construct($host, $user, $pass, $database, $port);

        if ($this->connect_error)
        {
            $this->error('MySQL Error: ' . $this->connect_errno . ' ' . $this->connect_error, true);

            return false;
        }

        // It's necessary for real_escape_string.
        if (false === $this->set_charset('utf8'))
        {
            $this->error('Error loading character set utf8: ' . $this->error);

            return false;
        }

        return $this->connected = true;
    }

    public function error($str = '', $fatal = false)
    {
        if ('' === $str) {
            return $this->last_error;
        }
        else {
            if (true === $fatal) {
                throw new \Exception($str);
            }
            else {
                $this->last_error = $str;
            }
        }
    }

    /**
     * Performs a generic query
     *
     * @param string $sql
     * @return DB_MySQL_Result
     */
    public function query($sql)
    {
        if (false === $this->_connect())
            return false;

        if (false === $this->real_query($sql))
        {
            $this->error('Error performing query ' . $sql . ' - Error message : ' . $this->error);

            return false;
        }

        return new DB_MySQL\Result($this);
    }

    /**
     * Performs a INSERT statement
     *
     * @param string $table_name
     * @param array $fields
     * @return int Returns the ID of the inserted row, or false on error
     */
    public function insert($table_name, $fields)
    {
        $sql = "INSERT INTO `$table_name`"
        . ' (`' . implode('`,`', array_keys($fields)) . '`)'
        . ' VALUES ';

        $prepared_fields = array();

        foreach ($fields as $field_name => $field_value)
            $prepared_fields[] = $this->escape($field_value, true);

        $sql .= '(' .implode(',', $prepared_fields) . ')';


        if (false === $this->query($sql))
            return false;
        else
            return $this->insert_id;
    }

    public function escape($str, $quoted = false)
    {
        $this->_connect(); // It's necessary for real_escape_string.

        $result = $this->real_escape_string($str);


        return true === $quoted && preg_match('#^-?[0-9\.]+$#', $str) !== 1? "'{$result}'" : $result;
    }

    private function parse_where($where)
    {
        if (is_array($where))
        {
            $fields = array();

            foreach ($where as $field_name => $field_value)
                $fields[] = "`{$field_name}` = " . $this->escape($field_value, true);

            $where_sql = implode(' AND ', $fields);

            $limit = null;
        }
        else
        {
            if (preg_match('#^-?[0-9]+$#', $where) === 1)
            {
                $where_sql = "`id` = {$where}";

                $limit = 1;
            }
            else
            {
                $where_sql = $where;

                $limit = null;
            }
        }


        return array($where_sql, $limit);
    }

    /**
     * Performs an UPDATE statement
     *
     * @param string $table_name The name of the table
     * @param array $fields The fields to update
     * @param mixed $where Accepts array, string and integer
     * @param int $limit (Optional) The limit of rows to update
     * @return int Returns the number of affected rows, or false on error
     */
    public function update($table_name, $fields, $where, $limit = null)
    {
        $sql = "UPDATE `{$table_name}` SET ";

        $prepared_fields = array();

        foreach ($fields as $field_name => $field_value)
            $prepared_fields[] = "`$field_name` = " . $this->escape($field_value, true);

        $sql .= implode(',', $prepared_fields);

        list($_where, $_limit) = $this->parse_where($where);
        $where = $_where;

        $sql .= " WHERE {$where}";

        if (null === $limit && null !== $_limit)
            $limit = $_limit;

        if (null !== $limit)
            $sql .= " LIMIT {$limit}";


        if (false === $this->query($sql))
            return false;
        else
            return $this->affected_rows;
    }

    /**
     * Performs a DELETE statement
     *
     * @param string $table_name The name of the table
     * @param string $where The where
     * @param int $limit (Optional) The limit
     * @return int Returns the number of affected rows, or false on error
     */
    public function delete($table_name, $where, $limit = null)
    {
        $sql = "DELETE FROM `{$table_name}`";

        list($_where, $_limit) = $this->parse_where($where);
        $where = $_where;

        $sql .= " WHERE {$where}";

        if (null === $limit && null !== $_limit)
            $limit = $_limit;

        if (null !== $limit)
            $sql .= " LIMIT {$limit}";


        if (false === $this->query($sql))
            return false;
        else
            return $this->affected_rows;
    }

    /**
     * Performs a SELECT statement
     *
     * @param string $table_name The name of the table
     * @param mixed $fields (Optional) The fields you want to obtain in the result. Accepts array or string
     * @param mixed $where (Optional) The where. Accepts array, string or intenger
     * @param string $order_by (Optional) The order by
     * @param int $limit (Optional) The limit
     * @return DB_MySQL_Result
     */
    public function select($table_name, $fields = null, $where = null, $order_by = null, $limit = null)
    {
        if (is_array($fields))
        {
            foreach ($fields as $key => $value)
            {
                $fields[$key] = "`{$value}`";
            }

            $fields = implode(',', $fields);
        } else if (is_null($fields)) {
            $fields = '*';
        }

        $sql = "SELECT {$fields} FROM `{$table_name}`";

        if (!is_null($where))
        {
            list($_where, $_limit) = $this->parse_where($where);
            $where = $_where;

            if (null === $limit && null !== $_limit)
                $limit = $_limit;

            $sql .= " WHERE {$where}";
        }

        if (!is_null($order_by))
        {
            $sql .= " ORDER BY {$order_by}";
        }

        if (!is_null($limit))
        {
            $sql .= " LIMIT {$limit}";
        }

        return $this->query($sql);
    }

    public function table($table_name, $table_args = [])
    {
        if (! isset($this->tables[$table_name]))
            $this->tables[$table_name] = new DB_MySQL_Table($this, $table_name, $table_args);

        return $this->tables[$table_name];
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Close the connection when instance is destroyed.
     */
    public function __destruct()
    {
        if (false === $this->connected)
            return;

        $this->close();
    }
}