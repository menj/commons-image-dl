# WordPress theme bridge to the Python core

Developed and coded by **MENJ**. GPL-3.0-only.

**`commons_image_dl.py` is the core engine. PHP is only the WordPress bridge.**
The standalone CLI and the theme integration share the same Python discovery,
filtering, HTTP retries, full-resolution downloads, metadata and file validation.
There is no separate PHP Commons downloader and no PHP fallback.

## Server setup

Install this repository on the WordPress server and create a Python environment:

```sh
cd /opt/commons-image-dl
python3 -m venv .venv
.venv/bin/python -m pip install .
```

Use Python 3.10+ and PHP 8.2+. The PHP worker must be allowed to execute Python
through `proc_open`, read the repository/environment, reach Commons over HTTPS,
and write to its temporary directory and WordPress uploads. No specific WordPress
release number is embedded in the bridge. Hosting that disables Python or
`proc_open` cannot run this local bridge.

In `wp-config.php`, set deployment-owned paths (never derive them from a request):

```php
define('COMMONS_IMAGE_DL_PYTHON', '/opt/commons-image-dl/.venv/bin/python');
define('COMMONS_IMAGE_DL_SCRIPT', '/opt/commons-image-dl/commons_image_dl.py');
define('COMMONS_IMAGE_DL_TIMEOUT', 300); // Optional; default 300 seconds per call.
```

In your theme's `functions.php`, load the bridge once:

```php
require_once '/opt/commons-image-dl/wordpress/commons-image-dl.php';
```

Alternatively, bundle the PHP file in your theme's `inc` directory and load it
with `require_once get_theme_file_path('/inc/commons-image-dl.php');` while keeping
the configured Python paths above. Include the copyright header and LICENSE.
Loading the bridge registers an attachment tag taxonomy but performs no downloads.

## Search through Python

```php
$result = \MENJ\CommonsImageDL\search('Penang mosques', 10, 'en');
if (is_wp_error($result)) {
    return; // Show an escaped error in the admin UI.
}
foreach ($result['images'] as $image) {
    // Display escaped metadata and use $image['page_id'] for import.
}
```

`search($query, $limit = 10, $language = 'en')` invokes Python's `--query` and
`--dry-run` and returns its manifest. The requested count is clamped to 1–50.
Python follows API continuation internally up to its candidate limit. Inspect
`complete`, `error` and `failures`: partial results remain available if the
requested count cannot be met. No images are downloaded during search.

The earlier experimental PHP-only adapter's continuation argument is removed.
There is no separate PHP pagination implementation; request a larger bounded
result set or refine the query.

## Import through Python, then register in WordPress

```php
$attachment_id = \MENJ\CommonsImageDL\import_image($commons_page_id, $post_id);
if (is_wp_error($attachment_id)) {
    return;
}
set_post_thumbnail($post_id, $attachment_id); // Optional.
```

`import_image($page_id, $post_id = 0, $language = 'en')` invokes the Python core
with `--page-id`, `--count=1` and `--include-tags`. Python resolves the current
original from Commons and downloads it into a private temporary job directory.
PHP verifies the resulting file path, SHA-256, MIME and dimensions, then moves
that local file into the Media Library. **PHP never fetches the image URL.**

Only users with `upload_files` may invoke the bridge. A target post also requires
`edit_post` permission. Each call launches Python directly with an argument array,
without a shell. Timeouts terminate the child process; job files are removed on
success and handled failures. PHP/server request limits may be shorter than the
bridge timeout. Use authenticated background admin jobs for lengthy operations.

Imports support JPEG, PNG, WebP and GIF, subject to the site's allowed upload
types. There is no default byte cap. Disk capacity and execution limits still
apply. Repeated imports create new attachments; retain and reuse their IDs.

## Original image preservation

Python always downloads the full-resolution original. PHP does not invoke
WordPress automatic scaling, re-encoding, EXIF rotation or intermediate-image
generation, so the imported file retains the Python download's bytes. Attachment
metadata contains original dimensions and an empty `sizes` array.

`wp_get_attachment_url($id)` and `wp_get_attachment_image($id, 'full')` use that
original. Responsive derivatives require an explicit later generation step;
serving large originals directly increases public-page weight. Other uploads
are unaffected. Avoid triggering imports during frontend page rendering.

## Descriptions, attribution and tags

| Python result | WordPress destination |
| --- | --- |
| File title | Attachment title |
| Description | Attachment description (`post_content`) |
| Author, credit, license and source links | Caption (`post_excerpt`) |
| Non-hidden Commons categories | `commons_image_tag` attachment terms |
| Original URL, checksum, dimensions, license and raw metadata | `_commons_image_dl` attachment meta |

The Python core follows category pagination and deduplicates tags. Missing
metadata is not invented. Alt text is left for the image's actual use. Original
embedded metadata is preserved; additional metadata is stored in WordPress.

```php
$metadata = get_post_meta($attachment_id, '_commons_image_dl', true);
$tags = wp_get_object_terms($attachment_id, 'commons_image_tag', ['fields' => 'names']);
$caption = wp_get_attachment_caption($attachment_id);
```

Display appropriate attribution where the image is used; saving it in the
database does not display it automatically. Metadata remains after switching
themes, though the taxonomy UI requires the bridge to remain loaded.

The same tags are available directly from the Python CLI:

```sh
python commons_image_dl.py --query 'Penang mosques' --count 10 --include-tags
python commons_image_dl.py --page-id 123 --count 1 --include-tags
```

## Secure admin form wiring

This is a developer integration API, not a search modal. Call it from a protected
admin form or job. For example:

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

POST to `admin_url('admin-post.php')` with `action=mytheme_import_commons`, a
numeric `commons_page_id`, optional `post_id`, and
`wp_nonce_field('mytheme_import_commons')`. Do not add a `nopriv` handler. No
public REST/AJAX import endpoint is installed by the bridge.

## Validation

```sh
python -m unittest discover -s tests -v
php -l wordpress/commons-image-dl.php
COMMONS_TEST_PYTHON="$(command -v python)" php tests/test_wordpress.php
```

The bridge tests launch an actual Python subprocess running the actual core,
with mocked Commons HTTP data and WordPress API doubles. They verify permissions,
argument handling, timeout termination, search results, originals, descriptions,
tags, checksums and cleanup. A staging WordPress/theme test is still needed to
validate the deployment's process permissions, media policy and hosting limits.
