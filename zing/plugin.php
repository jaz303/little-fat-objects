<?php
namespace lfo;

class ZingPlugin extends \zing\plugin\Plugin
{
    public function post_install() {
        
        $source = <<<SOURCE
function lfo_configure() {
    \\lfo\\Gateway::configure(function(\$g) {
        \$db = GDB::instance();
        \$g->mysql_link = \$db->get_mysql_link();
        \$g->table_name = 'lfo_object';
    });
}
SOURCE;
        
        \zing\sys\SourceBlockWriter::append_block_to_file(
            ZING_CONFIG_DIR . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'main.php',
            'plugin.jaz303.little-fat-objects',
            $source
        );
    }
}
?>