/** @type {import('next').NextConfig} */
const nextConfig = {
    basePath: process.env.NEXT_PUBLIC_BASE_PATH,
    assetPrefix: process.env.NEXT_PUBLIC_BASE_PATH,
	productionBrowserSourceMaps: true,
    output: "standalone",
    distDir: process.env.NEXT_DIST_DIR || ".next",
    images: {
        remotePatterns: [
            {
                protocol: 'https',
                hostname: '*',
            },
            {
                protocol: 'http',
                hostname: 'pe-api.test',
            },
            {
                protocol: 'https',
                hostname: 'pe-api.test',
            },
        ],
        minimumCacheTTL: 60 * 60 * 24 * 7, // Cache images for 7 days
        formats: ['image/webp'], // Use WebP for better compression
    },
    async rewrites() {
        return [
            {
                source: '/fetch-server-image-proxy/:documentId',
                destination: `${process.env.NEXT_INTERNAL_API_URL || 'http://slim'}/bookingfrontend/resources/document/:documentId/download`,
            },
            {
                source: '/fetch-building-image-proxy/:documentId',
                destination: `${process.env.NEXT_INTERNAL_API_URL || 'http://slim'}/bookingfrontend/buildings/document/:documentId/download`,
            },
        ];
    },
    async headers() {
        return [
            {
                // This header applies to all routes, including service workers
                source: '/:path*',
                headers: [
                    {
                        key: 'Service-Worker-Allowed',
                        value: '/',
                    },
                ],
            },
        ];
    },
};

export default nextConfig;
