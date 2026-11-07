import { useEffect, useRef, useState } from "react";
import type { FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useParams } from "react-router-dom";
import { api } from "./api";
import { MessageContent } from "./MessageContent";
import { SummaryPanel } from "./SummaryPanel";

type Conversation = { id: string; title: string };
type Message = {
  id: string;
  role: "user" | "assistant";
  content: string;
  status: "queued" | "streaming" | "completed" | "failed";
  model: string | null;
  attempt: number;
  error_code: string | null;
  memory_sources?: { id: string; revision: number }[];
  usage: { input_tokens?: number; output_tokens?: number } | null;
};
type Page<T> = { data: T[]; last_page: number };
type Model = { id: string; provider: string; available: boolean };
type Project = {
  id: string;
  name: string;
  cloud_processing_allowed: boolean;
  archived_at: string | null;
};

export default function ChatWorkspace() {
  const { projectId } = useParams();
  return <ProjectChat key={projectId} projectId={projectId!} />;
}
function ProjectChat({ projectId }: { projectId: string }) {
  const client = useQueryClient();
  const [selected, setSelected] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const project = useQuery({
    queryKey: ["project", projectId],
    queryFn: () => api<{ data: Project }>(`projects/${projectId}`),
  });
  const conversations = useQuery({
    queryKey: ["conversations", projectId, search, page],
    queryFn: () =>
      api<Page<Conversation>>(
        `projects/${projectId}/conversations?search=${encodeURIComponent(search)}&page=${page}`,
      ),
  });
  const create = useMutation({
    mutationFn: (title: string) =>
      api<{ data: Conversation }>(
        `projects/${projectId}/conversations`,
        "POST",
        { title },
      ),
    onSuccess: (result) => {
      setSelected(result.data.id);
      void client.invalidateQueries({ queryKey: ["conversations", projectId] });
    },
  });
  function createConversation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    create.mutate(String(new FormData(form).get("title")), {
      onSuccess: () => form.reset(),
    });
  }
  return (
    <>
      <Link to="/projects">← Projects</Link>
      <Link to={`/projects/${projectId}/memories`}>Memory Explorer</Link>
      <h1>AI Chat · {project.data?.data.name ?? "Project"}</h1>
      <p>
        Conversations are private to your account. Responses are AI suggestions
        and are not verified fixes.
      </p>
      {project.data && !project.data.data.cloud_processing_allowed && (
        <p role="status">
          Cloud processing is disabled. Enable it in project settings before
          sending code to the cloud provider.
        </p>
      )}
      {(project.error || conversations.error || create.error) && (
        <p role="alert">
          {(project.error || conversations.error || create.error)?.message}
        </p>
      )}
      <div className="grid gap-6 lg:grid-cols-[18rem_1fr]">
        <aside aria-label="Conversation history">
          <form onSubmit={createConversation}>
            <label>
              Conversation title
              <input name="title" required maxLength={150} />
            </label>
            <button
              disabled={create.isPending || !!project.data?.data.archived_at}
            >
              New conversation
            </button>
          </form>
          <label className="mt-5">
            Search conversations
            <input
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
          </label>
          {conversations.isPending && <p>Loading conversations…</p>}
          <ul>
            {conversations.data?.data.map((c) => (
              <li key={c.id} className="my-2">
                <button
                  className="w-full text-left"
                  aria-pressed={selected === c.id}
                  onClick={() => setSelected(c.id)}
                >
                  {c.title}
                </button>
              </li>
            ))}
          </ul>
          <nav aria-label="Conversation pages">
            <button disabled={page === 1} onClick={() => setPage(page - 1)}>
              Previous
            </button>
            <button
              disabled={
                !conversations.data || page >= conversations.data.last_page
              }
              onClick={() => setPage(page + 1)}
            >
              Next
            </button>
          </nav>
        </aside>
        {selected && project.data ? (
          <ConversationChat
            key={selected}
            id={selected}
            project={project.data.data}
            onDeleted={() => {
              setSelected(null);
              void client.invalidateQueries({
                queryKey: ["conversations", projectId],
              });
            }}
          />
        ) : (
          <p>Select a conversation or create one to start.</p>
        )}
      </div>
    </>
  );
}
function ConversationChat({
  id,
  project,
  onDeleted,
}: {
  id: string;
  project: Project;
  onDeleted: () => void;
}) {
  const client = useQueryClient();
  const [content, setContent] = useState("");
  const [model, setModel] = useState("");
  const [pending, setPending] = useState<Message | null>(null);
  const [live, setLive] = useState<{ id: string; content: string } | null>(
    null,
  );
  const [connection, setConnection] = useState("");
  const [reconnect, setReconnect] = useState(0);
  const request = useRef<{
    content: string;
    model: string;
    key: string;
  } | null>(null);
  const models = useQuery({
    queryKey: ["models"],
    queryFn: () => api<{ data: Model[] }>("models"),
  });
  const conversation = useQuery({
    queryKey: ["conversation", id],
    queryFn: () => api<{ data: Conversation }>(`conversations/${id}`),
  });
  const messages = useQuery({
    queryKey: ["messages", id],
    queryFn: () => api<Page<Message>>(`conversations/${id}/messages`),
  });
  const selectedModel =
    model || models.data?.data.find((m) => m.available)?.id || "";
  const active =
    pending ??
    messages.data?.data.find(
      (m) => m.status === "queued" || m.status === "streaming",
    );
  const refresh = () => {
    void client.invalidateQueries({ queryKey: ["messages", id] });
    void client.invalidateQueries({ queryKey: ["conversations", project.id] });
  };
  const send = useMutation({
    mutationFn: () => {
      if (
        !request.current ||
        request.current.content !== content ||
        request.current.model !== selectedModel
      )
        request.current = {
          content,
          model: selectedModel,
          key: crypto.randomUUID(),
        };
      return api<{ data: Message }>(`conversations/${id}/messages`, "POST", {
        content,
        model: selectedModel,
        idempotency_key: request.current.key,
      });
    },
    onSuccess: (result) => {
      setContent("");
      request.current = null;
      setPending(result.data);
      refresh();
    },
  });
  const retry = useMutation({
    mutationFn: (message: Message) =>
      api<{ data: Message }>(
        `conversations/${id}/messages/${message.id}/retry`,
        "POST",
        { attempt: message.attempt },
      ),
    onSuccess: (result) => {
      setPending(result.data);
      setLive(null);
      setReconnect((n) => n + 1);
      refresh();
    },
  });
  const remove = useMutation({
    mutationFn: () => api(`conversations/${id}`, "DELETE"),
    onSuccess: onDeleted,
  });
  const rename = useMutation({
    mutationFn: (title: string) =>
      api(`conversations/${id}`, "PATCH", { title }),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: ["conversation", id] });
      void client.invalidateQueries({
        queryKey: ["conversations", project.id],
      });
    },
  });
  const activeId = active?.id;
  useEffect(() => {
    if (!activeId) return;
    const stream = new EventSource(
      `/api/v1/conversations/${id}/messages/${activeId}/stream`,
    );
    const finish = () => {
      stream.close();
      setConnection("");
      setPending(null);
      void client.invalidateQueries({ queryKey: ["messages", id] });
    };
    stream.onopen = () => setConnection("Receiving response…");
    stream.addEventListener("reset", () =>
      setLive({ id: activeId, content: "" }),
    );
    stream.addEventListener("delta", (event) => {
      const data = JSON.parse((event as MessageEvent).data) as { text: string };
      setLive((previous) => ({
        id: activeId,
        content:
          (previous?.id === activeId ? previous.content : "") + data.text,
      }));
    });
    stream.addEventListener("completed", finish);
    stream.addEventListener("failed", finish);
    stream.addEventListener("end", finish);
    stream.onerror = () =>
      setConnection(
        stream.readyState === EventSource.CLOSED
          ? "Connection closed. Reconnect or reload saved history."
          : "Reconnecting to saved response…",
      );
    return () => stream.close();
  }, [activeId, id, reconnect, client]);
  const error =
    messages.error ||
    conversation.error ||
    models.error ||
    send.error ||
    retry.error ||
    remove.error ||
    rename.error;
  const canSend =
    project.cloud_processing_allowed &&
    !project.archived_at &&
    !!selectedModel &&
    models.data?.data.some((m) => m.id === selectedModel && m.available);
  return (
    <div className="min-w-0">
      <h2>{conversation.data?.data.title ?? "Conversation"}</h2>
      <form
        key={conversation.data?.data.title}
        onSubmit={(event) => {
          event.preventDefault();
          rename.mutate(String(new FormData(event.currentTarget).get("title")));
        }}
      >
        <label>
          Rename conversation
          <input
            name="title"
            required
            maxLength={150}
            defaultValue={conversation.data?.data.title}
          />
        </label>
        <button disabled={rename.isPending}>Rename</button>
        <button
          className="danger ml-3"
          type="button"
          disabled={!!active || remove.isPending}
          onClick={() => {
            if (window.confirm("Delete this conversation and its messages?"))
              remove.mutate();
          }}
        >
          Delete conversation
        </button>
      </form>
      {error && <p role="alert">{error.message}</p>}
      {connection && <p role="status">{connection}</p>}
      {active && (
        <button onClick={() => setReconnect((n) => n + 1)}>Reconnect</button>
      )}
      <SummaryPanel conversationId={id} disabled={!canSend || !!active} />
      <div aria-label="Messages" aria-live="polite" className="my-5">
        {messages.isPending && <p>Loading messages…</p>}
        {messages.data?.data.map((message) => (
          <section key={message.id}>
            <h3 className="font-bold">
              {message.role === "user" ? "You" : "Assistant"}
            </h3>
            <MessageContent
              content={live?.id === message.id ? live.content : message.content}
            />
            {message.role === "assistant" && (
              <p className="mt-3 text-sm">
                {message.model} · {message.status} · Attempt {message.attempt}
                {message.usage &&
                  ` · ${message.usage.input_tokens ?? "?"} input / ${message.usage.output_tokens ?? "?"} output tokens`}
              </p>
            )}
            {message.memory_sources && message.memory_sources.length > 0 && (
              <p>
                Memory sources:{" "}
                {message.memory_sources.map((source) => (
                  <Link
                    className="mr-3 underline"
                    key={source.id}
                    to={`/projects/${project.id}/memories?memory=${source.id}`}
                  >
                    {source.id.slice(0, 8)} · revision {source.revision}
                  </Link>
                ))}
              </p>
            )}
            {message.status === "failed" && (
              <>
                <p>
                  Response failed ({message.error_code}). Retrying may incur
                  another provider charge.
                </p>
                <button
                  disabled={
                    !canSend ||
                    !!active ||
                    retry.isPending ||
                    message.attempt >= 3 ||
                    message.id !== messages.data?.data.at(-1)?.id
                  }
                  onClick={() => retry.mutate(message)}
                >
                  Retry response
                </button>
              </>
            )}
          </section>
        ))}
      </div>
      <form
        onSubmit={(event) => {
          event.preventDefault();
          send.mutate();
        }}
      >
        <label>
          AI model
          <select
            value={selectedModel}
            onChange={(event) => setModel(event.target.value)}
            required
          >
            <option value="">Select configured model</option>
            {models.data?.data.map((m) => (
              <option key={m.id} value={m.id} disabled={!m.available}>
                {m.provider} · {m.id}
                {!m.available && " (not configured)"}
              </option>
            ))}
          </select>
        </label>
        {!models.isPending && !models.data?.data.some((m) => m.available) && (
          <p>
            Configure OPENAI_API_KEY and OPENAI_MODEL on the server to enable
            chat.
          </p>
        )}
        <label>
          Message
          <textarea
            value={content}
            onChange={(e) => setContent(e.target.value)}
            required
            maxLength={12000}
            rows={5}
          />
        </label>
        <button
          disabled={!canSend || !!active || send.isPending || !content.trim()}
        >
          Send message
        </button>
      </form>
    </div>
  );
}
