/** @type {import('next').NextConfig} */
const nextConfig = {
    basePath: process.env.NEXT_PUBLIC_BASE_PATH,
    assetPrefix: process.env.NEXT_PUBLIC_BASE_PATH,
	productionBrowserSourceMaps: true,
    output: "standalone",
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
