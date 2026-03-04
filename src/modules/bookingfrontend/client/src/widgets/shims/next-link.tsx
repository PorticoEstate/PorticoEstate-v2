import React from 'react';

interface LinkProps extends React.AnchorHTMLAttributes<HTMLAnchorElement> {
	href: string;
}

const Link = React.forwardRef<HTMLAnchorElement, LinkProps>(({href, children, ...props}, ref) => (
	<a href={href} ref={ref} {...props}>{children}</a>
));

Link.displayName = 'Link';

export default Link;
