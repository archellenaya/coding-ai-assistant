import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { beforeEach, expect, it, vi } from "vitest";
import MemoryExplorer from "./MemoryExplorer";
import { SummaryPanel } from "./SummaryPanel";
import { api } from "./api";
vi.mock("./api", async (original) => ({
  ...(await original<typeof import("./api")>()),
  api: vi.fn(),
}));
const mock = vi.mocked(api);
let memory = {
  id: "m1",
  category: "project",
  content: "Use PostgreSQL foreign keys",
  revision: 1,
  state: "active",
  verification_status: "unverified",
  source_kind: "manual",
  index_status: "ready",
  index_error: null,
  provenance: { method: "manual" },
  conversation_id: null,
};
beforeEach(() => {
  mock.mockReset();
  memory = {
    ...memory,
    content: "Use PostgreSQL foreign keys",
    revision: 1,
    state: "active",
  };
  mock.mockImplementation(async (path, method, body) => {
    if (path.startsWith("projects/p1/memories?") && !method)
      return { data: [memory], last_page: 1 } as never;
    if (path === "projects/p1/memories" && method === "POST") {
      memory = { ...memory, ...(body as object) };
      return { data: memory } as never;
    }
    if (path === "projects/p1/memories/search")
      return {
        data: [{ memory, score: 1 }],
        retrieval: { mode: "lexical", degraded: true },
      } as never;
    if (path === "memories/m1" && method === "PATCH") {
      memory = {
        ...memory,
        ...(body as object),
        revision: memory.revision + 1,
      };
      return { data: memory } as never;
    }
    if (path === "memories/m1/invalidate") {
      memory = { ...memory, state: "invalidated" };
      return { data: memory } as never;
    }
    return {
      data: memory,
      embedding: {
        model: "local-model",
        model_version: "v1",
        dimensions: 384,
        profile: "profile",
      },
    } as never;
  });
});
function mount(element = <MemoryExplorer />) {
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
      <MemoryRouter initialEntries={["/projects/p1/memories"]}>
        <Routes>
          <Route path="/projects/:projectId/memories" element={element} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}
it("creates a private unverified memory and exposes provenance", async () => {
  mount();
  const user = userEvent.setup();
  await user.type(
    screen.getByLabelText("Memory content"),
    "Remember index rules",
  );
  await user.click(screen.getByRole("button", { name: "Save memory" }));
  expect(
    await screen.findByRole("heading", { name: "Memory details" }),
  ).toBeInTheDocument();
  await waitFor(() =>
    expect(mock).toHaveBeenCalledWith(
      "projects/p1/memories",
      "POST",
      expect.objectContaining({
        category: "project",
        content: "Remember index rules",
      }),
    ),
  );
  expect(
    await screen.findByText(/Unverified · Revision 1/),
  ).toBeInTheDocument();
  await user.click(screen.getByText("Source provenance"));
  expect(screen.getByText(/"method": "manual"/)).toBeInTheDocument();
});
it("shows explicit keyword fallback and supports optimistic correction/invalidation", async () => {
  mount();
  const user = userEvent.setup();
  await user.type(screen.getByLabelText("Search query"), "foreign keys");
  await user.click(screen.getByRole("button", { name: "Search memories" }));
  expect(await screen.findByRole("status")).toHaveTextContent(
    "embeddings unavailable; keyword results only",
  );
  await user.click(screen.getByRole("button", { name: "Inspect memory" }));
  const field = await screen.findByLabelText("Correct memory");
  await user.clear(field);
  await user.type(field, "Corrected rule");
  await user.click(screen.getByRole("button", { name: "Save correction" }));
  await waitFor(() =>
    expect(mock).toHaveBeenCalledWith("memories/m1", "PATCH", {
      content: "Corrected rule",
      revision: 1,
    }),
  );
  await screen.findByText(/Revision 2/);
  await user.click(screen.getByRole("button", { name: "Invalidate memory" }));
  await waitFor(() =>
    expect(
      screen.getByRole("button", { name: "Re-index memory" }),
    ).toBeDisabled(),
  );
});
it("requires confirmation before permanently deleting a memory", async () => {
  mount();
  const user = userEvent.setup();
  await user.click(
    await screen.findByRole("button", { name: "Inspect memory" }),
  );
  await screen.findByLabelText("Correct memory");
  vi.spyOn(window, "confirm")
    .mockReturnValueOnce(false)
    .mockReturnValueOnce(true);
  await user.click(screen.getByRole("button", { name: "Delete memory" }));
  expect(mock).not.toHaveBeenCalledWith("memories/m1", "DELETE", undefined);
  await user.click(screen.getByRole("button", { name: "Delete memory" }));
  await waitFor(() =>
    expect(mock).toHaveBeenCalledWith("memories/m1", "DELETE", undefined),
  );
});
it("summary generation is explicit and disabled when cloud processing is unavailable", async () => {
  mock.mockResolvedValue({ data: null } as never);
  const view = mount(<SummaryPanel conversationId="c1" disabled />);
  expect(
    screen.getByRole("button", { name: "Summarize conversation" }),
  ).toBeDisabled();
  expect(mock).not.toHaveBeenCalledWith(
    "conversations/c1/summary",
    "POST",
    expect.anything(),
  );
  view.unmount();
  mock.mockImplementation(
    async (_path, method) =>
      ({
        data:
          method === "POST"
            ? {
                id: "s1",
                status: "ready",
                content: "Proposed fixes are unverified.",
                through_sequence: 2,
                attempt: 1,
              }
            : null,
      }) as never,
  );
  mount(<SummaryPanel conversationId="c1" disabled={false} />);
  const user = userEvent.setup();
  await user.click(
    screen.getByRole("button", { name: "Summarize conversation" }),
  );
  expect(
    await screen.findByText("Proposed fixes are unverified."),
  ).toBeInTheDocument();
  expect(mock).toHaveBeenCalledWith("conversations/c1/summary", "POST", {
    retry: false,
  });
});

it("retries a failed summary with its original snapshot ID and attempt", async () => {
  const failed = {
    id: "s1",
    status: "failed",
    content: null,
    through_sequence: 2,
    attempt: 1,
    error_code: "summary_failed",
  };
  mock.mockImplementation(
    async (_path, method) =>
      ({
        data:
          method === "POST"
            ? {
                ...failed,
                status: "ready",
                attempt: 2,
                content: "Unverified summary",
              }
            : failed,
      }) as never,
  );
  mount(<SummaryPanel conversationId="c1" disabled={false} />);
  const user = userEvent.setup();
  await user.click(
    await screen.findByRole("button", { name: "Retry summary" }),
  );
  await waitFor(() =>
    expect(mock).toHaveBeenCalledWith("conversations/c1/summary", "POST", {
      retry: true,
      summary_id: "s1",
      attempt: 1,
    }),
  );
  expect(await screen.findByText("Unverified summary")).toBeInTheDocument();
});
