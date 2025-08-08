# Newspack Post Image Downloader

This plugin is a content migration service tool. It imports externally hosted images or non-image file URLs found in your Post contents.

A typical use case for downloading and importing external images, is to aid migration from one host to another.

An optional feature is first attempting to import from a provided local folder containing the files, but if the image/file is not found it will be download from remote URL.

Another functionality is listing all the image or non-image URLs in your content database. To locate the image URLs, `src`s and `srcset`s of `<img>` elements are searched. To locate non-image URLs, a hybrid approach is used to search for them: firstly various attribute URLs are searched on DOM level, then additionally HTML is searched as clear text for just additional absolute URLs.

The plugin presently supports three kinds of URLs:
- absolute URLs (e.g. `https://example.com/wp-content/uploads/image.png`)
- root-relative URLs (e.g. `/wp-content/uploads/image.png`)
- protocol-relative URLs (e.g. `//example.com/path`)
but not:
- page-relative (e.g. `../uploads/image.png`)


## Features

The plugin features CLI commands with parameters which offer a flexible set of basic features.

### -- download or import images/files from local files 

If you have images available in your local files, and you use the `--folder-local-files` parameter, the plugin will first attempt to import these directly, without downloading them.

### -- download images/files from specific hosts only

The plugin downloads all the externally hosted images by default. Optionally, we can set to download just from specific hosts.

There is a helper command called `scan-existing-urls` which lists all the host names used in images. After listing all existing hosts, we can chose to download just from specific ones (via the `--only-download-from-hosts` command parameter).

### -- skip (exclude) specific hosts from downloading

Optional. Alternatively, you can specify hosts not to download images from, and the plugin will download images from all the hosts except these, e.g. `*.google.*` (the `--exclude-hosts` parameter). Wildcards are also supported to use all domain extensions and/or subdomains.

### -- skip (exclude) relative URLs from downloading

Optional. Unless `--do-not-download-root-relative-urls` and `--do-not-download-protocol-relative-urls` flags are set, the command will automatically download relative image URLs by prepending the `--default-image-host-and-schema` or `https:` protocol to them.

### -- full-size image downloading

Unless the optional flag is set `--do-not-download-large-sizes`, the `download-images` command will automatically attempt to import the large-sized version of the image (non-scaled and non-intermediate).

This ensures that the imported Media Library attachment image is of the highest available quality. Then the smaller scaled/intermediate image also gets downloaded side-by-side to the imported larger attachment object. The effect is not seen in post_content, as the same size of image will be displayed, but the Media Library does get the highest available image quality.

E.g.1. if an intermediate image is found in post_content for download https://www.mysite.com/wp-content/uploads/2025/01/img-puppy-300x244.jpg the command will actually import the non-intermediate image into the Media Library (without the `-300x244` suffix) https://www.mysite.com/wp-content/uploads/2025/01/img-puppy.jpg , and additionally just download the intermediate one next to it.

E.g.2. or if a scaled image https://www.mysite.com/wp-content/uploads/2025/01/img-kitten-scaled.jpg is used, the command will try and import the non-scaled image https://www.mysite.com/wp-content/uploads/2025/01/img-kitten.jpg , and still seamlessly download and use the scaled version in post_content.

See more about image sizes in [WordPress docs](https://make.wordpress.org/core/2019/10/09/introducing-handling-of-big-images-in-wordpress-5-3/).

### -- non-image file downloading

To download non-image files, a mandatory `--extensions` needs to be provided with specific extensions to download, e.g. `--extensions=pdf,docx,xlsx,pptx`.

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
