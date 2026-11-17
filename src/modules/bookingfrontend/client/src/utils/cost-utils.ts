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

// Norwegian amounts carry no decimals when they are whole kroner and exactly two (øre) otherwise:
// 282 kr, 1 500 kr, 423,50 kr. Intl's own minimumFractionDigits: 0 would print 423,5.
const fractionDigitsFor = (amount: number): number => (Math.round(amount * 100) % 100 === 0 ? 0 : 2);

export const formatAmount = (amount: number): string => {
    const digits = fractionDigitsFor(amount);
    return new Intl.NumberFormat('nb-NO', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(amount);
};

export const formatCurrency = (amount: number, currency: string = 'NOK'): string => {
    const digits = fractionDigitsFor(amount);
    return new Intl.NumberFormat('nb-NO', {
        style: 'currency',
        currency: currency,
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(amount);
};

export const getApplicationCurrency = (application: IApplication): string => {
    if (!application.orders || application.orders.length === 0) {
        return 'NOK';
    }
    
    const firstOrderLine = application.orders[0]?.lines?.[0];
    return firstOrderLine?.currency || 'NOK';
};