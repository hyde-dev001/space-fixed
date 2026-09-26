import { renderHook } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { useFinanceApi } from "../useFinanceApi";

const fetchMock = vi.fn();

vi.mock("@inertiajs/react", () => ({
  usePage: () => ({
    props: {
      auth: {
        user: { id: 10 },
        erpActor: { ownerMode: false },
      },
    },
  }),
}));

beforeEach(() => {
  fetchMock.mockReset();
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("useFinanceApi paginated responses", () => {
  it("keeps Laravel pagination metadata instead of returning only the data rows", async () => {
    const payload = {
      current_page: 2,
      last_page: 3,
      per_page: 10,
      total: 21,
      from: 11,
      to: 20,
      data: [{ id: "expense-11", reference: "EXP-11" }],
    };

    fetchMock.mockResolvedValue({
      ok: true,
      status: 200,
      headers: { get: () => "application/json" },
      json: async () => payload,
    });

    const { result } = renderHook(() => useFinanceApi());
    const response = await result.current.get("/api/finance/expenses?page=2");

    expect(response.ok).toBe(true);
    expect(response.data).toEqual(payload);
  });
});
