import { type Snippet, type InsertSnippet, type User, type InsertUser, snippets } from "@shared/schema";
import { db } from "./db";
import { eq, desc } from "drizzle-orm";

export interface IStorage {
  getSnippets(): Promise<Snippet[]>;
  getSnippet(id: number): Promise<Snippet | undefined>;
  createSnippet(snippet: InsertSnippet): Promise<Snippet>;
  deleteSnippet(id: number): Promise<void>;
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
}

export const storage = new DatabaseStorage();
