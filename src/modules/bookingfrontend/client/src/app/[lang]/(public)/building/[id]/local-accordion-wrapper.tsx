'use client'
import {FC, PropsWithChildren} from 'react';
import {Accordion} from "@digdir/designsystemet-react";

interface TestlocalProps extends PropsWithChildren {
}

const LocalAccordionWrapper: FC<TestlocalProps> = (props) => {
    return (
        <Accordion border color={"neutral"} className={'mx-3 my-2'}>
            {props.children}
        </Accordion>
    );
}

export default LocalAccordionWrapper


