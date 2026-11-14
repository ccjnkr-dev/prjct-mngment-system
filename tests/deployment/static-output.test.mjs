import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile, readFile, access, symlink, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { prepareStatic } from '../../scripts/prepare-vercel-static.mjs';

test('Vercel serves assets while keeping PHP, development files and private storage out of static output', async () => {
    const root = await mkdtemp(join(tmpdir(), 'ccjnkr-static-'));
    try {
        const source = join(root, 'public');
        const target = join(root, 'dist');
        await mkdir(join(source, 'build', 'assets'), { recursive: true });
        await mkdir(join(source, 'storage'), { recursive: true });
        const files = {
            'build/manifest.json': '{}', 'build/assets/app.js': 'public asset',
            'theme-init.js': 'theme initialization', 'robots.txt': 'User-agent: *',
            'index.php': '<?php echo "backend";', 'backdoor.PHTML': 'backend',
            '.htaccess': 'server configuration', '.env': 'SECRET=value',
            'hot': 'http://localhost:5173', 'storage/private.txt': 'private upload',
        };
        for (const [name, content] of Object.entries(files)) await writeFile(join(source, name), content);
        await writeFile(join(root, 'secret.txt'), 'not public');
        await symlink(join(root, 'secret.txt'), join(source, 'linked.txt'));
        await prepareStatic(source, target);
        assert.equal(await readFile(join(target, 'build/assets/app.js'), 'utf8'), 'public asset');
        assert.equal(await readFile(join(target, 'theme-init.js'), 'utf8'), 'theme initialization');
        for (const name of ['index.php', 'backdoor.PHTML', '.htaccess', '.env', 'hot', 'storage', 'linked.txt', 'build/manifest.json']) {
            await assert.rejects(access(join(target, name)), { code: 'ENOENT' });
        }
    } finally { await rm(root, { recursive: true, force: true }); }
});

test('missing frontend build fails rather than silently deploying an empty site', async () => {
    const root = await mkdtemp(join(tmpdir(), 'ccjnkr-missing-'));
    try { await assert.rejects(prepareStatic(join(root, 'public'), join(root, 'dist')), { code: 'ENOENT' }); }
    finally { await rm(root, { recursive: true, force: true }); }
});
