import { v4wp } from '@kucrut/vite-for-wp';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

export default defineConfig( {
	plugins: [ v4wp( { input: 'src/main.tsx', outDir: 'dist' } ), react(), tailwindcss() ],
	resolve: {
		alias: { '@': fileURLToPath( new URL( './src', import.meta.url ) ) },
	},
	build: {
		sourcemap: false,
		chunkSizeWarningLimit: 600,
	},
} );
