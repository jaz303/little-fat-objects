<?php
namespace lfo;

class RecordNotFoundException extends \Exception {}
class UnknownSerializationFormat extends \Exception {}
class QueryFailedException extends \Exception {}
class RollbackException extends \Exception {}

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
    
    public function load_indexes() {
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
    
    public function open($id) {
        $id     = (int) $id;
        $sql    = "SELECT * FROM {$this->table_name} WHERE id = " . $id;
        $row    = mysql_fetch_assoc($this->query($sql));
        
        if ($row) {
            return $this->unserialize($row);
        } else {
            throw new RecordNotFoundException("couldn't find a row with ID = $id");
        }
    }
    
    public function unserialize(&$row) {
        $data = $this->serializer_for($row['__serialized_format'])
                     ->unserialize($row['__serialized_data']);
        
        $class = $row['__object_class'];
        $instance = new $class;
        $instance->lfo_hydrate($id, $data);
        
        return $instance;
    }
    
    public function transaction($lambda) {
        try {
            $this->exec('BEGIN');
            if ($lambda() === false) {
                throw new RollbackException;
            } else {
                $this->exec('COMMIT');
                return true;
            }
        } catch (RollbackException $re) {
            $this->exec('ROLLBACK');
            return false;
        } catch (\Exception $e) {
            $this->exec('ROLLBACK');
            throw $e;
        }
    }
    
    public function create($object) {
        $insert = $this->quoted_fields_for_object($object);
        
        $sql = "
            INSERT INTO {$this->table_name}
                (" . implode(', ', array_keys($insert)) . ")
            VALUES
                (" . implode(', ', array_values($insert)) . ")";
        
        $this->exec($sql);
        return mysql_insert_id();
    }
    
    public function update($object) {
        $update = $this->quoted_fields_for_object($object);
        
        $sets = array();
        foreach ($update as $k => $v) $sets[] = "$k = $v";
        
        $sql  = "UPDATE {$this->table_name} SET " . implode(', ', $sets);
        $sql .= " WHERE id = " . (int) $object->get_id();
        
        return $this->exec($sql) > 0;
    }
    
    public function delete($object) {
        return $this->exec("DELETE FROM {$this->table_name} WHERE id = " . (int) $object->get_id()) > 0;
    }
    
    //
    //
    // End Public Interface
    
    private function quote_boolean($v) {
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
    
    private function quote_integer($v) {
        return $v === null ? 'NULL' : (int) $v;
    }
    
    private function quote_float($v) {
        if ($v === null) return 'NULL';
        if (is_string($v) && preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            return $v;
        } else {
            return (float) $v;
        }
    }
    
    private function quote_string($v) {
        return $v === null ? 'NULL' : ('\'' . mysql_real_escape_string($v, $this->mysql_link) . '\'');
    }
    
    private function query($sql) {
        if (!($res = mysql_query($sql, $this->mysql_link))) {
            throw new QueryFailedException;
        }
        return $res;
    }
    
    private function exec($sql) {
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
}

class Object
{
    public function __get($k) {
        if ($k === 'lfo') {
            $klass = get_called_class();
            $this->lfo = $klass::lfo_instance();
            return $this->lfo;
        }
    }
    
    public static function lfo_instance() {
        return \lfo\Gateway::instance(static::lfo_instance_id());
    }
    
    public static function lfo_instance_id() {
        return null;
    }
    
    /**
     * Override in subclasses to use a different serialization format
     */
    public static function lfo_serialize_as() {
        return 'php';
    }
    
    private $id             = null;
    private $created_at     = null;
    private $updated_at     = null;
    
    public function get_id() { return $this->id; }
    protected function set_id($id) { $this->id = $id === null ? null : (int) $id; }
    
    public function get_created_at() { return $this->created_at; }
    protected function set_created_at($ca) { $this->created_at = $ca; }
    
    public function get_updated_at() { return $this->updated_at; }
    protected function set_updated_at($ua) { $this->updated_at = $ua; }
    
    public function is_saved() { return $this->id !== null; }
    public function is_new_record() { return $this->id === null; }
    
    public function is_valid() {
        $this->validate_prepare();
        $this->before_validate();
        if ($this->is_new_record()) {
            $this->before_validate_on_create();
        } else {
            $this->before_validate_on_update();
        }
        return $this->perform_validate();
    }
    
    public function save() {
        if (!$this->is_valid()) {
            return false;
        }
        
        $self = $this;
        $id_before = $this->id;
        $created_at_before = $this->created_at;
        $updated_at_before = $this->updated_at;
        
        $success = $this->lfo->transaction(function() use ($self) {
            if ($self->send('invoke_callbacks', 'before_save') === false) {
                return false;
            }
            
            if ($self->is_new_record()) {
                $now = new \Date_Time;
                $self->send('set_created_at', $now);
                $self->send('set_updated_at', $now);
                if ($self->send('invoke_callbacks', 'before_save_on_create') === false) {
                    return false;
                }
                $self->send('set_id', $self->lfo->create($self));
                if ($self->send('invoke_callbacks', 'after_save_on_create') === false) {
                    return false;
                }
            } else {
                $self->send('set_updated_at', new \Date_Time);
                if ($self->send('invoke_callbacks', 'before_save_on_update') === false) {
                    return false;
                }
                $self->lfo->update($self);
                if ($self->send('invoke_callbacks', 'after_save_on_update') === false) {
                    return false;
                }
            }
            
            if ($self->send('invoke_callbacks', 'after_save') === false) {
                return false;
            }
            
            return true;
        });
        
        if (!$success) {
            $this->id = $id_before;
            $this->created_at = $created_at_before;
            $this->updated_at = $updated_at_before;
        }
        
        return $success;
    }
    
    public function delete() {
        $self = $this;
        return $this->lfo->transaction(function() use ($self) {
            if ($self->send('invoke_callbacks', 'before_delete') === false) {
                return false;
            }
            if ($self->lfo->delete($this) === false) {
                return false;
            }
            if ($self->send('invoke_callbacks', 'after_delete') === false) {
                return false;
            }
            return true;
        });
    }
    
    public function send() {
        $args = func_get_args();
        $method = array_shift($args);
        return call_user_func_array(array($this, $method), $args);
    }
    
    //
    // Validation
    
    protected function validate_prepare() {}
    protected function perform_validate() {
        return true;
    }
    
    //
    // Hooks
    
    protected function invoke_callbacks($chain) {
        return $this->$chain();
    }
    
    protected function before_validate() {}
    protected function before_validate_on_create() {}
    protected function before_validate_on_update() {}

    protected function before_save() {}
    protected function before_save_on_create() {}
    protected function before_save_on_update() {}
    
    protected function after_save() {}
    protected function after_save_on_create() {}
    protected function after_save_on_update() {}
    
    protected function before_delete() {}
    protected function after_delete() {}
    
}

class ArrayObject extends Object
{
    protected $attributes = array();
    
    public function lfo_serialization_data() {
        return array(
            'attributes'        => $this->attributes,
            'created_at'        => $this->get_created_at(),
            'updated_at'        => $this->get_updated_at()
        );
    }
    
    public function lfo_hydrate($id, $data) {
        $this->set_id($id);
        $this->set_created_at($data['created_at']);
        $this->set_updated_at($data['updated_at']);
        $this->attributes = $data['attributes'];
    }
}

class OpenArrayObject extends ArrayObject
{
    public function __call($method, $args) {
        if (preg_match('/^(get|set|is)_(\w+)/', $method, $m)) {
            if ($method[0] == 'g') {
                return $this->read_attribute($m[2]);
            } elseif ($method[0] == 's') {
                return $this->write_attribute($m[2], $args[0]);
            } elseif ($method[0] == 'i') {
                return (bool) $this->read_attribute($m[2]);
            }
        }
    }
    
    public function read_attribute($key, $default = null) {
        return isset($this->attributes[$key])
            ? $this->attributes[$key]
            : $default;
    }
    
    public function write_attribute($key, $value) {
        $this->attributes[$key] = $value;
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