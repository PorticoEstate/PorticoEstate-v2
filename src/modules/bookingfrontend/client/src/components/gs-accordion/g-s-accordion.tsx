// GSAccordion.tsx
import React, { FC } from 'react';
import { DetailsProps } from "@digdir/designsystemet-react";
import { ServerAccordionItem, ServerAccordionHeading, ServerAccordionContent } from './server-accordion';
import {
    ClientAccordionContent,
    ClientAccordionHeading,
    ClientAccordionItem
} from "@/components/gs-accordion/client-accordion";



const GSAccordion: FC<DetailsProps> & {
    // Item: any;
    Heading: any;
    Content: any;
} = (props) => {
    const AccordionComponent = ClientAccordionItem || ServerAccordionItem;
    return <AccordionComponent  data-color={'brand1'} {...props}>{props.children}</AccordionComponent>;
};

// Assign dynamically loaded components as subcomponents
// GSAccordion.Item = ClientAccordionItem || ServerAccordionItem;
GSAccordion.Heading = ClientAccordionHeading || ServerAccordionHeading;
GSAccordion.Content = ClientAccordionContent || ServerAccordionContent;

export default GSAccordion;
