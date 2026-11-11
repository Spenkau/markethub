import { defineConfig, type ProxyOptions } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
const proxy: ProxyOptions = {
    target: 'http://markethub.test',
    changeOrigin: true,
    configure(server) {
        server.on('proxyReq', (req) => {
            req.setHeader('Origin', 'http://markethub.test');
            req.setHeader('Referer', 'http://markethub.test/');
        });
    },
};
export default defineConfig({
    plugins: [vue(), tailwindcss()],
    server: {
        host: '127.0.0.1',
        port: 5173,
        strictPort: true,
        proxy: { '/api': proxy, '/sanctum': proxy },
    },
});
