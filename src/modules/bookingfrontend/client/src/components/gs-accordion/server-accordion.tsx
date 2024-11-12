// ServerFallbacks.tsx
import React, { FC } from 'react';

export const ServerAccordion: FC<{ children: React.ReactNode }> = ({ children }) => (
    <section className="gs-accordion-group mx-standard my-2">{children}</section>
);

export const ServerAccordionItem: FC<{ children: React.ReactNode }> = ({ children }) => (
    <div className="gs-accordion-item">{children}</div>
);

export const ServerAccordionHeading: FC<{ children: React.ReactNode }> = ({ children }) => (
    <div className="gs-accordion-header">{children}</div>
);

export const ServerAccordionContent: FC<{ children: React.ReactNode }> = ({ children }) => (
    <div className="gs-accordion-body">{children}</div>
);
