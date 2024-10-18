'use client'
import React, {FC, PropsWithChildren} from 'react';
import {
    useIsHardLoading,
    useIsSoftLoading,
} from "@/components/loading-wrapper/LoadingContext";
import {Spinner} from "@digdir/designsystemet-react";

interface LoadingIndicationWrapperProps extends PropsWithChildren {
}

const LoadingIndicationWrapper: FC<LoadingIndicationWrapperProps> = (props) => {
    const isHardLoading = useIsHardLoading();
    const isSoftLoading = useIsSoftLoading();
    if(isHardLoading) {
        return <div></div>
    }
    return <>
        {isSoftLoading &&
            <div style={{
                position: 'fixed',
                zIndex: 103,
                backgroundColor: 'white',
                borderRadius: '50%',
                border: 'white 5px solid',
                opacity: '75%',
                top: 5,
                right: 5
            }}>
                <Spinner title='Henter kaffi' size='sm'/>
            </div>
        }
        {props.children}
    </>;
}

export default LoadingIndicationWrapper


