// Construye las maquetas autocontenidas: mete base.css, datos.js y comun.js dentro de cada HTML.
// Uso: node docs/diseno-prevision/fuente/construir.mjs
import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, '..');
const read = (f) => readFileSync(join(here, f), 'utf8');

for (const file of readdirSync(here).filter((f) => f.endsWith('.html'))) {
    const html = read(file)
        .replace(/<link rel="stylesheet" href="([\w.-]+\.css)">/g, (_, f) => `<style>\n${read(f)}</style>`)
        .replace(/<script src="([\w.-]+\.js)"><\/script>/g, (_, f) => `<script>\n${read(f)}</script>`);
    writeFileSync(join(out, file), html);
    console.log(`✓ ${file}`);
}
