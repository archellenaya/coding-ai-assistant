import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { beforeEach, afterEach, expect, it, vi } from "vitest";
import ChatWorkspace from "./ChatWorkspace";
import { api, ApiError } from "./api";
import { MessageContent } from "./MessageContent";
vi.mock("./api", async (original) => ({
  ...(await original<typeof import("./api")>()),
  api: vi.fn(),
}));
const mock = vi.mocked(api);
class FakeEventSource extends EventTarget {
  static CLOSED = 2;
  static instances: FakeEventSource[] = [];
  readyState = 1;
  onopen: (() => void) | null = null;
  onerror: (() => void) | null = null;
  close = vi.fn();
  url: string;
  constructor(url: string) {
    super();
    this.url = url;
    FakeEventSource.instances.push(this);
  }
  emit(type: string, data: unknown) {
    this.dispatchEvent(new MessageEvent(type, { data: JSON.stringify(data) }));
  }
}
const project = {
  id: "p1",
  name: "Demo",
  cloud_processing_allowed: true,
  archived_at: null,
};
const model = { id: "configured-model", provider: "openai", available: true };
const conversation = { id: "c1", title: "Debug this" };
const prompt = {
  id: "u1",
  role: "user",
  status: "completed",
  content: "Explain",
  attempt: 1,
};
const assistant = {
  id: "a1",
  role: "assistant",
  status: "queued",
  content: "",
  attempt: 1,
  model: "configured-model",
  usage: null,
  error_code: null,
};
let transcript: unknown[];
let allowed: boolean;
let submissions: { body: unknown }[];
beforeEach(() => {
  mock.mockReset();
  FakeEventSource.instances = [];
  transcript = [];
  allowed = true;
  submissions = [];
  vi.stubGlobal("EventSource", FakeEventSource);
  mock.mockImplementation(async (path, method, body) => {
    if (path === "projects/p1")
      return {
        data: { ...project, cloud_processing_allowed: allowed },
      } as never;
    if (path.startsWith("projects/p1/conversations?"))
      return { data: [conversation], last_page: 1 } as never;
    if (path.startsWith("projects/p1/solutions")) return { data: [] } as never;
    if (path === "providers") return { data: [model] } as never;
    if (path === "conversations/c1") return { data: conversation } as never;
    if (path === "conversations/c1/messages" && method === "POST") {
      submissions.push({ body });
      transcript = [prompt, assistant];
      return { data: assistant } as never;
    }
    if (path === "conversations/c1/messages")
      return { data: transcript, last_page: 1 } as never;
    return { data: {} } as never;
  });
});
afterEach(() => {
  vi.unstubAllGlobals();
});
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
      <MemoryRouter initialEntries={["/projects/p1/chat"]}>
        <Routes>
          <Route path="/projects/:projectId/chat" element={<ChatWorkspace />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}
async function selectConversation() {
  const user = userEvent.setup();
  await user.click(await screen.findByRole("button", { name: "Debug this" }));
  await screen.findByLabelText("Message");
  return user;
}
it("streams a response, persists the completed view and closes its connection", async () => {
  mount();
  const user = await selectConversation();
  await user.type(screen.getByLabelText("Message"), "Explain");
  await user.click(screen.getByRole("button", { name: "Send message" }));
  await waitFor(() => expect(FakeEventSource.instances).toHaveLength(1));
  const stream = FakeEventSource.instances[0];
  expect(stream.url).toBe("/api/v1/conversations/c1/messages/a1/stream");
  act(() => {
    stream.emit("reset", { attempt: 1 });
    stream.emit("delta", { text: "Useful answer" });
  });
  expect(await screen.findByText("Useful answer")).toBeInTheDocument();
  transcript = [
    prompt,
    { ...assistant, status: "completed", content: "Useful answer" },
  ];
  act(() => {
    stream.emit("completed", { usage: {} });
  });
  await waitFor(() =>
    expect(screen.getByRole("button", { name: "Send message" })).toBeDisabled(),
  );
  expect(stream.close).toHaveBeenCalled();
  expect(submissions[0].body).toEqual(
    expect.objectContaining({
      content: "Explain",
      model: "configured-model",
      idempotency_key: expect.any(String),
    }),
  );
});
it("blocks cloud submission when the project has not opted in", async () => {
  allowed = false;
  mount();
  const user = await selectConversation();
  await user.type(screen.getByLabelText("Message"), "Private code");
  expect(screen.getByRole("button", { name: "Send message" })).toBeDisabled();
  expect(
    await screen.findByText(/Cloud processing is disabled/),
  ).toBeInTheDocument();
  expect(submissions).toHaveLength(0);
});
it("reuses the idempotency key after an uncertain transport failure", async () => {
  const normal = mock.getMockImplementation()!;
  const keys: string[] = [];
  mock.mockImplementation(async (path, method, body) => {
    if (path === "conversations/c1/messages" && method === "POST") {
      keys.push((body as { idempotency_key: string }).idempotency_key);
      throw new ApiError(502, "Connection lost");
    }
    return normal(path, method, body);
  });
  mount();
  const user = await selectConversation();
  await user.type(screen.getByLabelText("Message"), "Explain");
  await user.click(screen.getByRole("button", { name: "Send message" }));
  await screen.findByRole("alert");
  await user.click(screen.getByRole("button", { name: "Send message" }));
  await waitFor(() => expect(keys).toHaveLength(2));
  expect(keys[0]).toBe(keys[1]);
});
it("resumes an in-progress response and cleans up the stream on unmount", async () => {
  transcript = [prompt, assistant];
  const view = mount();
  await selectConversation();
  await waitFor(() => expect(FakeEventSource.instances).toHaveLength(1));
  view.unmount();
  expect(FakeEventSource.instances[0].close).toHaveBeenCalled();
});
it("renders hostile output as text and safely highlights fenced code", () => {
  const { container } = render(
    <MessageContent
      content={
        '<script>alert(1)</script>\n```javascript\nconst value = "<img onerror=alert(1)>";\n```'
      }
    />,
  );
  expect(container.querySelector("script")).toBeNull();
  expect(container.querySelector("img")).toBeNull();
  expect(container.querySelector("pre code")).toHaveTextContent("const value");
});
