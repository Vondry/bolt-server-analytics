// Checks the syntax of the inline <script> blocks of the Twig templates: no
// PHP tool reads them. Twig output ({{ … }}) becomes a number, Twig tags and
// comments go; then every script is compiled, never run.
//
//   node .github/scripts/check-template-scripts.mjs
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { Script } from 'node:vm';

const root = new URL('../../templates/', import.meta.url).pathname;

function templates(directory) {
    return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const path = join(directory, entry.name);
        if (entry.isDirectory()) {
            return templates(path);
        }
        return entry.name.endsWith('.twig') ? [path] : [];
    });
}

let scripts = 0;
let failures = 0;
for (const file of templates(root)) {
    const source = readFileSync(file, 'utf8');
    for (const match of source.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)) {
        const line = source.slice(0, match.index).split('\n').length;
        const code = match[1]
            .replace(/\{#[\s\S]*?#\}/g, '')
            .replace(/\{%[\s\S]*?%\}/g, '')
            .replace(/\{\{[\s\S]*?\}\}/g, '0');
        scripts += 1;
        try {
            new Script(code, { filename: `${relative(process.cwd(), file)}:${line}` });
        } catch (error) {
            failures += 1;
            console.error(`${relative(process.cwd(), file)}, script at line ${line}: ${error.message}`);
        }
    }
}

if (scripts === 0) {
    console.error('No inline scripts found in templates/.');
    process.exit(1);
}

console.log(`${scripts} inline script(s) checked, ${failures} with a syntax error.`);
process.exit(failures === 0 ? 0 : 1);
