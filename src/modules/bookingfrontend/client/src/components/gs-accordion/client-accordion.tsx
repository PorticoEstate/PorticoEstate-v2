'use client'
// ClientOnlyComponents.tsx
import dynamic from 'next/dynamic';
import { AccordionProps, AccordionItemProps, AccordionHeadingProps, AccordionContentProps } from "@digdir/designsystemet-react";

export const ClientAccordion = dynamic(() => import('@digdir/designsystemet-react').then(mod => mod.Accordion), { ssr: false });
export const ClientAccordionItem = dynamic(() => import('@digdir/designsystemet-react').then(mod => mod.Accordion.Item), { ssr: false });
export const ClientAccordionHeading = dynamic(() => import('@digdir/designsystemet-react').then(mod => mod.Accordion.Heading), { ssr: false });
export const ClientAccordionContent = dynamic(() => import('@digdir/designsystemet-react').then(mod => mod.Accordion.Content), { ssr: false });
