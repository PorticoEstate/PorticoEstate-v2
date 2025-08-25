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
  const pathType = pathParts[2]; // 'building', 'resource', 'search', 'organization', 'user', etc.
  const id = pathParts[3]; // The ID if present
  const subPath = pathParts[4]; // Additional path segment if present
  
  // Handle different page types
  if (pathType === 'search' || pathname.includes('/search/')) {
    if (pathname.includes('/search/event')) {
      // Redirect to event search in old client
      window.location.href = '/bookingfrontend/?menuaction=bookingfrontend.uieventsearch.show';
    } else {
      // Redirect to general search page in old client
      window.location.href = '/bookingfrontend/';
    }
  } else if (pathType === 'building' && id) {
    // Redirect to building page in old client
    window.location.href = `/bookingfrontend/?menuaction=bookingfrontend.uibuilding.show&id=${id}`;
  } else if (pathType === 'resource' && id) {
    // Redirect to resource page in old client
    window.location.href = `/bookingfrontend/?menuaction=bookingfrontend.uiresource.show&id=${id}`;
  } else if (pathType === 'organization' && id) {
    if (subPath === 'edit') {
      // Redirect to organization edit page in old client
      window.location.href = `/bookingfrontend/?menuaction=bookingfrontend.uiorganization.edit&id=${id}`;
    } else {
      // Redirect to organization show page in old client
      window.location.href = `/bookingfrontend/?menuaction=bookingfrontend.uiorganization.show&id=${id}`;
    }
  } else if (pathType === 'user') {
    if (pathname.includes('/user/applications/') && id && id !== 'applications') {
      // Redirect to specific application page in old client (when there's a specific ID)
      window.location.href = `/bookingfrontend/?menuaction=bookingfrontend.uiapplication.show&id=${id}`;
    } else if (pathname.includes('/user/applications')) {
      // Redirect to applications section in user show page
      window.location.href = `/bookingfrontend/?menuaction=bookingfrontend.uiuser.show#applications`;
    } else if (pathname.includes('/user/delegates')) {
      // Redirect to delegate section in user show page
      window.location.href = `/bookingfrontend/?menuaction=bookingfrontend.uiuser.show#delegate`;
    } else if (pathname.includes('/user/invoices')) {
      // Redirect to invoice section in user show page
      window.location.href = `/bookingfrontend/?menuaction=bookingfrontend.uiuser.show#invoice`;
    } else {
      // Redirect to user details page in old client
      window.location.href = `/bookingfrontend/?menuaction=bookingfrontend.uiuser.show`;
    }
  } else if (pathType === 'checkout') {
    // Redirect to checkout page in old client
    window.location.href = `/bookingfrontend/?menuaction=bookingfrontend.uiapplication.add_contact`;
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