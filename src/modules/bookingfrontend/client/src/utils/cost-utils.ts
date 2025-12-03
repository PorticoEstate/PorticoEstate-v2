import { IApplication, IOrder } from "@/service/types/api/application.types";

export const calculateApplicationCost = (application: IApplication): number => {
    if (!application.orders || application.orders.length === 0) {
        return 0;
    }
    
    return application.orders.reduce((total, order) => total + (order.sum || 0), 0);
};

export const calculateTotalCartCost = (applications: IApplication[]): number => {
    return applications.reduce((total, app) => total + calculateApplicationCost(app), 0);
};

export const formatCurrency = (amount: number, currency: string = 'NOK'): string => {
    return new Intl.NumberFormat('nb-NO', {
        style: 'currency',
        currency: currency,
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(amount);
};

export const getApplicationCurrency = (application: IApplication): string => {
    if (!application.orders || application.orders.length === 0) {
        return 'NOK';
    }
    
    const firstOrderLine = application.orders[0]?.lines?.[0];
    return firstOrderLine?.currency || 'NOK';
};