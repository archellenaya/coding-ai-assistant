import { useState } from "react";
import type { FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useParams, useSearchParams } from "react-router-dom";
import { api } from "./api";
import { MessageContent } from "./MessageContent";
type Repository = {
  id: string;
  name: string;
  path: string;
  revision: string | null;
  languages: string[] | null;
  frameworks: string[] | null;
};
type Chunk = {
  id: string;
  content: string;
  symbol: string | null;
  path: string;
  revision: string;
  start_line: number;
  end_line: number;
};
export default function ProjectExplorer() {
  const { projectId } = useParams();
  const [params, setParams] = useSearchParams();
  const [selected, select] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [filePage, setFilePage] = useState(1);
  const client = useQueryClient();
  const repos = useQuery({
    queryKey: ["repositories", projectId, page],
    queryFn: () =>
      api<{ data: Repository[]; last_page: number }>(
        `projects/${projectId}/repositories?page=${page}`,
      ),
  });
  const details = useQuery({
    queryKey: ["repository", selected],
    queryFn: () =>
      api<{
        data: Repository;
        job: {
          status: string;
          error_code: string | null;
          stats: Record<string, number> | null;
        } | null;
      }>(`repositories/${selected}`),
    enabled: !!selected,
    refetchInterval: (query) =>
      ["queued", "indexing"].includes(query.state.data?.job?.status ?? "")
        ? 1500
        : false,
  });
  const files = useQuery({
    queryKey: [
      "repository-files",
      selected,
      filePage,
      details.data?.job?.status,
    ],
    queryFn: () =>
      api<{
        data: { id: string; path: string; language: string; parser: string }[];
        last_page: number;
      }>(`repositories/${selected}/files?page=${filePage}`),
    enabled: !!selected,
  });
  const chunkId = params.get("chunk");
  const chunk = useQuery({
    queryKey: ["code-chunk", chunkId],
    queryFn: () =>
      api<{ data: Chunk; file: { path: string }; repository: Repository }>(
        `code-chunks/${chunkId}`,
      ),
    enabled: !!chunkId,
  });
  const mutation = useMutation({
    mutationFn: ({
      path,
      method,
      body,
    }: {
      path: string;
      method: string;
      body?: unknown;
    }) => api(path, method, body),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: ["repositories"] });
      void client.invalidateQueries({ queryKey: ["repository"] });
    },
  });
  const search = useMutation({
    mutationFn: (query: string) =>
      api<{
        data: { chunk: Chunk }[];
        retrieval: { mode: string; degraded: boolean };
      }>(`projects/${projectId}/code/search`, "POST", { query }),
  });
  function register(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    mutation.mutate({
      path: `projects/${projectId}/repositories`,
      method: "POST",
      body: Object.fromEntries(new FormData(event.currentTarget)),
    });
  }
  return (
    <>
      <Link to="/projects">Projects</Link>
      <h1>Project Explorer</h1>
      <p>
        Read-only source snapshots shared with authorized project members.
        Register only sanitized repositories under the administrator-configured
        directory. No clone or code execution is performed.
      </p>
      {[
        repos.error,
        details.error,
        files.error,
        chunk.error,
        mutation.error,
        search.error,
      ]
        .filter(Boolean)
        .map((e, i) => (
          <p role="alert" key={i}>
            {e?.message}
          </p>
        ))}
      <section>
        <h2>Register repository</h2>
        <form onSubmit={register}>
          <label>
            Repository name
            <input name="name" required maxLength={150} />
          </label>
          <label>
            Relative repository directory
            <input
              name="path"
              required
              maxLength={240}
              placeholder="my-project"
            />
          </label>
          <button disabled={mutation.isPending}>Register repository</button>
        </form>
        <p>
          Registration and indexing require project administration rights. The
          directory must contain a committed Git repository.
        </p>
      </section>
      {repos.data?.data.map((r) => (
        <section key={r.id}>
          <button
            onClick={() => {
              select(r.id);
              setFilePage(1);
            }}
          >
            {r.name}
          </button>
          <p>
            {r.path} · {r.revision ?? "Not indexed"}
          </p>
        </section>
      ))}
      <nav aria-label="Repository pages">
        <button disabled={page === 1} onClick={() => setPage(page - 1)}>
          Previous repositories
        </button>
        <button
          disabled={!repos.data || page >= repos.data.last_page}
          onClick={() => setPage(page + 1)}
        >
          Next repositories
        </button>
      </nav>
      {details.data && (
        <section>
          <h2>{details.data.data.name}</h2>
          <p>
            Languages: {details.data.data.languages?.join(", ") || "Unknown"} ·
            Frameworks:{" "}
            {details.data.data.frameworks?.join(", ") || "None detected"}
          </p>
          <p role="status">
            Index: {details.data.job?.status ?? "Not started"}
            {details.data.job?.error_code
              ? " — indexing failed; check mount, Git revision and size limits"
              : ""}
          </p>
          {details.data.job?.stats && (
            <pre>{JSON.stringify(details.data.job.stats, null, 2)}</pre>
          )}
          <button
            disabled={
              mutation.isPending ||
              ["queued", "indexing"].includes(details.data.job?.status ?? "")
            }
            onClick={() =>
              mutation.mutate({
                path: `repositories/${selected}/index`,
                method: "POST",
              })
            }
          >
            Index repository
          </button>
          <button
            disabled={mutation.isPending}
            onClick={() => {
              if (
                window.confirm(
                  "Remove repository and its index? Source files are preserved.",
                )
              )
                mutation.mutate(
                  { path: `repositories/${selected}`, method: "DELETE" },
                  { onSuccess: () => select(null) },
                );
            }}
          >
            Remove repository
          </button>
          <ul>
            {files.data?.data.map((f) => (
              <li key={f.id}>
                {f.path} · {f.language} · {f.parser}
              </li>
            ))}
          </ul>
          <nav aria-label="File pages">
            <button
              disabled={filePage === 1}
              onClick={() => setFilePage(filePage - 1)}
            >
              Previous files
            </button>
            <button
              disabled={!files.data || filePage >= files.data.last_page}
              onClick={() => setFilePage(filePage + 1)}
            >
              Next files
            </button>
          </nav>
        </section>
      )}
      <section>
        <h2>Search project code</h2>
        <form
          onSubmit={(e) => {
            e.preventDefault();
            search.mutate(String(new FormData(e.currentTarget).get("query")));
          }}
        >
          <label>
            Code query
            <input name="query" required maxLength={2000} />
          </label>
          <button disabled={search.isPending}>Search code</button>
        </form>
        {search.data && (
          <p role="status">
            {search.data.retrieval.degraded
              ? "Embeddings unavailable; keyword results only"
              : search.data.retrieval.mode}
          </p>
        )}
        {search.data?.data.map(({ chunk: c }) => (
          <p key={c.id}>
            <button onClick={() => setParams({ chunk: c.id })}>
              {c.path}:{c.start_line}–{c.end_line} {c.symbol}
            </button>
          </p>
        ))}
      </section>
      {chunk.data && (
        <section>
          <h2>Source reference</h2>
          <p>
            {chunk.data.file.path}:{chunk.data.data.start_line}–
            {chunk.data.data.end_line} · Git {chunk.data.repository.revision}
          </p>
          <p>
            Indexed workspace snapshot; local edits may differ from the Git
            revision. Removed or changed source links may expire.
          </p>
          <MessageContent
            content={"```\n" + chunk.data.data.content + "\n```"}
          />
        </section>
      )}
    </>
  );
}
