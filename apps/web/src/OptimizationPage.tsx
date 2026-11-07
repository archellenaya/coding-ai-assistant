import { useState } from "react";
import { Link, useParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api } from "./api";
import { MessageContent } from "./MessageContent";
type Solution = {
  id: string;
  error_signature: string;
  content: string;
  state: string;
  compatibility: Record<string, unknown>;
  evidence: Record<string, unknown>;
};
type Usage = {
  provider: string;
  model: string;
  calls: number;
  input_tokens: number | null;
  output_tokens: number | null;
  accounted_micro_usd: number;
  estimated_micro_usd: number | null;
  average_duration_ms: number | null;
  unknown_cost_calls?: number;
};
export default function OptimizationPage() {
  const { projectId } = useParams();
  const client = useQueryClient();
  const [validationError, setValidationError] = useState("");
  const [page, setPage] = useState(1);
  const [callPage, setCallPage] = useState(1);
  const solutions = useQuery({
    queryKey: ["solutions", projectId, page],
    queryFn: () =>
      api<{ data: Solution[]; last_page: number }>(
        `projects/${projectId}/solutions?page=${page}`,
      ),
  });
  const usage = useQuery({
    queryKey: ["usage", projectId, callPage],
    queryFn: () =>
      api<{
        data: {
          data: {
            id: string;
            provider: string;
            model: string;
            status: string;
            duration_ms: number | null;
            cache_hit: boolean;
          }[];
          last_page: number;
        };
        totals: Usage[];
        scope: string;
        budgets_micro_usd: Record<string, number>;
        cost_note: string;
      }>(`projects/${projectId}/usage?page=${callPage}`),
  });
  const action = useMutation({
    mutationFn: ({ path, body }: { path: string; body?: unknown }) =>
      api(path, "POST", body),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: ["solutions", projectId] });
    },
  });
  return (
    <>
      <Link to="/projects">Projects</Link>
      <h1>Solutions & usage</h1>
      <p>
        Saved solutions are private and require your test evidence and
        compatibility details. Evidence is manually attested; the assistant does
        not execute or independently verify it.
      </p>
      {[solutions.error, usage.error, action.error]
        .filter(Boolean)
        .map((e, i) => (
          <p role="alert" key={i}>
            {e?.message}
          </p>
        ))}
      {validationError && <p role="alert">{validationError}</p>}
      <section>
        <h2>Record tested solution</h2>
        <p>Project administration rights are required to attest a solution.</p>
        <form
          onSubmit={(e) => {
            e.preventDefault();
            const f = new FormData(e.currentTarget);
            let dependencies;
            try {
              dependencies = JSON.parse(String(f.get("dependencies")));
            } catch {
              setValidationError(
                "Enter dependency versions as a valid JSON object.",
              );
              return;
            }
            setValidationError("");
            action.mutate({
              path: `projects/${projectId}/solutions`,
              body: {
                error_signature: f.get("signature"),
                content: f.get("content"),
                compatibility: {
                  framework: f.get("framework"),
                  framework_version: f.get("version"),
                  dependencies,
                  environment: f.get("environment"),
                  git_commit: f.get("commit") || null,
                },
                evidence: {
                  command: f.get("command"),
                  exit_code: 0,
                  result: f.get("result"),
                  attestation: true,
                },
              },
            });
          }}
        >
          <label>
            Error signature
            <input name="signature" required maxLength={180} />
          </label>
          <label>
            Solution
            <textarea name="content" required maxLength={12000} />
          </label>
          <label>
            Framework
            <input name="framework" required maxLength={100} />
          </label>
          <label>
            Framework version
            <input name="version" required maxLength={100} />
          </label>
          <label>
            Dependency versions (JSON object)
            <textarea name="dependencies" defaultValue="{}" required />
          </label>
          <label>
            Environment
            <input name="environment" required maxLength={500} />
          </label>
          <label>
            Git commit
            <input name="commit" pattern="[a-f0-9]{40,64}" />
          </label>
          <label>
            Test command
            <input name="command" required maxLength={500} />
          </label>
          <label>
            Test result
            <textarea name="result" required maxLength={4000} />
          </label>
          <label>
            <input type="checkbox" required />I ran this test successfully and
            attest to its result
          </label>
          <button disabled={action.isPending}>Record tested solution</button>
        </form>
      </section>
      {solutions.data?.data.map((s) => (
        <section key={s.id}>
          <h2>{s.error_signature}</h2>
          <p>{s.state} · Manually attested</p>
          <MessageContent content={s.content} />
          <details>
            <summary>Compatibility and evidence</summary>
            <pre>
              {JSON.stringify(
                { compatibility: s.compatibility, evidence: s.evidence },
                null,
                2,
              )}
            </pre>
          </details>
          <button
            disabled={action.isPending || s.state !== "active"}
            onClick={() => {
              if (window.confirm("Invalidate this cached solution?"))
                action.mutate({ path: `solutions/${s.id}/invalidate` });
            }}
          >
            Invalidate solution
          </button>
        </section>
      ))}
      <nav aria-label="Solution pages">
        <button disabled={page === 1} onClick={() => setPage(page - 1)}>
          Previous solutions
        </button>
        <button
          disabled={!solutions.data || page >= solutions.data.last_page}
          onClick={() => setPage(page + 1)}
        >
          Next solutions
        </button>
      </nav>
      <section>
        <h2>Today's usage (UTC)</h2>
        <p>{usage.data?.cost_note}</p>
        <p>Totals scope: {usage.data?.scope}</p>
        <p>
          Daily limits:{" "}
          {Object.entries(usage.data?.budgets_micro_usd ?? {})
            .map(
              ([scope, value]) => `${scope} $${(value / 1000000).toFixed(2)}`,
            )
            .join(" · ")}
        </p>
        <table>
          <thead>
            <tr>
              <th>Provider / model</th>
              <th>Calls</th>
              <th>Tokens in / out</th>
              <th>Accounted USD</th>
              <th>Estimated USD</th>
              <th>Mean duration</th>
            </tr>
          </thead>
          <tbody>
            {usage.data?.totals.map((row) => (
              <tr key={`${row.provider}:${row.model}`}>
                <td>
                  {row.provider} / {row.model}
                </td>
                <td>{row.calls}</td>
                <td>
                  {row.input_tokens ?? "Unknown"} /{" "}
                  {row.output_tokens ?? "Unknown"}
                </td>
                <td>${(row.accounted_micro_usd / 1000000).toFixed(6)}</td>
                <td>
                  {row.estimated_micro_usd === null
                    ? "Unknown"
                    : `$${(row.estimated_micro_usd / 1000000).toFixed(6)}`}
                  {(row.unknown_cost_calls ?? 0) > 0
                    ? ` (${row.unknown_cost_calls} unpriced calls)`
                    : ""}
                </td>
                <td>
                  {row.average_duration_ms === null
                    ? "Unknown"
                    : `${Math.round(row.average_duration_ms)} ms`}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        <h3>Your calls</h3>
        {usage.data?.data.data.map((c) => (
          <p key={c.id}>
            {c.provider} / {c.model} · {c.status} · {c.duration_ms ?? "Unknown"}{" "}
            ms {c.cache_hit ? "· Cached solution" : ""}
          </p>
        ))}
        <button
          onClick={() => {
            void usage.refetch();
          }}
        >
          Refresh usage
        </button>
        <nav aria-label="Call pages">
          <button
            disabled={callPage === 1}
            onClick={() => setCallPage(callPage - 1)}
          >
            Previous calls
          </button>
          <button
            disabled={!usage.data || callPage >= usage.data.data.last_page}
            onClick={() => setCallPage(callPage + 1)}
          >
            Next calls
          </button>
        </nav>
      </section>
    </>
  );
}
