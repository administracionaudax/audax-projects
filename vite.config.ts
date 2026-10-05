import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        inertia(),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ]),
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    test: {
        include: ['tests/js/**/*.test.{ts,tsx}'],
        environment: 'node',
        setupFiles: ['tests/js/setup.ts'],
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            // Documentación en prosa: no se reformatea con el formateador de código.
            '**/*.md',
            // Ficheros estáticos (p. ej. los datos de emojis autoalojados, minificados) y el
            // fragmento de compose que se pega sangrado bajo `services:` (D-070).
            'public/**',
            'deploy/whisper/compose-service.yml',
            'playwright-report/**',
            'test-results/**',
            '.github/**',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
            // Fragmento YAML que se pega con su sangría en /opt/audax/compose.yml (D-070).
            'deploy/whisper/compose-service.yml',
            'deploy/gotenberg/compose-service.yml',
            // Copia literal de la hoja de documentos A4 del kit de Audax (D-140): no se reformatea.
            'resources/views/reports/pdf/audax-doc.css',
            // Fixture generado con el JS original de WeeklySync (un caso por línea, D-153).
            'tests/fixtures/weeklies/satisfaction-cases.json',
            // Volcado de ejemplo de WeeklySync (D-213): lo escribe el volcador y el manifiesto
            // lleva el sha256 de cada tabla; reformatearlo lo invalidaría.
            'tests/fixtures/weeklysync/tables/**',
            'tests/fixtures/weeklysync/manifest.json',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
