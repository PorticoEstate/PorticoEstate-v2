import { IApplication, IOrderLine } from '@/service/types/api/application.types';
import { RecurringInfoUtils, calculateRecurringInstances } from '@/utils/recurring-utils';
import { Season } from '@/service/types/Building';

export interface CartItem {
    id: number;
    name: string;
    building: { id: number; name: string };
    resources: { id: number; name: string }[];
    resourceCosts: { name: string; resourceId: number; total: number }[];
    articles: { name: string; price: number }[];
    dates: { from_: string; to_: string; id: number }[];
    recurring?: { occurrences: number };
    perOccurrence?: number;
    total?: number;
}

export interface CartGroup {
    id: number;
    name: string;
    items: CartItem[];
}

export function mapApplicationToCartItem(
    app: IApplication,
    seasons?: Season[]
): CartItem {
    let resourceCosts: CartItem['resourceCosts'] = [];
    let articles: CartItem['articles'] = [];

    // Build a name→resourceId lookup from the application's resources
    const resourceByName = new Map<string, number>();
    for (const r of app.resources || []) {
        resourceByName.set(r.name, r.id);
    }

    if (app.orders && app.orders.length > 0) {
        for (const order of app.orders) {
            for (const line of order.lines) {
                const lineTotal = Number(line.amount) + Number(line.tax || 0);
                if (line.unit === 'hour' || line.unit === 'dag' || line.unit === 'day') {
                    // Match line name to resource to get the real resource ID for colour circles
                    const matchedResourceId = resourceByName.get(line.name) ?? 0;
                    resourceCosts.push({
                        name: line.name,
                        resourceId: matchedResourceId,
                        total: lineTotal,
                    });
                } else {
                    articles.push({
                        name: line.name,
                        price: lineTotal,
                    });
                }
            }
        }
    }

    const isRecurring = RecurringInfoUtils.isRecurring(app);
    let recurring: CartItem['recurring'];
    let perOccurrence: number | undefined;
    let total: number | undefined;

    const baseCost = app.orders?.reduce((sum, o) => sum + (o.sum || 0), 0) ?? 0;

    if (isRecurring) {
        const instances = calculateRecurringInstances(app, seasons);
        const occurrences = instances.length || 1;
        recurring = { occurrences };
        perOccurrence = baseCost;
    } else {
        total = baseCost;
    }

    return {
        id: app.id,
        name: app.name,
        building: { id: app.building_id, name: app.building_name },
        resources: (app.resources || []).map((r) => ({ id: r.id, name: r.name })),
        resourceCosts,
        articles,
        dates: [...(app.dates || [])].sort((a, b) => a.from_.localeCompare(b.from_)),
        recurring,
        perOccurrence,
        total,
    };
}

export function totalForApp(item: CartItem): number {
    if (item.recurring) {
        return (item.perOccurrence || 0) * item.recurring.occurrences;
    }
    return item.total || 0;
}

export function cartTotal(items: CartItem[]): number {
    return items.reduce((sum, item) => sum + totalForApp(item), 0);
}

function getEarliestDate(item: CartItem): number {
    return item.dates.length > 0
        ? Math.min(...item.dates.map((d) => new Date(d.from_).getTime()))
        : 0;
}

export function groupByBuilding(items: CartItem[]): CartGroup[] {
    const map = new Map<number, CartGroup>();
    for (const item of items) {
        if (!map.has(item.building.id)) {
            map.set(item.building.id, {
                id: item.building.id,
                name: item.building.name,
                items: [],
            });
        }
        map.get(item.building.id)!.items.push(item);
    }

    // Sort items within each group by earliest date
    const groups = [...map.values()];
    for (const group of groups) {
        group.items.sort((a, b) => getEarliestDate(a) - getEarliestDate(b));
    }

    // Sort groups by their earliest item's date
    groups.sort((a, b) => getEarliestDate(a.items[0]) - getEarliestDate(b.items[0]));

    return groups;
}

export function formatRange(from: string, to: string): [string, string] {
    const f = new Date(from);
    const t = new Date(to);
    const months = ['jan', 'feb', 'mar', 'apr', 'mai', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'des'];
    const day = `${f.getDate()}. ${months[f.getMonth()]}`;
    const pad = (n: number) => String(n).padStart(2, '0');
    const time = `${pad(f.getHours())}:${pad(f.getMinutes())}–${pad(t.getHours())}:${pad(t.getMinutes())}`;
    return [day, time];
}

export function fmtKr(n: number): string {
    return new Intl.NumberFormat('nb-NO', { useGrouping: true }).format(Math.round(n)) + ' kr';
}
