import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, expect, it, vi } from "vitest";
import OwnerSetupGuide from "../OwnerSetupGuide";

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock("axios", () => ({ default: api }));
vi.mock("@inertiajs/react", () => ({
  usePage: () => ({ url: "/shop-owner/home", props: { auth: { shop_owner: { id: 5 } } } }),
  router: { visit: vi.fn() },
}));

beforeEach(() => {
  Element.prototype.scrollIntoView = vi.fn();
  sessionStorage.clear();
  api.get.mockResolvedValue({ data: {
    tasks: [{ key: "paymongo", group: "business", label: "Configure PayMongo", required: true, url: "/shop-owner/settings/payments-approvals", status: "incomplete" }],
    completed: 0, total: 1, tutorials: {}, welcome_seen: true,
  } });
  api.post.mockResolvedValue({ data: {} });
});

it("advances only when the owner clicks the real highlighted control, and keeps setup incomplete", async () => {
  render(<><button data-tour="owner-account-menu">Account menu</button><button data-tour="owner-settings-link">Business Settings</button><OwnerSetupGuide /></>);
  await waitFor(() => expect(api.get).toHaveBeenCalled());
  fireEvent(window, new Event("solespace:open-setup-guide"));
  fireEvent.click(await screen.findByRole("button", { name: "Show me how" }));
  fireEvent.click(screen.getByRole("button", { name: "Let’s go" }));
  expect(screen.getByText("Open your account menu")).toBeInTheDocument();
  expect(api.post).toHaveBeenCalledWith(expect.stringContaining("/progress"), expect.objectContaining({ task_key: "paymongo", status: "in_progress", step: 0 }));

  fireEvent.click(screen.getByRole("button", { name: "Account menu" }));
  await waitFor(() => expect(screen.getByText("Open Business Settings")).toBeInTheDocument());
  expect(api.post).toHaveBeenCalledWith(expect.stringContaining("/progress"), expect.objectContaining({ step: 1 }));
  expect(sessionStorage.getItem("solespace:owner-tour:5")).toContain('"step":1');
  expect(api.post.mock.calls.every(([, data]) => data.status !== "completed")).toBe(true);
});
