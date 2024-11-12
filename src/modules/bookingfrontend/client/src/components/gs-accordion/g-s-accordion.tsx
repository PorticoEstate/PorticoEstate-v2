// GSAccordion.tsx
import React, { FC } from 'react';
import { AccordionProps } from "@digdir/designsystemet-react";
import { ServerAccordion, ServerAccordionItem, ServerAccordionHeading, ServerAccordionContent } from './server-accordion';
import {
    ClientAccordion,
    ClientAccordionContent,
    ClientAccordionHeading,
    ClientAccordionItem
} from "@/components/gs-accordion/client-accordion";



const GSAccordion: FC<AccordionProps> & {
    Item: any;
    Heading: any;
    Content: any;
} = (props) => {
    const AccordionComponent = ClientAccordion || ServerAccordion;
    return <AccordionComponent {...props}>{props.children}</AccordionComponent>;
};

// Assign dynamically loaded components as subcomponents
GSAccordion.Item = ClientAccordionItem || ServerAccordionItem;
GSAccordion.Heading = ClientAccordionHeading || ServerAccordionHeading;
GSAccordion.Content = ClientAccordionContent || ServerAccordionContent;

export default GSAccordion;
