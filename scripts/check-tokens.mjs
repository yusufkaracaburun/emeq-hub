#!/usr/bin/env node
/*
 * Faalt de build wanneer een stylesheet of component een design-token gebruikt
 * dat nergens gedefinieerd is.
 *
 * Waarom dit een eigen check nodig heeft: `var(--typo)` levert geen fout op.
 * De browser laat die ene declaratie vallen, het element erft of valt terug op
 * de initiële waarde, en de pagina ziet er nét anders uit. Geen console-fout,
 * geen falende build, geen falende test — TypeScript kijkt niet in `var()` en
 * de linter vlagt kapotte waarden, niet ontbrekende.
 *
 * De concrete blootstelling hier: components/ui/glyphs.tsx vult inline-SVG's
 * met var(--color-card) en var(--color-brand) uit het @theme-blok in app.css.
 * Hernoem daar een token en de glyphs worden stil transparant.
 */
import { readdir, readFile } from 'node:fs/promises';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../resources', import.meta.url));

/*
  Vendor-views zijn van derden en laden hun eigen stylesheets — scramble/docs
  gebruikt var(--color-canvas) uit de Stoplight-CSS op unpkg. Die tokens kunnen
  wij niet zien en zijn niet van ons; meescannen zou vals alarm geven, en een
  check die wolf roept wordt uitgezet.
*/
const SKIP = /(^|\/)vendor\//;
const SCAN = /\.(css|tsx?|blade\.php)$/;

async function* walk(dir) {
    for (const entry of await readdir(dir, { withFileTypes: true })) {
        const path = join(dir, entry.name);
        if (entry.isDirectory()) yield* walk(path);
        else if (SCAN.test(entry.name)) yield path;
    }
}

const defined = new Set();
const used = new Map(); // token -> Set<bestand>

for await (const path of walk(ROOT)) {
    if (SKIP.test(relative(ROOT, path))) continue;
    const source = await readFile(path, 'utf8');

    /*
      Niet op regelbegin ankeren. `--bg: #fff; --card: #fff;` op één regel is
      geldige CSS en komt hier voor (errors/_layout.blade.php); een geankerde
      regex ziet dan alleen de eerste en meldt de rest ten onrechte als dood.
    */
    for (const [, name] of source.matchAll(/--([\w-]+)\s*:/g)) defined.add(name);

    for (const [, name] of source.matchAll(/var\(\s*--([\w-]+)/g)) {
        if (!used.has(name)) used.set(name, new Set());
        used.get(name).add(relative(ROOT, path));
    }
}

const unknown = [...used.keys()].filter((name) => !defined.has(name)).sort();

if (unknown.length) {
    console.error(`\nOnbekende design-tokens: ${unknown.length}\n`);
    for (const name of unknown) {
        console.error(`  --${name}`);
        for (const file of [...used.get(name)].sort()) console.error(`      ${file}`);
    }
    console.error(
        '\nDefinieer ze in het @theme-blok van resources/css/app.css, of corrigeer de naam.' +
            '\nEen fallback toevoegen (var(--x, rood)) telt niet: dat maakt de verkeerde' +
            '\nwaarde permanent en onzichtbaar in plaats van hem hier te vangen.\n',
    );
    process.exit(1);
}

console.log(`design-tokens: ${used.size} gebruikt, ${defined.size} gedefinieerd, geen onbekende`);
