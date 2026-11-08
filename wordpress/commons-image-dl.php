<?php
/**
 * Commons Image DL: WordPress theme bridge to the Python core.
 * Developed and coded by MENJ. Copyright (C) 2026 MENJ.
 * SPDX-License-Identifier: GPL-3.0-only
 */
namespace MENJ\CommonsImageDL;

if (!defined('ABSPATH')) {
    exit;
}

/** Load once from functions.php. No network requests occur during theme rendering. */
function register_tags() {
    register_taxonomy('commons_image_tag', array('attachment'), array(
        'label' => 'Commons image tags', 'public' => false, 'show_ui' => true,
        'show_in_rest' => true, 'hierarchical' => false, 'rewrite' => false,
        'capabilities' => array('manage_terms' => 'upload_files', 'edit_terms' => 'upload_files',
            'delete_terms' => 'upload_files', 'assign_terms' => 'upload_files'),
    ));
}
add_action('init', __NAMESPACE__ . '\\register_tags');

/** Remove only this invocation's private, flat working directory. */
function cleanup($directory) {
    foreach (glob($directory . '/*') ?: array() as $file) {
        if (is_file($file) || is_link($file)) {
            unlink($file);
        }
    }
    // Python can leave a partial file if the process was terminated.
    foreach (glob($directory . '/.commons-*') ?: array() as $file) {
        if (is_file($file) || is_link($file)) {
            unlink($file);
        }
    }
    rmdir($directory);
}

/** Execute the trusted Python core directly, without a shell. Internal bridge API. */
function run_core(array $arguments) {
    if (!current_user_can('upload_files')) {
        return new \WP_Error('commons_forbidden', 'Upload permission is required.');
    }
    $python = defined('COMMONS_IMAGE_DL_PYTHON') ? COMMONS_IMAGE_DL_PYTHON : '';
    $script = defined('COMMONS_IMAGE_DL_SCRIPT') ? COMMONS_IMAGE_DL_SCRIPT : dirname(__DIR__) . '/commons_image_dl.py';
    $python = $python ? realpath($python) : false;
    $script = realpath($script);
    if (!$python || !$script || !function_exists('proc_open') || !is_file($python) || !is_executable($python) || !is_file($script)) {
        return new \WP_Error('commons_python', 'Configure an absolute Python executable path and Python core script; proc_open must be enabled.');
    }
    $directory = rtrim(get_temp_dir(), '/\\') . '/commons-image-dl-' . bin2hex(random_bytes(16));
    if (!mkdir($directory, 0700)) {
        return new \WP_Error('commons_temp', 'Unable to create the private Python working directory.');
    }
    $process = null;
    $keep = false;
    try {
        $command = array_merge(array($python, $script), $arguments, array(
            '--output=' . $directory, '--formats', 'jpeg', 'png', 'webp', 'gif',
            '--user-agent=commons-image-dl WordPress (https://github.com/menj/commons-image-dl; ' . home_url('/') . ')'));
        $process = proc_open($command, array(0 => array('pipe', 'r'),
            1 => array('file', $directory . '/stdout.log', 'w'),
            2 => array('file', $directory . '/stderr.log', 'w')), $pipes, $directory, null, array('bypass_shell' => true));
        if (!is_resource($process)) {
            return new \WP_Error('commons_process', 'Could not start the Python core.');
        }
        fclose($pipes[0]);
        $deadline = microtime(true) + (defined('COMMONS_IMAGE_DL_TIMEOUT') ? max(1, (int) COMMONS_IMAGE_DL_TIMEOUT) : 300);
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                return new \WP_Error('commons_timeout', 'Python operation timed out. Retry in a background admin job or increase COMMONS_IMAGE_DL_TIMEOUT.');
            }
            usleep(50000);
        } while (true);
        $exit = $status['exitcode'];
        proc_close($process);
        $process = null;
        $manifests = glob($directory . '/manifest-*.json') ?: array();
        if (!in_array($exit, array(0, 1), true) || count($manifests) !== 1 || is_link($manifests[0])) {
            return new \WP_Error('commons_core', 'Python failed to produce a result. Check the configured Python environment and dependencies.');
        }
        $report = json_decode(file_get_contents($manifests[0]), true);
        if (!is_array($report) || !isset($report['images']) || !is_array($report['images'])) {
            return new \WP_Error('commons_manifest', 'Python returned an invalid manifest.');
        }
        $keep = true;
        return array('directory' => $directory, 'report' => $report);
    } finally {
        if (is_resource($process)) {
            proc_terminate($process, 9);
            proc_close($process);
        }
        if (!$keep) {
            cleanup($directory);
        }
    }
}

/** Search is a Python dry run. API pagination/filtering remain in the Python core. */
function search($query, $limit = 10, $language = 'en') {
    $query = trim((string) $query);
    if ($query === '') {
        return new \WP_Error('commons_query', 'A search phrase is required.');
    }
    $result = run_core(array('--query=' . $query, '--count=' . max(1, min(50, (int) $limit)),
        '--language=' . $language, '--dry-run'));
    if (is_wp_error($result)) {
        return $result;
    }
    try {
        return $result['report'];
    } finally {
        cleanup($result['directory']);
    }
}

/** Import one Commons page ID and return attachment ID or WP_Error. */
function import_image($page_id, $post_id = 0, $language = 'en') {
    if (!current_user_can('upload_files') || ($post_id && !current_user_can('edit_post', $post_id))) {
        return new \WP_Error('commons_forbidden', 'Upload and target post permissions are required.');
    }
    $page_id = absint($page_id);
    $post_id = absint($post_id);
    if (!$page_id || ($post_id && !get_post($post_id))) {
        return new \WP_Error('commons_id', 'A valid Commons page ID and target post are required.');
    }
    $result = run_core(array('--page-id=' . $page_id, '--count=1', '--include-tags', '--language=' . $language));
    if (is_wp_error($result)) {
        return $result;
    }
    try {
        $report = $result['report'];
        if (empty($report['complete']) || count($report['images']) !== 1) {
            return new \WP_Error('commons_download', 'Python could not download the requested original.', $report);
        }
        $image = $report['images'][0];
        $name = $image['local_file'] ?? '';
        $temp = $result['directory'] . '/' . $name;
        if (!$name || basename($name) !== $name || is_link($temp) || !is_file($temp)
            || (int) ($image['page_id'] ?? 0) !== $page_id
            || !hash_equals((string) ($image['sha256'] ?? ''), hash_file('sha256', $temp))) {
            return new \WP_Error('commons_integrity', 'Python output failed path or checksum verification.');
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $size = wp_getimagesize($temp);
        if (!$size || ($size['mime'] ?? '') !== $image['mime']
            || $size[0] !== $image['width'] || $size[1] !== $image['height']) {
            return new \WP_Error('commons_integrity', 'Downloaded image does not match Python metadata.');
        }
        $extensions = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif');
        $stem = pathinfo(preg_replace('/^File:/', '', $image['title']), PATHINFO_FILENAME);
        $file = array('name' => sanitize_file_name($page_id . '-' . $stem . '.' . $extensions[$image['mime']]),
            'tmp_name' => $temp, 'error' => 0, 'size' => filesize($temp));
        $upload = wp_handle_sideload($file, array('test_form' => false));
        if (isset($upload['error'])) {
            wp_delete_file($temp);
            return new \WP_Error('commons_upload', $upload['error']);
        }
        $caption = implode(' | ', array_filter(array($image['author'], $image['credit'],
            $image['license'], $image['license_url'], $image['description_url'])));
        $id = wp_insert_attachment(wp_slash(array('post_title' => preg_replace('/^File:/', '', $image['title']),
            'post_content' => $image['description'], 'post_excerpt' => $caption,
            'post_mime_type' => $upload['type'], 'post_status' => 'inherit')),
            $upload['file'], $post_id, true);
        if (is_wp_error($id)) {
            wp_delete_file($upload['file']);
            return $id;
        }
        // Do not invoke automatic scaling, re-encoding or EXIF rotation: keep original bytes.
        wp_update_attachment_metadata($id, array('width' => $size[0], 'height' => $size[1],
            'file' => _wp_relative_upload_path($upload['file']), 'filesize' => filesize($upload['file']),
            'sizes' => array(), 'image_meta' => wp_read_image_metadata($upload['file']) ?: array()));
        update_post_meta($id, '_commons_image_dl', wp_slash($image));
        if (!taxonomy_exists('commons_image_tag')) {
            register_tags();
        }
        $terms = wp_set_object_terms($id, $image['tags'], 'commons_image_tag');
        if (is_wp_error($terms)) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
            wp_delete_attachment($id, true);
            return $terms;
        }
        return $id;
    } finally {
        cleanup($result['directory']);
    }
}
