import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api } from "./api";
import { MessageContent } from "./MessageContent";
type Summary = {
  id: string;
  status: string;
  content: string | null;
  through_sequence: number;
  attempt: number;
  error_code: string | null;
};
export function SummaryPanel({
  conversationId,
  disabled,
}: {
  conversationId: string;
  disabled: boolean;
}) {
  const client = useQueryClient();
  const key = ["summary", conversationId];
  const summary = useQuery({
    queryKey: key,
    queryFn: () =>
      api<{ data: Summary | null }>(`conversations/${conversationId}/summary`),
    refetchInterval: (query) =>
      ["queued", "processing"].includes(query.state.data?.data?.status ?? "")
        ? 1500
        : false,
  });
  const generate = useMutation({
    mutationFn: () =>
      api<{ data: Summary }>(
        `conversations/${conversationId}/summary`,
        "POST",
        {
          retry: summary.data?.data?.status === "failed",
          ...(summary.data?.data?.status === "failed"
            ? {
                summary_id: summary.data.data.id,
                attempt: summary.data.data.attempt,
              }
            : {}),
        },
      ),
    onSuccess: (result) => {
      client.setQueryData(key, result);
      void client.invalidateQueries({ queryKey: ["memories"] });
    },
  });
  const current = summary.data?.data;
  const busy = current && ["queued", "processing"].includes(current.status);
  return (
    <section aria-label="Conversation summary">
      <h3 className="font-bold">Conversation summary</h3>
      <p>
        Uses the configured cloud model and may incur charges. Generated
        summaries are unverified.
      </p>
      {(summary.error || generate.error) && (
        <p role="alert">{(summary.error || generate.error)?.message}</p>
      )}
      {current && (
        <p role="status">
          {current.status} · Through message {current.through_sequence} ·
          Attempt {current.attempt}
        </p>
      )}
      {current?.status === "ready" && current.content && (
        <MessageContent content={current.content} />
      )}
      <button
        disabled={
          disabled ||
          !!busy ||
          generate.isPending ||
          (current?.status === "failed" && current.attempt >= 3)
        }
        onClick={() => generate.mutate()}
      >
        {current?.status === "failed"
          ? "Retry summary"
          : "Summarize conversation"}
      </button>
    </section>
  );
}
