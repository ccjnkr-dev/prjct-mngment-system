import { cp, lstat, mkdir, readFile, rm } from 'node:fs/promises';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

export async function prepareStatic(publicDirectory, outputDirectory) {
    // A missing Vite output should fail here, rather than produce a broken deployment.
    JSON.parse(await readFile(join(publicDirectory, 'build', 'manifest.json'), 'utf8'));
    await rm(outputDirectory, { recursive: true, force: true });
    await mkdir(outputDirectory, { recursive: true });
    await cp(publicDirectory, outputDirectory, {
        recursive: true,
        filter: async (source) => {
            const name = relative(publicDirectory, source);
            if (!name) return true;
            const parts = name.split(sep);
            if (parts.some(part => part.startsWith('.'))) return false;
            if (parts[0] === 'storage' || name === 'hot') return false;
            if (name === join('build', 'manifest.json')) return false;
            if (/\.(?:php\d*|phtml|phar)$/i.test(name)) return false;
            return !(await lstat(source)).isSymbolicLink();
        },
    });
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
    await prepareStatic(join(root, 'public'), join(root, 'dist'));
    console.log('Vercel static assets prepared in dist (PHP, local storage, and development files excluded).');
}
