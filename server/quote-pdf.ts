import PDFDocument from "pdfkit";
import { PassThrough } from "stream";
import { drawBrandHeader, drawBrandFooter } from "./brand-header";
import { LineItem, money } from "./line-items";

export interface QuoteData {
  quoteNumber: string;
  /** Lead/client the quote is addressed to. */
  preparedFor: string;
  preparedForEmail?: string | null;
  documentDate: Date;
  validUntil?: Date | null;
  items?: LineItem[];
  /** Plain numbers — the template formats them once via `money()`. */
  subtotal: number;
  tax: number;
  total: number;
  note?: string | null;
  memo?: string | null;
}

const LEFT = 50;
const RIGHT = 545;

function fmtDate(d: Date): string {
  return d.toLocaleDateString("en-US", { year: "numeric", month: "long", day: "numeric" });
}

/**
 * Generate a branded sales quote PDF and return it as a Buffer for streaming.
 * Shares the letterhead and footer with the invoice/receipt template via
 * `brand-header`, and the line-item parsing via `line-items`.
 */
export async function generateQuotePdfBuffer(data: QuoteData): Promise<Buffer> {
  return new Promise<Buffer>((resolve, reject) => {
    try {
      const doc = new PDFDocument({
        size: "A4",
        margin: 50,
        info: {
          Title: `Quote ${data.quoteNumber}`,
          Author: "Blue Mogul",
          Subject: `Quotation for ${data.preparedFor}`,
        },
      });

      const passThrough = new PassThrough();
      const chunks: Buffer[] = [];
      passThrough.on("data", (c: Buffer) => chunks.push(c));
      passThrough.on("end", () => resolve(Buffer.concat(chunks)));
      passThrough.on("error", reject);
      doc.pipe(passThrough);

      // ── Branded letterhead ──────────────────────────────────────────────
      drawBrandHeader(doc, {
        title: "SALES QUOTE",
        number: `Quote #${data.quoteNumber}`,
        dateLine: `Date: ${fmtDate(data.documentDate)}`,
        secondDateLine: data.validUntil ? `Valid until: ${fmtDate(data.validUntil)}` : undefined,
      });

      // ── Prepared for ────────────────────────────────────────────────────
      doc.moveDown(1.5);
      const pfY = doc.y;
      doc.font("Helvetica-Bold").fontSize(11).fillColor("#1e293b")
        .text("PREPARED FOR", LEFT, pfY, { width: 495 });
      doc.font("Helvetica").fontSize(10).fillColor("#334155")
        .text(data.preparedFor || "Client", LEFT, doc.y, { width: 495 });
      if (data.preparedForEmail) {
        doc.text(data.preparedForEmail, LEFT, doc.y, { width: 495 });
      }

      // ── Line items ──────────────────────────────────────────────────────
      doc.moveDown(1.5);
      const tableTop = doc.y;
      const rowHeight = 20;
      const colDesc = LEFT;
      const colQty = 340;
      const colRate = 400;
      const colAmt = 480;

      doc.font("Helvetica-Bold").fontSize(10).fillColor("#1e3a5f");
      doc.text("Description", colDesc, tableTop, { width: 270 });
      doc.text("Qty", colQty, tableTop, { width: 50, align: "right" });
      doc.text("Rate", colRate, tableTop, { width: 70, align: "right" });
      doc.text("Amount", colAmt, tableTop, { width: 65, align: "right" });

      const sepY = tableTop + rowHeight - 5;
      doc.strokeColor("#cbd5e1").lineWidth(1).moveTo(LEFT, sepY).lineTo(RIGHT, sepY).stroke();

      const items: LineItem[] = data.items && data.items.length ? data.items : [];
      let rowY = sepY + 5;
      doc.font("Helvetica").fontSize(10).fillColor("#334155");
      if (items.length === 0) {
        doc.text("No line items.", colDesc, rowY, { width: 495 });
        rowY += rowHeight;
      }
      for (const li of items) {
        const qty = li.quantity || 1;
        const lineTotal = (parseFloat(li.unitPrice) || 0) * qty;
        doc.text(li.description, colDesc, rowY, { width: 270 });
        doc.text(String(qty), colQty, rowY, { width: 50, align: "right" });
        doc.text(money(li.unitPrice), colRate, rowY, { width: 70, align: "right" });
        doc.text(money(lineTotal), colAmt, rowY, { width: 65, align: "right" });
        rowY += rowHeight;
      }

      const bottomY = rowY - 5;
      doc.moveTo(LEFT, bottomY).lineTo(RIGHT, bottomY).stroke();

      // ── Totals ──────────────────────────────────────────────────────────
      let ty = bottomY + 10;
      const labelX = 340;
      const valueW = 65;
      doc.font("Helvetica").fontSize(10).fillColor("#334155");
      doc.text("Subtotal", labelX, ty, { width: 100, align: "right" });
      doc.text(money(data.subtotal), colAmt - 40, ty, { width: 105, align: "right" });
      ty += 16;
      doc.text("Tax", labelX, ty, { width: 100, align: "right" });
      doc.text(money(data.tax), colAmt - 40, ty, { width: 105, align: "right" });

      ty += 8;
      doc.moveTo(labelX, ty).lineTo(RIGHT, ty).strokeColor("#cbd5e1").stroke();
      ty += 6;
      doc.font("Helvetica-Bold").fontSize(11).fillColor("#1e293b");
      doc.text("TOTAL", labelX, ty, { width: 100, align: "right" });
      doc.font("Helvetica-Bold").fontSize(12).fillColor("#059669");
      doc.text(money(data.total), colAmt - 40, ty, { width: 105, align: "right" });

      // ── Notes ───────────────────────────────────────────────────────────
      const noteText = [data.note, data.memo].filter(Boolean).join("\n").trim();
      if (noteText) {
        doc.moveDown(3);
        const nY = doc.y;
        doc.font("Helvetica-Bold").fontSize(10).fillColor("#1e293b")
          .text("NOTES", LEFT, nY, { width: 495 });
        doc.font("Helvetica").fontSize(9).fillColor("#475569")
          .text(noteText, LEFT, doc.y, { width: 495 });
      }

      drawBrandFooter(doc, [
        "This quotation is valid until the date shown above.",
      ]);

      doc.end();
    } catch (err) {
      reject(err);
    }
  });
}
