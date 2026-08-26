import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

// Static build; the site is served as plain files by nginx on skaz-kray.ru.
export default defineConfig({
  site: 'https://skaz-kray.ru',
  trailingSlash: 'always',
  build: {
    format: 'directory', // /pravila/ -> pravila/index.html, preserves WP-style URLs
  },
  integrations: [sitemap()],
});
