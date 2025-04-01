'use client'
import React, {FC, Fragment, PropsWithChildren} from 'react';
import {
    useIsHardLoading,
    useIsSoftLoading,
} from "@/components/loading-wrapper/LoadingContext";
import {Spinner} from "@digdir/designsystemet-react";
import {useIsFetching, useQueryClient} from "@tanstack/react-query";

interface LoadingIndicationWrapperProps extends PropsWithChildren {
	loadingString: string
}

const LoadingIndicationWrapper: FC<LoadingIndicationWrapperProps> = (props) => {
    const isHardLoading = useIsHardLoading();
    const isSoftLoading = useIsSoftLoading();
    const isFetching = useIsFetching()
    if(isHardLoading) {
        return <div></div>
    }
    const isSoft = !!(isSoftLoading || isFetching)
    return <Fragment>
        {isSoft &&
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
                <Spinner aria-label={props.loadingString}/>
            </div>
        }
        {props.children}
    </Fragment>;
}

export default LoadingIndicationWrapper


