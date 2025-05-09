'use client'
import React, {Dispatch, FC, useEffect, useRef} from 'react';
import { createPortal } from 'react-dom';
import ShoppingCartContent from "@/components/layout/header/shopping-cart/shopping-cart-content";
import ApplicationCrud from "@/components/building-calendar/modules/event/edit/application-crud";
import styles from "./shopping-cart-drawer.module.scss";

interface ShoppingCartDrawerProps {
    open: boolean;
    setOpen: Dispatch<boolean>;
    anchor?: HTMLButtonElement | null;
}

const ShoppingCartDrawer: FC<ShoppingCartDrawerProps> = (props) => {
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
        if (props.open) {
            const handleClickOutside = (event: MouseEvent) => {
                // Ignore clicks on the anchor element
                if (props.anchor && props.anchor.contains(event.target as Node)) {
                    return;
                }
                if (drawerRef.current && !drawerRef.current.contains(event.target as Node)) {
                    props.setOpen(false);
                }
            };
            document.addEventListener('mousedown', handleClickOutside);
            return () => {
                document.removeEventListener('mousedown', handleClickOutside);
            };
        }
    }, [props.open, props.setOpen, props.anchor]);

    // Add/remove overflow hidden class on body when drawer is open
    useEffect(() => {
        if (props.open) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
        return () => {
            document.body.style.overflow = '';
        };
    }, [props.open]);

    // Don't render anything if not open or not mounted
    if (!props.open || !mounted) {
        return null;
    }
    
    // Get portal container or fallback to body
    const drawerContent = (
        <div id="shopping-cart-drawer-root">
            <div className={styles.drawerOverlay} onClick={() => props.setOpen(false)} />
            <div 
                ref={drawerRef}
                className={styles.drawer}
            >
                <ShoppingCartContent setOpen={props.setOpen} setCurrentApplication={setCurrentApplication}/>
            </div>
            {currentApplication && (
                <ApplicationCrud 
                    onClose={() => setCurrentApplication(undefined)} 
                    applicationId={currentApplication.application_id} 
                    date_id={currentApplication.date_id}
                    building_id={currentApplication.building_id} 
                />
            )}
        </div>
    );
    
    // Create portal to document.body
    return createPortal(drawerContent, document.body);
};

export default ShoppingCartDrawer;