import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { beforeEach, expect, it, vi } from "vitest";
import { ProviderPreferences } from "./ProviderPreferences";
import OptimizationPage from "./OptimizationPage";
import { api } from "./api";
vi.mock("./api", async (original) => ({
  ...(await original<typeof import("./api")>()),
  api: vi.fn(),
}));
const mock = vi.mocked(api);
const models = [
  { id: "local", provider: "ollama", available: true },
  { id: "cloud", provider: "openai", available: true },
];
beforeEach(() => {
  mock.mockReset();
  mock.mockResolvedValue({ data: {} } as never);
});
function mount(
  element = (
    <ProviderPreferences
      id="c1"
      preferences={{
        provider_mode: "openai",
        preferred_model: "cloud",
        allow_fallback: false,
      }}
      models={models}
      disabled={false}
      cloudAllowed
    />
  ),
) {
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
      <MemoryRouter initialEntries={["/projects/p1/optimization"]}>
        <Routes>
          <Route path="/projects/:projectId/optimization" element={element} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}
it("saves provider and model with explicit Auto fallback consent", async () => {
  mount();
  const user = userEvent.setup();
  await user.selectOptions(screen.getByLabelText("AI provider"), "auto");
  await user.selectOptions(screen.getByLabelText("AI model"), "local");
  await user.click(screen.getByRole("checkbox"));
  expect(mock).not.toHaveBeenCalled();
  await user.click(
    screen.getByRole("button", { name: "Save provider preferences" }),
  );
  expect(mock).toHaveBeenCalledWith("conversations/c1/preferences", "PATCH", {
    provider_mode: "auto",
    preferred_model: "local",
    allow_fallback: true,
  });
});
it("keeps cloud selection unavailable for a local-only project", () => {
  mount(
    <ProviderPreferences
      id="c1"
      preferences={{ provider_mode: "ollama" }}
      models={models}
      disabled={false}
      cloudAllowed={false}
    />,
  );
  expect(screen.getByRole("option", { name: "OpenAI (Cloud)" })).toBeDisabled();
  expect(screen.queryByRole("option", { name: "openai · cloud" })).toBeNull();
  expect(screen.getByText(/hardware costs remain/)).toBeInTheDocument();
});
it("shows preference availability errors without dropping saved history", async () => {
  mock.mockRejectedValue(new Error("Selected model is unavailable"));
  mount();
  const user = userEvent.setup();
  await user.click(
    screen.getByRole("button", { name: "Save provider preferences" }),
  );
  expect(await screen.findByRole("alert")).toHaveTextContent(
    "Selected model is unavailable",
  );
});
function setupPage() {
  mock.mockImplementation(async (path) => {
    if (path.startsWith("projects/p1/solutions"))
      return { data: [], last_page: 1 } as never;
    if (path.startsWith("projects/p1/usage"))
      return {
        data: {
          data: [
            {
              id: "call",
              provider: "ollama",
              model: "local",
              status: "completed",
              duration_ms: 200,
              cache_hit: false,
            },
          ],
          last_page: 1,
        },
        totals: [
          {
            provider: "ollama",
            model: "local",
            calls: 1,
            input_tokens: 10,
            output_tokens: 5,
            accounted_micro_usd: 0,
            estimated_micro_usd: 0,
            average_duration_ms: 200,
          },
        ],
        scope: "project",
        budgets_micro_usd: { user: 10000000 },
        cost_note: "Estimates, not invoices; local hardware cost is separate.",
      } as never;
    return { data: {} } as never;
  });
}
it("reports local API charges and measured duration with invoice limitations", async () => {
  setupPage();
  mount(<OptimizationPage />);
  expect(
    await screen.findByText(/Estimates, not invoices/),
  ).toBeInTheDocument();
  expect(
    await screen.findByText("ollama / local · completed · 200 ms"),
  ).toBeInTheDocument();
  expect(screen.getAllByText("$0.000000")).toHaveLength(2);
});
it("records manual test evidence only after explicit attestation", async () => {
  setupPage();
  mount(<OptimizationPage />);
  const user = userEvent.setup();
  for (const [label, value] of [
    ["Error signature", "fixture-error"],
    ["Solution", "Tested fix"],
    ["Framework", "Laravel"],
    ["Framework version", "12"],
    ["Environment", "Linux"],
    ["Test command", "php artisan test"],
    ["Test result", "Passed"],
  ]) {
    await user.type(screen.getByLabelText(label), value);
  }
  await user.click(screen.getByRole("checkbox"));
  await user.click(
    screen.getByRole("button", { name: "Record tested solution" }),
  );
  await waitFor(() =>
    expect(mock).toHaveBeenCalledWith(
      "projects/p1/solutions",
      "POST",
      expect.objectContaining({
        evidence: {
          command: "php artisan test",
          exit_code: 0,
          result: "Passed",
          attestation: true,
        },
      }),
    ),
  );
});
