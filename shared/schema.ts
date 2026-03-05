import { sql } from "drizzle-orm";
import { pgTable, text, varchar, timestamp, serial, boolean, decimal, integer, date, jsonb } from "drizzle-orm/pg-core";
import { createInsertSchema } from "drizzle-zod";
import { z } from "zod";

export const snippets = pgTable("snippets", {
  id: serial("id").primaryKey(),
  title: text("title").notNull(),
  code: text("code").notNull(),
  description: text("description").default(""),
  createdAt: timestamp("created_at").defaultNow().notNull(),
});

export const insertSnippetSchema = createInsertSchema(snippets).omit({
  id: true,
  createdAt: true,
});

export type InsertSnippet = z.infer<typeof insertSnippetSchema>;
export type Snippet = typeof snippets.$inferSelect;

export const users = pgTable("users", {
  id: serial("id").primaryKey(),
  email: varchar("email", { length: 255 }).notNull().unique(),
  password: varchar("password", { length: 255 }).notNull(),
  name: varchar("name", { length: 255 }),
  isAdmin: boolean("is_admin").default(false),
  createdAt: timestamp("created_at").defaultNow(),
});

export const insertUserSchema = createInsertSchema(users).omit({
  id: true,
  createdAt: true,
});

export type InsertUser = z.infer<typeof insertUserSchema>;
export type User = typeof users.$inferSelect;

export const clients = pgTable("clients", {
  id: serial("id").primaryKey(),
  userId: integer("user_id").references(() => users.id),
  parentClientId: integer("parent_client_id"),
  name: varchar("name", { length: 255 }).notNull(),
  email: varchar("email", { length: 255 }).notNull().unique(),
  phone: varchar("phone", { length: 50 }),
  company: varchar("company", { length: 255 }),
  address: text("address"),
  city: varchar("city", { length: 100 }),
  state: varchar("state", { length: 50 }),
  zip: varchar("zip", { length: 20 }),
  notes: text("notes"),
  latitude: varchar("latitude", { length: 20 }),
  longitude: varchar("longitude", { length: 20 }),
  creditBalance: decimal("credit_balance", { precision: 10, scale: 2 }).default("0"),
  createdAt: timestamp("created_at").defaultNow(),
  updatedAt: timestamp("updated_at").defaultNow(),
});

export const insertClientSchema = createInsertSchema(clients).omit({
  id: true,
  createdAt: true,
  updatedAt: true,
});

export type InsertClient = z.infer<typeof insertClientSchema>;
export type Client = typeof clients.$inferSelect;

export const products = pgTable("products", {
  id: serial("id").primaryKey(),
  name: varchar("name", { length: 255 }).notNull().unique(),
  category: varchar("category", { length: 100 }).notNull(),
  tier: varchar("tier", { length: 50 }),
  price: decimal("price", { precision: 10, scale: 2 }).notNull(),
  billingPeriod: varchar("billing_period", { length: 20 }).default("monthly"),
  description: text("description"),
  features: jsonb("features"),
  active: boolean("active").default(true),
  createdAt: timestamp("created_at").defaultNow(),
});

export const insertProductSchema = createInsertSchema(products).omit({
  id: true,
  createdAt: true,
});

export type InsertProduct = z.infer<typeof insertProductSchema>;
export type Product = typeof products.$inferSelect;

export const subscriptions = pgTable("subscriptions", {
  id: serial("id").primaryKey(),
  clientId: integer("client_id").references(() => clients.id),
  productId: integer("product_id").references(() => products.id),
  status: varchar("status", { length: 50 }).default("active"),
  stripeSubscriptionId: varchar("stripe_subscription_id", { length: 255 }),
  stripeCustomerId: varchar("stripe_customer_id", { length: 255 }),
  startDate: date("start_date").notNull(),
  endDate: date("end_date"),
  mrr: decimal("mrr", { precision: 10, scale: 2 }).notNull(),
  createdAt: timestamp("created_at").defaultNow(),
  updatedAt: timestamp("updated_at").defaultNow(),
});

export const insertSubscriptionSchema = createInsertSchema(subscriptions).omit({
  id: true,
  createdAt: true,
  updatedAt: true,
});

export type InsertSubscription = z.infer<typeof insertSubscriptionSchema>;
export type Subscription = typeof subscriptions.$inferSelect;

export const invoices = pgTable("invoices", {
  id: serial("id").primaryKey(),
  clientId: integer("client_id").references(() => clients.id),
  subscriptionId: integer("subscription_id").references(() => subscriptions.id),
  invoiceNumber: varchar("invoice_number", { length: 50 }).notNull().unique(),
  amount: decimal("amount", { precision: 10, scale: 2 }).notNull(),
  tax: decimal("tax", { precision: 10, scale: 2 }).default("0"),
  total: decimal("total", { precision: 10, scale: 2 }).default("0"),
  status: varchar("status", { length: 50 }).default("unpaid"),
  dueDate: date("due_date"),
  paidDate: date("paid_date"),
  stripeInvoiceId: varchar("stripe_invoice_id", { length: 255 }),
  items: jsonb("items").default([]),
  notes: text("notes"),
  footer: text("footer"),
  createdAt: timestamp("created_at").defaultNow(),
});

export const insertInvoiceSchema = createInsertSchema(invoices).omit({
  id: true,
  createdAt: true,
});

export type InsertInvoice = z.infer<typeof insertInvoiceSchema>;
export type Invoice = typeof invoices.$inferSelect;

export const tickets = pgTable("tickets", {
  id: serial("id").primaryKey(),
  clientId: integer("client_id").references(() => clients.id),
  subject: varchar("subject", { length: 255 }).notNull(),
  description: text("description"),
  status: varchar("status", { length: 50 }).default("open"),
  priority: varchar("priority", { length: 20 }).default("medium"),
  assignedTo: varchar("assigned_to", { length: 100 }),
  source: varchar("source", { length: 50 }).default("portal"),
  createdAt: timestamp("created_at").defaultNow(),
  updatedAt: timestamp("updated_at").defaultNow(),
});

export const insertTicketSchema = createInsertSchema(tickets).omit({
  id: true,
  createdAt: true,
  updatedAt: true,
});

export type InsertTicket = z.infer<typeof insertTicketSchema>;
export type Ticket = typeof tickets.$inferSelect;

export const ticketComments = pgTable("ticket_comments", {
  id: serial("id").primaryKey(),
  ticketId: integer("ticket_id").references(() => tickets.id),
  userId: integer("user_id").references(() => users.id),
  comment: text("comment").notNull(),
  isInternal: boolean("is_internal").default(false),
  createdAt: timestamp("created_at").defaultNow(),
});

export const insertTicketCommentSchema = createInsertSchema(ticketComments).omit({
  id: true,
  createdAt: true,
});

export type InsertTicketComment = z.infer<typeof insertTicketCommentSchema>;
export type TicketComment = typeof ticketComments.$inferSelect;

export const documents = pgTable("documents", {
  id: serial("id").primaryKey(),
  clientId: integer("client_id").references(() => clients.id),
  uploadedBy: integer("uploaded_by").references(() => users.id),
  name: varchar("name", { length: 255 }).notNull(),
  filename: varchar("filename", { length: 255 }).notNull(),
  filepath: varchar("filepath", { length: 500 }).notNull(),
  filesize: integer("filesize"),
  mimetype: varchar("mimetype", { length: 100 }),
  category: varchar("category", { length: 100 }).default("general"),
  description: text("description"),
  isPublic: boolean("is_public").default(false),
  createdAt: timestamp("created_at").defaultNow(),
});

export const insertDocumentSchema = createInsertSchema(documents).omit({
  id: true,
  createdAt: true,
});

export type InsertDocument = z.infer<typeof insertDocumentSchema>;
export type Document = typeof documents.$inferSelect;

export const payments = pgTable("payments", {
  id: serial("id").primaryKey(),
  invoiceId: integer("invoice_id").references(() => invoices.id),
  clientId: integer("client_id").references(() => clients.id),
  amount: decimal("amount", { precision: 10, scale: 2 }).notNull(),
  method: varchar("method", { length: 50 }).default("stripe"),
  stripePaymentId: varchar("stripe_payment_id", { length: 255 }),
  status: varchar("status", { length: 50 }).default("completed"),
  createdAt: timestamp("created_at").defaultNow(),
});

export const insertPaymentSchema = createInsertSchema(payments).omit({
  id: true,
  createdAt: true,
});

export type InsertPayment = z.infer<typeof insertPaymentSchema>;
export type Payment = typeof payments.$inferSelect;

export const activityLog = pgTable("activity_log", {
  id: serial("id").primaryKey(),
  userId: integer("user_id").references(() => users.id),
  action: varchar("action", { length: 255 }).notNull(),
  entityType: varchar("entity_type", { length: 50 }),
  entityId: integer("entity_id"),
  details: jsonb("details"),
  createdAt: timestamp("created_at").defaultNow(),
});

export const insertActivityLogSchema = createInsertSchema(activityLog).omit({
  id: true,
  createdAt: true,
});

export type InsertActivityLog = z.infer<typeof insertActivityLogSchema>;
export type ActivityLog = typeof activityLog.$inferSelect;

export const agentLogs = pgTable("agent_logs", {
  id: serial("id").primaryKey(),
  agentName: varchar("agent_name", { length: 100 }).notNull(),
  action: varchar("action", { length: 255 }).notNull(),
  status: varchar("status", { length: 50 }).notNull(),
  details: jsonb("details"),
  executionTime: integer("execution_time"),
  createdAt: timestamp("created_at").defaultNow(),
});

export const insertAgentLogSchema = createInsertSchema(agentLogs).omit({
  id: true,
  createdAt: true,
});

export type InsertAgentLog = z.infer<typeof insertAgentLogSchema>;
export type AgentLog = typeof agentLogs.$inferSelect;

export const networkDevices = pgTable("network_devices", {
  id: serial("id").primaryKey(),
  clientId: integer("client_id").references(() => clients.id),
  hostname: varchar("hostname", { length: 255 }).notNull(),
  deviceType: varchar("device_type", { length: 100 }).notNull(),
  manufacturer: varchar("manufacturer", { length: 100 }),
  model: varchar("model", { length: 100 }),
  serialNumber: varchar("serial_number", { length: 100 }),
  ipAddress: varchar("ip_address", { length: 50 }),
  macAddress: varchar("mac_address", { length: 50 }),
  osName: varchar("os_name", { length: 100 }),
  osVersion: varchar("os_version", { length: 100 }),
  cpu: varchar("cpu", { length: 150 }),
  ramGb: integer("ram_gb"),
  diskGb: integer("disk_gb"),
  status: varchar("status", { length: 50 }).default("online"),
  lastSeen: timestamp("last_seen"),
  itarianAgentId: varchar("itarian_agent_id", { length: 255 }),
  notes: text("notes"),
  createdAt: timestamp("created_at").defaultNow(),
  updatedAt: timestamp("updated_at").defaultNow(),
});

export const insertNetworkDeviceSchema = createInsertSchema(networkDevices).omit({
  id: true,
  createdAt: true,
  updatedAt: true,
});

export type InsertNetworkDevice = z.infer<typeof insertNetworkDeviceSchema>;
export type NetworkDevice = typeof networkDevices.$inferSelect;

export const networkCredentials = pgTable("network_credentials", {
  id: serial("id").primaryKey(),
  clientId: integer("client_id").references(() => clients.id),
  title: varchar("title", { length: 255 }).notNull(),
  category: varchar("category", { length: 100 }).default("general"),
  username: varchar("username", { length: 255 }),
  password: varchar("password", { length: 500 }),
  url: varchar("url", { length: 500 }),
  notes: text("notes"),
  createdBy: integer("created_by").references(() => users.id),
  createdAt: timestamp("created_at").defaultNow(),
  updatedAt: timestamp("updated_at").defaultNow(),
});

export const insertNetworkCredentialSchema = createInsertSchema(networkCredentials).omit({
  id: true,
  createdAt: true,
  updatedAt: true,
});

export type InsertNetworkCredential = z.infer<typeof insertNetworkCredentialSchema>;
export type NetworkCredential = typeof networkCredentials.$inferSelect;

export const knowledgeArticles = pgTable("knowledge_articles", {
  id: serial("id").primaryKey(),
  title: varchar("title", { length: 255 }).notNull(),
  slug: varchar("slug", { length: 255 }).notNull().unique(),
  content: text("content").notNull(),
  category: varchar("category", { length: 100 }).default("general"),
  tags: text("tags"),
  isPublished: boolean("is_published").default(false),
  authorId: integer("author_id").references(() => users.id),
  viewCount: integer("view_count").default(0),
  createdAt: timestamp("created_at").defaultNow(),
  updatedAt: timestamp("updated_at").defaultNow(),
});

export const insertKnowledgeArticleSchema = createInsertSchema(knowledgeArticles).omit({
  id: true,
  createdAt: true,
  updatedAt: true,
});

export type InsertKnowledgeArticle = z.infer<typeof insertKnowledgeArticleSchema>;
export type KnowledgeArticle = typeof knowledgeArticles.$inferSelect;

export const notifications = pgTable("notifications", {
  id: serial("id").primaryKey(),
  userId: integer("user_id").references(() => users.id),
  title: varchar("title", { length: 255 }).notNull(),
  message: text("message").notNull(),
  type: varchar("type", { length: 50 }).default("info"),
  entityType: varchar("entity_type", { length: 50 }),
  entityId: integer("entity_id"),
  isRead: boolean("is_read").default(false),
  createdAt: timestamp("created_at").defaultNow(),
});

export const insertNotificationSchema = createInsertSchema(notifications).omit({
  id: true,
  createdAt: true,
});

export type InsertNotification = z.infer<typeof insertNotificationSchema>;
export type Notification = typeof notifications.$inferSelect;
