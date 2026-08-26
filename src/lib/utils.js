const MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
  'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

// "2013-03-14 13:36:17" -> "14 марта 2013"
export function ruDate(s) {
  if (!s) return '';
  const m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!m) return s;
  const [, y, mo, d] = m;
  return `${parseInt(d, 10)} ${MONTHS[parseInt(mo, 10) - 1]} ${y}`;
}

export function sortByDateDesc(a, b) {
  return String(b.data.date).localeCompare(String(a.data.date));
}

// Decode HTML entities (numeric refs like &#x1f333; for emoji, plus common named ones)
function decodeEntities(str) {
  return str
    .replace(/&#x([0-9a-f]+);/gi, (_, hex) => String.fromCodePoint(parseInt(hex, 16)))
    .replace(/&#(\d+);/g, (_, dec) => String.fromCodePoint(parseInt(dec, 10)))
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&(#39|apos);/g, "'");
}

// Plain-text excerpt from HTML body
export function excerptFrom(entry, len = 180) {
  if (entry.data.excerpt) return decodeEntities(entry.data.excerpt);
  const text = decodeEntities((entry.body || '').replace(/<[^>]+>/g, ' '))
    .replace(/&[a-z]+;/g, ' ') // any remaining unknown named entities
    .replace(/\s+/g, ' ')
    .trim();
  return text.length > len ? text.slice(0, len).replace(/\s+\S*$/, '') + '…' : text;
}

// First image src in a post body (for card thumbnails), or null
export function firstImage(entry) {
  if (entry.data.cover) return entry.data.cover;
  const m = (entry.body || '').match(/<img[^>]+src=["']([^"']+)["']/i);
  return m ? m[1] : null;
}
