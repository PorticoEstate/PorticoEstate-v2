'use client';

import { useQueryClient } from '@tanstack/react-query';
import { useMessageTypeSubscription } from './use-websocket-subscriptions';
import type { IWSCacheInvalidationMessage } from '../websocket/websocket.types';

/**
 * Global hook that listens for cache_invalidation WebSocket messages
 * and invalidates React Query caches accordingly.
 *
 * Should be registered once at app root level.
 */
export function useCacheInvalidation() {
    const queryClient = useQueryClient();

    useMessageTypeSubscription('cache_invalidation', (message: IWSCacheInvalidationMessage) => {
        console.log('[Cache Invalidation] Received:', message);

        const { queryKeys } = message;

        if (!queryKeys || queryKeys.length === 0) {
            console.warn('[Cache Invalidation] No query keys provided');
            return;
        }

        // Invalidate each specified query key
        queryKeys.forEach(queryKey => {
            console.log('[Cache Invalidation] Invalidating:', queryKey);
            queryClient.invalidateQueries({ queryKey });
        });

        console.log('[Cache Invalidation] Complete - invalidated', queryKeys.length, 'cache(s)');
    });
}
