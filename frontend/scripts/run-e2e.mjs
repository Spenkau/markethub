import { spawn } from 'node:child_process';
import { realpathSync } from 'node:fs';
import { fileURLToPath, URL } from 'node:url';
import process from 'node:process';
import console from 'node:console';
import { join } from 'node:path';

// Canonical Windows casing prevents loading two Playwright test singletons.
const root = realpathSync.native(fileURLToPath(new URL('../', import.meta.url)));
const child = spawn(
    process.execPath,
    [join(root, 'node_modules/@playwright/test/cli.js'), 'test', ...process.argv.slice(2)],
    {
        cwd: root,
        windowsHide: true,
        stdio: ['inherit', 'pipe', 'pipe'],
    },
);
// Keep native stderr warnings from being interpreted as PowerShell command errors.
child.stdout.pipe(process.stdout);
child.stderr.pipe(process.stdout);
child.on('error', (error) => {
    console.log(error.message);
    process.exitCode = 1;
});
child.on('exit', (code, signal) => {
    console.log(`Playwright runner exit: ${code ?? signal}`);
    process.exitCode = code ?? 1;
});
