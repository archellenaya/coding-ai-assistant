import { useState } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { api } from "./api";
export type ProviderModel = {
  id: string;
  provider: string;
  available: boolean;
  error?: string | null;
  context_window?: number;
};
export type Preferences = {
  provider_mode?: "ollama" | "openai" | "auto";
  preferred_model?: string | null;
  allow_fallback?: boolean;
};
export function ProviderPreferences({
  id,
  preferences,
  models,
  disabled,
  cloudAllowed,
}: {
  id: string;
  preferences: Preferences;
  models: ProviderModel[];
  disabled: boolean;
  cloudAllowed: boolean;
}) {
  const [mode, setMode] = useState(preferences.provider_mode ?? "openai");
  const [model, setModel] = useState(preferences.preferred_model ?? "");
  const [fallback, setFallback] = useState(preferences.allow_fallback ?? false);
  const client = useQueryClient();
  const save = useMutation({
    mutationFn: () =>
      api<{ data: Preferences }>(`conversations/${id}/preferences`, "PATCH", {
        provider_mode: mode,
        preferred_model: model || null,
        allow_fallback: mode === "auto" && fallback,
      }),
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: ["conversation", id] });
    },
  });
  return (
    <section aria-label="Conversation provider settings">
      <form
        onSubmit={(e) => {
          e.preventDefault();
          save.mutate();
        }}
      >
        <label>
          AI provider
          <select
            value={mode}
            disabled={disabled || save.isPending}
            onChange={(e) => {
              setMode(e.target.value as typeof mode);
              setModel("");
              setFallback(false);
            }}
          >
            <option value="auto">Auto (Smart Routing)</option>
            <option value="ollama">Ollama (Local)</option>
            <option value="openai" disabled={!cloudAllowed}>
              OpenAI (Cloud)
            </option>
          </select>
        </label>
        <label>
          AI model
          <select
            value={model}
            disabled={disabled || save.isPending}
            onChange={(e) => setModel(e.target.value)}
          >
            <option value="">Choose by configured policy</option>
            {models
              .filter(
                (m) =>
                  (mode === "auto" || m.provider === mode) &&
                  (cloudAllowed || m.provider !== "openai"),
              )
              .map((m) => (
                <option key={m.id} value={m.id} disabled={!m.available}>
                  {m.provider} · {m.id}
                  {!m.available ? ` (${m.error ?? "unavailable"})` : ""}
                </option>
              ))}
          </select>
        </label>
        {mode === "auto" && (
          <label>
            <input
              type="checkbox"
              checked={fallback}
              disabled={disabled || save.isPending}
              onChange={(e) => setFallback(e.target.checked)}
            />
            Allow another authorized model if the first is unavailable before
            inference
          </label>
        )}
        <button disabled={disabled || save.isPending}>
          Save provider preferences
        </button>
      </form>
      <p>
        Save changes before sending. Explicit provider modes never switch
        providers. Local-only project policy overrides Auto; cloud fallback
        requires project permission and your consent.
      </p>
      {models.map((m) => (
        <p key={m.id}>
          {m.provider} · {m.id}:{" "}
          {m.available
            ? "Connected; model visible"
            : (m.error ?? "Unavailable")}
        </p>
      ))}
      <p>
        OpenAI API billing is separate from ChatGPT subscriptions. Metadata
        probes cannot verify usable quota. Ollama has no per-token API charge;
        hardware costs remain.
      </p>
      {save.error && <p role="alert">{save.error.message}</p>}
    </section>
  );
}
