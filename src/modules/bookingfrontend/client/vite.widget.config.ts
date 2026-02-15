import {defineConfig, type Plugin} from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';
import fs from 'fs';

const SCOPE = '.pe-widget-scope';
const OUT_DIR = path.resolve(__dirname, '../../booking/js/built');

/**
 * Vite plugin that scopes all CSS output under a wrapper class.
 * Prevents designsystemet :root variables and global styles from
 * leaking into the host page.
 */
function cssScope(): Plugin {
	return {
		name: 'css-scope',
		closeBundle() {
			const cssPath = path.join(OUT_DIR, 'highlighted-buildings.css');
			if (!fs.existsSync(cssPath)) return;

			let css = fs.readFileSync(cssPath, 'utf-8');

			// Replace :root with the scope class.
			css = css.replace(/:root/g, SCOPE);

			// Scope bare attribute selectors that the designsystemet uses for theming.
			// e.g. ",[data-color-scheme]{"  →  ",.pe-widget-scope [data-color-scheme]{"
			css = css.replace(
				/,\s*(\[data-(?:color-scheme|color|size|typography)[^\]]*\])/g,
				`, ${SCOPE} $1`,
			);

			fs.writeFileSync(cssPath, css);
		},
	};
}

export default defineConfig({
	plugins: [react(), cssScope()],
	publicDir: false,
	resolve: {
		alias: [
			// Specific shims MUST come before the generic @/ alias
			{find: '@/app/i18n/ClientTranslationProvider', replacement: path.resolve(__dirname, 'src/widgets/shims/client-translation-provider.ts')},
			{find: '@/app/i18n/settings', replacement: path.resolve(__dirname, 'src/widgets/shims/i18n-settings.ts')},
			{find: '@/service/hooks/api-hooks', replacement: path.resolve(__dirname, 'src/widgets/shims/api-hooks.ts')},
			{find: '@/service/hooks/is-mobile', replacement: path.resolve(__dirname, 'src/widgets/shims/is-mobile.ts')},
			{find: '@/service/multi-domain-utils', replacement: path.resolve(__dirname, 'src/widgets/shims/multi-domain-utils.ts')},
			// Shim Next.js modules
			{find: 'next/image', replacement: path.resolve(__dirname, 'src/widgets/shims/next-image.tsx')},
			{find: 'next/link', replacement: path.resolve(__dirname, 'src/widgets/shims/next-link.tsx')},
			// Generic path alias — must be last
			{find: '@/', replacement: path.resolve(__dirname, 'src') + '/'},
		],
	},
	define: {
		'process.env': JSON.stringify({
			NODE_ENV: 'production',
			NEXT_PUBLIC_BASE_PATH: '',
		}),
	},
	build: {
		outDir: OUT_DIR,
		emptyOutDir: true,
		lib: {
			entry: path.resolve(__dirname, 'src/widgets/highlighted-buildings.tsx'),
			name: 'HighlightedBuildingsWidget',
			formats: ['iife'],
			fileName: () => 'highlighted-buildings.js',
		},
		cssCodeSplit: false,
		rollupOptions: {
			output: {
				inlineDynamicImports: true,
				assetFileNames: 'highlighted-buildings[extname]',
			},
		},
	},
	css: {
		preprocessorOptions: {
			scss: {
				silenceDeprecations: ['legacy-js-api'],
			},
		},
	},
});
