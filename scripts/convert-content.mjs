// Convert the exported WordPress content (frontmatter + raw HTML) into Astro
// content-collection entries. Cleans WP shortcodes and Word/MSO junk, localizes
// skaz-kray.ru links/images to site-relative, maps categories to slugs.
import fs from 'node:fs';
import path from 'node:path';
import matter from 'gray-matter';
import { autop } from '@wordpress/autop';

const EXPORT = 'C:/Users/user/AppData/Local/Temp/claude/C--Projects-abconsult-app/cdecd18e-539e-4271-9c53-2a34a3eb88be/scratchpad/skaz-migration/export';
const OUT_PAGES = 'C:/Projects/skaz-kray-astro/src/content/pages';
const OUT_POSTS = 'C:/Projects/skaz-kray-astro/src/content/posts';

// category name -> slug (mirror of src/data/categories.js)
const { categories } = await import('../src/data/categories.js');
const byNameLoose = {};
const norm = (s) => String(s).replace(/[«»„“”"]/g, '').replace(/\s+/g, ' ').trim().toLowerCase();
for (const c of categories) byNameLoose[norm(c.name)] = c.slug;
const catSlug = (name) => byNameLoose[norm(name)] || null;

const SKIP_PAGE_SLUGS = new Set(['yarmarka', 'kontakty']); // Ярмарка dropped; Контакты is bespoke

function stripMso(html) {
  return html
    .replace(/<!--\[if[^\]]*\]>[\s\S]*?<!\[endif\]-->/gi, '')   // full conditional comments
    .replace(/<!--\[if[^\]]*\]>/gi, '')
    .replace(/\[if[^\]]*\]&gt;-->/gi, '')
    .replace(/<!\[endif\]-->/gi, '')
    .replace(/\[endif\]/gi, '')
    .replace(/<!--\s*(StartFragment|EndFragment)\s*-->/gi, '')
    .replace(/<\/?o:p>/gi, '')
    .replace(/\sclass="Mso[^"]*"/gi, '')
    .replace(/<span[^>]*>(\s*)<\/span>/gi, '$1');
}

function convertCaptions(html) {
  return html.replace(/\[caption([^\]]*)\]([\s\S]*?)\[\/caption\]/gi, (m, attrs, inner) => {
    const w = (attrs.match(/width=["'](\d+)["']/) || [])[1];
    const alignM = attrs.match(/align=["']([^"']+)["']/);
    const align = alignM ? ' ' + alignM[1] : '';
    // split media (a>img or img) from trailing caption text
    const mm = inner.match(/^([\s\S]*?<img\b[^>]*>(?:\s*<\/a>)?)([\s\S]*)$/i);
    const media = mm ? mm[1].trim() : inner.trim();
    const cap = mm ? mm[2].trim() : '';
    const style = w ? ` style="max-width:${w}px"` : '';
    return `<figure class="wp-caption${align}"${style}>${media}` +
      (cap ? `<figcaption>${cap}</figcaption>` : '') + `</figure>`;
  });
}

function convertAudio(html) {
  return html.replace(/\[audio\s+mp3=["']([^"']+)["'][^\]]*\]/gi,
    (m, url) => `<audio controls src="${url}"></audio>`);
}

function localize(html) {
  return html.replace(/https?:\/\/(?:www\.)?skaz-kray\.ru/gi, '');
}

function cleanBody(html) {
  let h = html || '';
  h = stripMso(h);
  h = convertCaptions(h);
  h = convertAudio(h);
  h = localize(h);
  h = autop(h);            // WP-faithful paragraphs / <br>, applied last
  h = h.replace(/\n{3,}/g, '\n\n').trim();
  return h;
}

function yamlSafe(obj) {
  // gray-matter/js-yaml handles quoting; keep undefined out
  const clean = {};
  for (const [k, v] of Object.entries(obj)) {
    if (v === undefined || v === null || v === '' ||
        (Array.isArray(v) && v.length === 0)) continue;
    clean[k] = v;
  }
  return clean;
}

let nPages = 0, nPosts = 0, warnings = [];

function processDir(subdir, kind) {
  const dir = path.join(EXPORT, subdir);
  for (const file of fs.readdirSync(dir)) {
    if (!file.endsWith('.md')) continue;
    const raw = fs.readFileSync(path.join(dir, file), 'utf8');
    const { data, content } = matter(raw);

    if (data.status !== 'publish') continue;
    const slug = data.slug || String(data.id);

    if (kind === 'page') {
      if (SKIP_PAGE_SLUGS.has(slug)) continue;
      const fm = yamlSafe({
        title: data.title,
        permalink: data.permalink,
        date: data.date,
        seoTitle: data.seo?.su_title || data.seo?.title,
        seoDescription: data.seo?.su_description || data.seo?.description,
      });
      const body = cleanBody(content);
      fs.writeFileSync(path.join(OUT_PAGES, `${slug}.md`), matter.stringify('\n' + body + '\n', fm));
      nPages++;
    } else {
      const catNames = data.categories || [];
      const slugs = catNames.map(catSlug).filter(Boolean);
      const missing = catNames.filter((n) => !catSlug(n));
      if (missing.length) warnings.push(`${slug}: unmapped cat ${JSON.stringify(missing)}`);
      const fm = yamlSafe({
        title: data.title,
        date: data.date,
        categories: slugs,
        excerpt: data.excerpt,
        cover: data.featured_image ? localize(data.featured_image) : undefined,
        seoTitle: data.seo?.su_title || data.seo?.title,
        seoDescription: data.seo?.su_description || data.seo?.description,
      });
      const body = cleanBody(content);
      fs.writeFileSync(path.join(OUT_POSTS, `${slug}.md`), matter.stringify('\n' + body + '\n', fm));
      nPosts++;
    }
  }
}

fs.mkdirSync(OUT_PAGES, { recursive: true });
fs.mkdirSync(OUT_POSTS, { recursive: true });
// wipe previous generated content (keep dirs)
for (const d of [OUT_PAGES, OUT_POSTS])
  for (const f of fs.readdirSync(d)) if (f.endsWith('.md')) fs.unlinkSync(path.join(d, f));

processDir('pages', 'page');
processDir('posts', 'post');

console.log(`Converted: ${nPages} pages, ${nPosts} posts`);
if (warnings.length) { console.log('Warnings:'); warnings.forEach((w) => console.log('  ' + w)); }
