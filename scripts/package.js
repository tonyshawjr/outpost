#!/usr/bin/env node
/**
 * Outpost CMS — Package Script
 * Builds Svelte admin panel and packages everything into a distributable `outpost/` folder.
 *
 * Usage: node scripts/package.js
 * Or:    npm run package
 */

import { cpSync, mkdirSync, existsSync, writeFileSync, rmSync, readdirSync } from 'fs';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';

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

// ─── Directories to copy recursively ───
const dirs = [
  { name: 'admin',        label: 'admin/ (compiled Svelte SPA)' },
  { name: 'themes',       label: 'themes/ (starter + personal)' },
  { name: 'docs',         label: 'docs/ (developer documentation)' },
  { name: 'member-pages', label: 'member-pages/ (auth pages)' },
  { name: 'tools',        label: 'tools/ (utilities)' },
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

// ─── Create protected directories with .htaccess ───

// data/ — database storage, deny all web access
mkdirSync(resolve(DIST_DIR, 'data'), { recursive: true });
writeFileSync(resolve(DIST_DIR, 'data', '.htaccess'), 'Deny from all\n');
console.log('  Created data/.htaccess');

// uploads/ — user uploads, block PHP execution
mkdirSync(resolve(DIST_DIR, 'uploads'), { recursive: true });
writeFileSync(
  resolve(DIST_DIR, 'uploads', '.htaccess'),
  '<FilesMatch "\\.php$">\n    Deny from all\n</FilesMatch>\n'
);
console.log('  Created uploads/.htaccess');

// cache/ — compiled templates, deny all web access
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

    # Route everything else through index.php
    RewriteRule ^ index.php [L]
</IfModule>
`;
writeFileSync(resolve(ROOT, 'dist', '.htaccess'), htaccess);
console.log('  Created dist/.htaccess');

// ─── .gitignore for runtime directories ───
writeFileSync(
  resolve(DIST_DIR, '.gitignore'),
  `data/cms.db
data/.installed
uploads/*
!uploads/.htaccess
cache/*
!cache/.htaccess
`
);
console.log('  Created .gitignore');

// ─── Summary ───
const totalPhp = phpFiles.length;
const totalDirs = dirs.filter(d => existsSync(resolve(PHP_DIR, d.name))).length;
console.log(`\nDone! Packaged ${totalPhp} PHP files + ${totalDirs} directories.`);
console.log(`Distribution ready at: dist/outpost/`);
console.log('To install: copy the outpost/ folder to your site root and visit /outpost/install.php');
