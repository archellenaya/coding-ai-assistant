import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, expect, it, vi } from "vitest";
import App from "./App";
import { api, ApiError } from "./api";
vi.mock("./api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("./api")>()),
  api: vi.fn(),
}));
const mock = vi.mocked(api);
function mount() {
  return render(
    <QueryClientProvider
      client={
        new QueryClient({
          defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
          },
        })
      }
    >
      <MemoryRouter>
        <App />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}
beforeEach(() => {
  mock.mockReset();
});
it("registers through the API and opens the authenticated workspace", async () => {
  let signedIn = false;
  mock.mockImplementation(async (path) => {
    if (path === "user") {
      if (!signedIn) throw new ApiError(401, "Unauthenticated");
      return { data: { id: 1, name: "Ada" } } as never;
    }
    if (path === "register") {
      signedIn = true;
      return { data: { id: 1, name: "Ada" } } as never;
    }
    if (path === "organizations") return { data: [] } as never;
    return { data: [], last_page: 1 } as never;
  });
  mount();
  const user = userEvent.setup();
  await user.click(await screen.findByRole("button", { name: "Register" }));
  await user.type(screen.getByLabelText("Name"), "Ada");
  await user.type(screen.getByLabelText("Email"), "ada@example.com");
  await user.type(screen.getByLabelText("Password"), "long-password-123");
  await user.type(
    screen.getByLabelText("Confirm password"),
    "long-password-123",
  );
  await user.click(screen.getByRole("button", { name: "Create account" }));
  expect(
    await screen.findByRole("heading", { name: "Projects" }),
  ).toBeInTheDocument();
  expect(mock).toHaveBeenCalledWith(
    "register",
    "POST",
    expect.objectContaining({ name: "Ada", email: "ada@example.com" }),
  );
});
it("creates and archives a project and confirms before deletion", async () => {
  const project = {
    id: "p1",
    name: "Example",
    description: "Demo",
    archived_at: null,
    cloud_processing_allowed: false,
  };
  mock.mockImplementation(async (path, method) => {
    if (path === "user") return { data: { id: 1, name: "Ada" } } as never;
    if (path === "organizations")
      return { data: [{ id: "o1", name: "Team", role: "owner" }] } as never;
    if (method) return { data: project } as never;
    return { data: [project], last_page: 1 } as never;
  });
  mount();
  const user = userEvent.setup();
  await screen.findByRole("heading", { name: "Example" });
  await user.selectOptions(screen.getByLabelText("Organization"), "o1");
  await user.type(screen.getByLabelText("Project name"), "New");
  await user.click(screen.getByRole("button", { name: "Save project" }));
  await waitFor(() =>
    expect(mock).toHaveBeenCalledWith(
      "projects",
      "POST",
      expect.objectContaining({ name: "New", organization_id: "o1" }),
    ),
  );
  await user.click(screen.getByRole("button", { name: "Archive" }));
  await waitFor(() =>
    expect(mock).toHaveBeenCalledWith("projects/p1", "PATCH", {
      archived: true,
    }),
  );
  vi.spyOn(window, "confirm")
    .mockReturnValueOnce(false)
    .mockReturnValueOnce(true);
  await user.click(screen.getByRole("button", { name: "Delete" }));
  expect(mock).not.toHaveBeenCalledWith("projects/p1", "DELETE", undefined);
  await user.click(screen.getByRole("button", { name: "Delete" }));
  await waitFor(() =>
    expect(mock).toHaveBeenCalledWith("projects/p1", "DELETE", undefined),
  );
});
it("shows service errors instead of treating them as signed out", async () => {
  mock.mockImplementation(async () => {
    throw new ApiError(500, "Service unavailable");
  });
  mount();
  expect(await screen.findByRole("alert")).toHaveTextContent(
    "Service unavailable",
  );
});
