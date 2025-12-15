# Newspack Post Image Downloader

This plugin downloads image or non-image files from externally hosted file URLs found in your post content directly to your site's Media Library, and updates those URLs in post content.

The plugin presently supports three kinds of URLs:
- absolute URLs (e.g. `https://example.com/wp-content/uploads/image.png`)
- root-relative URLs (e.g. `/wp-content/uploads/image.png`)
- protocol-relative URLs (e.g. `//example.com/path`)

but not:
- page-relative (e.g. `../uploads/image.png`)

Optionally you can provide a local folder containing the files, and the downloader will try and import the files from this local folder, or if they're not found there it will download them from the remote URL.


## DISCLAIMER

**This plugin destructively modifies your site's content by downloading and replacing external URLs from the post content with local media library attachments. It is recommended to create a complete backup of your site before using this plugin. Use this plugin at your own risk and responsibility. The authors are not responsible for any data loss, site issues, or other consequences resulting from the use of this plugin.**


## Table of Contents

This guide provides step-by-step workflows for downloading images and non-image files.

1. [Downloading Image Files](#downloading-image-files)
2. [Downloading Non-Image Files](#downloading-non-image-files)
3. [Command Reference](#command-reference)
4. [Other notes](#other-notes)

---

## Recommended workflow

The following workflow demonstrates how to download all image and non-image files from content. If downloading both images and non-image files is the goal, it is recommended to do it in this order.

### Downloading Image Files

#### Step 1: Scan and Select Hostnames to Download From

First, discover all image URLs on your site:

```bash
wp newspack-post-image-downloader scan-existing-urls
```

This will:
- Scan all posts and pages for `<img src>` and `srcset` attributes
- Generate `cmd_scan_existing_urls.csv` with all found URLs, post IDs, hostnames and file extensions
- Display a summary of hostnames and file extensions found

From the resulting list, select the hosts to download from using `--only-download-from-hosts`. Both this and `--exclude-hosts` support wildcards and CSV values, e.g. `--only-download-from-hosts=example.com,*.example.com` downloads from example.com and all its subdomains.

#### Step 2: Download Images

```bash
wp newspack-post-image-downloader download-images \
  --only-download-from-hosts=example.com,*.example.com \
  --default-host-and-schema=https://www.example.com
```

**Key options:**
- `--default-host-and-schema` Required for root-relative (`/path/img.jpg`) and protocol-relative (`//host/img.jpg`) URLs. Can skip these with `--do-not-download-root-relative-urls` or `--do-not-download-protocol-relative-urls`
- `--do-not-download-large-sizes` Use when source is a non-WordPress site (skips attempting to find/download the original full-size image from intermediate/scaled versions)
- `--dry-run` Preview what would be downloaded before executing

**WordPress image size handling:** The plugin automatically attempts to import the largest available image size into the Media Library (e.g. `img-puppy.jpg` instead of `img-puppy-300x244.jpg`, or `img-kitten.jpg` instead of `img-kitten-scaled.jpg`), while also downloading intermediate sizes alongside it.

### Downloading Non-Image Files

#### Step 3: Scan for Non-Image URLs and Select Extensions

```bash
wp newspack-post-image-downloader scan-existing-urls --include-non-image-urls
```

Review the results to:
- Check if any image extensions were missed (may indicate custom syntax needing manual handling)
- Identify non-image file extensions to download, e.g. `--extensions=pdf,m4a,mp4`

#### Step 4: Download Non-Image Files

```bash
wp newspack-post-image-downloader download-non-images-files \
  --only-download-from-hosts=example.com,*.example.com \
  --default-host-and-schema=https://www.example.com \
  --extensions=pdf,m4a,mp4
```

---

## Command Reference

@see \NewspackPostImageDownloader\Downloader::register_commands for list of all available command parameters.

---

## Other Notes

### Performance Tips

- Use `--dry-run` first to test your configuration
- Consider using `--do-not-download-large-sizes` when files are coming from a non-WordPress site, since the image naming standard might not be the same as in EP
- Use `--post-ids-csv` or `--post-id-from`/`--post-id-to` to process specific posts

### Troubleshooting

**"Could not download relative src" error**: Add `--default-host-and-schema="https://yoursite.com"`

**"Extension not supported" error**: The file type isn't allowed by WordPress. Check your site's allowed file types.

**Memory issues**: In case your host has a lot of posts and runs out of memory, process posts in smaller batches using `--post-id-from` and `--post-id-to`.
