import { Buildings3Icon } from "@navikt/aksel-icons";
import {FC, forwardRef, Ref, type SVGProps} from 'react';
import * as React from "react";
import {SVGRProps} from "@/icons/iconTypes";

const BuildingIcon = forwardRef(({
									  ...props
								  }: SVGProps<SVGSVGElement> & SVGRProps, ref: Ref<SVGSVGElement>) => {
	return <Buildings3Icon {...props} />;
});

BuildingIcon.displayName = 'BuildingIcon';

export default BuildingIcon


