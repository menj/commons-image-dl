<?php
/** Regression tests for the optional WordPress adapter, with WordPress API doubles. */
$root = sys_get_temp_dir() . '/commons-wp-' . uniqid() . '/';
mkdir($root . 'wp-admin/includes', 0777, true);
foreach (array('file', 'image', 'post') as $name) { file_put_contents($root . 'wp-admin/includes/' . $name . '.php', '<?php'); }
define('ABSPATH', $root);
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
function wp_safe_remote_get($url, $args) {
    $GLOBALS['requests'][] = $url;
    if (!$GLOBALS['responses']) { throw new Exception('Unexpected HTTP request'); }
    return array_shift($GLOBALS['responses']);
}
function wp_remote_retrieve_response_code($r) { return $r['status']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function wp_strip_all_tags($v) { return strip_tags($v); }
function sanitize_text_field($v) { return trim($v); }
function wp_parse_url($url) { return parse_url($url); }
function esc_url_raw($v) { return $v; }
function wp_json_encode($v) { return json_encode($v); }
function wp_slash($v) { return $v; }
function sanitize_file_name($v) { return basename($v); }
function download_url($url, $timeout) {
    $GLOBALS['download_url'] = $url;
    $file = ABSPATH . 'download.tmp'; file_put_contents($file, $GLOBALS['payload']); return $file;
}
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
use function MENJ\CommonsImageDL\original_url;
use function MENJ\CommonsImageDL\search;
use function MENJ\CommonsImageDL\details;
use function MENJ\CommonsImageDL\import_image;
function check($condition, $message) { if (!$condition) { throw new Exception($message); } $GLOBALS['checks']++; }
function response($page, $next = array()) {
    return array('status' => 200, 'body' => json_encode(array('query' => array('pages' => array($page)), 'continue' => $next)));
}
$checks = 0; $allowed = true; $responses = array(); $requests = array(); $upload_fail = false; $term_fail = false;
$payload = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aIOsAAAAASUVORK5CYII=');
$url = 'https://upload.wikimedia.org/wikipedia/commons/a/ab/Test.png';
$page = array('pageid' => 123, 'title' => 'File:Test.png', 'index' => 1,
    'imageinfo' => array(array('url' => $url, 'mime' => 'image/png', 'width' => 1, 'height' => 1,
        'size' => strlen($payload), 'sha1' => 'example', 'thumburl' => 'https://example.test/thumb.png',
        'extmetadata' => array('ImageDescription' => array('value' => '<p>Original description</p>'),
            'Artist' => array('value' => 'Example author'), 'LicenseShortName' => array('value' => 'CC BY-SA 4.0')))),
    'categories' => array(array('title' => 'Category:Penang')));
try {
    check(original_url($url), 'Original rejected');
    foreach (array('http://upload.wikimedia.org/wikipedia/commons/a/ab/Test.png',
        'https://evil.test/wikipedia/commons/a/ab/Test.png',
        'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Test.png/200px-Test.png',
        'https://user@upload.wikimedia.org/wikipedia/commons/a/ab/Test.png') as $bad) {
        check(!original_url($bad), 'Unsafe or thumbnail URL accepted');
    }
    $allowed = false;
    check(is_wp_error(search('Penang')), 'Anonymous search accepted');
    check(is_wp_error(import_image(123)), 'Anonymous import accepted');
    check(count($requests) === 0, 'Anonymous action accessed network');
    $allowed = true;
    check(is_wp_error(import_image(0)), 'Invalid ID accepted');
    check(is_wp_error(import_image(123, 999)), 'Missing parent accepted');
    $responses = array(response($page));
    $found = search('Penang', 10, array('prop' => 'revisions', 'gsroffset' => 20));
    check($found['images'][0]['original_url'] === $url, 'Thumbnail selected');
    parse_str(parse_url(end($requests), PHP_URL_QUERY), $params);
    check($params['prop'] === 'imageinfo' && $params['gsroffset'] === '20', 'Continuation overwrote API query');
    check(!isset($params['iiurlwidth']), 'Thumbnail requested');
    $second = array('pageid' => 123, 'categories' => array(array('title' => 'Category:Mosques'), array('title' => 'Category:Penang')));
    $responses = array(response($page, array('continue' => '||', 'clcontinue' => 'next')), response($second));
    $image = details(123, 'en');
    check($image['tags'] === array('Penang', 'Mosques'), 'Category continuation or deduplication failed');
    check($image['description'] === 'Original description', 'Description missing');
    $responses = array(response($page, array('continue' => '||', 'clcontinue' => 'same')), response($second, array('continue' => '||', 'clcontinue' => 'same')));
    check(is_wp_error(details(123, 'en')), 'Repeated continuation not rejected');
    $responses = array(array('status' => 429, 'body' => '{}'));
    check(is_wp_error(search('Penang')), 'HTTP error ignored');
    $responses = array(response($page));
    $id = import_image(123);
    check($id === 42, 'Import failed');
    check(file_get_contents($uploaded) === $payload, 'Original bytes changed');
    check($download_url === $url, 'Wrong file downloaded');
    check($attachment['post_content'] === 'Original description', 'Attachment description missing');
    check(strpos($attachment['post_excerpt'], 'CC BY-SA 4.0') !== false, 'Attribution missing');
    check($saved_tags === array('Penang'), 'Attachment tags missing');
    check($attachment_meta['sizes'] === array(), 'Unwanted resizing');
    check($saved_meta['original_url'] === $url, 'Source metadata missing');
    $upload_fail = true; $responses = array(response($page));
    check(is_wp_error(import_image(123)), 'Upload failure ignored');
    check(!file_exists(ABSPATH . 'download.tmp'), 'Temporary file leaked');
    $upload_fail = false; $term_fail = true; $responses = array(response($page));
    check(is_wp_error(import_image(123)), 'Tag failure ignored');
    check(!file_exists($uploaded), 'Failed attachment not removed');
    echo "$checks WordPress adapter checks passed\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABSPATH, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir(ABSPATH);
}
