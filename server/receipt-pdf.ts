import PDFDocument from "pdfkit";
import { join } from "path";
import { existsSync, mkdirSync, createWriteStream } from "fs";
import { PassThrough } from "stream";

const RECEIPTS_DIR = join(process.cwd(), "public", "receipts");

export interface ReceiptData {
  invoiceNumber: string;
  clientName: string;
  clientEmail: string;
  clientAddress: string | null;
  amount: string;
  tax: string;
  total: string;
  description: string;
  createdAt: Date;
  lineItems?: ReceiptLineItem[];
}

export interface ReceiptLineItem {
  description: string;
  quantity: number;
  unitPrice: string;
}

/**
 * Generate a branded INV-XXXXX receipt PDF.
 * Returns the relative URL path to the PDF.
 */
export async function generateReceiptPdf(data: ReceiptData): Promise<string> {
  // Ensure the receipts directory exists
  if (!existsSync(RECEIPTS_DIR)) {
    mkdirSync(RECEIPTS_DIR, { recursive: true });
  }

  const filename = `receipt-${data.invoiceNumber.replace(/[^a-zA-Z0-9_-]/g, "_")}.pdf`;
  const filepath = join(RECEIPTS_DIR, filename);

  return new Promise<string>((resolve, reject) => {
    try {
      const doc = new PDFDocument({
        size: "A4",
        margin: 50,
        info: {
          Title: `Receipt ${data.invoiceNumber}`,
          Author: "Blue Mogul",
          Subject: `Payment receipt for ${data.clientName}`,
        },
      });

      const stream = createWriteStream(filepath);
      doc.pipe(stream);

      // ── Header ──────────────────────────────────────────────────────────
      doc.font("Helvetica-Bold").fontSize(22).fillColor("#1e3a5f")
        .text("Blue Mogul", 50, 50, { align: "left" });

      doc.font("Helvetica").fontSize(10).fillColor("#64748b")
        .text("Boutique Managed IT & Fiber Services", { align: "left" })
        .text("Houston, TX", { align: "left" })
        .moveDown(0.5);

      // ── Receipt Title ──────────────────────────────────────────────────
      doc.moveDown(1);
      doc.font("Helvetica-Bold").fontSize(18).fillColor("#1e293b")
        .text("PAYMENT RECEIPT", { align: "center" });
      doc.moveDown(0.3);
      doc.font("Helvetica").fontSize(12).fillColor("#334155")
        .text(`Receipt #${data.invoiceNumber}`, { align: "center" });
      doc.moveDown(0.3);

      const dateStr = data.createdAt.toLocaleDateString("en-US", {
        year: "numeric", month: "long", day: "numeric"
      });
      doc.font("Helvetica").fontSize(10).fillColor("#64748b")
        .text(`Date: ${dateStr}`, { align: "center" });

      // ── Divider ────────────────────────────────────────────────────────
      doc.moveDown(1);
      const dividerY = doc.y;
      doc.strokeColor("#e2e8f0")
        .lineWidth(1)
        .moveTo(50, dividerY)
        .lineTo(545, dividerY)
        .stroke();

      // ── Client Info ────────────────────────────────────────────────────
      doc.moveDown(1);
      const clientLabelY = doc.y;
      doc.font("Helvetica-Bold").fontSize(11).fillColor("#1e293b")
        .text("CLIENT", 50, clientLabelY);
      doc.font("Helvetica").fontSize(10).fillColor("#334155")
        .text(data.clientName);
      doc.text(data.clientEmail);
      if (data.clientAddress) {
        doc.text(data.clientAddress);
      }

      // ── Payment Details Table ───────────────────────────────────────────
      doc.moveDown(1.5);
      const tableTop = doc.y;

      // Table header
      const col1X = 50;
      const col2X = 350;
      const col3X = 480;
      const rowHeight = 20;

      doc.font("Helvetica-Bold").fontSize(10).fillColor("#1e3a5f");
      doc.text("Description", col1X, tableTop, { width: 280 });
      doc.text("Amount", col2X, tableTop, { width: 80, align: "right" });
      doc.text("Total", col3X, tableTop, { width: 65, align: "right" });

      // Table separator
      const sepY = tableTop + rowHeight - 5;
      doc.strokeColor("#cbd5e1").lineWidth(1)
        .moveTo(50, sepY)
        .lineTo(545, sepY)
        .stroke();

      // Rows — itemized when line items are available, else one summary row.
      const lines: ReceiptLineItem[] =
        data.lineItems && data.lineItems.length
          ? data.lineItems
          : [{ description: data.description || "Account top-up", quantity: 1, unitPrice: data.amount }];

      let rowY = sepY + 5;
      doc.font("Helvetica").fontSize(10).fillColor("#334155");
      for (const li of lines) {
        const qty = li.quantity || 1;
        const qtyLabel = qty !== 1 ? `${qty} × ` : "";
        const lineTotal = (parseFloat(li.unitPrice || "0") || 0) * qty;
        doc.text(`${qtyLabel}${li.description}`, col1X, rowY, { width: 250 });
        doc.text(`$${lineTotal.toFixed(2)}`, col2X, rowY, { width: 80, align: "right" });
        rowY += rowHeight;
      }

      // Bottom line
      const bottomY = rowY - 5;
      doc.moveTo(50, bottomY).lineTo(545, bottomY).stroke();

      // Total row
      const totalY = bottomY + 4;
      doc.font("Helvetica-Bold").fontSize(11).fillColor("#1e293b");
      doc.text("TOTAL PAID", col1X, totalY);
      doc.font("Helvetica-Bold").fontSize(12).fillColor("#059669");
      doc.text(`$${data.total}`, col3X, totalY, { width: 65, align: "right" });

      // ── Footer ────────────────────────────────────────────────────────
      // Anchor the footer above the bottom margin — pageHeight - 100 leaves
      // the 3 footer lines just past the margin, which spills a blank 2nd page.
      const pageHeight = doc.page.height;
      doc.y = pageHeight - 150;

      doc.moveDown(2);
      const footerDivY = doc.y;
      doc.strokeColor("#e2e8f0").lineWidth(1)
        .moveTo(50, footerDivY)
        .lineTo(545, footerDivY)
        .stroke();

      doc.x = 50;
      doc.moveDown(0.5);
      // Width must be explicit — without it the text inherits a narrow cursor x
      // (left over from the amount column) and wraps to two lines per string.
      doc.font("Helvetica").fontSize(8).fillColor("#94a3b8")
        .text("Blue Mogul", { align: "center", width: 495 })
        .text("Thank you for your business!", { align: "center", width: 495 })
        .text("This receipt serves as a record of your prepaid balance top-up.", { align: "center", width: 495 });

      // Finalize
      doc.end();

      stream.on("finish", () => {
        resolve(`/receipts/${filename}`);
      });
      stream.on("error", (err: Error) => {
        reject(err);
      });
    } catch (err) {
      reject(err);
    }
  });
}

/**
 * Generate a branded receipt PDF and return it as a Buffer (for streaming
 * via HTTP response).  Uses the same layout as generateReceiptPdf but pipes
 * into memory instead of writing to disk.
 */
export async function generateReceiptPdfBuffer(data: ReceiptData): Promise<Buffer> {
  return new Promise<Buffer>((resolve, reject) => {
    try {
      const doc = new PDFDocument({
        size: "A4",
        margin: 50,
        info: {
          Title: `Receipt ${data.invoiceNumber}`,
          Author: "Blue Mogul",
          Subject: `Payment receipt for ${data.clientName}`,
        },
      });

      const passThrough = new PassThrough();
      const chunks: Buffer[] = [];
      passThrough.on("data", (chunk: Buffer) => chunks.push(chunk));
      passThrough.on("end", () => resolve(Buffer.concat(chunks)));
      passThrough.on("error", reject);

      doc.pipe(passThrough);

      // ── Header ──────────────────────────────────────────────────────────
      doc.font("Helvetica-Bold").fontSize(22).fillColor("#1e3a5f")
        .text("Blue Mogul", 50, 50, { align: "left" });

      doc.font("Helvetica").fontSize(10).fillColor("#64748b")
        .text("Boutique Managed IT & Fiber Services", { align: "left" })
        .text("Houston, TX", { align: "left" })
        .moveDown(0.5);

      // ── Receipt Title ──────────────────────────────────────────────────
      doc.moveDown(1);
      doc.font("Helvetica-Bold").fontSize(18).fillColor("#1e293b")
        .text("PAYMENT RECEIPT", { align: "center" });
      doc.moveDown(0.3);
      doc.font("Helvetica").fontSize(12).fillColor("#334155")
        .text(`Receipt #${data.invoiceNumber}`, { align: "center" });
      doc.moveDown(0.3);

      const dateStr = data.createdAt.toLocaleDateString("en-US", {
        year: "numeric", month: "long", day: "numeric"
      });
      doc.font("Helvetica").fontSize(10).fillColor("#64748b")
        .text(`Date: ${dateStr}`, { align: "center" });

      // ── Divider ────────────────────────────────────────────────────────
      doc.moveDown(1);
      const dividerY = doc.y;
      doc.strokeColor("#e2e8f0")
        .lineWidth(1)
        .moveTo(50, dividerY)
        .lineTo(545, dividerY)
        .stroke();

      // ── Client Info ────────────────────────────────────────────────────
      doc.moveDown(1);
      const clientLabelY = doc.y;
      doc.font("Helvetica-Bold").fontSize(11).fillColor("#1e293b")
        .text("CLIENT", 50, clientLabelY);
      doc.font("Helvetica").fontSize(10).fillColor("#334155")
        .text(data.clientName);
      doc.text(data.clientEmail);
      if (data.clientAddress) {
        doc.text(data.clientAddress);
      }

      // ── Payment Details Table ───────────────────────────────────────────
      doc.moveDown(1.5);
      const tableTop = doc.y;

      // Table header
      const col1X = 50;
      const col2X = 350;
      const col3X = 480;
      const rowHeight = 20;

      doc.font("Helvetica-Bold").fontSize(10).fillColor("#1e3a5f");
      doc.text("Description", col1X, tableTop, { width: 280 });
      doc.text("Amount", col2X, tableTop, { width: 80, align: "right" });
      doc.text("Total", col3X, tableTop, { width: 65, align: "right" });

      // Table separator
      const sepY = tableTop + rowHeight - 5;
      doc.strokeColor("#cbd5e1").lineWidth(1)
        .moveTo(50, sepY)
        .lineTo(545, sepY)
        .stroke();

      // Rows — itemized when line items are available, else one summary row.
      const lines: ReceiptLineItem[] =
        data.lineItems && data.lineItems.length
          ? data.lineItems
          : [{ description: data.description || "Account top-up", quantity: 1, unitPrice: data.amount }];

      let rowY = sepY + 5;
      doc.font("Helvetica").fontSize(10).fillColor("#334155");
      for (const li of lines) {
        const qty = li.quantity || 1;
        const qtyLabel = qty !== 1 ? `${qty} × ` : "";
        const lineTotal = (parseFloat(li.unitPrice || "0") || 0) * qty;
        doc.text(`${qtyLabel}${li.description}`, col1X, rowY, { width: 250 });
        doc.text(`$${lineTotal.toFixed(2)}`, col2X, rowY, { width: 80, align: "right" });
        rowY += rowHeight;
      }

      // Bottom line
      const bottomY = rowY - 5;
      doc.moveTo(50, bottomY).lineTo(545, bottomY).stroke();

      // Total row
      const totalY = bottomY + 4;
      doc.font("Helvetica-Bold").fontSize(11).fillColor("#1e293b");
      doc.text("TOTAL PAID", col1X, totalY);
      doc.font("Helvetica-Bold").fontSize(12).fillColor("#059669");
      doc.text(`$${data.total}`, col3X, totalY, { width: 65, align: "right" });

      // ── Footer ────────────────────────────────────────────────────────
      // Anchor the footer above the bottom margin — pageHeight - 100 leaves
      // the 3 footer lines just past the margin, which spills a blank 2nd page.
      const pageHeight = doc.page.height;
      doc.y = pageHeight - 150;

      doc.moveDown(2);
      const footerDivY = doc.y;
      doc.strokeColor("#e2e8f0").lineWidth(1)
        .moveTo(50, footerDivY)
        .lineTo(545, footerDivY)
        .stroke();

      doc.x = 50;
      doc.moveDown(0.5);
      // Width must be explicit — without it the text inherits a narrow cursor x
      // (left over from the amount column) and wraps to two lines per string.
      doc.font("Helvetica").fontSize(8).fillColor("#94a3b8")
        .text("Blue Mogul", { align: "center", width: 495 })
        .text("Thank you for your business!", { align: "center", width: 495 })
        .text("This receipt serves as a record of your prepaid balance top-up.", { align: "center", width: 495 });

      // Finalize
      doc.end();
    } catch (err) {
      reject(err);
    }
  });
}
