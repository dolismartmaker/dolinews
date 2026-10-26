import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import { existsSync, readdirSync } from 'node:fs';

// One stylesheet per skin of the reader-facing pages, found on disk: adding
// a skin is adding its directory (resources/skins/<code>/skin.css).
const skinSheets = readdirSync('resources/skins', { withFileTypes: true })
    .filter((entry) => entry.isDirectory() && existsSync(`resources/skins/${entry.name}/skin.css`))
    .map((entry) => `resources/skins/${entry.name}/skin.css`)
    .sort();

export default defineConfig({
    plugins: [
        laravel({
            // The public stylesheet, the back-office one (daisyUI, loaded by
            // the admin layout alone), and one script: the screenshot viewer,
            // loaded by the project sheet alone when it has a gallery. No
            // other page carries JavaScript, and the back-office gets Alpine
            // from Livewire's own bundle. A second Alpine would break every
            // wire:click. The skin sheets follow, CSS only.
            input: ['resources/css/app.css', 'resources/css/admin.css', 'resources/js/gallery.js', ...skinSheets],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
