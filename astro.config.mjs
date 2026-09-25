import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

// Static build; the site is served as plain files by nginx on skaz-kray.ru.
export default defineConfig({
  site: 'https://skaz-kray.ru',
  trailingSlash: 'always',
  build: {
    format: 'directory', // /pravila/ -> pravila/index.html, preserves WP-style URLs
  },
  integrations: [
    sitemap({
      // Redirect-заглушки на старых кириллических адресах постов (см. [...slug].astro)
      // в карту сайта не нужны: у них canonical на латинский адрес. Собственных
      // страниц с кириллицей в пути у сайта нет.
      filter: (page) => !/[^\x00-\x7F]/.test(decodeURI(new URL(page).pathname)),
    }),
  ],
});
