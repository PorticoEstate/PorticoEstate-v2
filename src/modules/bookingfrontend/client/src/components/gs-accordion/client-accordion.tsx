'use client'
// ClientOnlyComponents.tsx
import dynamic from 'next/dynamic';

export const ClientAccordionItem = dynamic(() => import('@digdir/designsystemet-react').then(mod => mod.Details), { ssr: false });
export const ClientAccordionHeading = dynamic(() => import('@digdir/designsystemet-react').then(mod => mod.Details.Summary), { ssr: false });
export const ClientAccordionContent = dynamic(() => import('@digdir/designsystemet-react').then(mod => mod.Details.Content), { ssr: false });
