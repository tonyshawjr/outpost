# Deployment Guide

Deploy Outpost to any PHP server — cPanel shared hosting, a VPS with Apache, or Nginx.
No build step, no package manager — just upload the files.

---

## Requirements

| Requirement | Details |
|---|---|
| PHP | 8.0+ |
| `pdo_sqlite` | Required — the database engine |
| `mbstring` | Required — text processing |
| `gd` | Recommended — image thumbnails |
| `json` | Required — included in all standard PHP builds |
| `file_uploads` | Must be `On` in `php.ini` |
| `upload_max_filesize` | Recommended: `10M` or higher |
| `post_max_size` | Recommended: `12M` or higher (must exceed `upload_max_filesize`) |

Check extensions: `php -m | grep pdo_sqlite`

---

## File Structure

Upload these to your web root (e.g. `public_html/` or `/var/www/html/`):

```
public_html/
  .htaccess         ← Apache rewrite rules (included)
  index.php         ← front controller (included)
  outpost/          ← the entire CMS directory
```

---

## Apache (.htaccess)

Place this `.htaccess` at your web root (same directory as `index.php`):

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

**Enable mod_rewrite** (Debian/Ubuntu):
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

**VirtualHost** — must include `AllowOverride All`:
```apache
<VirtualHost *:80>
    ServerName example.com
    DocumentRoot /var/www/html

    <Directory /var/www/html>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Without `AllowOverride All`, the `.htaccess` rules are silently ignored and all URLs except the homepage return 404.

### Apache — Subdirectory Install

To run at `example.com/blog/`, place `.htaccess`, `index.php`, and `outpost/` inside the subdirectory. No extra configuration needed — the `.htaccess` works the same way.

---

## Nginx

No `.htaccess` with Nginx — all config goes in the server block:

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/html;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Block direct access to database and cache
    location ~ ^/outpost/(data|cache)/ {
        deny all;
        return 404;
    }
}
```

**Common `fastcgi_pass` paths:**
- Ubuntu/Debian: `unix:/var/run/php/php8.2-fpm.sock`
- CentOS/RHEL: `unix:/run/php-fpm/www.sock`
- TCP: `fastcgi_pass 127.0.0.1:9000;`

### Nginx — Subdirectory Install

```nginx
location /blog/ {
    try_files $uri $uri/ /blog/index.php?$query_string;
}

location ~ ^/blog/.*\.php$ {
    fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

---

## File Permissions

```bash
# Create directories if they don't exist
mkdir -p outpost/data outpost/uploads outpost/cache/templates

# Set permissions
chmod 755 outpost/data outpost/uploads outpost/cache outpost/cache/templates

# Set ownership (replace www-data with your web server user)
chown -R www-data:www-data outpost/data
chown -R www-data:www-data outpost/uploads
chown -R www-data:www-data outpost/cache
```

Do **not** use `chmod 777`. Use `755` for directories and ensure the web server user is the owner.

---

## Deploying Updates

```bash
# rsync — skips data/ and uploads/ to protect live content
rsync -avz --exclude 'outpost/data/' \
           --exclude 'outpost/uploads/' \
           outpost/ user@server:/var/www/html/outpost/

rsync -avz index.php user@server:/var/www/html/index.php
```

**Never overwrite or delete `outpost/data/` or `outpost/uploads/` on production.** These contain your database and uploaded media.

| Path | On re-deploy |
|---|---|
| `outpost/data/` | **Preserve** — never overwrite |
| `outpost/uploads/` | **Preserve** — never overwrite |
| `outpost/cache/` | Safe to clear — rebuilds automatically |
| Everything else | Replace with new version |

After deploying, clear the cache:
```bash
rm -f outpost/cache/*.html
rm -f outpost/cache/templates/*.php
```

Or use **Admin → Settings → Advanced → Clear Cache**.

---

## SSL (HTTPS)

Recommended: [Certbot](https://certbot.eff.org/) with Let's Encrypt (free).

```bash
# Debian/Ubuntu with Nginx
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d example.com -d www.example.com
```

After enabling HTTPS, update your site URL in **Admin → Settings → General** to use `https://`.

---

## First Boot

On a fresh install, the first request automatically:
1. Creates `outpost/data/cms.db`
2. Runs all schema migrations
3. Seeds a default admin account

Visit `https://your-domain.com/outpost/` → log in with `admin` / `admin` → **change your password immediately**.

---

## Backup

All persistent state lives in two places:

| Back up | Why |
|---|---|
| `outpost/data/cms.db` | All content, settings, users |
| `outpost/uploads/` | All uploaded media |

Simple daily cron backup:
```bash
0 2 * * * cp /var/www/html/outpost/data/cms.db /backups/cms-$(date +\%Y\%m\%d).db
0 2 * * * find /backups/ -name "cms-*.db" -mtime +30 -delete
```

---

## Production Checklist

- [ ] Change default admin password
- [ ] Set site URL to HTTPS (Admin → Settings → General)
- [ ] SSL certificate installed
- [ ] `outpost/data/` not web-accessible (request `/outpost/data/cms.db` → 403/404)
- [ ] File permissions correct (upload an image to verify)
- [ ] PHP extensions present (admin loads = `pdo_sqlite` works)
- [ ] Cache cleared after deploy
- [ ] Backup scheduled
- [ ] Theme active (Admin → Themes)
- [ ] Sitemap accessible (`/sitemap.xml`)
