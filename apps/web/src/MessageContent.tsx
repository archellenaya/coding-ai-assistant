import { Highlight, Prism, themes } from "prism-react-renderer";
import type { Language } from "prism-react-renderer";

export function MessageContent({ content }: { content: string }) {
  const parts = content.split(/(```[^\n]*\n[\s\S]*?```)/g);
  return (
    <>
      {parts.map((part, index) => {
        const code = /^```([^\n]*)\n([\s\S]*?)```$/.exec(part);
        if (!code)
          return (
            <span className="whitespace-pre-wrap break-words" key={index}>
              {part}
            </span>
          );
        const requested = code[1].trim().toLowerCase();
        const language = (
          Prism.languages[requested] ? requested : "text"
        ) as Language;
        return (
          <Highlight
            key={index}
            code={code[2]}
            language={language}
            theme={themes.nightOwl}
          >
            {({ className, style, tokens, getLineProps, getTokenProps }) => (
              <pre
                className={`${className} overflow-auto rounded p-3 my-3`}
                style={style}
              >
                <code>
                  {tokens.map((line, i) => (
                    <div key={i} {...getLineProps({ line })}>
                      {line.map((token, j) => (
                        <span key={j} {...getTokenProps({ token })} />
                      ))}
                    </div>
                  ))}
                </code>
              </pre>
            )}
          </Highlight>
        );
      })}
    </>
  );
}
