'use client';

import { FC } from 'react';
import { Button } from "@digdir/designsystemet-react";

interface PageProps {
}

const Page: FC<PageProps> = (props) => {
    return (
        <div>
            <div>Placeholder page</div>
            <div style={{display: 'flex', gap: '1.5rem', flexWrap: 'wrap', marginBottom: '2rem'}}>
                <Button variant={"primary"} data-color={'accent'}>Accent</Button>
                <Button variant={"primary"} data-color={'neutral'}>neutral</Button>
                <Button variant={"primary"} data-color={'brand1'}>brand1</Button>
                <Button variant={"primary"} data-color={'brand2'}>brand2</Button>
                <Button variant={"primary"} data-color={'brand3'}>brand3</Button>
                {/*<Button variant={"primary"} data-color={'info'}>info</Button>*/}
                {/*<Button variant={"primary"} data-color={'success'}>success</Button>*/}
                {/*<Button variant={"primary"} data-color={'warning'}>warning</Button>*/}
            </div>


        </div>
    );
}

export default Page

