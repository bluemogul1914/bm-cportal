import nodemailer from "nodemailer";

export interface EmailOptions {
  to: string;
  subject: string;
  text: string;
  html?: string;
}

/**
 * Send an email using SMTP credentials from env or system settings.
 * Falls back to Hermes sendmail-style if no SMTP config is set.
 */
export async function sendEmail(opts: EmailOptions): Promise<boolean> {
  const smtpHost = process.env.SMTP_HOST || process.env.MAIL_HOST;
  const smtpPort = parseInt(process.env.SMTP_PORT || process.env.MAIL_PORT || "587", 10);
  const smtpUser = process.env.SMTP_USER || process.env.MAIL_USER;
  const smtpPass = process.env.SMTP_PASSWORD || process.env.MAIL_PASSWORD;
  const fromName = process.env.MAIL_FROM_NAME || "Blue Mogul Billing";
  const fromAddr = process.env.MAIL_FROM_ADDRESS || "billing@bluemogul.biz";

  // If SMTP is configured, use it
  if (smtpHost && smtpUser && smtpPass) {
    try {
      const transporter = nodemailer.createTransport({
        host: smtpHost,
        port: smtpPort,
        secure: smtpPort === 465,
        auth: { user: smtpUser, pass: smtpPass },
      });

      await transporter.sendMail({
        from: `"${fromName}" <${fromAddr}>`,
        to: opts.to,
        subject: opts.subject,
        text: opts.text,
        html: opts.html || opts.text.replace(/\n/g, "<br>"),
      });
      return true;
    } catch (err: any) {
      console.error("[email] SMTP send failed:", err.message);
      return false;
    }
  }

  // Fallback: just log (no email infrastructure available)
  console.log(`[email] Would send to ${opts.to}: ${opts.subject}`);
  console.log(`[email] Body: ${opts.text.substring(0, 200)}...`);
  return false;
}