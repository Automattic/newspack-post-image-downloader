# Newspack Post Image Downloader

This plugin imports externally hosted file URLs found in your post content. It was originally developed to a download external images, but later extended to support non-image file URLs as well.

There is also a command to list all the image or non-image URLs, so that one can examine what should be downloaded -- by specific host and extension.

Optionally, one can provide a local folder containing the files. The downloader will first look for the file there, and if it's not found locally, it will be download from remote URL.

The plugin presently supports three kinds of URLs:
- absolute URLs (e.g. `https://example.com/wp-content/uploads/image.png`)
- root-relative URLs (e.g. `/wp-content/uploads/image.png`)
- protocol-relative URLs (e.g. `//example.com/path`)
but not:
- page-relative (e.g. `../uploads/image.png`)


## Features

### -- download or import images/files from local files 

Optional. You can provide the path to `--folder-local-files`, and the plugin will first attempt to import files from this location, or if not found, it will download them from remote URL.

### -- download images/files from specific hosts only

The plugin will attempt to download URLs from all external hosts. This is usually a bad thing, and one should specify and whitelist the hosts to download from by using the `--only-download-from-hosts` command parameter.

Accepts wildcards, e.g. `*.example.*` to download from all Example domains, and CSV values, e.g. `*.example.com,example.com,othersite.org` to download from any of example.com subdomains, example.com (no subdomain), as well as othersite.org.

### -- skip (exclude) specific hosts from downloading

Alternatively to whitelisting, you may blacklist hosts by using the `--exclude-hosts` parameter. 

Wildcards and CSV values are also supported here.

### -- downloads absolute and relative referenced URIs

Besides downloading images from fully qualified/absolute URLs, e.g. `https://host.com/img.jpg`, the plugin can download root-relative URLs if you provide the CLI param `--default-host-and-schema=https://example-host.com` and the plugin will attempt download root-relative image URLs by prepending the `--default-host-and-schema` to them.

Downloading relative URLs can be disabled by using the `--do-not-download-root-relative-urls` and `--do-not-download-protocol-relative-urls` flags.

### -- scan existing URLs

There is a helper command called `scan-existing-urls` which lists all the host names used in images. After listing all existing hosts, we can chose to download just from specific ones.

### -- full-size image downloading

This plugin was made with WordPress sites in mind, and the default behavior is to import the largest available image size into the media library, and then also serve the smaller intermediate file sizes as well.

Unless the optional flag is set `--do-not-download-large-sizes`, the `download-images` command will automatically attempt to import the largest-sized version of the image it can find (non-scaled and non-intermediate).

For those not familiar with WordPress image sizes, see more about them in [WordPress docs](https://make.wordpress.org/core/2019/10/09/introducing-handling-of-big-images-in-wordpress-5-3/).

E.g.1. if an intermediate image (with a size suffix appended to filename, e.g. `-300x244`) is found in post_content for download https://www.mysite.com/wp-content/uploads/2025/01/img-puppy-300x244.jpg the command will attempt to import the non-intermediate image into the Media Library https://www.mysite.com/wp-content/uploads/2025/01/img-puppy.jpg , and additionally just download the intermediate one next to it.

E.g.2. or if a scaled image https://www.mysite.com/wp-content/uploads/2025/01/img-kitten-scaled.jpg is used, the command will try and import the non-scaled image into the media library https://www.mysite.com/wp-content/uploads/2025/01/img-kitten.jpg , and still also seamlessly download and use the scaled version in post_content.

### -- non-image file downloading

To download non-image files, a mandatory `--extensions` parameter is required, e.g. `--extensions=pdf,docx,xlsx,pptx`.

Recommended workflow is to first run the `scan-existing-urls --include-non-image-urls` command, and then determine which extensions to download by using the `--extensions` parameter, and of course also decide which hosts to download from (using `--only-download-from-hosts` or `--exclude-hosts`).

### -- parallel downloading

To speed up downloading, you might want to run several download commands in parallel by splitting and grouping your Post IDs into several batches. For example, the commands can be run with different `--post-id-from` and `--post-id-to` ID ranges, or with a specific `--post-ids-csv`.

### -- custom post type and post status selection

By default, the Plugin downloads or imports external images from all the public Posts and Pages, and this can be customized with `--post-types` and `--post-statuses`.  

### -- download images for specific Posts only

You can specify a list of Post IDs by using the `--post-ids-csv` or an ID range `--post-id-from` abd `--post-id-to`.  

### -- error logging

Creates detailed error logs for review afterwards.

### -- dry-run

You may simulate and run the command without actual downloads or changes to your content by using the `--dry-run` flag. 

## How to install

Run `composer install`.

## List of all the available commands and parameters.

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

## DISCLAIMER

**This plugin destructively modifies your site's content by downloading and replacing external URLs from the post content with local media library attachments. Use this plugin at your own risk and responsibility. The authors are not responsible for any data loss, site issues, or other consequences resulting from the use of this plugin.**
