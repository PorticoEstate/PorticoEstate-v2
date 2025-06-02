'use client';

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { fetchVersionSettings, setVersionSettings, VersionSettings } from '../api/api-utils';
import { phpGWLink } from '../util';
import { usePathname } from 'next/navigation';

// Query key for version settings
const VERSION_SETTINGS_KEY = ['versionSettings'];

/**
 * Hook to fetch current version settings
 */
export function useVersionSettings() {
  return useQuery({
    queryKey: VERSION_SETTINGS_KEY,
    queryFn: fetchVersionSettings,
  });
}

/**
 * Helper function to handle routing to old client
 */
const handleRoutingToOldClient = (pathname: string) => {
  // Extract resource or building ID from the path if present
  const pathParts = pathname.split('/');
  const pathType = pathParts[2]; // 'building', 'resource', 'search', etc.
  const id = pathParts[3]; // The ID if present
  
  // Handle different page types
  if (pathType === 'search' || pathname.includes('/search/')) {
    // Redirect to search page in old client
    window.location.href = '/bookingfrontend/';
  } else if (pathType === 'building' && id) {
    // Redirect to building page in old client
    window.location.href = `/bookingfrontend/?menuaction=bookingfrontend.uibuilding.show&id=${id}`;
  } else if (pathType === 'resource' && id) {
    // Redirect to resource page in old client
    window.location.href = `/bookingfrontend/?menuaction=bookingfrontend.uiresource.show&id=${id}`;
  } else {
    // Default redirect for other pages
    window.location.href = '/bookingfrontend/';
  }
};

/**
 * Hook to update version settings
 */
export function useSetVersionSettings() {
  const queryClient = useQueryClient();
  const pathname = usePathname();

  return useMutation({
    mutationFn: (version: 'original' | 'new') => setVersionSettings(version),

    // When mutation succeeds, update the cache and refetch
    onSuccess: (data) => {
      queryClient.setQueryData(VERSION_SETTINGS_KEY, data);
      queryClient.invalidateQueries({ queryKey: VERSION_SETTINGS_KEY });

      // Handle routing to old client for both versions
      handleRoutingToOldClient(pathname);
    }
  });
}