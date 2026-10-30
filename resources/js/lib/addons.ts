/**
 * Product add-ons on the selling screens (point of sale, website shop). The screen shows prices and totals; the
 * server prices every line again from the database and only takes the ids and quantities sent from here.
 */
export interface AddonOption {
    id: number;
    name: string;
    description?: string | null;
    price: string;
    /** How many of it may go with one unit of the product. */
    max: number;
    available?: boolean;
}

/** An add-on chosen for a line: how many per unit of the product. */
export interface ChosenAddon {
    id: number;
    qty: number;
}

const minor = (value: string | number) => Math.round(Number(value) * 1000);

/** One line per product and set of add-ons: the same product with other add-ons is a separate line. */
export function lineKey(productId: number, addons: ChosenAddon[] = []): string {
    return `${productId}|${[...addons].sort((a, b) => a.id - b.id).map((a) => `${a.id}x${a.qty}`).join(',')}`;
}

/** The chosen add-ons that still exist, with their names and prices (an add-on withdrawn since drops out). */
export function describe(chosen: ChosenAddon[] = [], options: AddonOption[] = []): (AddonOption & { qty: number })[] {
    return chosen.flatMap((c) => {
        const option = options.find((o) => o.id === c.id);
        return option && c.qty > 0 ? [{ ...option, qty: Math.min(c.qty, option.max) }] : [];
    });
}

/** The price of one unit with its add-ons. */
export function unitPrice(price: string | number, chosen: ChosenAddon[] = [], options: AddonOption[] = []): number {
    return (minor(price) + describe(chosen, options).reduce((t, a) => t + minor(a.price) * a.qty, 0)) / 1000;
}

/** A line's amount: (price + add-ons) × quantity, worked in thousandths so it matches the server. */
export function lineTotal(price: string | number, qty: number, chosen: ChosenAddon[] = [], options: AddonOption[] = []): number {
    return Math.round(unitPrice(price, chosen, options) * 1000 * qty) / 1000;
}

/** What the server is sent for a line's add-ons. */
export function payload(chosen: ChosenAddon[] = []): { addon_id: number; quantity: number }[] {
    return chosen.filter((a) => a.qty > 0).map((a) => ({ addon_id: a.id, quantity: a.qty }));
}
