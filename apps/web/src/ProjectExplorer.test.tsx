import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import { beforeEach, expect, it, vi } from "vitest";
import ProjectExplorer from "./ProjectExplorer";
import { api } from "./api";
vi.mock("./api", async (original) => ({
  ...(await original<typeof import("./api")>()),
  api: vi.fn(),
}));
const mock = vi.mocked(api);
const repository = {
  id: "r1",
  name: "Auth source",
  path: "auth",
  revision: "abc123",
  languages: ["typescript"],
  frameworks: ["React"],
};
beforeEach(() => {
  mock.mockReset();
  mock.mockImplementation(async (path) => {
    if (path.startsWith("projects/p1/repositories?"))
      return { data: [repository], last_page: 1 } as never;
    if (path === "repositories/r1")
      return {
        data: repository,
        job: { status: "ready", stats: { files: 1 } },
      } as never;
    if (path.startsWith("repositories/r1/files?"))
      return {
        data: [
          {
            id: "f1",
            path: "auth.ts",
            language: "typescript",
            parser: "tree-sitter",
          },
        ],
        last_page: 1,
      } as never;
    if (path === "projects/p1/code/search")
      return {
        data: [
          {
            chunk: {
              id: "c1",
              path: "auth.ts",
              symbol: "renew_session",
              start_line: 1,
              end_line: 3,
            },
          },
        ],
        retrieval: { mode: "lexical", degraded: true },
      } as never;
    if (path === "code-chunks/c1")
      return {
        data: {
          id: "c1",
          start_line: 1,
          end_line: 3,
          content: '<script>alert("x")</script>',
        },
        file: { path: "auth.ts" },
        repository,
      } as never;
    return { data: repository } as never;
  });
});
function mount(entry = "/projects/p1/repository") {
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
      <MemoryRouter initialEntries={[entry]}>
        <Routes>
          <Route
            path="/projects/:projectId/repository"
            element={<ProjectExplorer />}
          />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}
it("registers a relative repository and shows read-only indexing details", async () => {
  mount();
  const user = userEvent.setup();
  await user.type(screen.getByLabelText("Repository name"), "New source");
  await user.type(
    screen.getByLabelText("Relative repository directory"),
    "new-source",
  );
  await user.click(screen.getByRole("button", { name: "Register repository" }));
  expect(mock).toHaveBeenCalledWith("projects/p1/repositories", "POST", {
    name: "New source",
    path: "new-source",
  });
  await user.click(await screen.findByRole("button", { name: "Auth source" }));
  expect(await screen.findByText(/Languages: typescript/)).toBeInTheDocument();
  expect(
    await screen.findByText(/auth.ts · typescript · tree-sitter/),
  ).toBeInTheDocument();
  await user.click(screen.getByRole("button", { name: "Index repository" }));
  await waitFor(() =>
    expect(mock).toHaveBeenCalledWith(
      "repositories/r1/index",
      "POST",
      undefined,
    ),
  );
});
it("shows degraded retrieval, source ranges and escapes indexed HTML", async () => {
  mount();
  const user = userEvent.setup();
  await user.type(screen.getByLabelText("Code query"), "session refresh");
  await user.click(screen.getByRole("button", { name: "Search code" }));
  expect(
    await screen.findByText("Embeddings unavailable; keyword results only"),
  ).toBeInTheDocument();
  await user.click(
    screen.getByRole("button", { name: /auth.ts:1–3 renew_session/ }),
  );
  expect(await screen.findByText(/Git abc123/)).toBeInTheDocument();
  expect(document.querySelector("script")).toBeNull();
  expect(screen.getByText(/Indexed workspace snapshot/)).toBeInTheDocument();
});
it("requires confirmation before removing a repository association", async () => {
  mount();
  const user = userEvent.setup();
  await user.click(await screen.findByRole("button", { name: "Auth source" }));
  const button = await screen.findByRole("button", {
    name: "Remove repository",
  });
  vi.spyOn(window, "confirm")
    .mockReturnValueOnce(false)
    .mockReturnValueOnce(true);
  await user.click(button);
  expect(mock).not.toHaveBeenCalledWith("repositories/r1", "DELETE", undefined);
  await user.click(button);
  await waitFor(() =>
    expect(mock).toHaveBeenCalledWith("repositories/r1", "DELETE", undefined),
  );
});
