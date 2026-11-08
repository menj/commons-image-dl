# WordPress theme integration

Developed and coded by **MENJ**. Licensed under GPL-3.0-only.

The Python CLI remains the standalone downloader with its existing commands and
batch workflows. `wordpress/commons-image-dl.php` is an **additional PHP adapter**
for themes. It calls Commons directly through WordPress APIs; it does not replace,
execute or require Python. Use PHP 8.2 or newer. No specific WordPress release
number is embedded in the integration.

## Load in a theme

Copy `wordpress/commons-image-dl.php` to your theme's
`inc/commons-image-dl.php` and load it once from `functions.php`:

```php
require_once get_theme_file_path('/inc/commons-image-dl.php');
```

Load only one copy if a parent theme already includes the adapter. Keep the
copyright header and distribute the repository's LICENSE with the theme module.

Loading the file registers the `commons_image_tag` attachment taxonomy on `init`.
It performs no HTTP requests or downloads. The adapter is a developer API, not a
Media Library search modal or a complete theme settings screen.

## Search for images

Call from an authenticated admin workflow after WordPress has initialized:

```php
$result = \MENJ\CommonsImageDL\search('Penang mosques', 10);
if (is_wp_error($result)) {
    // Present the error in your admin UI, escaped with esc_html().
    return;
}
foreach ($result['images'] as $image) {
    // Use page_id for import; escape descriptions/titles before displaying them.
    // original_url always points to the original, never a Commons thumbnail.
}

// When nonempty, pass continuation to fetch the next batch with the same query.
if ($result['continue']) {
    $next = \MENJ\CommonsImageDL\search('Penang mosques', 10, $result['continue']);
}
```

`search($query, $limit = 10, $continuation = [], $language = 'en')` returns
`images` and `continue`, or `WP_Error`. The limit is 1–50 API candidates;
unsupported files are filtered out, so fewer images may be returned. Results
preserve relevance order. Search is restricted to users with `upload_files`.

## Import and use an image

```php
$attachment_id = \MENJ\CommonsImageDL\import_image($commons_page_id, $post_id);
if (is_wp_error($attachment_id)) {
    return;
}

// Optional: set the imported attachment as this post's featured image.
set_post_thumbnail($post_id, $attachment_id);
```

`import_image($page_id, $post_id = 0, $language = 'en')` returns an attachment ID
or `WP_Error`. It re-fetches the image URL and metadata from Commons using the
numeric page ID, rather than trusting a URL from a form. Both `upload_files`
and permission to edit the target post are checked. Pass `0` for an unattached
Media Library import. Repeated imports create new attachments; store and reuse
the returned ID in your theme's workflow.

The adapter downloads the full-resolution current original. Supported original
formats are JPEG, PNG, WebP and GIF, subject to the site's allowed upload types.
It verifies the downloaded dimensions, MIME and reported byte size. There is no
adapter-imposed byte cap; server disk space, execution time and hosting limits
still apply. HTTP/API errors are returned for the caller to retry later.

To preserve the file exactly, the adapter does **not** invoke WordPress automatic
big-image scaling, EXIF rotation, conversion or intermediate image generation.
The attachment contains dimensions and original-file metadata, with an empty
`sizes` array. `wp_get_attachment_url($attachment_id)` and
`wp_get_attachment_image($attachment_id, 'full')` use the original. This means no
responsive derivatives are available unless you explicitly generate them later;
serving very large originals on public pages can increase page weight. Existing
images and other WordPress uploads are unaffected.

## Descriptions, attribution and tags

| Commons data | WordPress destination |
| --- | --- |
| File title | Attachment title |
| Image description | Attachment description (`post_content`), converted to plain text |
| Author, credit, license and source links | Attachment caption (`post_excerpt`) |
| All non-hidden categories | `commons_image_tag` taxonomy terms |
| Original URL, dimensions, license, usage terms and raw metadata | `_commons_image_dl` attachment meta |

Category pagination is followed and duplicate category names are removed. Tags
are Commons categories, not invented keywords. Missing description or license
fields remain empty. Alt text is not automatically invented; set it for the
image's actual use in your theme. The original's embedded metadata remains
unchanged; the additional information is stored in WordPress's database.

```php
$source = get_post_meta($attachment_id, '_commons_image_dl', true);
$tags = wp_get_object_terms($attachment_id, 'commons_image_tag', ['fields' => 'names']);
$caption = wp_get_attachment_caption($attachment_id);
```

Display the caption/source attribution where the image is used and review the
source license's requirements. Storing credit in the database alone does not
display it on a page. Media and metadata remain in WordPress when switching
themes, though the taxonomy UI requires this module to remain loaded.

## Secure admin form wiring

Do not call imports from template rendering or an unauthenticated endpoint.
For a theme's admin form, use an `admin_post` handler, a nonce and POST data:

```php
add_action('admin_post_mytheme_import_commons', function () {
    if (!current_user_can('upload_files')) {
        wp_die('Upload permission required.', '', ['response' => 403]);
    }
    check_admin_referer('mytheme_import_commons');
    $id = \MENJ\CommonsImageDL\import_image(
        absint($_POST['commons_page_id'] ?? 0),
        absint($_POST['post_id'] ?? 0)
    );
    if (is_wp_error($id)) {
        wp_die(esc_html($id->get_error_message()));
    }
    wp_safe_redirect(admin_url('post.php?post=' . $id . '&action=edit'));
    exit;
});
```

The corresponding form must POST to `admin_url('admin-post.php')`, include
`action=mytheme_import_commons`, the numeric `commons_page_id`, an optional
`post_id`, and `wp_nonce_field('mytheme_import_commons')`. Do not register a
`nopriv` handler. This library deliberately exposes no public REST/AJAX import
endpoint. Any custom endpoint must enforce authentication and nonce checks.

## Validation

```sh
php -l wordpress/commons-image-dl.php
php tests/test_wordpress.php
python -m unittest discover -s tests -v
```

The PHP tests use WordPress API doubles to check permission gates, original URL
selection, pagination, descriptions, tags, byte preservation and failure cleanup.
They do not replace testing inside a real WordPress installation and the target
theme. Run a staging import to check the host's upload policy and storage limits.
