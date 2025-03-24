import {Tabs, TabsTabProps} from "@digdir/designsystemet-react";
import {ReactNode} from "react";
import Link from "next/link";

interface LinkTabProps extends Omit<TabsTabProps, 'value'> {
	href: string;
	value?: string;
	children: ReactNode;
}

export const LinkTab = ({ value, href, children, ...props }: LinkTabProps) => {
	return (
		<Link href={href} passHref legacyBehavior>
			<Tabs.Tab value={value || href} {...props}>
				{children}
			</Tabs.Tab>
		</Link>
	);
}
