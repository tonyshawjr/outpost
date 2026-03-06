<?php
/**
 * Outpost CMS — Media Manager
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

class OutpostMedia {
    public static function upload(array $file): array {
        // Validate upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['error' => 'Upload failed with error code: ' . $file['error']];
        }

        if ($file['size'] > OUTPOST_MAX_UPLOAD_SIZE) {
            $maxMB = OUTPOST_MAX_UPLOAD_SIZE / 1024 / 1024;
            return ['error' => "File exceeds maximum size of {$maxMB}MB"];
        }

        // Check extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, OUTPOST_ALLOWED_EXTENSIONS)) {
            return ['error' => 'File type not allowed: ' . $ext];
        }

        // Verify MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, OUTPOST_ALLOWED_MIME_TYPES)) {
            return ['error' => 'MIME type not allowed: ' . $mime];
        }

        // SVG sanitization — DOM-based allowlist
        if ($ext === 'svg') {
            $svgContent = file_get_contents($file['tmp_name']);
            $sanitized = self::sanitizeSvg($svgContent);
            if ($sanitized === false) {
                return ['error' => 'SVG contains unsafe content and could not be sanitized'];
            }
            file_put_contents($file['tmp_name'], $sanitized);
        }

        // Generate safe filename
        $safeName = self::sanitizeFilename($file['name']);
        $filename = time() . '_' . $safeName;

        // Ensure uploads dir exists
        if (!is_dir(OUTPOST_UPLOADS_DIR)) {
            mkdir(OUTPOST_UPLOADS_DIR, 0755, true);
        }

        $destPath = OUTPOST_UPLOADS_DIR . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return ['error' => 'Failed to move uploaded file'];
        }

        // Get image dimensions
        $width = 0;
        $height = 0;
        $thumbPath = '';

        if (str_starts_with($mime, 'image/') && $ext !== 'svg') {
            $info = getimagesize($destPath);
            if ($info) {
                $width = $info[0];
                $height = $info[1];
            }

            // Resize if too large
            if ($width > OUTPOST_MAX_IMAGE_WIDTH) {
                self::resizeImage($destPath, OUTPOST_MAX_IMAGE_WIDTH);
                $info = getimagesize($destPath);
                if ($info) {
                    $width = $info[0];
                    $height = $info[1];
                }
            }

            // Generate thumbnail
            $thumbPath = self::generateThumbnail($destPath, $filename);
        }

        // Relative path for storage (relative to site root)
        $relativePath = self::relativePath($destPath);
        $relativeThumb = $thumbPath ? self::relativePath($thumbPath) : '';

        // Store in database
        $id = OutpostDB::insert('media', [
            'filename' => $filename,
            'original_name' => $file['name'],
            'path' => $relativePath,
            'thumb_path' => $relativeThumb,
            'mime_type' => $mime,
            'file_size' => filesize($destPath),
            'width' => $width,
            'height' => $height,
            'alt_text' => '',
        ]);

        return OutpostDB::fetchOne('SELECT * FROM media WHERE id = ?', [$id]);
    }

    public static function delete(array $media): void {
        // Delete physical files
        $basePath = self::absolutePath($media['path']);
        if (file_exists($basePath)) {
            unlink($basePath);
        }

        if ($media['thumb_path']) {
            $thumbPath = self::absolutePath($media['thumb_path']);
            if (file_exists($thumbPath)) {
                unlink($thumbPath);
            }
        }

        // Delete DB record
        OutpostDB::delete('media', 'id = ?', [$media['id']]);
    }

    public static function getAbsolutePath(string $relativePath): string {
        return self::absolutePath($relativePath);
    }

    public static function getRelativePath(string $absolutePath): string {
        return self::relativePath($absolutePath);
    }

    public static function regenerateThumbnail(string $sourcePath, string $filename): string {
        return self::generateThumbnail($sourcePath, $filename);
    }

    private static function generateThumbnail(string $sourcePath, string $filename): string {
        if (!extension_loaded('gd')) return '';

        $thumbDir = OUTPOST_UPLOADS_DIR . 'thumbs/';
        if (!is_dir($thumbDir)) {
            mkdir($thumbDir, 0755, true);
        }

        $thumbPath = $thumbDir . $filename;

        $info = getimagesize($sourcePath);
        if (!$info) return '';

        [$origW, $origH] = $info;
        $mime = $info['mime'];

        // Calculate thumb dimensions
        $ratio = min(OUTPOST_THUMB_WIDTH / $origW, OUTPOST_THUMB_HEIGHT / $origH);
        if ($ratio >= 1) {
            // Image is already small enough, copy as thumb
            copy($sourcePath, $thumbPath);
            return $thumbPath;
        }

        $newW = (int) round($origW * $ratio);
        $newH = (int) round($origH * $ratio);

        $thumb = imagecreatetruecolor($newW, $newH);

        $source = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($sourcePath),
            'image/png' => imagecreatefrompng($sourcePath),
            'image/gif' => imagecreatefromgif($sourcePath),
            'image/webp' => imagecreatefromwebp($sourcePath),
            default => null,
        };

        if (!$source) return '';

        // Preserve transparency for PNG/GIF/WebP
        if (in_array($mime, ['image/png', 'image/gif', 'image/webp'])) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefilledrectangle($thumb, 0, 0, $newW, $newH, $transparent);
        }

        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

        match ($mime) {
            'image/jpeg' => imagejpeg($thumb, $thumbPath, 85),
            'image/png' => imagepng($thumb, $thumbPath),
            'image/gif' => imagegif($thumb, $thumbPath),
            'image/webp' => imagewebp($thumb, $thumbPath, 85),
            default => null,
        };

        imagedestroy($source);
        imagedestroy($thumb);

        return $thumbPath;
    }

    private static function resizeImage(string $path, int $maxWidth): void {
        if (!extension_loaded('gd')) return;

        $info = getimagesize($path);
        if (!$info) return;

        [$origW, $origH] = $info;
        $mime = $info['mime'];

        if ($origW <= $maxWidth) return;

        $ratio = $maxWidth / $origW;
        $newW = $maxWidth;
        $newH = (int) round($origH * $ratio);

        $resized = imagecreatetruecolor($newW, $newH);

        $source = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/gif' => imagecreatefromgif($path),
            'image/webp' => imagecreatefromwebp($path),
            default => null,
        };

        if (!$source) return;

        if (in_array($mime, ['image/png', 'image/gif', 'image/webp'])) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }

        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

        match ($mime) {
            'image/jpeg' => imagejpeg($resized, $path, 90),
            'image/png' => imagepng($resized, $path),
            'image/gif' => imagegif($resized, $path),
            'image/webp' => imagewebp($resized, $path, 90),
            default => null,
        };

        imagedestroy($source);
        imagedestroy($resized);
    }

    private static function sanitizeFilename(string $name): string {
        // Remove path components
        $name = basename($name);
        // Keep only safe chars
        $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
        // Strip all extensions, then re-attach only if allowlisted
        // This blocks .phtml, .php7, .phar, .inc, and any other executable extension
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = pathinfo($name, PATHINFO_FILENAME) ?: 'upload';
        if ($ext && in_array($ext, OUTPOST_ALLOWED_EXTENSIONS)) {
            return $base . '.' . $ext;
        }
        // Discard unrecognised extension — keep the base name only
        return $base ?: 'upload';
    }

    private static function relativePath(string $absolutePath): string {
        // Return as absolute URL path (leading slash) so it works on any page depth
        $siteRoot = dirname(OUTPOST_DIR) . '/';
        if (str_starts_with($absolutePath, $siteRoot)) {
            return '/' . substr($absolutePath, strlen($siteRoot));
        }
        return $absolutePath;
    }

    private static function absolutePath(string $relativePath): string {
        $siteRoot = dirname(OUTPOST_DIR) . '/';
        // Strip leading slash before joining with siteRoot
        $stripped = ltrim($relativePath, '/');
        if (str_starts_with($stripped, 'outpost/') || str_starts_with($stripped, $siteRoot)) {
            return $siteRoot . $stripped;
        }
        return $siteRoot . $stripped;
    }

    /**
     * DOM-based SVG sanitizer — strips dangerous elements and attributes.
     * Returns sanitized SVG string, or false if the SVG cannot be parsed.
     */
    private static function sanitizeSvg(string $svg): string|false {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        if (!$dom->loadXML($svg)) {
            libxml_clear_errors();
            return false;
        }
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Remove script elements
        foreach (iterator_to_array($xpath->query('//script') ?: []) as $node) {
            $node->parentNode->removeChild($node);
        }

        // Remove foreignObject elements (can embed arbitrary HTML)
        foreach (iterator_to_array($xpath->query('//foreignObject') ?: []) as $node) {
            $node->parentNode->removeChild($node);
        }

        // Allowed URI schemes for href/src attributes
        $allowedSchemes = ['http', 'https', 'data:image/']; // data:image/ only for inline images

        // Process all elements
        $allElements = $xpath->query('//*');
        foreach ($allElements ?: [] as $el) {
            $attrsToRemove = [];
            foreach ($el->attributes as $attr) {
                $name = strtolower($attr->name);
                $value = trim($attr->value);
                $stripped = preg_replace('/[\s\x00-\x1f]/u', '', strtolower($value));

                // Remove all event handler attributes (on*)
                if (str_starts_with($name, 'on')) {
                    $attrsToRemove[] = $attr->name;
                    continue;
                }

                // Check href, xlink:href, src, action for dangerous URIs
                if (in_array($name, ['href', 'xlink:href', 'src', 'action', 'formaction'])) {
                    if (preg_match('/^(javascript|vbscript|data(?!:image\/))\s*:/i', $stripped)) {
                        $attrsToRemove[] = $attr->name;
                    }
                }
            }
            foreach ($attrsToRemove as $a) {
                $el->removeAttribute($a);
            }
        }

        // Remove <use> elements with external references (only allow internal #id refs)
        foreach (iterator_to_array($xpath->query('//use') ?: []) as $node) {
            $href = $node->getAttribute('href') ?: $node->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
            if ($href && !str_starts_with($href, '#')) {
                $node->parentNode->removeChild($node);
            }
        }

        return $dom->saveXML();
    }
}
