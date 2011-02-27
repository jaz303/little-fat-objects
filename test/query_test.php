<?php
class QueryTest extends LFOTest
{
    public function setup() {
        parent::setup();
        $this->q = new \lfo\Query($this->gateway);
    }
    
    public function test_default_query() {
        $this->assert_sql();
    }
    
    public function test_one_class() {
        $this->q->of('Foo');
        $this->assert_sql("WHERE __object_class = 'Foo'");
    }
    
    public function test_multiple_classes() {
        $this->q->of(array('Foo', 'Bar'), 'Baz', 'Bleem');
        $this->assert_sql("WHERE __object_class IN ('Foo', 'Bar', 'Baz', 'Bleem')");
    }
    
    public function test_order_single() {
        $this->q->order('created', 'asc');
        $this->assert_sql("ORDER BY created ASC");
    }
    
    public function test_order_multi() {
        $this->q->order('age', 'desc');
        $this->q->order('created', 'asc');
        $this->assert_sql("ORDER BY age DESC, created ASC");
    }
    
    public function test_condition_presence() {
        $this->q->where('full_name');
        $this->assert_sql("WHERE full_name IS NOT NULL");
    }
    
    public function test_condition_fragment() {
        $this->q->where("full_name = 'quux'");
        $this->assert_sql("WHERE full_name = 'quux'");
    }
    
    public function test_condition_field_value() {
        $this->q->where("created_at", new Date_Time(2011, 10, 02, 14, 0, 0));
        $this->assert_sql("WHERE created_at = '2011-10-02T14:00:00'");
    }
    
    public function test_condition_field_array_value() {
        $this->q->where("full_name", array('foo', 'bar', 'quux'));
        $this->assert_sql("WHERE full_name IN ('foo', 'bar', 'quux')");
    }
    
    public function test_condition_field_operator_value() {
        $this->q->where("is_awesome", "<>", false);
        $this->assert_sql("WHERE is_awesome <> 0");
    }
    
    public function test_composite_query() {
        $this->q->of('Foo', 'Bar')
                ->where('is_awesome', true)
                ->where('full_name', 'Prince Valiant')
                ->order('full_name')
                ->order('score', 'desc');
        $this->assert_sql("WHERE is_awesome = 1 AND full_name = 'Prince Valiant' AND __object_class IN ('Foo', 'Bar') ORDER BY full_name ASC, score DESC");
    }
    
    public function test_condition_with_ids() {
        $this->q->where('id', array(1, 2, 3, 4, 5));
        $this->assert_sql("WHERE id IN (1, 2, 3, 4, 5)");
    }
    
    public function test_getIterator_returns_result_object() {
        ensure($this->q->getIterator() instanceof \lfo\Result);
    }
    
    private function assert_sql($sql = '') {
        $sql = "SELECT * FROM " . LFO_TABLE_NAME . ($sql ? " {$sql}" : '');
        assert_equal($sql, $this->q->to_sql());
    }
}
?>