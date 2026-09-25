import { defineCollection } from 'astro:content';
import { glob } from 'astro/loaders';
import { z } from 'astro/zod';

// Content Layer (Astro 5+): файлы по-прежнему лежат в src/content/{pages,posts}.
// id записи = путь без расширения, слагифицированный как раньше entry.slug —
// на нём держатся адреса постов (postSlug) и redirect-заглушки.

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
  loader: glob({ pattern: '**/*.md', base: './src/content/pages' }),
  schema: z.object({
    title: z.string(),
    permalink: z.string(),
    date: dateAsString.optional(),
    seoTitle: z.string().optional(),
    seoDescription: z.string().optional(),
  }),
});

const posts = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/posts' }),
  schema: z.object({
    title: z.string(),
    date: dateAsString,
    categories: z.array(z.string()).default([]),
    excerpt: z.string().optional(),
    cover: z.string().optional(),
    // Галерея фото: список URL (Decap list из виджетов tgimage). Пустые
    // строки отсеиваем на рендере — Decap может оставить пустой пункт.
    gallery: z.array(z.string()).optional(),
    seoTitle: z.string().optional(),
    seoDescription: z.string().optional(),
  }),
});

export const collections = { pages, posts };
