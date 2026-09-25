import rss from '@astrojs/rss';
import { getCollection } from 'astro:content';
import type { APIContext } from 'astro';
import { postSlug, excerptFrom } from '../lib/utils.js';

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
      // Даты в контенте — московское время без пояса: явный +03:00, иначе они
      // зависели бы от пояса машины, где идёт сборка.
      pubDate: new Date(String(p.data.date).replace(' ', 'T') + '+03:00'),
      description: excerptFrom(p, 300),
      link: `/${postSlug(p)}/`,
    })),
    customData: '<language>ru-RU</language>',
  });
}
