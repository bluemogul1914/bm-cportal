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
  name: varchar("name", { length: 255 }).notNull(),
  email: varchar("email", { length: 255 }).notNull().unique(),
  phone: varchar("phone", { length: 50 }),
  company: varchar("company", { length: 255 }),
  address: text("address"),
  city: varchar("city", { length: 100 }),
  state: varchar("state", { length: 50 }),
  zip: varchar("zip", { length: 20 }),
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
  status: varchar("status", { length: 50 }).default("unpaid"),
  dueDate: date("due_date"),
  paidDate: date("paid_date"),
  stripeInvoiceId: varchar("stripe_invoice_id", { length: 255 }),
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
