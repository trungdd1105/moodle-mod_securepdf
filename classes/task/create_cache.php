<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Create cache task for securepdf
 *
 * @package    mod_securepdf
 * @copyright  2021 Yedidia Klein <yedidia@openapp.co.il>
 * @since      Moodle 3.1
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_securepdf\task;

defined('MOODLE_INTERNAL') || die();

class create_cache extends \core\task\adhoc_task
{
    public function execute() {
        $data = $this->get_custom_data();
        $moduleid = $data->moduleid;
        // Init cache object.
        $cache = \cache::make('mod_securepdf', 'pages');
        // Read Module file.
        $context = \context_module::instance($moduleid);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_securepdf', 'content', 0, 'sortorder', false);
        $file = null;
        foreach ($files as $f) {
            if (!$f->is_directory()) {
                $file = $f;
                break;
            }
        }
        
        if (!$file) {
            return;
        }

        $settings = get_config('securepdf');
        $resolution = !empty($settings->resolution) ? $settings->resolution : 150;

        $tmpdir = make_request_directory();
        $tmpfname = $tmpdir . '/document.pdf';
        
        $file->copy_content_to($tmpfname);

        $out_pattern = $tmpdir . '/page_%d.jpg';
        $gs_path = file_exists('/usr/bin/gs') ? '/usr/bin/gs' : (file_exists('/usr/local/bin/gs') ? '/usr/local/bin/gs' : 'gs');
        
        $gs_cmd = $gs_path . " -dSAFER -dBATCH -dNOPAUSE -dNumRenderingThreads=4 -sDEVICE=jpeg -dJPEGQ=85 -r{$resolution} -sOutputFile=" . escapeshellarg($out_pattern) . " -q -f " . escapeshellarg($tmpfname) . " 2>&1";
        
        exec($gs_cmd, $output, $return_var);
        if ($return_var !== 0) {
            echo "[mod_securepdf] Ghostscript failed (code $return_var): \n" . implode("\n", $output) . "\n";
        }

        $page = 0;
        while (file_exists($tmpdir . '/page_' . ($page + 1) . '.jpg')) {
            echo '[mod_securepdf] Caching page ' . $page . ' of module ' . $moduleid . "\n";
            $img = file_get_contents($tmpdir . '/page_' . ($page + 1) . '.jpg');
            $base64 = base64_encode($img);
            $cache->set($moduleid . '_' . $page, $base64);
            unlink($tmpdir . '/page_' . ($page + 1) . '.jpg');
            $page++;
        }
        
        $cache->set($moduleid, $page);

        if (file_exists($tmpfname)) {
            unlink($tmpfname);
        }
    }
}
