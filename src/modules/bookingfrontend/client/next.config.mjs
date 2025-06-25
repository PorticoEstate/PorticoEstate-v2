/** @type {import('next').NextConfig} */
const nextConfig = {
    basePath: process.env.NEXT_PUBLIC_BASE_PATH,
    assetPrefix: process.env.NEXT_PUBLIC_BASE_PATH,
	productionBrowserSourceMaps: true,
    output: "standalone",
    // Configure allowed dev origins to prevent cross-origin requests
    allowedDevOrigins: [
        'pe-api.test',
        'localhost',
        '127.0.0.1',
        // Add any other domains you use for development
    ],
    // Dev indicators configuration for Next.js 15
    devIndicators: {
        position: 'bottom-right',
    },
    // Disable development features that don't work well with basePath
    ...(process.env.NODE_ENV === 'development' && {
        experimental: {
            // Disable features that cause basePath issues in dev mode
            optimizePackageImports: [],
        },
        // Disable error overlay to prevent __nextjs_original-stack-frames requests
        onDemandEntries: {
            maxInactiveAge: 25 * 1000,
            pagesBufferLength: 2,
        },
    }),
    images: {
        remotePatterns: [
            {
                protocol: 'https',
                hostname: '*',
            },
        ],
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
