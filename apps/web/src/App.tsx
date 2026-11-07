import { useState } from "react";
import type { FormEvent } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { Navigate, Route, Routes, Link } from "react-router-dom";
import { api, ApiError } from "./api";
import ChatWorkspace from "./ChatWorkspace";
type User = { id: string; name: string };
type Organization = { id: string; name: string; role: string };
type Project = {
  id: string;
  name: string;
  description: string | null;
  archived_at: string | null;
  cloud_processing_allowed: boolean;
};
function AuthForm({ done }: { done: () => void }) {
  const [register, setRegister] = useState(false);
  const mutation = useMutation({
    mutationFn: (data: Record<string, FormDataEntryValue>) =>
      api(register ? "register" : "login", "POST", data),
    onSuccess: done,
  });
  function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    mutation.mutate(Object.fromEntries(new FormData(e.currentTarget)));
  }
  return (
    <main className="auth">
      <h1>Coding AI Assistant</h1>
      <p>Your project workspace</p>
      <form onSubmit={submit}>
        {register && (
          <label>
            Name
            <input name="name" required maxLength={100} />
          </label>
        )}
        <label>
          Email
          <input name="email" type="email" autoComplete="email" required />
        </label>
        <label>
          Password
          <input
            name="password"
            type="password"
            autoComplete={register ? "new-password" : "current-password"}
            minLength={register ? 12 : 1}
            required
          />
        </label>
        {register && (
          <label>
            Confirm password
            <input
              name="password_confirmation"
              type="password"
              autoComplete="new-password"
              minLength={12}
              required
            />
          </label>
        )}
        {mutation.error && <p role="alert">{mutation.error.message}</p>}
        <button disabled={mutation.isPending}>
          {register ? "Create account" : "Sign in"}
        </button>
      </form>
      <button
        className="secondary"
        onClick={() => {
          setRegister(!register);
          mutation.reset();
        }}
      >
        {register ? "Use existing account" : "Register"}
      </button>
    </main>
  );
}
function Projects() {
  const client = useQueryClient();
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Project | null>(null);
  const organizations = useQuery({
    queryKey: ["organizations"],
    queryFn: () => api<{ data: Organization[] }>("organizations"),
  });
  const projects = useQuery({
    queryKey: ["projects", page],
    queryFn: () =>
      api<{ data: Project[]; last_page: number }>(`projects?page=${page}`),
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
      void client.invalidateQueries({ queryKey: ["projects"] });
      setEditing(null);
    },
  });
  function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = e.currentTarget;
    const data = Object.fromEntries(new FormData(form));
    mutation.mutate(
      {
        path: editing ? `projects/${editing.id}` : "projects",
        method: editing ? "PATCH" : "POST",
        body: data,
      },
      {
        onSuccess: () => {
          if (!editing) form.reset();
        },
      },
    );
  }
  return (
    <>
      <h1>Projects</h1>
      <p>
        Manage your development projects. Cloud processing is disabled by
        default.
      </p>
      {(projects.error || organizations.error || mutation.error) && (
        <p role="alert">
          {(projects.error || organizations.error || mutation.error)?.message}
        </p>
      )}
      <section>
        <h2>{editing ? "Edit project" : "Create project"}</h2>
        <form key={editing?.id ?? "new"} onSubmit={submit}>
          {!editing && (
            <label>
              Organization
              <select name="organization_id" required>
                <option value="">Select organization</option>
                {organizations.data?.data
                  .filter((o) => o.role === "owner")
                  .map((o) => (
                    <option key={o.id} value={o.id}>
                      {o.name}
                    </option>
                  ))}
              </select>
            </label>
          )}
          <label>
            Project name
            <input
              name="name"
              required
              maxLength={150}
              defaultValue={editing?.name}
            />
          </label>
          <label>
            Description
            <textarea
              name="description"
              maxLength={5000}
              defaultValue={editing?.description ?? ""}
            />
          </label>
          <button disabled={mutation.isPending}>Save project</button>
          {editing && (
            <button
              type="button"
              className="secondary"
              onClick={() => setEditing(null)}
            >
              Cancel
            </button>
          )}
        </form>
      </section>
      {projects.isPending && <p>Loading projects…</p>}
      {projects.data?.data.length === 0 && (
        <p>No projects yet. Create your first project above.</p>
      )}
      <div className="projects">
        {projects.data?.data.map((p) => (
          <section key={p.id}>
            <h2>{p.name}</h2>
            <p>{p.description}</p>
            <p>
              {p.archived_at ? "Archived" : "Active"} · Cloud processing{" "}
              {p.cloud_processing_allowed ? "allowed" : "disabled"}
            </p>
            <div className="actions">
              <Link to={`/projects/${p.id}/chat`}>Open chat</Link>
              <button onClick={() => setEditing(p)}>Edit</button>
              <button
                onClick={() =>
                  mutation.mutate({
                    path: `projects/${p.id}`,
                    method: "PATCH",
                    body: { archived: !p.archived_at },
                  })
                }
                disabled={mutation.isPending}
              >
                {p.archived_at ? "Restore" : "Archive"}
              </button>
              <button
                onClick={() =>
                  mutation.mutate({
                    path: `projects/${p.id}`,
                    method: "PATCH",
                    body: {
                      cloud_processing_allowed: !p.cloud_processing_allowed,
                    },
                  })
                }
                disabled={mutation.isPending}
              >
                {p.cloud_processing_allowed ? "Disable cloud" : "Allow cloud"}
              </button>
              <button
                className="danger"
                disabled={mutation.isPending}
                onClick={() => {
                  if (
                    window.confirm(`Delete ${p.name}? This cannot be undone.`)
                  )
                    mutation.mutate({
                      path: `projects/${p.id}`,
                      method: "DELETE",
                    });
                }}
              >
                Delete
              </button>
            </div>
          </section>
        ))}
      </div>
      <nav aria-label="Pagination">
        <button disabled={page === 1} onClick={() => setPage(page - 1)}>
          Previous
        </button>
        <span>Page {page}</span>
        <button
          disabled={!projects.data || page >= projects.data.last_page}
          onClick={() => setPage(page + 1)}
        >
          Next
        </button>
      </nav>
    </>
  );
}
export default function App() {
  const client = useQueryClient();
  const user = useQuery({
    queryKey: ["user"],
    queryFn: () => api<{ data: User }>("user"),
    retry: false,
    throwOnError: false,
  });
  const logout = useMutation({
    mutationFn: () => api("logout", "POST"),
    onSuccess: () => {
      client.clear();
      client.setQueryData(["user"], null);
    },
  });
  if (user.isPending) return <p>Loading workspace…</p>;
  if (
    user.error &&
    !(user.error instanceof ApiError && user.error.status === 401)
  )
    return <p role="alert">{user.error.message}</p>;
  if (!user.data)
    return (
      <AuthForm
        done={() => {
          void client.invalidateQueries({ queryKey: ["user"] });
        }}
      />
    );
  return (
    <>
      <header>
        <Link to="/projects">Coding AI Assistant</Link>
        <span>{user.data.data.name}</span>
        <button onClick={() => logout.mutate()} disabled={logout.isPending}>
          Sign out
        </button>
      </header>
      <main>
        {logout.error && <p role="alert">{logout.error.message}</p>}
        <Routes>
          <Route path="/projects" element={<Projects />} />
          <Route path="/projects/:projectId/chat" element={<ChatWorkspace />} />
          <Route path="*" element={<Navigate to="/projects" replace />} />
        </Routes>
      </main>
    </>
  );
}
