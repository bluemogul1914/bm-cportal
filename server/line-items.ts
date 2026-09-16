/**
 * Shared line-item normalisation for generated documents (receipts and quotes).
 *
 * Both `invoices.items` and `lead_quotes.items` have been written in several
 * shapes over the life of this codebase, and quotes add `rate` / `price` on top
 * of the invoice spellings. Keep ONE implementation so the invoice and quote
 * templates can never disagree about what a line means.
 */
export interface LineItem {
  description: string;
  quantity: number;
  unitPrice: string;
}

/**
 * Accepts anything and returns only well-formed lines.
 * Never throws — bad/partial rows are dropped rather than failing a document.
 */
export function normalizeLineItems(raw: unknown): LineItem[] {
  if (!Array.isArray(raw)) return [];
  return raw
    .map((li: any) => {
      if (!li || typeof li !== "object") return null;
      const description = String(li.description ?? li.name ?? li.item ?? "").trim();
      if (!description) return null;
      const quantity = Number(li.quantity ?? li.qty ?? 1) || 1;
      // Prefer an explicit unit price; `amount` may be a line total, so it is last.
      const unitPrice = String(
        li.unit_price ?? li.unitPrice ?? li.rate ?? li.price ?? li.amount ?? "0"
      );
      return { description, quantity, unitPrice };
    })
    .filter((x): x is LineItem => x !== null);
}

/** Sum of quantity x unitPrice across lines, as a number. */
export function lineItemsSubtotal(items: LineItem[]): number {
  return items.reduce((sum, li) => sum + (parseFloat(li.unitPrice) || 0) * (li.quantity || 1), 0);
}

/** Money formatting used by every generated document.
 *  Tolerates an already-formatted string ("$1,234.50") as well as a number, so a
 *  double-format can never silently collapse a total to $0.00. */
export function money(n: number | string): string {
  const v = typeof n === "number" ? n : parseFloat(String(n).replace(/[^0-9.-]/g, ""));
  return `$${(Number.isFinite(v) ? v : 0).toLocaleString("en-US", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}
