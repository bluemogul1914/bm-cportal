import { join } from "path";
import { existsSync } from "fs";

/**
 * Shared brand identity for generated documents (invoices/receipts and quotes).
 *
 * Keep every brand string here so the invoice and quote templates cannot drift
 * apart — both must render the identical letterhead.
 */
export const BRAND = {
  name: "Blue Mogul",
  /** Separator is a bullet (•) — the portal's own convention for taglines. */
  tagline: "Veteran-owned • MSP • Fiber • Telecom • AI",
  address: "801 Travis St, Houston, TX 77002",
  navy: "#052a52",
  accent: "#5271ff",
  /** Night-sky text on the navy band. */
  bandText: "#cbd8e8",
  logoPath: join(process.cwd(), "assets", "img", "bluemogul-wordmark.png"),
};

/** Height of the navy letterhead band, in PDF points. */
export const BAND_HEIGHT = 96;

export interface DocHeaderOptions {
  /** e.g. "PAYMENT RECEIPT" or "SALES QUOTE" */
  title: string;
  /** e.g. "INV-00003" */
  number: string;
  /** Right-hand subtitle under the title line, e.g. "Date: August 16, 2026" */
  dateLine: string;
  /** Optional extra line, e.g. "Valid until: September 15, 2026" */
  secondDateLine?: string;
}

/**
 * Draw the branded letterhead (navy band + wordmark + tagline + address) and the
 * document title block. Returns the y coordinate where body content may begin.
 *
 * The wordmark PNG has a transparent background (knocked out from the original
 * black-background asset), so it sits cleanly on the navy band.
 */
export function drawBrandHeader(doc: any, opts: DocHeaderOptions): number {
  const left = 50;
  const pageWidth = doc.page.width;

  // ── Navy letterhead band ──────────────────────────────────────────────
  doc.rect(0, 0, pageWidth, BAND_HEIGHT).fill(BRAND.navy);

  let textTop = 58;

  if (existsSync(BRAND.logoPath)) {
    // Raster wordmark is 307x64 trimmed; render at height 30 -> ~144pt wide.
    doc.image(BRAND.logoPath, left, 22, { height: 30 });
  } else {
    // Never fail document generation on a missing asset — fall back to type.
    doc.font("Helvetica-Bold").fontSize(24).fillColor("#ffffff")
      .text(BRAND.name, left, 24, { align: "left", width: pageWidth - left * 2 });
    textTop = 58;
  }

  doc.font("Helvetica").fontSize(9).fillColor("#ffffff")
    .text(BRAND.tagline, left, textTop, { align: "left", width: pageWidth - left * 2 });
  doc.font("Helvetica").fontSize(9).fillColor(BRAND.bandText)
    .text(BRAND.address, left, textTop + 12, { align: "left", width: pageWidth - left * 2 });

  // ── Document title block (on white, below the band) ───────────────────
  let y = BAND_HEIGHT + 26;
  doc.x = left;
  doc.y = y;

  doc.font("Helvetica-Bold").fontSize(18).fillColor("#1e293b")
    .text(opts.title, left, y, { align: "center", width: pageWidth - left * 2 });

  y = doc.y + 6;
  doc.font("Helvetica").fontSize(12).fillColor("#334155")
    .text(opts.number, left, y, { align: "center", width: pageWidth - left * 2 });

  y = doc.y + 4;
  doc.font("Helvetica").fontSize(10).fillColor("#64748b")
    .text(opts.dateLine, left, y, { align: "center", width: pageWidth - left * 2 });

  if (opts.secondDateLine) {
    y = doc.y;
    doc.text(opts.secondDateLine, left, y, { align: "center", width: pageWidth - left * 2 });
  }

  // Caller continues from here; reset x so subsequent left-aligned text starts at the margin.
  const contentTop = doc.y;
  doc.x = left;
  return contentTop;
}

/** Centered grey footer used by every document, anchored above the bottom margin.
 *  `extraLines` lets a document add its own closing copy (e.g. a receipt note)
 *  without duplicating the brand block. */
export function drawBrandFooter(doc: any, extraLines: string[] = []): void {
  const left = 50;
  const pageWidth = doc.page.width;
  const width = pageWidth - left * 2;

  // Anchor above the bottom margin — pageHeight - 100 spills a blank second page,
  // and the brand block plus any extra lines needs the extra headroom.
  doc.y = doc.page.height - 170;
  doc.moveDown(2);
  const divY = doc.y;
  doc.strokeColor("#e2e8f0").lineWidth(1).moveTo(left, divY).lineTo(pageWidth - left, divY).stroke();

  doc.x = left;
  doc.moveDown(0.5);
  // Width must be explicit or the text inherits a narrow cursor x and wraps.
  doc.font("Helvetica").fontSize(8).fillColor("#94a3b8")
    .text(BRAND.name, left, doc.y, { align: "center", width })
    .text(BRAND.tagline, left, doc.y, { align: "center", width })
    .text(BRAND.address, left, doc.y, { align: "center", width })
    .text(extraLines[0] ?? "", left, doc.y, { align: "center", width });

  for (const line of extraLines.slice(1)) {
    doc.text(line, left, doc.y, { align: "center", width });
  }
}
