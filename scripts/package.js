#!/usr/bin/env node
/**
 * Outpost CMS — Package Script
 * Builds Svelte admin panel and packages everything into a distributable `outpost/` folder.
 *
 * Usage: node scripts/package.js
 * Or:    npm run package
 */

import { cpSync, mkdirSync, existsSync, writeFileSync, rmSync, readdirSync, readFileSync, symlinkSync, statSync } from 'fs';
import { resolve, dirname, relative, join } from 'path';
import { fileURLToPath } from 'url';
import { execSync } from 'child_process';
import { createHash } from 'crypto';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..');
const PHP_DIR = resolve(ROOT, 'php');
const DIST_DIR = resolve(ROOT, 'dist', 'outpost');

console.log('Packaging Outpost CMS...\n');

// Clean previous dist
if (existsSync(DIST_DIR)) {
  rmSync(DIST_DIR, { recursive: true });
}

// Create dist directory
mkdirSync(DIST_DIR, { recursive: true });

// ─── PHP files (all .php in php/ root) ───
const phpFiles = readdirSync(PHP_DIR).filter(f => f.endsWith('.php'));

for (const file of phpFiles) {
  const src = resolve(PHP_DIR, file);
  cpSync(src, resolve(DIST_DIR, file));
  console.log(`  Copied ${file}`);
}

// ─── Core directories to copy recursively ───
const dirs = [
  { name: 'admin',         label: 'admin/ (compiled Svelte SPA)' },
  { name: 'docs',          label: 'docs/ (developer documentation)' },
  { name: 'member-pages',  label: 'member-pages/ (auth pages)' },
  { name: 'tools',         label: 'tools/ (utilities)' },
  { name: 'content-packs', label: 'content-packs/ (setup wizard data)' },
  { name: 'framework',     label: 'framework/ (CSS design framework)' },
  { name: 'components',    label: 'components/ (HTML component library)' },
];

for (const { name, label } of dirs) {
  const src = resolve(PHP_DIR, name);
  if (existsSync(src)) {
    cpSync(src, resolve(DIST_DIR, name), { recursive: true });
    console.log(`  Copied ${label}`);
  } else {
    console.warn(`  Warning: ${name}/ not found`);
  }
}

// ─── content/ — user data directory ───
const CONTENT_DIR = resolve(DIST_DIR, 'content');
mkdirSync(CONTENT_DIR, { recursive: true });
writeFileSync(resolve(CONTENT_DIR, '.htaccess'), 'Options -Indexes\n');
console.log('  Created content/.htaccess');

// content/data/ — database storage, deny all web access
mkdirSync(resolve(CONTENT_DIR, 'data'), { recursive: true });
writeFileSync(resolve(CONTENT_DIR, 'data', '.htaccess'), 'Deny from all\n');
console.log('  Created content/data/.htaccess');

// content/uploads/ — user uploads, block PHP execution
mkdirSync(resolve(CONTENT_DIR, 'uploads'), { recursive: true });
writeFileSync(
  resolve(CONTENT_DIR, 'uploads', '.htaccess'),
  '<FilesMatch "\\.php$">\n    Deny from all\n</FilesMatch>\n'
);
console.log('  Created content/uploads/.htaccess');

// content/themes/ — copy starter themes
const themesSrc = resolve(PHP_DIR, 'themes');
if (existsSync(themesSrc)) {
  cpSync(themesSrc, resolve(CONTENT_DIR, 'themes'), { recursive: true });
  console.log('  Copied content/themes/ (starter + personal)');

  // Generate .outpost-manifest.json for managed themes
  const themesDestDir = resolve(CONTENT_DIR, 'themes');
  for (const themeSlug of readdirSync(themesDestDir)) {
    const themeDir = resolve(themesDestDir, themeSlug);
    if (!statSync(themeDir).isDirectory()) continue;

    const themeJsonPath = resolve(themeDir, 'theme.json');
    if (!existsSync(themeJsonPath)) continue;

    const themeJson = JSON.parse(readFileSync(themeJsonPath, 'utf8'));
    if (!themeJson.managed) continue;

    // Hash all files in the theme directory
    const manifest = {};
    function hashDir(dir, base) {
      for (const entry of readdirSync(dir)) {
        if (entry === '.outpost-manifest.json') continue;
        const fullPath = resolve(dir, entry);
        const relPath = relative(base, fullPath);
        if (statSync(fullPath).isDirectory()) {
          hashDir(fullPath, base);
        } else {
          const hash = createHash('md5').update(readFileSync(fullPath)).digest('hex');
          manifest[relPath] = hash;
        }
      }
    }
    hashDir(themeDir, themeDir);

    writeFileSync(resolve(themeDir, '.outpost-manifest.json'), JSON.stringify(manifest, null, 2));
    console.log(`  Generated .outpost-manifest.json for ${themeSlug} (${Object.keys(manifest).length} files)`);
  }
} else {
  mkdirSync(resolve(CONTENT_DIR, 'themes'), { recursive: true });
  console.warn('  Warning: themes/ not found, created empty content/themes/');
}

// content/backups/ — backup storage, deny all web access
mkdirSync(resolve(CONTENT_DIR, 'backups'), { recursive: true });
writeFileSync(resolve(CONTENT_DIR, 'backups', '.htaccess'), 'Deny from all\n');
console.log('  Created content/backups/.htaccess');

// URL-compatible symlinks: outpost/uploads → outpost/content/uploads, outpost/themes → outpost/content/themes
try {
  symlinkSync('content/uploads', resolve(DIST_DIR, 'uploads'));
  symlinkSync('content/themes', resolve(DIST_DIR, 'themes'));
  console.log('  Created symlinks: uploads → content/uploads, themes → content/themes');
} catch (err) {
  console.warn('  Warning: Could not create symlinks (may need admin privileges on Windows)');
}

// cache/ — compiled templates, deny all web access (outside content/)
mkdirSync(resolve(DIST_DIR, 'cache'), { recursive: true });
mkdirSync(resolve(DIST_DIR, 'cache', 'templates'), { recursive: true });
writeFileSync(resolve(DIST_DIR, 'cache', '.htaccess'), 'Deny from all\n');
console.log('  Created cache/.htaccess');

// ─── Root index.php (Apache/Nginx front controller) ───
const indexSrc = resolve(ROOT, 'test-site', 'index.php');
if (existsSync(indexSrc)) {
  cpSync(indexSrc, resolve(ROOT, 'dist', 'index.php'));
  console.log('  Copied index.php (front controller)');
} else {
  console.warn('  Warning: test-site/index.php not found — no front controller in dist/');
}

// ─── Root .htaccess (Apache rewrite rules) ───
const htaccess = `# Outpost CMS — Apache Rewrite Rules
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Serve static files directly
    RewriteCond %{REQUEST_FILENAME} -f
    RewriteRule ^ - [L]

    # Serve directories directly (outpost/ admin, docs, assets)
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]

    # Route everything else through index.php
    RewriteRule ^ index.php [L]
</IfModule>
`;
writeFileSync(resolve(ROOT, 'dist', '.htaccess'), htaccess);
console.log('  Created dist/.htaccess');

// ─── .gitignore for runtime directories ───
writeFileSync(
  resolve(DIST_DIR, '.gitignore'),
  `content/data/cms.db
content/data/.installed
content/uploads/*
!content/uploads/.htaccess
content/backups/*
!content/backups/.htaccess
cache/*
!cache/.htaccess
`
);
console.log('  Created .gitignore');

// ─── Create release zip ───
const pkg = JSON.parse(readFileSync(resolve(ROOT, 'package.json'), 'utf8'));
const version = pkg.version;
const zipName = `outpost-v${version}.zip`;
const zipPath = resolve(ROOT, 'dist', zipName);

// Remove old zip if exists
if (existsSync(zipPath)) rmSync(zipPath);

try {
  // Create zip from dist/ directory (contains outpost/ + index.php + .htaccess)
  execSync(`cd "${resolve(ROOT, 'dist')}" && zip -r "${zipName}" outpost/ index.php .htaccess`, { stdio: 'pipe' });
  console.log(`\n  Created ${zipName}`);
} catch (err) {
  console.warn(`\n  Warning: Could not create zip (is 'zip' installed?). dist/outpost/ is still ready.`);
}

// ─── Summary ───
const totalPhp = phpFiles.length;
const totalDirs = dirs.filter(d => existsSync(resolve(PHP_DIR, d.name))).length;
console.log(`\nDone! Packaged ${totalPhp} PHP files + ${totalDirs} directories.`);
console.log(`Distribution ready at: dist/outpost/`);
if (existsSync(zipPath)) {
  console.log(`Release zip: dist/${zipName}`);
  console.log(`Upload this zip to GitHub Releases for the auto-updater.`);
}
console.log('To install: copy the outpost/ folder to your site root and visit /outpost/install.php');
