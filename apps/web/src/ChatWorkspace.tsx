import { useEffect, useRef, useState } from "react";
import type { FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useParams } from "react-router-dom";
import { api } from "./api";
import { MessageContent } from "./MessageContent";
import { ProviderPreferences } from "./ProviderPreferences";
import type { Preferences, ProviderModel } from "./ProviderPreferences";
import { SummaryPanel } from "./SummaryPanel";

type Conversation = { id: string; title: string } & Preferences;
type Message = {
  id: string;
  role: "user" | "assistant";
  content: string;
  status: "queued" | "streaming" | "completed" | "failed";
  provider?: string;
  routing?: { decision?: string };
  context_metrics?: { input_tokens?: number; reduction_fraction?: number };
  model: string | null;
  attempt: number;
  error_code: string | null;
  code_sources?: {
    id: string;
    path: string;
    start_line: number;
    end_line: number;
  }[];
  memory_sources?: { id: string; revision: number }[];
  usage: { input_tokens?: number; output_tokens?: number } | null;
};
type Page<T> = { data: T[]; last_page: number };
type Model = ProviderModel;
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
      <Link to={`/projects/${projectId}/optimization`}>Solutions & usage</Link>
      <h1>AI Chat · {project.data?.data.name ?? "Project"}</h1>
      <p>
        Conversations are private to your account. Responses are AI suggestions
        and are not verified fixes.
      </p>
      {project.data && !project.data.data.cloud_processing_allowed && (
        <p role="status">
          Cloud processing is disabled. Local Ollama remains available when
          configured. Enable cloud processing only if you permit sending code to
          OpenAI.
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
  const [solutionId, setSolutionId] = useState("");
  const [solutionFilter, setSolutionFilter] = useState("");
  const [solutionSearch, setSolutionSearch] = useState("");
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
    solutionId: string;
  } | null>(null);
  const models = useQuery({
    queryKey: ["providers"],
    queryFn: () => api<{ data: Model[] }>("providers"),
  });
  const conversation = useQuery({
    queryKey: ["conversation", id],
    queryFn: () => api<{ data: Conversation }>(`conversations/${id}`),
  });
  const messages = useQuery({
    queryKey: ["messages", id],
    queryFn: () => api<Page<Message>>(`conversations/${id}/messages`),
  });
  const mode = conversation.data?.data.provider_mode ?? "openai";
  const selectedModel =
    mode === "auto"
      ? ""
      : conversation.data?.data.preferred_model ||
        models.data?.data.find((m) => m.provider === mode && m.available)?.id ||
        "";
  const solutions = useQuery({
    queryKey: ["solutions", project.id, solutionSearch],
    queryFn: () =>
      api<{
        data: {
          id: string;
          error_signature: string;
          state: string;
          compatibility: Record<string, unknown>;
        }[];
      }>(
        `projects/${project.id}/solutions?search=${encodeURIComponent(solutionSearch)}`,
      ),
  });
  const chosenSolution = solutions.data?.data.find((s) => s.id === solutionId);
  const compatibility = chosenSolution
    ? Object.fromEntries(
        Object.entries(chosenSolution.compatibility).filter(
          ([k]) => k !== "repository_digest",
        ),
      )
    : null;
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
        request.current.model !== selectedModel ||
        request.current.solutionId !== solutionId
      )
        request.current = {
          content,
          model: selectedModel,
          solutionId,
          key: crypto.randomUUID(),
        };
      return api<{ data: Message }>(`conversations/${id}/messages`, "POST", {
        content,
        ...(selectedModel ? { model: selectedModel } : {}),
        ...(chosenSolution
          ? {
              solution_context: {
                error_signature: chosenSolution.error_signature,
                compatibility,
              },
            }
          : {}),
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
    !project.archived_at &&
    (mode !== "openai" || project.cloud_processing_allowed) &&
    models.data?.data.some(
      (m) =>
        m.available &&
        (project.cloud_processing_allowed || m.provider !== "openai") &&
        (mode === "auto" || m.id === selectedModel),
    );
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
      <button
        disabled={models.isFetching}
        onClick={() => {
          void models.refetch();
        }}
      >
        Refresh provider status
      </button>
      {conversation.data && (
        <ProviderPreferences
          key={`${id}:${mode}:${conversation.data.data.preferred_model}:${conversation.data.data.allow_fallback}`}
          id={id}
          preferences={conversation.data.data}
          models={models.data?.data ?? []}
          disabled={!!active}
          cloudAllowed={project.cloud_processing_allowed}
        />
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
                {message.provider ?? "openai"} · {message.model} ·{" "}
                {message.routing?.decision ?? "saved response"} ·{" "}
                {message.status} · Attempt {message.attempt}
                {message.usage &&
                  ` · ${message.usage.input_tokens ?? "?"} input / ${message.usage.output_tokens ?? "?"} output tokens`}
              </p>
            )}
            {message.context_metrics?.input_tokens !== undefined && (
              <p>
                Context: {message.context_metrics.input_tokens} estimated input
                tokens ·{" "}
                {Math.round(
                  (message.context_metrics.reduction_fraction ?? 0) * 100,
                )}
                % reduction against full-history baseline
              </p>
            )}
            {message.code_sources && message.code_sources.length > 0 && (
              <p>
                Code sources:{" "}
                {message.code_sources.map((source) => (
                  <Link
                    key={source.id}
                    to={`/projects/${project.id}/repository?chunk=${source.id}`}
                  >
                    {source.path}:{source.start_line}–{source.end_line}{" "}
                  </Link>
                ))}
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
          Find a saved error signature
          <input
            maxLength={180}
            value={solutionFilter}
            onChange={(e) => setSolutionFilter(e.target.value)}
          />
        </label>
        <button
          type="button"
          onClick={() => {
            setSolutionSearch(solutionFilter);
            setSolutionId("");
          }}
        >
          Find solutions
        </button>
        <label>
          Check a compatible saved solution first
          <select
            value={solutionId}
            onChange={(e) => setSolutionId(e.target.value)}
          >
            <option value="">No cache lookup</option>
            {solutions.data?.data
              .filter((s) => s.state === "active")
              .map((s) => (
                <option key={s.id} value={s.id}>
                  {s.error_signature}
                </option>
              ))}
          </select>
        </label>
        {!models.isPending && !models.data?.data.some((m) => m.available) && (
          <p>
            No configured provider is currently available. Check server settings
            and provider status.
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
