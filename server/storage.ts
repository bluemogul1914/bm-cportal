import {
  type Snippet, type InsertSnippet, snippets,
  type User, type InsertUser, users,
  type Client, type InsertClient, clients,
  type Product, type InsertProduct, products,
  type Subscription, type InsertSubscription, subscriptions,
  type Invoice, type InsertInvoice, invoices,
  type Ticket, type InsertTicket, tickets,
  type AgentLog, type InsertAgentLog, agentLogs,
} from "@shared/schema";
import { db } from "./db";
import { eq, desc } from "drizzle-orm";

export interface IStorage {
  getSnippets(): Promise<Snippet[]>;
  getSnippet(id: number): Promise<Snippet | undefined>;
  createSnippet(snippet: InsertSnippet): Promise<Snippet>;
  deleteSnippet(id: number): Promise<void>;

  getUsers(): Promise<User[]>;
  getUser(id: number): Promise<User | undefined>;
  getUserByEmail(email: string): Promise<User | undefined>;
  createUser(user: InsertUser): Promise<User>;

  getClients(): Promise<Client[]>;
  getClient(id: number): Promise<Client | undefined>;
  createClient(client: InsertClient): Promise<Client>;
  updateClient(id: number, client: Partial<InsertClient>): Promise<Client | undefined>;
  deleteClient(id: number): Promise<void>;

  getProducts(): Promise<Product[]>;
  getProduct(id: number): Promise<Product | undefined>;
  createProduct(product: InsertProduct): Promise<Product>;
  updateProduct(id: number, product: Partial<InsertProduct>): Promise<Product | undefined>;
  deleteProduct(id: number): Promise<void>;

  getSubscriptions(): Promise<Subscription[]>;
  getSubscription(id: number): Promise<Subscription | undefined>;
  createSubscription(subscription: InsertSubscription): Promise<Subscription>;
  updateSubscription(id: number, subscription: Partial<InsertSubscription>): Promise<Subscription | undefined>;

  getInvoices(): Promise<Invoice[]>;
  getInvoice(id: number): Promise<Invoice | undefined>;
  createInvoice(invoice: InsertInvoice): Promise<Invoice>;
  updateInvoice(id: number, invoice: Partial<InsertInvoice>): Promise<Invoice | undefined>;

  getTickets(): Promise<Ticket[]>;
  getTicket(id: number): Promise<Ticket | undefined>;
  createTicket(ticket: InsertTicket): Promise<Ticket>;
  updateTicket(id: number, ticket: Partial<InsertTicket>): Promise<Ticket | undefined>;
  deleteTicket(id: number): Promise<void>;

  getAgentLogs(): Promise<AgentLog[]>;
  createAgentLog(log: InsertAgentLog): Promise<AgentLog>;
}

export class DatabaseStorage implements IStorage {
  async getSnippets(): Promise<Snippet[]> {
    return await db.select().from(snippets).orderBy(desc(snippets.createdAt));
  }

  async getSnippet(id: number): Promise<Snippet | undefined> {
    const [snippet] = await db.select().from(snippets).where(eq(snippets.id, id));
    return snippet;
  }

  async createSnippet(snippet: InsertSnippet): Promise<Snippet> {
    const [created] = await db.insert(snippets).values(snippet).returning();
    return created;
  }

  async deleteSnippet(id: number): Promise<void> {
    await db.delete(snippets).where(eq(snippets.id, id));
  }

  async getUsers(): Promise<User[]> {
    return await db.select().from(users);
  }

  async getUser(id: number): Promise<User | undefined> {
    const [user] = await db.select().from(users).where(eq(users.id, id));
    return user;
  }

  async getUserByEmail(email: string): Promise<User | undefined> {
    const [user] = await db.select().from(users).where(eq(users.email, email));
    return user;
  }

  async createUser(user: InsertUser): Promise<User> {
    const [created] = await db.insert(users).values(user).returning();
    return created;
  }

  async getClients(): Promise<Client[]> {
    return await db.select().from(clients).orderBy(desc(clients.createdAt));
  }

  async getClient(id: number): Promise<Client | undefined> {
    const [client] = await db.select().from(clients).where(eq(clients.id, id));
    return client;
  }

  async createClient(client: InsertClient): Promise<Client> {
    const [created] = await db.insert(clients).values(client).returning();
    return created;
  }

  async updateClient(id: number, client: Partial<InsertClient>): Promise<Client | undefined> {
    const [updated] = await db.update(clients).set(client).where(eq(clients.id, id)).returning();
    return updated;
  }

  async deleteClient(id: number): Promise<void> {
    await db.delete(clients).where(eq(clients.id, id));
  }

  async getProducts(): Promise<Product[]> {
    return await db.select().from(products).orderBy(products.category, products.tier);
  }

  async getProduct(id: number): Promise<Product | undefined> {
    const [product] = await db.select().from(products).where(eq(products.id, id));
    return product;
  }

  async createProduct(product: InsertProduct): Promise<Product> {
    const [created] = await db.insert(products).values(product).returning();
    return created;
  }

  async updateProduct(id: number, product: Partial<InsertProduct>): Promise<Product | undefined> {
    const [updated] = await db.update(products).set(product).where(eq(products.id, id)).returning();
    return updated;
  }

  async deleteProduct(id: number): Promise<void> {
    await db.delete(products).where(eq(products.id, id));
  }

  async getSubscriptions(): Promise<Subscription[]> {
    return await db.select().from(subscriptions).orderBy(desc(subscriptions.createdAt));
  }

  async getSubscription(id: number): Promise<Subscription | undefined> {
    const [sub] = await db.select().from(subscriptions).where(eq(subscriptions.id, id));
    return sub;
  }

  async createSubscription(subscription: InsertSubscription): Promise<Subscription> {
    const [created] = await db.insert(subscriptions).values(subscription).returning();
    return created;
  }

  async updateSubscription(id: number, subscription: Partial<InsertSubscription>): Promise<Subscription | undefined> {
    const [updated] = await db.update(subscriptions).set(subscription).where(eq(subscriptions.id, id)).returning();
    return updated;
  }

  async getInvoices(): Promise<Invoice[]> {
    return await db.select().from(invoices).orderBy(desc(invoices.createdAt));
  }

  async getInvoice(id: number): Promise<Invoice | undefined> {
    const [invoice] = await db.select().from(invoices).where(eq(invoices.id, id));
    return invoice;
  }

  async createInvoice(invoice: InsertInvoice): Promise<Invoice> {
    const [created] = await db.insert(invoices).values(invoice).returning();
    return created;
  }

  async updateInvoice(id: number, invoice: Partial<InsertInvoice>): Promise<Invoice | undefined> {
    const [updated] = await db.update(invoices).set(invoice).where(eq(invoices.id, id)).returning();
    return updated;
  }

  async getTickets(): Promise<Ticket[]> {
    return await db.select().from(tickets).orderBy(desc(tickets.createdAt));
  }

  async getTicket(id: number): Promise<Ticket | undefined> {
    const [ticket] = await db.select().from(tickets).where(eq(tickets.id, id));
    return ticket;
  }

  async createTicket(ticket: InsertTicket): Promise<Ticket> {
    const [created] = await db.insert(tickets).values(ticket).returning();
    return created;
  }

  async updateTicket(id: number, ticket: Partial<InsertTicket>): Promise<Ticket | undefined> {
    const [updated] = await db.update(tickets).set(ticket).where(eq(tickets.id, id)).returning();
    return updated;
  }

  async deleteTicket(id: number): Promise<void> {
    await db.delete(tickets).where(eq(tickets.id, id));
  }

  async getAgentLogs(): Promise<AgentLog[]> {
    return await db.select().from(agentLogs).orderBy(desc(agentLogs.createdAt));
  }

  async createAgentLog(log: InsertAgentLog): Promise<AgentLog> {
    const [created] = await db.insert(agentLogs).values(log).returning();
    return created;
  }
}

export const storage = new DatabaseStorage();
