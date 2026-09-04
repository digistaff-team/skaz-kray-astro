import rss from '@astrojs/rss';
import { getCollection } from 'astro:content';
import type { APIContext } from 'astro';
import { postSlug } from '../lib/utils.js';

// RSS at /feed.xml; nginx internally rewrites the legacy WP URL /feed/ to it,
// so existing subscribers keep working.
export async function GET(context: APIContext) {
  const posts = await getCollection('posts');
  posts.sort((a, b) => String(b.data.date).localeCompare(String(a.data.date)));
  return rss({
    title: 'Сказочный Край',
    description: 'Поселение родовых поместий в Краснодарском крае — дневники, новости, статьи.',
    site: context.site!,
    items: posts.slice(0, 40).map((p) => ({
      title: p.data.title,
      pubDate: new Date(String(p.data.date).replace(' ', 'T')),
      description: p.data.excerpt || '',
      link: `/${postSlug(p)}/`,
    })),
    customData: '<language>ru-RU</language>',
  });
}
