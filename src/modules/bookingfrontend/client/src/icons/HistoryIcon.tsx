import { ClockDashedIcon } from "@navikt/aksel-icons";
import {FC, forwardRef, Ref, type SVGProps} from 'react';
import * as React from "react";
import {SVGRProps} from "@/icons/iconTypes";


const HistoryIcon = forwardRef(({
									  ...props
								  }: SVGProps<SVGSVGElement> & SVGRProps, ref: Ref<SVGSVGElement>) => {
	return <ClockDashedIcon {...props} />;
});

HistoryIcon.displayName = 'HistoryIcon';

export default HistoryIcon


