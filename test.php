<?php
require 'src/datetime.php';
require 'src/lfo.php';

date_default_timezone_set('Europe/London');

\lfo\Gateway::configure(function($cfg) {
    $cfg->mysql_link = mysql_connect('127.0.0.1', 'root', '');
    mysql_select_db('lfo', $cfg->mysql_link);
});

class Person extends \lfo\OpenArrayObject
{
    public function get_full_name() {
        return $this->get_forename() . ' ' . $this->get_surname();
    }
}

$person = new Person;

$person->set_forename("Jason");
$person->set_surname("Frame");

$person->save();

var_dump($person->get_id());

$p2 = \lfo\Gateway::instance()->open($person->get_id());



$p2->set_forename('Edward');
$p2->set_role('Captain');

sleep(1);

$p2->save();

var_dump($p2);

?>