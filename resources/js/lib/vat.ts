/**
 * What one document costs once issued: VAT on top of what is taxable, in thousandths and rounded as the server rounds
 * it (InvoiceService::issue: tax = round(taxable × rate / 100) in minor units). The till shows the amount it will
 * record as paid, so the cash taken, the change and the card terminal match the invoice.
 */
export function withVat(amount: number, rate: number): number {
    const minor = Math.round(amount * 1000);
    if (!(rate > 0) || minor <= 0) return minor / 1000;

    return (minor + Math.round((minor * rate) / 100)) / 1000;
}
