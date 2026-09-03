import { defineCollection, z } from 'astro:content';

// Decap CMS datetime widget пишет дату в frontmatter без кавычек, YAML парсит
// её как нативный Date, а не строку — коэрсим обратно в исходный формат
// "YYYY-MM-DD HH:mm:ss" (компоненты берём в UTC, т.к. без явной timezone
// YAML-парсер трактует timestamp как UTC).
const pad = (n: number) => String(n).padStart(2, '0');
const dateAsString = z.union([z.string(), z.date()]).transform((v) => {
  if (typeof v === 'string') return v;
  return `${v.getUTCFullYear()}-${pad(v.getUTCMonth() + 1)}-${pad(v.getUTCDate())} ${pad(v.getUTCHours())}:${pad(v.getUTCMinutes())}:${pad(v.getUTCSeconds())}`;
});

const pages = defineCollection({
  type: 'content',
  schema: z.object({
    title: z.string(),
    permalink: z.string(),
    date: dateAsString.optional(),
    seoTitle: z.string().optional(),
    seoDescription: z.string().optional(),
  }),
});

const posts = defineCollection({
  type: 'content',
  schema: z.object({
    title: z.string(),
    date: dateAsString,
    categories: z.array(z.string()).default([]),
    excerpt: z.string().optional(),
    cover: z.string().optional(),
    seoTitle: z.string().optional(),
    seoDescription: z.string().optional(),
  }),
});

export const collections = { pages, posts };
