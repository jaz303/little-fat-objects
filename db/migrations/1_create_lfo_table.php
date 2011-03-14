<?php
class CreateLfoTable extends zing\db\Migration
{
    public function up() {
        $this->create_table('lfo_object', function($t) {
            $t->string('__object_class', array('null' => false));
            $t->string('__serialized_format', array('limit' => 20, 'null' => false));
            $t->text('__serialized_data', array('null' => false, 'mysql.size' => 'long'));
        });
    }
    
    public function down() {
        $this->drop_table('lfo_object');
    }
}
?>