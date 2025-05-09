'use client'
import React, { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import ShoppingCartContent from "@/components/layout/header/shopping-cart/shopping-cart-content";
import ApplicationCrud from "@/components/building-calendar/modules/event/edit/application-crud";
import styles from "./shopping-cart-drawer.module.scss";
import { useShoppingCartDrawer } from './shopping-cart-drawer-context';

const ShoppingCartDrawerComponent: React.FC = () => {
    const { isOpen, setIsOpen, anchorRef } = useShoppingCartDrawer();
    const [currentApplication, setCurrentApplication] = React.useState<{
        application_id: number,
        date_id: number,
        building_id: number
    }>();
    const drawerRef = useRef<HTMLDivElement>(null);
    const [mounted, setMounted] = React.useState(false);

    // Set mounted state on client-side
    useEffect(() => {
        setMounted(true);
    }, []);

    // Handle click outside to close the drawer
    useEffect(() => {
        if (isOpen) {
            const handleClickOutside = (event: MouseEvent) => {
                // Ignore clicks on the anchor element
                if (anchorRef && anchorRef.current && anchorRef.current.contains(event.target as Node)) {
                    return;
                }
                if (drawerRef.current && !drawerRef.current.contains(event.target as Node)) {
                    setIsOpen(false);
                }
            };
            document.addEventListener('mousedown', handleClickOutside);
            return () => {
                document.removeEventListener('mousedown', handleClickOutside);
            };
        }
    }, [isOpen, setIsOpen, anchorRef]);

    // Add/remove overflow hidden class on body when drawer is open
    useEffect(() => {
        if (isOpen) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
        return () => {
            document.body.style.overflow = '';
        };
    }, [isOpen]);

    // Don't render anything if not open or not mounted
    if ( !mounted) {
        return null;
    }

    // Get portal container or fallback to body
    const drawerContent = (
		<>
			{isOpen && <div id="shopping-cart-drawer-root">
				<div className={styles.drawerOverlay} onClick={() => setIsOpen(false)}/>
				<div
					ref={drawerRef}
					className={styles.drawer}
				>
					<ShoppingCartContent setOpen={setIsOpen} setCurrentApplication={setCurrentApplication}/>
				</div>

			</div>}
			{currentApplication && (
				<ApplicationCrud
					onClose={() => setCurrentApplication(undefined)}
					applicationId={currentApplication.application_id}
					date_id={currentApplication.date_id}
					building_id={currentApplication.building_id}
				/>
			)}
		</>
	);

	// Create portal to document.body
	return createPortal(drawerContent, document.body);
};

export default ShoppingCartDrawerComponent;