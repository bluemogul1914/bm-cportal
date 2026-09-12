import PDFDocument from "pdfkit";
import { join } from "path";
import { existsSync, mkdirSync, createWriteStream } from "fs";

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
          Author: "Blue Mogul Technologies",
          Subject: `Payment receipt for ${data.clientName}`,
        },
      });

      const stream = createWriteStream(filepath);
      doc.pipe(stream);

      // ── Header ──────────────────────────────────────────────────────────
      doc.font("Helvetica-Bold").fontSize(22).fillColor("#1e3a5f")
        .text("Blue Mogul Technologies", 50, 50, { align: "left" });

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

      // Row
      const rowY = sepY + 5;
      doc.font("Helvetica").fontSize(10).fillColor("#334155");
      doc.text(data.description || "Account top-up", col1X, rowY, { width: 250 });
      doc.text(`$${data.amount}`, col2X, rowY, { width: 80, align: "right" });
      doc.text(`$${data.total}`, col3X, rowY, { width: 65, align: "right" });

      // Bottom line
      const bottomY = doc.y + 8;
      doc.moveTo(50, bottomY).lineTo(545, bottomY).stroke();

      // Total row
      const totalY = bottomY + 4;
      doc.font("Helvetica-Bold").fontSize(11).fillColor("#1e293b");
      doc.text("TOTAL PAID", col1X, totalY);
      doc.font("Helvetica-Bold").fontSize(12).fillColor("#059669");
      doc.text(`$${data.total}`, col3X, totalY, { width: 65, align: "right" });

      // ── Footer ────────────────────────────────────────────────────────
      const pageHeight = doc.page.height;
      doc.y = pageHeight - 100;

      doc.moveDown(2);
      const footerDivY = doc.y;
      doc.strokeColor("#e2e8f0").lineWidth(1)
        .moveTo(50, footerDivY)
        .lineTo(545, footerDivY)
        .stroke();

      doc.moveDown(0.5);
      doc.font("Helvetica").fontSize(8).fillColor("#94a3b8")
        .text("Blue Mogul Technologies", { align: "center" })
        .text("Thank you for your business!", { align: "center" })
        .text("This receipt serves as a record of your prepaid balance top-up.", { align: "center" });

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