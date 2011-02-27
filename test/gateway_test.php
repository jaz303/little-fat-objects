<?php
// get_id
// lfo_serialize_as
// lfo_serialization_data
// lfo_hydrate

class GatewayTestObject
{
    public $id;
    public $forename;
    public $surname;
    public $score;
    
    public function get_id() { return $this->id; }
    
    public function __construct($fo = null, $su = null, $sc = null) {
        $this->forename = $fo;
        $this->surname = $su;
        $this->score = $sc;
    }
    
    public function lfo_serialize_as() { return 'php'; }
    public function lfo_serialization_data() {
        return array(
            'forename' => $this->forename,
            'surname'  => $this->surname,
            'score' => $this->score
        );
    }
    
    public function lfo_hydrate($id, $data) {
        $this->id = $id;
        $this->forename = $data['forename'];
        $this->surname = $data['surname'];
        $this->score = $data['score'];
    }
    
    // indexes
    public function get_full_name() { return "{$this->forename} {$this->surname}"; }
    public function get_score() { return $this->score; }
    public function get_is_awesome() { return true; }
}

class GatewayTest extends LFOTest
{
    public function test_indexes() {
        $ix = $this->gateway->indexes;
        
        assert_equal(7, count($ix));
        assert_equal('string',      $ix['full_name']['type']);
        assert_equal('date',        $ix['date_of_birth']['type']);
        assert_equal('date_time',   $ix['created_at']['type']);
        assert_equal('date_time',   $ix['updated_at']['type']);
        assert_equal('integer',     $ix['salary']['type']);
        assert_equal('float',       $ix['score']['type']);
        assert_equal('boolean',     $ix['is_awesome']['type']);
    }
    
    public function test_index_quoting() {
        $gw = $this->gateway;
        assert_equal("'foo'",                   $gw->quote_index_value('full_name', 'foo'));
        assert_equal("'2011-02-26'",            $gw->quote_index_value('date_of_birth', new Date(2011, 2, 26)));
        assert_equal("'2011-02-28T14:00:23'",   $gw->quote_index_value('created_at', new Date_Time(2011, 2, 28, 14, 0, 23)));
        assert_equal("500",                     $gw->quote_index_value('salary', 500));
        assert_equal("4.5",                     $gw->quote_index_value('score', 4.5));
        assert_equal("1",                       $gw->quote_index_value('is_awesome', true));
    }
    
    public function test_unserialize() {
        
        $hsh = array(
            'id'                        => 15,
            '__object_class'            => 'GatewayTestObject',
            '__serialized_format'       => 'php',
            '__serialized_data'         => serialize(array('forename' => 'Jason', 'surname' => 'Frame', 'score' => 3.0))
        );
        
        $me = $this->gateway->unserialize($hsh);
        
        ensure($me instanceof GatewayTestObject);
        assert_equal(15,        $me->id);
        assert_equal('Jason',   $me->forename);
        assert_equal('Frame',   $me->surname);
        assert_equal(3.0,       $me->score);
        
    }
    
    public function test_create() {
        
        $count_before = $this->count_lfo_objects();
        
        $me = new GatewayTestObject();
        $me->forename = 'Jason';
        $me->surname = 'Frame';
        $me->score = 5.0;
        
        $id = $this->gateway->create($me);
        $row = mysql_fetch_assoc(mysql_query("SELECT * FROM " . LFO_TABLE_NAME . " WHERE id = $id"));
        
        //
        // Ensure row has been added to DB
        
        assert_equal($count_before + 1, $this->count_lfo_objects());
        
        //
        // Index population
        
        assert_equal('Jason Frame',             $row['full_name']);
        assert_null($row['date_of_birth']);
        assert_null($row['created_at']);
        assert_null($row['updated_at']);
        assert_null($row['salary']);
        assert_equal(5.0,                       $row['score']);
        assert_equal(1,                         $row['is_awesome']);
        
        //
        // Ensure same data is read out back into object
        
        $evil_twin = $this->gateway->unserialize($row);
        
        ensure($evil_twin instanceof GatewayTestObject);
        assert_equal($id,               $evil_twin->id);
        assert_equal('Jason',           $evil_twin->forename);
        assert_equal('Frame',           $evil_twin->surname);
        assert_equal(5.0,               $evil_twin->score);
    
    }
    
    public function test_open_when_record_exists() {
        $some_dude = $this->create_some_dude();
        $dude = $this->gateway->open($some_dude->id);
        $this->assert_some_dude($dude, $some_dude->id);
    }
    
    public function test_open_with_class_restriction_when_record_exists() {
        $some_dude = $this->create_some_dude();
        $dude = $this->gateway->open($some_dude->id, 'GatewayTestObject');
        $this->assert_some_dude($dude, $some_dude->id);
    }
    
    public function test_open_when_record_does_not_exist() {
        $gw = $this->gateway;
        assert_throws('lfo\RecordNotFoundException', function() use ($gw) {
            $gw->open(1000000);
        });
    }
    
    public function test_open_with_incorrect_class_restriction_record_exists() {
        $sd = $this->create_some_dude();
        $gw = $this->gateway;
        assert_throws('lfo\RecordNotFoundException', function() use ($sd, $gw) {
            $gw->open($sd->id, 'FooBarBazModel');
        });
    }
    
    public function test_update() {
        $dude = $this->create_some_dude();
        
        $dude->forename = 'Henry';
        $dude->surname = 'VIII';
        $dude->score = 2.6;
        
        //
        // Check no rows inserted/deleted by update
        
        $count_before = $this->count_lfo_objects();
        $this->gateway->update($dude);
        assert_equal($count_before, $this->count_lfo_objects());
        
        $row = mysql_fetch_assoc(mysql_query("SELECT * FROM " . LFO_TABLE_NAME . " WHERE id = $dude->id"));
        
        //
        // Check indexes are updated appropriately
        
        assert_equal('Henry VIII', $row['full_name']);
        assert_equal(2.6, $row['score']);
        assert_equal(1, $row['is_awesome']);
        
        //
        // Check data unserializes correctly
        
        $return_of_the_king = $this->gateway->unserialize($row);
        
        ensure($return_of_the_king instanceof GatewayTestObject);
        assert_equal('Henry', $return_of_the_king->forename);
        assert_equal('VIII', $return_of_the_king->surname);
        assert_equal(2.6, $return_of_the_king->score);
        
    }
    
    public function test_delete() {
        $gw = $this->gateway;
        $dude = $this->create_some_dude();
        $count_before = $this->count_lfo_objects();
        $gw->delete($dude->id);
        assert_equal($count_before - 1, $this->count_lfo_objects());
        assert_throws('lfo\RecordNotFoundException', function() use ($gw) {
            $gw->open($dude->id);
        });
    }
    
    private function count_lfo_objects() {
        return mysql_result(mysql_query("SELECT COUNT(*) FROM " . LFO_TABLE_NAME), 0);
    }
    
    private function create_some_dude() {
        $some_dude = new GatewayTestObject('Some', 'Dude', 3.2);
        $some_dude->id = $this->gateway->create($some_dude);
        return $some_dude;
    }
    
    private function assert_some_dude($dude, $id) {
        ensure($dude instanceof GatewayTestObject);
        assert_equal($id,               $dude->id);
        assert_equal('Some Dude',       $dude->get_full_name());
    }
}
?>