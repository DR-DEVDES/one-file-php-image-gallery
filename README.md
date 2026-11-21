# PHP Image Gallery

A lightweight, responsive image gallery built with **PHP, HTML, CSS and vanilla JavaScript**.
Drop images into the gallery directory — subfolders automatically become albums.

## Features

- Responsive justified image grid
- Automatic album detection from subfolders
- Search by filename and path
- Sort by newest, oldest, name or random
- Color-based filtering
- Adjustable thumbnail density
- Full-screen lightbox
- Previous / next navigation and touch swipe
- Slideshow with keyboard controls
- Automatic thumbnail generation and caching
- Average-color analysis for image placeholders
- Lazy-loaded images
- No database and no JavaScript framework

## Requirements

- PHP 7.4+ (PHP 8.x recommended)
- PHP GD extension for thumbnail generation
- PHP EXIF extension (optional; improves JPEG orientation handling)
- A web server with read access to image files
- Write access to the gallery directory for `.gallery-cache/`

AVIF and WebP thumbnail support depends on the GD build installed on the server.

## Installation

1. Upload `gallery.php` to a directory on your web server.
2. Place your images in the same directory.
3. Optionally create subfolders to organize images into albums.
4. Open `gallery.php` in your browser.

Example:

```text
photo-gallery/
├── gallery.php
├── portrait/
│   ├── image-01.jpg
│   └── image-02.jpg
├── travel/
│   ├── city.webp
│   └── coast.png
└── .gallery-cache/   # created automatically
```

## Configuration

Edit the `$C` array near the top of `gallery.php`:

```php
$C = [
    'title'  => 'Galerie',
    'cache'  => '.gallery-cache',
    'depth'  => 3,
    'max'    => 4000,
    'budget' => 14,
    'exts'   => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'],
];
```

| Option | Description |
| --- | --- |
| `title` | Gallery page title |
| `cache` | Directory for thumbnails and the JSON index |
| `depth` | Maximum subdirectory depth to scan |
| `max` | Maximum number of images to include |
| `budget` | Maximum color analyses per page request |
| `exts` | Supported image file extensions |

## Keyboard shortcuts

| Key | Action |
| --- | --- |
| `Esc` | Close the lightbox |
| `←` / `→` | Previous / next image |
| `Space` | Start / stop slideshow |
| `F` | Toggle fullscreen |

## Cache

The `.gallery-cache/` directory contains generated thumbnails and the image index.
It is created automatically and should be writable by PHP.

The cache is excluded from Git by `.gitignore`. It can be deleted safely;
the gallery will regenerate thumbnails and metadata as needed.

## Security notes

- Keep the gallery and image directories read-only for visitors.
- Do not grant write permissions to the public web server beyond the cache directory.
- Consider protecting the gallery with HTTP authentication if it contains private images.
- Use a current, supported PHP version and keep server packages updated.

## License

No license is included yet. Add a `LICENSE` file before publishing if you want
to grant others explicit permission to use, modify or redistribute this project.
