import { useState } from "react";
import type { FormEvent } from "react";
import { Link, useParams, useSearchParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api } from "./api";
import { MessageContent } from "./MessageContent";

type Memory = {
  id: string;
  category: string;
  content: string;
  revision: number;
  state: string;
  verification_status: string;
  source_kind: string;
  index_status: string;
  index_error: string | null;
  provenance: Record<string, unknown>;
  conversation_id: string | null;
};
type SearchResult = {
  data: { memory: Memory; score: number }[];
  retrieval: { mode: string; degraded: boolean };
};

export default function MemoryExplorer() {
  const { projectId } = useParams();
  return <ProjectMemories key={projectId} projectId={projectId!} />;
}
function ProjectMemories({ projectId }: { projectId: string }) {
  const client = useQueryClient();
  const [params, setParams] = useSearchParams();
  const selected = params.get("memory");
  const [page, setPage] = useState(1);
  const [category, setCategory] = useState("");
  const [state, setState] = useState("active");
  const memories = useQuery({
    queryKey: ["memories", projectId, page, category, state],
    queryFn: () =>
      api<{ data: Memory[]; last_page: number }>(
        `projects/${projectId}/memories?page=${page}&category=${category}&state=${state}`,
      ),
    refetchInterval: (query) =>
      query.state.data?.data.some((m) =>
        ["pending", "indexing"].includes(m.index_status),
      )
        ? 1500
        : false,
  });
  const create = useMutation({
    mutationFn: (data: unknown) =>
      api<{ data: Memory }>(`projects/${projectId}/memories`, "POST", data),
    onSuccess: (result) => {
      setParams({ memory: result.data.id });
      void client.invalidateQueries({ queryKey: ["memories", projectId] });
    },
  });
  const search = useMutation({
    mutationFn: (query: string) =>
      api<SearchResult>(`projects/${projectId}/memories/search`, "POST", {
        query,
      }),
  });
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    create.mutate(Object.fromEntries(new FormData(form)), {
      onSuccess: () => form.reset(),
    });
  }
  return (
    <>
      <Link to="/projects">← Projects</Link> ·{" "}
      <Link to={`/projects/${projectId}/chat`}>AI chat</Link>
      <h1>Memory Explorer</h1>
      <p>
        Private project knowledge with source provenance. All entries are
        unverified, including generated summaries and proposed solutions.
      </p>
      {(memories.error || create.error || search.error) && (
        <p role="alert">
          {(memories.error || create.error || search.error)?.message}
        </p>
      )}
      <section>
        <h2>Add memory</h2>
        <form onSubmit={submit}>
          <label>
            Memory category
            <select name="category" defaultValue="project">
              {["conversation", "project", "error", "solution"].map((value) => (
                <option key={value}>{value}</option>
              ))}
            </select>
          </label>
          <label>
            Memory content
            <textarea name="content" required maxLength={12000} rows={4} />
          </label>
          <button disabled={create.isPending}>Save memory</button>
        </form>
      </section>
      <section>
        <h2>Search memory</h2>
        <form
          onSubmit={(event) => {
            event.preventDefault();
            search.mutate(
              String(new FormData(event.currentTarget).get("query")),
            );
          }}
        >
          <label>
            Search query
            <input name="query" maxLength={2000} required />
          </label>
          <button disabled={search.isPending}>Search memories</button>
        </form>
        {search.data && (
          <>
            <p role="status">
              Retrieval: {search.data.retrieval.mode}
              {search.data.retrieval.degraded
                ? " — embeddings unavailable; keyword results only"
                : ""}
            </p>
            {search.data.data.length === 0 && <p>No matching memories.</p>}
            <ul>
              {search.data.data.map((hit) => (
                <li key={hit.memory.id}>
                  <button
                    className="secondary"
                    onClick={() => setParams({ memory: hit.memory.id })}
                  >
                    {hit.memory.category}: {hit.memory.content.slice(0, 100)}
                  </button>
                </li>
              ))}
            </ul>
          </>
        )}
      </section>
      <div className="grid gap-6 lg:grid-cols-2">
        <div>
          <h2>Stored memories</h2>
          <label>
            Filter category
            <select
              value={category}
              onChange={(e) => {
                setCategory(e.target.value);
                setPage(1);
              }}
            >
              <option value="">All categories</option>
              {["conversation", "project", "error", "solution"].map((value) => (
                <option key={value}>{value}</option>
              ))}
            </select>
          </label>
          <label>
            Filter state
            <select
              value={state}
              onChange={(e) => {
                setState(e.target.value);
                setPage(1);
              }}
            >
              <option value="active">Active</option>
              <option value="invalidated">Invalidated</option>
            </select>
          </label>
          {memories.isPending && <p>Loading memories…</p>}
          {memories.data?.data.length === 0 && <p>No stored memories yet.</p>}
          {memories.data?.data.map((memory) => (
            <section key={memory.id}>
              <h3>
                {memory.category} · {memory.source_kind}
              </h3>
              <p>{memory.content.slice(0, 200)}</p>
              <p>{memory.index_status} · Unverified</p>
              <button onClick={() => setParams({ memory: memory.id })}>
                Inspect memory
              </button>
            </section>
          ))}
          <nav aria-label="Memory pages">
            <button disabled={page === 1} onClick={() => setPage(page - 1)}>
              Previous
            </button>
            <span>Page {page}</span>
            <button
              disabled={!memories.data || page >= memories.data.last_page}
              onClick={() => setPage(page + 1)}
            >
              Next
            </button>
          </nav>
        </div>
        {selected && (
          <MemoryDetail
            key={selected}
            id={selected}
            projectId={projectId}
            onDeleted={() => setParams({})}
          />
        )}
      </div>
    </>
  );
}
function MemoryDetail({
  id,
  projectId,
  onDeleted,
}: {
  id: string;
  projectId: string;
  onDeleted: () => void;
}) {
  const client = useQueryClient();
  const detail = useQuery({
    queryKey: ["memory", id],
    queryFn: () =>
      api<{
        data: Memory;
        embedding: {
          model: string;
          model_version: string;
          dimensions: number;
          profile: string;
        } | null;
      }>(`memories/${id}`),
    refetchInterval: (query) =>
      ["pending", "indexing"].includes(
        query.state.data?.data.index_status ?? "",
      )
        ? 1500
        : false,
  });
  const mutation = useMutation({
    mutationFn: ({
      method,
      suffix = "",
      body,
    }: {
      method: string;
      suffix?: string;
      body?: unknown;
    }) => api(`memories/${id}${suffix}`, method, body),
    onSuccess: (_result, variables) => {
      void client.invalidateQueries({ queryKey: ["memory", id] });
      void client.invalidateQueries({ queryKey: ["memories", projectId] });
      if (variables.method === "DELETE") onDeleted();
    },
  });
  const memory = detail.data?.data;
  return (
    <section aria-label="Memory details">
      <h2>Memory details</h2>
      {(detail.error || mutation.error) && (
        <p role="alert">{(detail.error || mutation.error)?.message}</p>
      )}
      {detail.isPending && <p>Loading memory…</p>}
      {memory && (
        <>
          <p>
            {memory.category} · {memory.state} · Unverified · Revision{" "}
            {memory.revision}
          </p>
          <p>
            Index: {memory.index_status}
            {memory.index_error && ` (${memory.index_error})`}
          </p>
          <MessageContent content={memory.content} />
          <details>
            <summary>Source provenance</summary>
            <pre className="overflow-auto">
              {JSON.stringify(memory.provenance, null, 2)}
            </pre>
          </details>
          {detail.data?.embedding && (
            <p>
              Embedding: {detail.data.embedding.model} ·{" "}
              {detail.data.embedding.model_version} ·{" "}
              {detail.data.embedding.dimensions} dimensions
            </p>
          )}
          <form
            key={memory.revision}
            className="mt-4"
            onSubmit={(event) => {
              event.preventDefault();
              mutation.mutate({
                method: "PATCH",
                body: {
                  content: String(
                    new FormData(event.currentTarget).get("content"),
                  ),
                  revision: memory.revision,
                },
              });
            }}
          >
            <label>
              Correct memory
              <textarea
                name="content"
                defaultValue={memory.content}
                required
                maxLength={12000}
                rows={5}
              />
            </label>
            <button disabled={mutation.isPending}>Save correction</button>
          </form>
          <div className="actions mt-3">
            <button
              disabled={mutation.isPending || memory.state !== "active"}
              onClick={() =>
                mutation.mutate({ method: "POST", suffix: "/invalidate" })
              }
            >
              Invalidate memory
            </button>
            <button
              disabled={
                mutation.isPending ||
                memory.state !== "active" ||
                ["pending", "indexing"].includes(memory.index_status)
              }
              onClick={() =>
                mutation.mutate({ method: "POST", suffix: "/reindex" })
              }
            >
              Re-index memory
            </button>
            <button
              className="danger"
              disabled={mutation.isPending}
              onClick={() => {
                if (
                  window.confirm(
                    "Delete this memory and its embedding permanently?",
                  )
                )
                  mutation.mutate({ method: "DELETE" });
              }}
            >
              Delete memory
            </button>
          </div>
        </>
      )}
    </section>
  );
}
