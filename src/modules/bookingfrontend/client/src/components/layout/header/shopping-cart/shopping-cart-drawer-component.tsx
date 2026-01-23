'use client'
import React, { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import ShoppingCartContent from "@/components/layout/header/shopping-cart/shopping-cart-content";
import ApplicationCrud from "@/components/building-calendar/modules/event/edit/application-crud";
import styles from "./shopping-cart-drawer.module.scss";
import { useShoppingCartDrawer } from './shopping-cart-drawer-context';
import {useScrollLockEffect} from '@/contexts/ScrollLockContext';
import {useBookingUser} from "@/service/hooks/api-hooks";
import {useToast} from "@/components/toast/toast-context";

const ShoppingCartDrawerComponent: React.FC = () => {
    const { isOpen, setIsOpen, anchorRef } = useShoppingCartDrawer();
    const {data: bookingUser} = useBookingUser();
    const [currentApplication, setCurrentApplication] = React.useState<{
        application_id: number,
        date_id: number,
        building_id: number
    }>();
    const drawerRef = useRef<HTMLDivElement>(null);
    const [mounted, setMounted] = React.useState(false);
	// const {dismissAllToasts} = useToast()

    // Use scroll lock context to manage body overflow
    useScrollLockEffect('shopping-cart-drawer-component', isOpen);

    // Set mounted state on client-side
    useEffect(() => {
        setMounted(true);
    }, []);

	// useEffect(() => {
	// 	if(isOpen) {
	// 		dismissAllToasts();
	// 	}
	// }, [isOpen, dismissAllToasts]);

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

    // Auto-open ApplicationCrud for existing applications when user logs in with pending recurring data
    useEffect(() => {
        if (bookingUser && !currentApplication) {
            const pendingData = localStorage.getItem('pendingRecurringApplication');
            if (pendingData) {
                try {
                    const storedData = JSON.parse(pendingData);

                    // Check if data is expired (10 minutes = 600000 ms)
                    const isExpired = storedData.timestamp && (Date.now() - storedData.timestamp > 600000);

                    if (isExpired) {
                        localStorage.removeItem('pendingRecurringApplication');
                        return;
                    }

                    // Check if this is for an EXISTING application (must have applicationId, building_id, and date_id)
                    if (storedData.applicationId && storedData.building_id && storedData.date_id) {
                        // Open the existing application for editing
                        setCurrentApplication({
                            application_id: storedData.applicationId,
                            date_id: storedData.date_id,
                            building_id: storedData.building_id
                        });

                        // Also open the shopping cart to show context
                        setIsOpen(true);
                    }
                } catch (error) {
                    console.error('Error parsing pending recurring application data:', error);
                    localStorage.removeItem('pendingRecurringApplication');
                }
            }
        }
    }, [bookingUser, currentApplication, setIsOpen]);


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