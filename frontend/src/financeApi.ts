import { authInfo } from "./api";
export type Account = {
  id: string;
  name: string;
  type: string;
  initial_balance: string;
  balance: string;
  currency: string;
  is_active: boolean;
};
export type Category = {
  id: string;
  name: string;
  type: "income" | "expense";
  icon: string | null;
};
export type Entry = {
  id: string;
  account_id: string;
  destination_account_id: string | null;
  category_id: string | null;
  type: "income" | "expense" | "transfer";
  amount: string;
  description: string;
  occurred_at: string;
};
export type Summary = {
  balance: string;
  income: string;
  expense: string;
  result: string;
  month: string;
  as_of: string;
  accounts: Account[];
  recent_transactions: Entry[];
  expense_by_category: {
    category_id: string | null;
    name: string;
    amount: string;
  }[];
};
export async function finance<T>(
  path: string,
  method = "GET",
  data?: unknown,
): Promise<T> {
  const headers: Record<string, string> = {};
  if (method !== "GET") {
    headers["Content-Type"] = "application/json";
    headers["X-CSRF-Token"] = (await authInfo()).csrf_token;
  }
  const response = await fetch("/api" + path, {
    method,
    headers,
    credentials: "same-origin",
    cache: "no-store",
    body: data === undefined ? undefined : JSON.stringify(data),
  });
  if (response.status === 401) {
    window.location.replace("/login");
    throw new Error("Sua sessão terminou. Entre novamente.");
  }
  if (response.status === 204) return undefined as T;
  const result = await response.json();
  if (!response.ok)
    throw new Error(
      result.error?.message || "Não foi possível concluir. Tente novamente.",
    );
  return result;
}
export function money(value: string): string {
  const negative = value.startsWith("-");
  const [whole, fraction = "00"] = value.replace(/^-/, "").split(".");
  return `${negative ? "−" : ""}R$ ${new Intl.NumberFormat("pt-BR").format(BigInt(whole))},${fraction.padEnd(2, "0")}`;
}
export function parseMoney(value: string): string {
  if (!/^-?(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d{1,2})?$/.test(value.trim()))
    throw new Error(
      "Use um valor em reais, como 1.234,56, com até duas casas decimais.",
    );
  return value.trim().replace(/\./g, "").replace(",", ".");
}
export const kinds = {
  income: "Receita",
  expense: "Despesa",
  transfer: "Transferência",
};
export const accountTypes: Record<string, string> = {
  checking: "Conta corrente",
  savings: "Poupança",
  cash: "Dinheiro",
  digital_wallet: "Carteira digital",
};
