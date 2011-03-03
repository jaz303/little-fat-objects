<?php
namespace lfo;

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
    
    //
    //
    
    /**
     * Find an object of this class by ID
     */
    public static function find($id) {
        return $this->lfo->open($id, get_called_class());
    }
    
    /**
     * Return a query object restricted to instances of this class.
     *
     * Example:
     * Person::all()->where('forename', 'Jason')
     */
    public static function all() {
        return $this->lfo->query(get_called_class());
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
        return $value;
    }
}
?>