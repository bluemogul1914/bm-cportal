// pdfkit ships no type declarations. Rather than add a third-party @types
// package, declare the module ambiently so `tsc --noEmit` stays honest for every
// importer (receipt-pdf.ts, quote-pdf.ts) without weakening strict mode globally.
declare module "pdfkit";
