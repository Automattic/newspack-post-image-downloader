# Newspack Post Image Downloader

This plugin downloads externally hosted images found in tour Posts. It can optionally also import images directly from local files, if available.

## Features

The CLI command parameters offer a flexible set of features.

### -- download images ony from specific hosts

By default, the Plugin will download all the externally hosted images. But optionally, you may select just specific hosts to download images from.

You might first run the helper command `scan-existing-images-hostnames` to list all the host names used in `<img src="...">`, and then chose to download just from specific ones. You can do so by setting the `--only-download-from-hosts` command parameter. 

### -- skip (exclude) specific hosts from downloading

This is optional, but alternatively you can specify hosts not to download images from, and the Plugin will download images from all the hosts except these.
By using the `--exclude-hosts` command parameter, you can set a list of excluded hosts and downloading will be skipped from these (e.g. `*.google.*`).

### -- download or import images from local files 

If you have local image files available, the Plugin will first attempt to import these directly without downloading them. Use the `--folder-local-images` folder path to let the Plugin know where the image files are located.

### -- parallel downloading

To speed up downloading, you could run parallel commands. This is done by choosing groups of Post IDs, and running the command with different `--post-id-from` and `--post-id-to` ID ranges.

### -- custom post type and post status selection

By default, the Plugin downloads or imports external images from all the public Posts and Pages, and this can be customized with `--post-types` and `--post-statuses`.  

### -- downloads absolute or relative referenced URIs

Except from downloading images with the standard `https://...` `src` prefix, by providing the `--default-image-host-and-schema=https://example-origin.com`, these URI types get downloaded too:
- _absolute referenced sources_, e.g. `/path/image.jpg`,
- the Plugin also attempts to download _relative referenced sources_, e.g. `path/image.jpg`, by treating them as absolute ones (which makes sense if the page where the images were located at is located at the root path, e.g. `https://host.com/my_post_with_images`) 

### -- error logging

Creates detailed custom error logs, and warns you if any errors occurred.

### -- dry-run

You may simulate and run the command without actual downloads or changes to your content by using the `--dry-run` flag. 

## How to install

Run `composer install`.

## List of all available commands and parameters.

Can be found [here](https://github.com/Automattic/newspack-post-image-downloader/blob/master/src/class-downloader.php#L39).

## Caveats

A fresh WordPress site [supports importing a number of image formats](https://core.trac.wordpress.org/browser/tags/5.1.1/src/wp-includes/functions.php#L2707). In case of different formats, you may find this error in your logs:
```
Sorry, this file type is not permitted for security reasons.
```

In this case, you can additionally use [the `upload_mimes` WP hook](https://developer.wordpress.org/reference/hooks/upload_mimes/) to temporarily or permanently permit additional formats.

Or even (temporarily) set the following constant in your `wp-config.php`
```php
define( 'ALLOW_UNFILTERED_UPLOADS', true );
```
