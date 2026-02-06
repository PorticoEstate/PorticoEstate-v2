import { PackageIcon } from "@navikt/aksel-icons";
import {FC, forwardRef, Ref, type SVGProps} from 'react';
import * as React from "react";
import {SVGRProps} from "@/icons/iconTypes";


const ResourceIcon = forwardRef(({
									  ...props
								  }: SVGProps<SVGSVGElement> & SVGRProps, ref: Ref<SVGSVGElement>) => {
	return <PackageIcon {...props} />;
});

ResourceIcon.displayName = 'ResourceIcon';

export default ResourceIcon


