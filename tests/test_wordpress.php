<?php
/** PHP-to-Python integration tests: real subprocess and core, mocked HTTP/WordPress APIs. */
$root = sys_get_temp_dir() . '/commons-wp-' . uniqid() . '/';
mkdir($root . 'wp-admin/includes', 0777, true);
foreach (array('file', 'image', 'post') as $name) { file_put_contents($root . 'wp-admin/includes/' . $name . '.php', '<?php'); }
define('ABSPATH', $root);
define('COMMONS_IMAGE_DL_PYTHON', getenv('COMMONS_TEST_PYTHON'));
define('COMMONS_IMAGE_DL_SCRIPT', __DIR__ . '/fixtures/wordpress_core.py');
define('COMMONS_IMAGE_DL_TIMEOUT', 2);
function get_temp_dir() { return ABSPATH; }
class WP_Error { public $code; public function __construct($code, $message = '') { $this->code = $code; } }
function is_wp_error($v) { return $v instanceof WP_Error; }
function add_action(...$args) {}
function register_taxonomy(...$args) {}
function taxonomy_exists($name) { return true; }
function current_user_can(...$args) { return $GLOBALS['allowed']; }
function get_post($id) { return $id === 999 ? null : (object) array('ID' => $id); }
function absint($v) { return abs((int) $v); }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function home_url($path) { return 'https://example.test/'; }
function wp_safe_remote_get(...$args) { throw new Exception('PHP must not access Commons'); }
function wp_remote_retrieve_response_code($r) { return $r['status']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function wp_strip_all_tags($v) { return strip_tags($v); }
function sanitize_text_field($v) { return trim($v); }
function wp_parse_url($url) { return parse_url($url); }
function esc_url_raw($v) { return $v; }
function wp_json_encode($v) { return json_encode($v); }
function wp_slash($v) { return $v; }
function sanitize_file_name($v) { return basename($v); }
function download_url(...$args) { throw new Exception('Only Python may download'); }
function wp_getimagesize($path) { return getimagesize($path); }
function wp_delete_file($file) { if (file_exists($file)) { unlink($file); } }
function wp_handle_sideload($file, $args) {
    if ($GLOBALS['upload_fail']) { return array('error' => 'Upload failed'); }
    $target = ABSPATH . $file['name']; rename($file['tmp_name'], $target);
    $GLOBALS['uploaded'] = $target;
    return array('file' => $target, 'type' => 'image/png', 'url' => 'https://example.test/image.png');
}
function wp_insert_attachment($post, $path, $parent, $error) { $GLOBALS['attachment'] = $post; return 42; }
function wp_update_attachment_metadata($id, $meta) { $GLOBALS['attachment_meta'] = $meta; }
function _wp_relative_upload_path($path) { return basename($path); }
function wp_read_image_metadata($path) { return array(); }
function update_post_meta($id, $key, $value) { $GLOBALS['saved_meta'] = $value; }
function wp_set_object_terms($id, $tags, $taxonomy) {
    $GLOBALS['saved_tags'] = $tags;
    return $GLOBALS['term_fail'] ? new WP_Error('terms') : array(1);
}
function wp_delete_attachment($id, $force) { wp_delete_file($GLOBALS['uploaded']); }
require dirname(__DIR__) . '/wordpress/commons-image-dl.php';
use function MENJ\CommonsImageDL\search;
use function MENJ\CommonsImageDL\import_image;
function check($condition, $message) { if (!$condition) { throw new Exception($message); } $GLOBALS['checks']++; }
function response($page, $next = array()) {
    return array('status' => 200, 'body' => json_encode(array('query' => array('pages' => array($page)), 'continue' => $next)));
}
$checks = 0; $allowed = false; $upload_fail = false; $term_fail = false;
try {
    check(is_wp_error(search('Penang')), 'Anonymous search accepted');
    check(is_wp_error(import_image(123)), 'Anonymous import accepted');
    $allowed = true;
    check(is_wp_error(import_image(0)), 'Invalid ID accepted');
    check(is_wp_error(import_image(123, 999)), 'Missing parent accepted');
    $query = 'Penang; $(touch /tmp/commons-shell-injection)';
    $found = search($query, 1);
    check(!is_wp_error($found), 'Python search failed');
    check($found['images'][0]['discovery_source'] === $query, 'Query did not reach Python intact');
    check(!file_exists('/tmp/commons-shell-injection'), 'Shell injection');
    check($found['dry_run'] === true, 'Search did not use Python dry-run');
    check(strpos($found['images'][0]['original_url'], '/thumb/') === false, 'Thumbnail selected');
    $failure = search('__failure__', 1);
    check($failure['complete'] === false && $failure['error'] === 'Fixture API failure', 'Python failure lost');
    check(is_wp_error(search('__timeout__', 1)), 'Process timeout ignored');
    check(is_wp_error(import_image(404)), 'Failed Python import accepted');
    $id = import_image(123);
    check($id === 42, 'Import failed');
    check(hash_file('sha256', $uploaded) === $saved_meta['sha256'], 'Python original bytes changed');
    check($attachment['post_content'] === 'Original description', 'Description missing');
    check(strpos($attachment['post_excerpt'], 'CC BY-SA 4.0') !== false, 'Attribution missing');
    check($saved_tags === array('Penang'), 'Python tags missing');
    check($attachment_meta['sizes'] === array(), 'Unwanted resizing');
    check($saved_meta['status'] === 'downloaded', 'Python did not download');
    $upload_fail = true;
    check(is_wp_error(import_image(123)), 'Upload failure ignored');
    $upload_fail = false; $term_fail = true;
    check(is_wp_error(import_image(123)), 'Tag failure ignored');
    check(!file_exists($uploaded), 'Failed attachment not removed');
    check(glob(ABSPATH . 'commons-image-dl-*') === array(), 'Private job files leaked');
    echo "$checks PHP-to-Python bridge checks passed\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABSPATH, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir(ABSPATH);
}
