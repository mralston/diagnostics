/**
 * Vite plugin for host applications.
 *
 * Registers the `@mralston/diagnostics` alias so the host can import the panel
 * and composable straight from the Composer package, and allows Vite's dev
 * server to serve those files when vendor/mralston/diagnostics is a symlink to
 * a checkout outside the project (a Composer path repository).
 *
 *   import diagnostics from './vendor/mralston/diagnostics/vite.js';
 *   export default defineConfig({ plugins: [laravel({...}), vue(), diagnostics()] });
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export default function diagnostics(options = {}) {
    const here = path.dirname(fileURLToPath(import.meta.url));
    const js = fs.realpathSync(path.join(here, 'resources', 'js'));
    const alias = options.alias ?? '@mralston/diagnostics';

    return {
        name: 'mralston-diagnostics',
        config() {
            return {
                resolve: {
                    alias: { [alias]: js },
                },
                server: {
                    fs: {
                        allow: [fs.realpathSync(here)],
                    },
                },
            };
        },
    };
}
