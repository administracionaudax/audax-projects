// Capturas de las maquetas: escritorio (1440) y móvil (375), en claro y oscuro.
// Uso: node docs/diseno-prevision/fuente/capturas.mjs [filtro]
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');
const dir = join(root, 'capturas');
mkdirSync(dir, { recursive: true });

// [fichero, nombre, query, acción antes de capturar (selector a sobrevolar), página completa]
const SHOTS = [
    ['01-prevision-matriz.html', '01-matriz', '', null],
    ['01-prevision-matriz.html', '01-matriz-tooltip', '', { hover: '[data-person="luis"] td.c:nth-of-type(6)' }],
    ['01-prevision-matriz.html', '01-matriz-12-meses', '?meses=12&por=meses&abiertos=dis', null],
    ['02-prevision-barras.html', '02-barras', '', null],
    ['03-prevision-cronograma.html', '03-cronograma', '', null],
    ['04-ficha-previsto.html', '04-ficha-previsto', '', null],
    ['05-planificacion-proyecto.html', '05-planificacion', '', null],
    ['06-estimado-frente-a-real.html', '06-estimado-real', '', null],
    ['07-mi-carga.html', '07-mi-carga', '', null],
];
const VIEWPORTS = [['escritorio', 1440, 900], ['movil', 375, 812]];
const THEMES = ['light', 'dark'];
const filter = process.argv[2];

const browser = await chromium.launch();
for (const [file, name, query, action] of SHOTS) {
    if (filter && !name.includes(filter)) continue;
    for (const [vp, width, height] of VIEWPORTS) {
        for (const theme of THEMES) {
            const page = await browser.newPage({ viewport: { width, height }, colorScheme: theme, deviceScaleFactor: vp === 'movil' ? 2 : 1 });
            await page.goto(pathToFileURL(join(root, file)).href + query);
            await page.evaluate(() => document.fonts.ready);
            await page.waitForTimeout(250);
            if (action?.hover) {
                const el = page.locator(action.hover).first();
                await el.scrollIntoViewIfNeeded();
                await el.hover();
                await page.waitForTimeout(150);
            }
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            if (overflow > 0) console.warn(`⚠ ${name} ${vp}: la página desborda ${overflow}px en horizontal`);
            const path = join(dir, `${name}-${vp}-${theme === 'light' ? 'claro' : 'oscuro'}.png`);
            await page.screenshot({ path, fullPage: !action });
            console.log(`✓ ${path.replace(root + '/', '')}`);
            await page.close();
        }
    }
}
await browser.close();
