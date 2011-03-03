<?php
set_time_limit(0);

require 'classes/datetime.php';
require 'classes/lfo.php';
require 'classes/lfo_object.php';

//
// Configurable bits of stuff

define('LFO_TABLE_NAME', 'lfo_object');
$link = mysql_connect('127.0.0.1', 'root', '') or die("Could not connect to MySQL\n");
mysql_select_db('lfo') or die("Could not select database\n");

//
//
//

date_default_timezone_set('UTC');

mysql_query("DROP TABLE IF EXISTS lfo_object");
mysql_query("
    CREATE TABLE `" . LFO_TABLE_NAME . "` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `__object_class` varchar(255) NOT NULL,
      `__serialized_format` varchar(20) NOT NULL,
      `__serialized_data` longtext NOT NULL,
      
      -- Test indexes
      full_name varchar(255) NULL,
      date_of_birth date NULL,
      created_at datetime NULL,
      updated_at datetime NULL,
      salary int NULL,
      score float NULL,
      is_awesome tinyint(1) NULL,
      
      PRIMARY KEY (`id`)
    );
");

//
// Here's the testing mojo ->

// adjust this to point to wherever ztest is located
require dirname(__FILE__) . '/vendor/ztest/ztest.php';

class LFOTest extends \ztest\UnitTestCase
{
    public function setup() {
        mysql_query("TRUNCATE TABLE " . LFO_TABLE_NAME);
        $this->gateway = \lfo\Gateway::instance();
    }
}

\lfo\Gateway::configure(function($g) use ($link) {
    $g->mysql_link = $link;
    $g->table_name = LFO_TABLE_NAME;
});

$suite = new ztest\TestSuite("LFO Test Suite");

// Recursively scan the 'test' directory and require() all PHP source files
$suite->require_all('test');

// Add non-abstract subclasses of ztest\TestCase as test-cases to be run
$suite->auto_fill();

// Create a reporter and enable color output
$reporter = new ztest\ConsoleReporter;
$reporter->enable_color();

// Go, go, go
$suite->run($reporter);
?>