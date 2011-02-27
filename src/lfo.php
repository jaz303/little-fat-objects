<?php
namespace lfo;

class RecordNotFoundException extends \Exception {}
class UnknownSerializationFormat extends \Exception {}
class UnknownIndexException extends \Exception {}
class QueryFailedException extends \Exception {}
class RollbackException extends \Exception {}

if (function_exists('lfo_configure')) {
    \lfo_configure();
}

class Gateway
{
    //
    // Serializer registry
    
    public static $serializers = array(
        'php'           => '\lfo\PHPSerializer'
    );
    
    public function serializer_for($type) {
        if (is_object($type)) {
            $type = $type->lfo_serialize_as();
        }
        if (!isset(self::$serializers[$type])) {
            throw new UnknownSerializationFormat($type);
        }
        $klass = self::$serializers[$type];
        return new $klass;
    }
    
    //
    // Instance registry
    
    public static $registry = array();
    public static function instance($id = null) {
        if (!$id) $id = 'default';
        if (!isset(self::$registry[$id])) {
            self::$registry[$id] = new self;
        }
        return self::$registry[$id];
    }
    
    //
    //
    
    // Table objects are stored in
    public $table_name          = 'object';
    
    // If true, all datetime objects will be converted to UTC before insertion
    // into the database.
    public $use_utc             = true;

    // Commented fields are virtual, created on demand by __get()
    // Alternatively, you can assign them yourself during configuration. This allows
    // use of an existing MySQL connection, or cached indexes.
    // public $mysql_link;
    // public $indexes;
    
    // Database connection settings
    // These are only necessary if a $mysql_link is not supplied manually.
    public $mysql_hostname      = 'localhost';
    public $mysql_username      = null;
    public $mysql_password      = null;
    public $mysql_database      = null;
    
    public static function configure($id, $lambda = null) {
        if ($lambda === null) {
            $lambda = $id;
            $id = null;
        }
        $instance = self::instance($id);
        $lambda($instance);
    }
    
    public function __get($k) {
        if ($k == 'indexes') {
            $this->load_indexes();
            return $this->indexes;
        } else if ($k == 'mysql_link') {
            $this->mysql_link = mysql_connect($this->mysql_hostname,
                                              $this->mysql_username,
                                              $this->mysql_password);
            mysql_select_db($this->mysql_database, $this->mysql_link);
            return $this->mysql_link;
        } else {
            throw new \Exception("Unknown config key: $k");
        }
    }
    
    public function transaction($lambda) {
        try {
            $this->x('BEGIN');
            if ($lambda() === false) {
                throw new RollbackException;
            } else {
                $this->x('COMMIT');
                return true;
            }
        } catch (RollbackException $re) {
            $this->x('ROLLBACK');
            return false;
        } catch (\Exception $e) {
            $this->x('ROLLBACK');
            throw $e;
        }
    }
    
    public function unserialize(&$row) {
        $data = $this->serializer_for($row['__serialized_format'])
                     ->unserialize($row['__serialized_data']);
        
        $class = $row['__object_class'];
        $instance = new $class;
        $instance->lfo_hydrate($row['id'], $data);
        
        return $instance;
    }
    
    public function open($id, $class = null) {
        $id = (int) $id;
        
        $sql = "SELECT * FROM {$this->table_name} WHERE id = " . $id;
        if ($class) {
            $sql .= " AND __object_class = " . $this->quote_string(ltrim($class, '\\'));
        }
        
        if ($row = mysql_fetch_assoc($this->q($sql))) {
            return $this->unserialize($row);
        } else {
            throw new RecordNotFoundException("couldn't find a row with ID = $id");
        }
    }
    
    public function query() {
        $q = new Query($this);
        if (func_num_args() > 0) {
            call_user_func_array(array($q, 'of'), func_get_args());
        }
        return $q;
    }
    
    public function create($object) {
        $insert = $this->quoted_fields_for_object($object);
        
        $sql = "
            INSERT INTO {$this->table_name}
                (" . implode(', ', array_keys($insert)) . ")
            VALUES
                (" . implode(', ', array_values($insert)) . ")";
        
        $this->x($sql);
        return mysql_insert_id();
    }
    
    public function update($object) {
        $update = $this->quoted_fields_for_object($object);
        
        $sets = array();
        foreach ($update as $k => $v) $sets[] = "$k = $v";
        
        $sql  = "UPDATE {$this->table_name} SET " . implode(', ', $sets);
        $sql .= " WHERE id = " . (int) $object->get_id();
        
        return $this->x($sql) > 0;
    }
    
    public function delete($id) {
        if (is_object($id)) $id = $id->get_id();
        return $this->x("DELETE FROM {$this->table_name} WHERE id = " . (int) $id) > 0;
    }
    
    public function quote_index_value($index, $value) {
        if ($index == 'id') {
            return (int) $value;
        }
        if (!isset($this->indexes[$index])) {
            throw new UnknownIndexException;
        }
        return $this->{"quote_{$this->indexes[$index]['type']}"}($value);
    }
    
    public function quote_boolean($v) {
        return $v === null ? 'NULL' : ($v ? '1' : '0');
    }
    
    public function quote_date($v) {
        if ($v === null) return 'NULL';
        if (!is_object($v)) $v = new \Date($v);
        if ($this->use_utc) $v = $v->to_utc();
        return $this->quote_string($v->iso_date());
    }
    
    public function quote_date_time($v) {
        if ($v === null) return 'NULL';
        if (!is_object($v)) $v = new \Date_Time($v);
        if ($this->use_utc) $v = $v->to_utc();
        return $this->quote_string($v->iso_date_time());
    }
    
    public function quote_integer($v) {
        return $v === null ? 'NULL' : (int) $v;
    }
    
    public function quote_float($v) {
        if ($v === null) return 'NULL';
        if (is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            return $v;
        } else {
            return (float) $v;
        }
    }
    
    public function quote_string($v) {
        return $v === null ? 'NULL' : ('\'' . mysql_real_escape_string($v, $this->mysql_link) . '\'');
    }
    
    //
    //
    // End Public Interface
    
    private function q($sql) {
        if (!($res = mysql_query($sql, $this->mysql_link))) {
            throw new QueryFailedException;
        }
        return $res;
    }
    
    private function x($sql) {
        if (!mysql_query($sql, $this->mysql_link)) {
            throw new QueryFailedException;
        }
        return mysql_affected_rows($this->mysql_link);
    }
    
    private function quoted_fields_for_object($object) {
        $serializer = $this->serializer_for($object);
        $serialized = $serializer->serialize($object->lfo_serialization_data());
        
        $fields = array(
            '__object_class'        => $this->quote_string(get_class($object)),
            '__serialized_data'     => $this->quote_string($serialized),
            '__serialized_format'   => $this->quote_string($object->lfo_serialize_as())
        );
        
        // TODO: extract index-reader to lambda
        foreach ($this->indexes as $field => $meta) {
            $getter = "get_{$field}";
            $value  = null;
            if (method_exists($object, $getter)) {
                $value = $object->$getter();
            } elseif (method_exists($object, '__call')) {
                try {
                    $value = $object->$getter();
                } catch (\Exception $e) {
                    // swallow; want to catch any exceptions raised by __call
                }
            }
            $fields[$field] = $this->{"quote_{$meta['type']}"}($value);
        }
        
        return $fields;
    }
    
    private static $index_type_map = array(
        'date'              => 'date',
        'datetime'          => 'date_time',
        
        'tinyint'           => 'boolean',
        
        'smallint'          => 'integer',
        'mediumint'         => 'integer',
        'int'               => 'integer',
        'bigint'            => 'integer',
        
        'float'             => 'float',
        'double'            => 'float',
        'decimal'           => 'float',
        
        'char'              => 'string',
        'varchar'           => 'string',
        'tinytext'          => 'string',
        'text'              => 'string',
        'mediumtext'        => 'string',
        'longtext'          => 'string'
    );
    
    private function load_indexes() {
        $this->indexes = array();
        $res = mysql_query("DESCRIBE " . $this->table_name);
        while ($row = mysql_fetch_assoc($res)) {
            $field = $row['Field'];
            $type = strtolower($row['Type']);
            if ($field == 'id' || ($field[0] == '_' && $field[1] == '_')) {
                continue;
            }
            if (($p = strpos($type, '(')) > 0) {
                $type = substr($type, 0, $p);
            }
            if (isset(self::$index_type_map[$type])) {
                $this->indexes[$field] = array('type' => self::$index_type_map[$type]);
            }
        }
    }
}

class Query implements \IteratorAggregate
{
    private $gateway        = null;
    private $classes        = array();
    private $conditions     = array();
    private $order          = array();
    
    public function __construct($gateway) {
        $this->gateway = $gateway;
    }
    
    public function of($classes) {
        foreach (func_get_args() as $classes) {
            foreach ((array) $classes as $class) {
                $this->classes[] = $class;
            }
        }
        return $this;
    }
    
    /**
     * Adds a condition to this query. Remember, you can only search on indexes.
     * Advantage of 2/3 arg variants is that they perform quoting based on known
     * index column type.
     *
     * All conditions added via where() will be ANDed together in final query.
     *
     * 1 arg: "username" - finds objects where username is present (i.e. NOT NULL)
     * 1 arg: "username = 'Jason'" - SQL literal
     * 2 args: "username", "Jason", generates "username = 'Jason'"
     * 3 args: "username", "<>", "Jason", generates "username <> 'Jason'"
     */
    public function where($field, $operator = null, $value = null) {
        $gateway = $this->gateway;
        if ($operator === null) {
            if (preg_match('/^\w+$/', $field)) {
                $this->conditions[] = "$field IS NOT NULL";
            } else {
                $this->conditions[] = $field;
            }
        } elseif ($value === null) {
            if (is_array($operator)) {
                $this->conditions[] = "$field IN (" . implode(', ', array_map(function($v) use ($field, $gateway) {
                    return $gateway->quote_index_value($field, $v);
                }, $operator)) . ')';
            } else {
                $this->conditions[] = "$field = " . $gateway->quote_index_value($field, $operator);
            }
        } else {
            $this->conditions[] = "$field $operator " . $gateway->quote_index_value($field, $value);
        }
        return $this;
    }
    
    public function order($field, $direction = 'ASC') {
        $this->order[] = $field . ' ' . strtoupper($direction);
        return $this;
    }
    
    public function to_sql() {
        $sql        = "SELECT * FROM {$this->gateway->table_name}";
        $gateway    = $this->gateway;
        $conditions = $this->conditions;
        
        if (count($this->classes) == 1) {
            $conditions[] = '__object_class = ' . $gateway->quote_string($this->classes[0]);
        } elseif (count($this->classes) > 1) {
            $conditions[] = '__object_class IN (' . implode(', ', array_map(function($c) use ($gateway) {
                return $gateway->quote_string($c);
            }, $this->classes)) . ')';
        }
        
        if (count($conditions)) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        
        if (count($this->order)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->order);
        }
        
        return $sql;
    }
    
    public function exec() {
        if (!($res = mysql_query($this->to_sql(), $this->gateway->mysql_link))) {
            throw new QueryFailedException;
        }
        return new Result($this->gateway, $res);
    }
    
    public function getIterator() {
        return $this->exec();
    }
}

class Result implements \Iterator, \Countable
{
    private $gateway;
    private $result;
    
    private $paginating         = false;
    private $page               = null;
    private $rpp                = null;
    
    public function __construct($gateway, $result) {
        $this->gateway = $gateway;
        $this->result = $result;
    }
    
    public function row_count() { return mysql_num_rows($this->result); }
    public function value($offset = 0) { return mysql_result($this->result, 0, $offset); }
    public function seek($offset) { mysql_data_seek($this->result, $offset); }
    public function free() { mysql_free_result($this->result); }
    
    public function paginate($rpp, $page = 1) {
        $this->paginating   = true;
        $this->rpp          = (int) $rpp;
        $this->page         = (int) $page;
        return $this;
    }
    
    public function page_count() {
        if ($this->paginating) {
            return ceil($this->row_count() / $this->rpp);
        } else {
            return $this->row_count() ? 1 : 0;
        }
    }
    
    public function page() { return $this->paginating ? $this->page : 1; }
    public function rpp() { return $this->rpp; }
    
    private $first_row_memo     = null;
    private $first_object_memo  = null;
    
    public function first_row() {
        if ($this->first_row_memo === null) {
            $this->first_row_memo = mysql_fetch_assoc($this->result);
        }
        return $this->first_row_memo;
    }
    
    public function first_object() {
        if ($this->first_object_memo === null) {
            $this->first_object_memo = $this->gateway->unserialize($this->first_row());
        }
        return $this->first_object_memo;
    }
    
    public function stack() {
        $out = array();
        foreach ($this as $v) $out[] = $v;
        return $out;
    }
    
    //
    // Iterator implementation

    private $index              = -1;
    private $limit              = null;
    private $current_row;
    private $current_row_memo   = null;

    public function rewind() {

        if ($this->paginating) {
            $start_index = ($this->page - 1) * $this->rpp;
            $this->limit = $this->rpp;
        } else {
            $start_index = 0;
            $this->limit = null;
        }

        if ($this->index == -1) {
            if ($start_index != 0) {
                $this->seek($start_index);
                $this->index = $start_index - 1;
            }
            $this->next();
        } elseif ($this->index == $start_index) {
            // Do nothing; iterator is in rewound state
        } else {
            $this->seek($start_index);
            $this->index = $start_index - 1;
            $this->next();
        }

    }

    public function current() {
        if (!$this->current_row_memo) {
            $this->current_row_memo = $this->gateway->unserialize($this->current_row);
        }
        return $this->current_row_memo;
    }
    
    public function key() { return $this->index; }
    public function valid() { return $this->current_row !== false; }
    public function count() { return $this->row_count(); }

    public function next() {
        if ($this->limit === 0) {
            $this->current_row = false;
        } else {
            $this->current_row = mysql_fetch_assoc($this->result);
            if ($this->limit !== null) $this->limit--;
        }
        $this->current_row_memo = null;
        $this->index++;
    }
}

class PHPSerializer
{
    public function serialize($data) {
        return serialize($data);
    }
    
    public function unserialize($data) {
        return unserialize($data);
    }
}
?>