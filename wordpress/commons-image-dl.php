<?php
/**
 * Commons Image DL: optional native WordPress theme integration.
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

function api(array $params) {
    $url = add_query_arg(array_merge(array(
        'action' => 'query', 'format' => 'json', 'formatversion' => 2, 'maxlag' => 5,
    ), $params), 'https://commons.wikimedia.org/w/api.php');
    $response = wp_safe_remote_get($url, array('timeout' => 30,
        'user-agent' => 'commons-image-dl WordPress (https://github.com/menj/commons-image-dl; ' . home_url('/') . ')'));
    if (is_wp_error($response)) {
        return $response;
    }
    if (200 !== wp_remote_retrieve_response_code($response)) {
        return new \WP_Error('commons_http', 'Commons request failed. Retry later.');
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data) || isset($data['error']) || !isset($data['query'])) {
        return new \WP_Error('commons_api', 'Commons returned an error or invalid response. Retry later.');
    }
    return $data;
}

function text($value) {
    return sanitize_text_field(html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/** Reject thumbnail, transformed, non-Commons and arbitrary caller-supplied URLs. */
function original_url($url) {
    $parts = wp_parse_url($url);
    return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
        && ($parts['host'] ?? '') === 'upload.wikimedia.org'
        && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
        && preg_match('~^/wikipedia/commons/[0-9a-f]/[0-9a-f]{2}/[^/]+$~D', $parts['path'] ?? '')
        && !isset($parts['query']) && !isset($parts['fragment']);
}

function record(array $page) {
    $info = $page['imageinfo'][0] ?? array();
    $mime = $info['mime'] ?? '';
    if (!in_array($mime, array('image/jpeg', 'image/png', 'image/webp', 'image/gif'), true)
        || !original_url($info['url'] ?? '')) {
        return new \WP_Error('commons_image', 'No supported original image is available.');
    }
    $raw = $info['extmetadata'] ?? array();
    $meta = array();
    foreach ($raw as $key => $value) {
        $meta[$key] = text($value['value'] ?? '');
    }
    return array('page_id' => (int) $page['pageid'], 'title' => text($page['title']),
        'original_url' => $info['url'], 'mime' => $mime,
        'width' => (int) ($info['width'] ?? 0), 'height' => (int) ($info['height'] ?? 0),
        'size' => (int) ($info['size'] ?? 0), 'source_sha1' => $info['sha1'] ?? '',
        'description_url' => 'https://commons.wikimedia.org/?curid=' . (int) $page['pageid'],
        'description' => $meta['ImageDescription'] ?? '', 'author' => $meta['Artist'] ?? '',
        'credit' => $meta['Credit'] ?? '', 'license' => $meta['LicenseShortName'] ?? '',
        'license_url' => esc_url_raw($raw['LicenseUrl']['value'] ?? ''),
        'usage_terms' => $meta['UsageTerms'] ?? '', 'raw_extmetadata' => $raw);
}

/** Return search results or WP_Error. The continuation object can be passed back unchanged. */
function search($query, $limit = 10, array $continuation = array(), $language = 'en') {
    if (!current_user_can('upload_files')) {
        return new \WP_Error('commons_forbidden', 'Upload permission is required.');
    }
    $query = trim((string) $query);
    if ($query === '') {
        return new \WP_Error('commons_query', 'A search phrase is required.');
    }
    $params = array('generator' => 'search', 'gsrnamespace' => 6, 'gsrsearch' => $query,
        'gsrlimit' => max(1, min(50, (int) $limit)), 'prop' => 'imageinfo',
        'iiprop' => 'url|size|mime|sha1|extmetadata', 'iiextmetadatalanguage' => $language);
    // Continuation cannot override API actions or the image source.
    foreach (array('continue', 'gsroffset') as $key) {
        if (isset($continuation[$key])) {
            $params[$key] = $continuation[$key];
        }
    }
    $data = api($params);
    if (is_wp_error($data)) {
        return $data;
    }
    $pages = $data['query']['pages'] ?? array();
    usort($pages, function ($a, $b) { return ($a['index'] ?? 0) <=> ($b['index'] ?? 0); });
    $images = array();
    foreach ($pages as $page) {
        $image = record($page);
        if (!is_wp_error($image)) {
            $images[] = $image;
        }
    }
    return array('images' => $images, 'continue' => $data['continue'] ?? array());
}

/** Fetch authoritative metadata and every non-hidden category before downloading. */
function details($page_id, $language) {
    $params = array('pageids' => $page_id, 'prop' => 'imageinfo|categories',
        'iiprop' => 'url|size|mime|sha1|extmetadata', 'iiextmetadatalanguage' => $language,
        'clshow' => '!hidden', 'cllimit' => 'max');
    $image = null;
    $tags = array();
    $seen = array();
    do {
        $data = api($params);
        if (is_wp_error($data)) {
            return $data;
        }
        $page = $data['query']['pages'][0] ?? array();
        if (null === $image) {
            $image = record($page);
            if (is_wp_error($image)) {
                return $image;
            }
        }
        foreach ($page['categories'] ?? array() as $category) {
            $tag = text(preg_replace('/^Category:/', '', $category['title']));
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }
        $next = $data['continue'] ?? array();
        $token = wp_json_encode($next);
        if ($next && isset($seen[$token])) {
            return new \WP_Error('commons_continue', 'Commons repeated a continuation token.');
        }
        $seen[$token] = true;
        $params = array_merge($params, $next);
    } while ($next);
    $image['tags'] = array_values(array_unique($tags));
    return $image;
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
    $image = details($page_id, $language);
    if (is_wp_error($image)) {
        return $image;
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $temp = download_url($image['original_url'], 300);
    if (is_wp_error($temp)) {
        return $temp;
    }
    $size = wp_getimagesize($temp);
    if (!$size || ($size['mime'] ?? '') !== $image['mime']
        || $size[0] !== $image['width'] || $size[1] !== $image['height']
        || ($image['size'] && filesize($temp) !== $image['size'])) {
        wp_delete_file($temp);
        return new \WP_Error('commons_integrity', 'Downloaded original does not match Commons metadata.');
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
}
