'use client'
import React, {FC, ReactNode, cloneElement, isValidElement} from 'react';
import {Dropdown, Divider, Button} from "@digdir/designsystemet-react";
import {useIsMobile} from "@/service/hooks/is-mobile";
import styles from './responsive-dropdown.module.scss';

interface ResponsiveDropdownProps {
    trigger: ReactNode;
    children: ReactNode;
}

interface ResponsiveDropdownItemProps {
    children: ReactNode;
    href?: string;
    onClick?: () => void;
    className?: string;
}

interface ResponsiveDropdownListProps {
    children: ReactNode;
}

interface ResponsiveDropdownButtonProps {
    children: ReactNode;
    asChild?: boolean;
}

const ResponsiveDropdownItem: FC<ResponsiveDropdownItemProps> = ({children}) => {
    const isMobile = useIsMobile();

    if (isMobile) {
        return <li className={styles.responsiveDropdownMobileItem}>{children}</li>;
    }

    return (
        <Dropdown.Item>
            {children}
        </Dropdown.Item>
    );
};

const ResponsiveDropdownList: FC<ResponsiveDropdownListProps> = ({children}) => {
    const isMobile = useIsMobile();

    if (isMobile) {
        return <ul className={styles.responsiveDropdownMobileList}>{children}</ul>;
    }

    return <Dropdown.List>{children}</Dropdown.List>;
};

const ResponsiveDropdownButton: FC<ResponsiveDropdownButtonProps> = ({children, asChild, ...props}) => {
    const isMobile = useIsMobile();

    if (isMobile) {
		return <Button
			variant="tertiary"
			color="accent"
			data-size="sm"
		asChild={asChild} {...props} className={`${'className' in props && props.className || ''} ${styles.responsiveDropdownMobileButton}`}>{children}</Button>;
    }

    return <Dropdown.Button asChild={asChild} {...props}>{children}</Dropdown.Button>;
};

const ResponsiveDropdownDivider: FC = () => {
    const isMobile = useIsMobile();

    if (isMobile) {
        return <hr className={styles.responsiveDropdownMobileDivider} />;
    }

    return <Divider />;
};

const ResponsiveDropdown: FC<ResponsiveDropdownProps> & {
    Item: typeof ResponsiveDropdownItem;
    List: typeof ResponsiveDropdownList;
    Button: typeof ResponsiveDropdownButton;
    Divider: typeof ResponsiveDropdownDivider;
} = ({trigger, children}) => {
    const isMobile = useIsMobile();

    if (isMobile) {
        return (
            <div className={styles.responsiveDropdownMobile}>
                {children}
            </div>
        );
    }

    return (
        <Dropdown.TriggerContext>
            {trigger}
            <Dropdown>
                {children}
            </Dropdown>
        </Dropdown.TriggerContext>
    );
};

ResponsiveDropdown.Item = ResponsiveDropdownItem;
ResponsiveDropdown.List = ResponsiveDropdownList;
ResponsiveDropdown.Button = ResponsiveDropdownButton;
ResponsiveDropdown.Divider = ResponsiveDropdownDivider;

export default ResponsiveDropdown;