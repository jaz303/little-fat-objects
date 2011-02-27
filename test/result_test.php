<?php
class ResultTestObject
{
    public $id;
    public function get_id() { return $this->id; }
    
    public function lfo_serialize_as() { return 'php'; }
    public function lfo_serialization_data() { return array(); }
    public function lfo_hydrate($id, $data) { $this->id = $id; }
}

class ResultTest extends LFOTest
{
    public function setup() {
        parent::setup();
        
        $this->o1 = $this->create_test_object();
        $this->o2 = $this->create_test_object();
        $this->o3 = $this->create_test_object();
        $this->o4 = $this->create_test_object();
        $this->o5 = $this->create_test_object();
        $this->o6 = $this->create_test_object();
        
        $this->q = new \lfo\Query($this->gateway);
        $this->q->order('id');
        $this->res = $this->q->exec();
    }
    
    public function test_row_count_is_correct() {
        assert_equal(6, count($this->res));
        assert_equal(6, $this->res->row_count());
    }
    
    public function test_single_result_can_be_accessed() {
        assert_equal($this->o1->id, $this->res->value('id'));
    }
    
    public function test_page_count_is_1_when_not_paginating() {
        assert_equal(1, $this->res->page_count());
    }
    
    public function test_page_is_1_when_not_paginating() {
        assert_equal(1, $this->res->page());
    }
    
    public function test_first_row_returns_first_row() {
        $row = $this->res->first_row();
        assert_equal($this->o1->id, $row['id']);
    }
    
    public function test_first_object_returns_first_object() {
        $obj = $this->res->first_object();
        ensure($obj instanceof ResultTestObject);
        assert_equal($this->o1->id, $obj->id);
    }
    
    // this implicitly tests iterator implementation
    public function test_stacking_objects_when_not_paginating() {
        $stack = $this->res->stack();
        assert_equal(6, count($stack));
        assert_equal($this->o1->id,         $stack[0]->id);
        assert_equal($this->o2->id,         $stack[1]->id);
        assert_equal($this->o3->id,         $stack[2]->id);
        assert_equal($this->o4->id,         $stack[3]->id);
        assert_equal($this->o5->id,         $stack[4]->id);
        assert_equal($this->o6->id,         $stack[5]->id);
    }
    
    //
    // Pagination
    
    public function test_page_count_when_paginating() {
        $this->res->paginate(4, 1);
        assert_equal(2, $this->res->page_count());
    }
    
    public function test_page_and_rpp_are_reported_correctly_when_paginating() {
        $this->res->paginate(4, 1);
        assert_equal(1, $this->res->page());
        assert_equal(4, $this->res->rpp());
    }
    
    public function test_stacking_objects_when_paginating_page_1() {
        $this->res->paginate(4, 1);
        $stack = $this->res->stack();
        assert_equal(4, count($stack));
        assert_equal($this->o1->id,         $stack[0]->id);
        assert_equal($this->o2->id,         $stack[1]->id);
        assert_equal($this->o3->id,         $stack[2]->id);
        assert_equal($this->o4->id,         $stack[3]->id);
    }
    
    public function test_stacking_objects_when_paginating_page_2() {
        $this->res->paginate(4, 2);
        $stack = $this->res->stack();
        assert_equal(2, count($stack));
        assert_equal($this->o5->id,         $stack[0]->id);
        assert_equal($this->o6->id,         $stack[1]->id);
    }
    
    //
    // Empty Result
    
    public function test_empty_result() {
        $empty = new \lfo\Result($this->gateway, mysql_query("SELECT * FROM " . LFO_TABLE_NAME . " WHERE 1 = 0"));
        assert_equal(0, $empty->row_count());
        assert_equal(0, $empty->page_count());
        assert_equal(0, count($empty->stack()));
    }
    
    //
    //
    
    private function create_test_object() {
        $obj = new ResultTestObject;
        $obj->id = $this->gateway->create($obj);
        return $obj;
    }
}
?>