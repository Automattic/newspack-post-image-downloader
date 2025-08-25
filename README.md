# Newspack Post Image Downloader

This plugin downloads image or non-image files from externally hosted file URLs found in your post content directly to your site's Media Library, and updates those URLs in post content.

The plugin presently supports three kinds of URLs:
- absolute URLs (e.g. `https://example.com/wp-content/uploads/image.png`)
- root-relative URLs (e.g. `/wp-content/uploads/image.png`)
- protocol-relative URLs (e.g. `//example.com/path`)
but not:
- page-relative (e.g. `../uploads/image.png`)

Optionally you can provide a local folder containing these files. The downloader will try and find the files in this folder, and if it's not found it will download it from the remote URL.


## DISCLAIMER

**This plugin destructively modifies your site's content by downloading and replacing external URLs from the post content with local media library attachments. It is recommended to create a complete backup of your site before using this plugin. Use this plugin at your own risk and responsibility. The authors are not responsible for any data loss, site issues, or other consequences resulting from the use of this plugin.**


## Table of Contents

This guide provides step-by-step workflows for downloading images and non-image files.

1. [Downloading Image Files](#downloading-image-files)
2. [Downloading Non-Image Files](#downloading-non-image-files)
3. [Command Reference](#command-reference)
4. [Important notes](#important-notes)

---

## Downloading Image Files

### Recommended Workflow

#### Step 1: Scan and Analyze Existing Image URLs and Decide Which Hosts to Download From

First, discover all image URLs on your site to understand which hosts they come from, and decide which hosts to download from (e.g. you might not want to download images from some 3rd party hosts):

```bash
wp newspack-post-image-downloader scan-existing-urls
```

This command will:
- Scan all posts and pages for `<img src>` and `srcset` attributes
- Generate a CSV file with list of all found URLs, post IDs, hostnames and file extensions
- Display a summary of all hostnames and file extensions found

View the generated `cmd_scan_existing_urls.csv` file to see:
- Which posts contain images
- Which hostnames are used
- What file extensions are present
- Specific URLs that will be processed

There are several ways to download images from specific hosts only, namely by using the `--only-download-from-hosts` or `--exclude-hosts` parameters.

Both parameters accept wildcards (enabling you to download from all subdomains of a specific host, e.g. `*.example.com`), and multiple hosts CSV values.

**Option A: Download from all hosts -- not recommended, you probably do not want to download "the entire Internet"**
```bash
wp newspack-post-image-downloader download-images
```

**Option B: Download from specific hosts only**
Images will be downloaded from the specified hosts only.

```bash
wp newspack-post-image-downloader download-images --only-download-from-hosts="example.com,*.example.com,other-host-example.com"
```

**Option C: Exclude certain hosts**
```bash
wp newspack-post-image-downloader download-images --exclude-hosts="cdn.example.com,images.unsplash.com"
```

#### Step 2: Select to download Root-Relative and Protocol-Relative URLs or not, as Well as the Large Image Sizes

If image links on your site have root-relative URLs (starting with `/`) or protocol-relative URLs (starting with `//`), you must provide the default source host which will be used to download these URLs:

```bash
wp newspack-post-image-downloader download-images --default-host-and-schema="https://oldsite.com"
```

You may also optionally skip downloading root-relative and protocol-relative URLs by using the `--do-not-download-root-relative-urls` and `--do-not-download-protocol-relative-urls` flags.

This plugin was made with WordPress sites in mind, and by default it plugin will attempt to import the largest available image size into the Media Library, and also download the smaller intermediate/scaled image sizes side.

You may optionally skip downloading the largest image size by using the `--do-not-download-large-sizes` flag with the `download-images` command. Use this if the image links come from a non-WordPress site, because it likely does not have the same image size conventions as WP.

For those who are not familiar with WordPress image sizes, see more about those in [WordPress docs](https://make.wordpress.org/core/2019/10/09/introducing-handling-of-big-images-in-wordpress-5-3/), but here's how this plugin will handle them:
- e.g.1. if an intermediate image (with a size suffix appended to filename, e.g. `-300x244`) is found in post_content for download https://www.mysite.com/wp-content/uploads/2025/01/img-puppy-300x244.jpg the command will attempt to import the non-intermediate image into the Media Library https://www.mysite.com/wp-content/uploads/2025/01/img-puppy.jpg , and additionally just download the intermediate one next to it.
- e.g.2. or if a scaled image https://www.mysite.com/wp-content/uploads/2025/01/img-kitten-scaled.jpg is used, the command will try and import the non-scaled image into the media library https://www.mysite.com/wp-content/uploads/2025/01/img-kitten.jpg , and still also seamlessly download and use the scaled version in post_content.


#### Step 3: Execute the Download

Optionally before the actual download test with a dry run `--dry-run` to see what would be downloaded. Once satisfied with the dry run results, execute the download:

```bash
wp newspack-post-image-downloader download-images --only-download-from-hosts="example.com" --default-host-and-schema="https://oldsite.com"
```


## Downloading Non-Image Files

### Recommended Workflow

#### Step 1: Scan for All Non-Image URLs and Decide Which Extensions to Download

Discover all non-image URLs on your site:

```bash
wp newspack-post-image-downloader scan-existing-urls --include-non-image-urls
```

This command will:
- Scan all posts and pages for ALL URLs (not just images)
- Generate a CSV file with hostnames and extensions
- Show you what file types are present

Review the `cmd_scan_existing_urls.csv` and look for:
- File extensions (pdf, docx, xlsx, pptx, etc.)
- Hostnames serving these files
- Which posts contain non-image files

Based on the scan, choose which file types to download. Common choices:
- **Documents**: `pdf,doc,docx,xls,xlsx,ppt,pptx`
- **Archives**: `zip,rar,7z`
- **Media**: `mp4,mp3,wav,avi`
- **Other**: `txt,csv,xml`

#### Step 2: Execute the Download

Optionally, you can run a dry run first with `--dry-run` to see what would be downloaded:

```bash
wp newspack-post-image-downloader download-non-images-files \
  --extensions="pdf,docx,xlsx" \
  --default-host-and-schema="https://oldsite.com"
```

---

## Command Reference

@see \NewspackPostImageDownloader\Downloader::register_commands for list of all available command parameters.

---

## Important Notes

### Performance Tips

- Use `--dry-run` first to test your configuration
- Consider using `--do-not-download-large-sizes` for images to avoid downloading multiple sizes
- Use `--post-ids-csv` or `--post-id-from`/`--post-id-to` to process specific posts
- Use host filtering to reduce processing time

### Troubleshooting

**"Could not download relative src" error**: Add `--default-host-and-schema="https://yoursite.com"`

**"Extension not supported" error**: The file type isn't allowed by WordPress. Check your site's allowed file types.

**Memory issues**: In case your host has a lot of posts and runs out of memory, process posts in smaller batches using `--post-id-from` and `--post-id-to`.
