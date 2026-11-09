// Post-build prerender step (static site generation).
//
// Runs after `vite build` (client) and `vite build --ssr` (server entry).
// For every route in src/seo/routes.ts it:
//   1. renders the full React page to HTML (real headings, text and links —
//      not an empty <div id="root">), so every crawler sees the content;
//   2. bakes the route's <title>, meta description, canonical, Open Graph,
//      Twitter and JSON-LD tags into <head>;
//   3. writes dist/<route>.html (Vercel cleanUrls serves it at /<route>);
//   4. writes dist/404.html (served with a real 404 status) and sitemap.xml.
//
// The browser then hydrates the HTML (see src/main.tsx).

import { readFileSync, writeFileSync, mkdirSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');
const DIST = join(ROOT, 'dist');
const SERVER_DIR = join(ROOT, 'dist-server');

const server = await import(pathToFileURL(join(SERVER_DIR, 'entry-server.js')).href);
const { render, ROUTES, NOT_FOUND_SEO, buildHeadTags, absoluteUrl } = server;

let template;
try {
  template = readFileSync(join(DIST, 'index.html'), 'utf8');
} catch {
  console.error('[prerender] dist/index.html not found — run "vite build" first.');
  process.exit(1);
}

function renderPage(url, route) {
  const { title, html: headTags } = buildHeadTags(route);
  const appHtml = render(url);

  let html = template
    .replace(/<title>[\s\S]*?<\/title>/i, `<title>${title}</title>`)
    .replace(/<meta\s+name=["']description["'][^>]*>\s*/i, '');

  html = html.includes('<!--SEO-->')
    ? html.replace('<!--SEO-->', headTags)
    : html.replace('</head>', `    ${headTags}\n  </head>`);

  return html.replace('<div id="root"></div>', `<div id="root">${appHtml}</div>`);
}

const outFile = (path) => (path === '/' ? join(DIST, 'index.html') : join(DIST, `${path.replace(/^\//, '')}.html`));

let count = 0;
for (const route of ROUTES) {
  const file = outFile(route.path);
  mkdirSync(dirname(file), { recursive: true });
  const rendered = renderPage(route.path, route);
  writeFileSync(file, rendered);
  count += 1;

  // For non-root routes, also write <route>/index.html
  // Guarantees directory compatibility for parent routes sharing folder names (e.g. /services, /blog)
  if (route.path !== '/') {
    const dirIndex = join(DIST, route.path.replace(/^\//, ''), 'index.html');
    mkdirSync(dirname(dirIndex), { recursive: true });
    writeFileSync(dirIndex, rendered);
  }
}

// 404 page — Vercel serves dist/404.html with HTTP 404 for unknown URLs.
writeFileSync(join(DIST, '404.html'), renderPage('/__not-found__', NOT_FOUND_SEO));

console.log(`[prerender] wrote ${count} prerendered pages + 404.html`);

// sitemap.xml
const today = new Date().toISOString().split('T')[0];
const urls = ROUTES.filter((r) => !r.noindex)
  .map((r) => {
    const lines = [`    <loc>${absoluteUrl(r.path)}</loc>`, `    <lastmod>${r.lastmod || today}</lastmod>`];
    if (r.changefreq) lines.push(`    <changefreq>${r.changefreq}</changefreq>`);
    if (r.priority != null) lines.push(`    <priority>${r.priority.toFixed(1)}</priority>`);
    return `  <url>\n${lines.join('\n')}\n  </url>`;
  })
  .join('\n');

const sitemapContent = `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls}\n</urlset>\n`;
writeFileSync(join(DIST, 'sitemap.xml'), sitemapContent);
writeFileSync(join(ROOT, 'public', 'sitemap.xml'), sitemapContent);
console.log(`[prerender] sitemap.xml with ${ROUTES.length} URLs (written to dist and public)`);

// The server bundle is only needed during the build.
rmSync(SERVER_DIR, { recursive: true, force: true });
