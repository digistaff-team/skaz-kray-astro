// Downscale/compress the source photos in public/images in place.
// Slider photos -> max 1600w, block photos -> max 1000w, JPEG q72.
import fs from 'node:fs';
import path from 'node:path';
import sharp from 'sharp';

const jobs = [
  { dir: 'public/images/slider', width: 1600 },
  { dir: 'public/images/blocks', width: 1000 },
];

for (const { dir, width } of jobs) {
  const abs = path.resolve(dir);
  for (const f of fs.readdirSync(abs)) {
    if (!/\.jpe?g$/i.test(f)) continue;
    const p = path.join(abs, f);
    const input = fs.readFileSync(p);
    const before = input.length;
    const buf = await sharp(input)
      .resize({ width, withoutEnlargement: true })
      .jpeg({ quality: 72, mozjpeg: true })
      .toBuffer();
    fs.writeFileSync(p, buf);
    console.log(`${f}: ${(before / 1024).toFixed(0)}KB -> ${(buf.length / 1024).toFixed(0)}KB`);
  }
}
console.log('done');
