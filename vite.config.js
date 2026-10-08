import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // The stylesheet, and one script: the screenshot viewer, loaded by
            // the project sheet alone when it has a gallery. No other public
            // page carries JavaScript, and the back-office gets Alpine from
            // Livewire's own bundle. A second Alpine would break every
            // wire:click.
            input: ['resources/css/app.css', 'resources/js/gallery.js'],
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
