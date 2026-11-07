"""Bounded, descriptor-relative read-only repository analysis; never executes repository code."""
import hashlib
import importlib.metadata
import os
import re
import stat
from pathlib import PurePosixPath

EXCLUDED = {"vendor", "node_modules", "dist", "build", "target", "coverage", "__pycache__", "storage", "cache", "out", "public/build", "generated", "venv"}
LANGUAGES = {".py": "python", ".js": "javascript", ".jsx": "javascript", ".ts": "typescript", ".tsx": "tsx", ".php": "php", ".json": "json", ".md": "markdown", ".css": "css", ".html": "html", ".yaml": "yaml", ".yml": "yaml", ".sql": "sql", ".sh": "shell", ".toml": "toml", ".go": "go", ".rs": "rust", ".java": "java"}
PARSER_VERSION = "symbols-v1-" + hashlib.sha256("|".join(importlib.metadata.version(p) for p in ["tree-sitter", "tree-sitter-python", "tree-sitter-javascript", "tree-sitter-typescript", "tree-sitter-php"]).encode()).hexdigest()[:16]


def safe_parts(path):
    if not path or len(path) > 240 or "\\" in path or any(ord(c) < 32 for c in path):
        raise ValueError("Invalid repository path")
    parts = path.split("/")
    if any(not re.fullmatch(r"[A-Za-z0-9_. -]+", part) or part in {".", ".."} or part.startswith(".") for part in parts):
        raise ValueError("Invalid repository path")
    return parts


def directory(parent, name):
    return os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)


def read_file(parent, name, limit=131072):
    fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_size > limit:
            raise ValueError("Repository file limit exceeded")
        with os.fdopen(fd, "rb", closefd=False) as stream:
            data = stream.read(limit + 1)
        if len(data) > limit:
            raise ValueError("Repository file limit exceeded")
        return data
    finally:
        os.close(fd)


def revision(root):
    git = directory(root, ".git")  # Reject worktrees, submodules and external Git metadata.
    try:
        head = read_file(git, "HEAD", 256).decode().strip()
        if re.fullmatch(r"[a-f0-9]{40,64}", head):
            return head
        if not head.startswith("ref: refs/"):
            raise ValueError("Invalid Git HEAD")
        ref = head[5:]
        parts = ref.split("/")
        if any(not re.fullmatch(r"[A-Za-z0-9_.-]+", p) or p in {".", ".."} for p in parts):
            raise ValueError("Invalid Git reference")
        descriptors = []
        try:
            current = git
            for part in parts[:-1]:
                current = directory(current, part)
                descriptors.append(current)
            value = read_file(current, parts[-1], 256).decode().strip()
        except FileNotFoundError:
            packed = read_file(git, "packed-refs").decode()
            value = next((line.split(" ")[0] for line in packed.splitlines() if line.endswith(" " + ref)), "")
        finally:
            for descriptor in reversed(descriptors):
                os.close(descriptor)
        if not re.fullmatch(r"[a-f0-9]{40,64}", value):
            raise ValueError("Repository needs a committed Git revision")
        return value
    finally:
        os.close(git)


def parser(language):
    from tree_sitter import Language, Parser
    if language == "python":
        import tree_sitter_python as grammar
        capsule = grammar.language()
    elif language == "javascript":
        import tree_sitter_javascript as grammar
        capsule = grammar.language()
    elif language in {"typescript", "tsx"}:
        import tree_sitter_typescript as grammar
        capsule = grammar.language_tsx() if language == "tsx" else grammar.language_typescript()
    elif language == "php":
        import tree_sitter_php as grammar
        capsule = grammar.language_php()
    else:
        return None
    return Parser(Language(capsule))


def code_chunks(text, language):
    data = text.encode()
    engine = parser(language)
    boundaries = {0, len(data)}
    symbols = []
    if engine:
        tree = engine.parse(data)
        stack = [tree.root_node]
        while stack:
            node = stack.pop()
            if node.type in {"function_definition", "class_definition", "function_declaration", "class_declaration", "method_declaration", "method_definition", "interface_declaration", "lexical_declaration"}:
                name = node.child_by_field_name("name")
                symbol = data[name.start_byte:name.end_byte].decode() if name else None
                symbols.append((node.start_byte, node.end_byte, symbol))
                boundaries.update((node.start_byte, node.end_byte))
            stack.extend(reversed(node.children))
    result = []
    points = sorted(boundaries)
    for start, end in zip(points, points[1:]):
        segment = data[start:end].decode()
        # Preserve all text, splitting oversized symbols explicitly with accurate byte/line ranges.
        offset = start
        current = ""
        for line in segment.splitlines(keepends=True):
            pieces = [line[i:i+3000] for i in range(0, len(line), 3000)]
            for piece in pieces:
                if len(current) + len(piece) > 3000:
                    result.append((offset, current))
                    offset += len(current.encode())
                    current = ""
                current += piece
        if current:
            result.append((offset, current))
    chunks = []
    for offset, content in result:
        enclosing = sorted((s for s in symbols if s[0] <= offset < s[1]), key=lambda s: s[1]-s[0])
        chunks.append({"content": content, "symbol": enclosing[0][2] if enclosing else None,
                       "start_line": data[:offset].count(b"\n") + 1,
                       "end_line": data[:offset+len(content.encode())].count(b"\n") + (0 if content.endswith("\n") else 1)})
    return chunks, "tree-sitter" if engine else "text"


def snapshot(path, root=None, project_id=None):
    parts = safe_parts(path)
    if project_id is not None:
        from uuid import UUID
        canonical = str(UUID(project_id))
        if canonical != project_id:
            raise ValueError("Invalid project directory")
        parts.insert(0, canonical)
    root = root if root is not None else os.getenv("REPOSITORY_ROOT", "/repositories")
    descriptors = [os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)]
    try:
        for part in parts:
            descriptors.append(directory(descriptors[-1], part))
        repo = descriptors[-1]
        commit = revision(repo)
        files, total, count, entries = [], 0, 0, 0
        def walk(fd, prefix="", depth=0):
            nonlocal total, count, entries
            if depth > 20:
                raise ValueError("Repository depth limit exceeded")
            names = os.listdir(fd)
            entries += len(names)
            if entries > 10000:
                raise ValueError("Repository entry limit exceeded")
            for name in sorted(names):
                if name.startswith(".") or name.lower() in EXCLUDED or any(word in name.lower() for word in ("credential", "secret", "private", "password")):
                    continue
                info = os.stat(name, dir_fd=fd, follow_symlinks=False)
                relative = prefix + name
                if len(relative) > 500 or any(ord(c) < 32 for c in relative):
                    raise ValueError("Unsupported source path")
                if stat.S_ISDIR(info.st_mode):
                    child = directory(fd, name)
                    try:
                        walk(child, relative + "/", depth+1)
                    finally:
                        os.close(child)
                    continue
                language = "text" if name == "requirements.txt" else LANGUAGES.get(PurePosixPath(name).suffix.lower())
                if not stat.S_ISREG(info.st_mode) or not language or name.endswith((".min.js", ".min.css", ".lock")):
                    continue
                raw = read_file(fd, name)
                if b"\x00" in raw:
                    continue
                try:
                    text = raw.decode("utf-8")
                except UnicodeDecodeError:
                    continue
                # Defense in depth for obvious credentials embedded in otherwise allowed source.
                if re.search(r"-----BEGIN [A-Z ]*PRIVATE KEY-----|(?:sk-[A-Za-z0-9_-]{20,})|AKIA[0-9A-Z]{16}|(?i:password|api[_-]?key|access[_-]?token|secret)\s*[:=]\s*[\"'][^\"'\n]{8,}", text):
                    continue
                total += len(raw)
                if total > 1048576 or len(files) >= 200:
                    raise ValueError("Repository size limit exceeded")
                chunks, parsed = code_chunks(text, language)
                count += len(chunks)
                if count > 500:
                    raise ValueError("Repository chunk limit exceeded")
                files.append({"path": relative, "language": language, "hash": hashlib.sha256(raw).hexdigest(), "parser": parsed, "chunks": chunks})
        walk(repo)
        if revision(repo) != commit:
            raise ValueError("Git revision changed during discovery")
        frameworks = []
        for item in files:
            if PurePosixPath(item["path"]).name in {"package.json", "composer.json", "pyproject.toml", "requirements.txt"}:
                source = "".join(c["content"] for c in item["chunks"])
                for dependency, label in [("laravel/framework", "Laravel"), ('"react"', "React"), ('"next"', "Next.js"), ("fastapi", "FastAPI"), ("django", "Django")]:
                    if dependency in source.lower():
                        frameworks.append(label)
        return {"revision": commit, "parser_version": PARSER_VERSION, "languages": sorted({f["language"] for f in files}), "frameworks": sorted(set(frameworks)), "files": files}
    finally:
        for descriptor in reversed(descriptors):
            os.close(descriptor)
