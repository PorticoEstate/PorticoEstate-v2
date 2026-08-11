import {defineConfig} from 'vite';
import react from '@vitejs/plugin-react';

const apiPort = process.env.API_PORT || '3002';

// Hostnames the dev server will answer to. Override with DMAIL_ALLOWED_HOSTS
// (comma separated) if you serve dmail under a different name.
const allowedHosts = (process.env.DMAIL_ALLOWED_HOSTS || 'dmail.test,pe-mail.test,localhost')
    .split(',')
    .map((host) => host.trim())
    .filter(Boolean);

export default defineConfig({
    plugins: [react()],
    server: {
        allowedHosts,
        proxy: {
            '/api': `http://localhost:${apiPort}`,
        },
    },
});
