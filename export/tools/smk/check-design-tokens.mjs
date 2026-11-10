import { readFile, readdir } from 'node:fs/promises';

const root = 'resources/css/smk/';
const names = (await readdir(root)).filter(name => name.endsWith('.css'));
const sources = await Promise.all(names.map(name => readFile(root + name, 'utf8')));
const declarations = new Map();
for (const source of sources) {
    for (const [, name, value] of source.matchAll(/(--smk-[\w-]+)\s*:\s*([^;{}]+);/g)) {
        const references = [...value.matchAll(/var\((--smk-[\w-]+)/g)].map(match => match[1]);
        declarations.set(name, new Set([...(declarations.get(name) || []), ...references]));
    }
}
const visiting = new Set();
const visited = new Set();
function visit(name) {
    if (!declarations.has(name)) throw new Error('Missing default for ' + name);
    if (visiting.has(name)) throw new Error('Cyclic token reference at ' + name);
    if (visited.has(name)) return;
    visiting.add(name);
    for (const dependency of declarations.get(name)) visit(dependency);
    visiting.delete(name);
    visited.add(name);
}
for (const name of declarations.keys()) visit(name);
for (const name of ['base.css', 'typography.css']) {
    if ((await readFile(root + name, 'utf8')).includes('var(--smk-primitive-')) throw new Error(name + ' consumes private primitives');
}
const manifest = JSON.parse(await readFile('public/build/manifest.json', 'utf8'));
const css = await readFile('public/build/' + manifest['resources/css/site.css'].file, 'utf8');
for (const utility of ['bg-smk-accent', 'text-smk-on-accent', 'text-smk-lead', 'max-w-smk-reading', 'px-smk-gutter', 'py-smk-section-normal']) {
    if (!css.includes('.' + utility)) throw new Error('Missing built utility: ' + utility);
}
console.log(`PASS: ${declarations.size} SMK tokens have defaults and no alias cycles; consumers use public roles; used utilities survive the build.`);
