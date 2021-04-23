# Newspack Post Image Downloader

This plugin is a content migration service tool. It imports externally hosted images found in your Post contents.

A typical use case for downloading and importing external images, is to aid importing of all the contents after the site's migration from one host to another.

The plugin can first attempt to import the images from local files, if you have them available as local files, or if not, it will download and import them from the source URL.

## Features

The plugin features CLI commands with parameters which offer a flexible set of basic features.

### -- download or import images from local files 

If you have images available in your local files, and you use the `--folder-local-images` parameter, the plugin will first attempt to import these directly, without downloading them.

### -- download images from specific hosts only

The plugin downloads all the externally hosted images by default. Optionally, we can set to download just from specific hosts.

There is a helper command called `scan-existing-images-hostnames` which lists all the host names used in images. After listing all existing hosts, we can chose to download just from specific ones (via the `--only-download-from-hosts` command parameter).

### -- skip (exclude) specific hosts from downloading

Also optional. Alternatively, you can specify hosts not to download images from, and the plugin will download images from all the hosts except these, e.g. `*.google.*` (the `--exclude-hosts` parameter). Wildcards are also supported to use all domain extensions and/or subdomains.

### -- parallel downloading

To speed up downloading, you could even run several commands in parallel by splitting and grouping your Post IDs into several batches, and then running the command with different `--post-id-from` and `--post-id-to` ID ranges, or with a specific `--post-ids-csv`.

### -- downloads absolute or relative referenced URIs

Besides downloading images from fully qualified/absolute URLs, e.g. `https://host.com/img.jpg`, the plugin can download relative URLs if you provide the CLI param `--default-image-host-and-schema=https://example-host.com`.

### -- custom post type and post status selection

By default, the Plugin downloads or imports external images from all the public Posts and Pages, and this can be customized with `--post-types` and `--post-statuses`.  

### -- download images for specific Posts only

You can specify a list of Post IDs by using the `--post-ids-csv` or an ID range `--post-id-from` abd `--post-id-to`.  

### -- error logging

Creates detailed custom error logs for review afterwards.

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
