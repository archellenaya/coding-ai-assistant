FROM python:3.12.10-slim-bookworm
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 FASTEMBED_CACHE_PATH=/opt/models HF_HUB_DISABLE_XET=1
WORKDIR /app
COPY services/intelligence/requirements.lock ./
RUN pip install --no-cache-dir -r requirements.lock
COPY services/intelligence/download_model.py ./
ARG EMBEDDING_MODEL=BAAI/bge-small-en-v1.5
ARG EMBEDDING_REVISION=aa8f8b060edb00e03bfdd08813a2949946c8ba55
RUN python download_model.py
COPY services/intelligence/ ./
ENV HF_HUB_OFFLINE=1
USER 10001:10001
EXPOSE 8001
HEALTHCHECK --interval=15s --timeout=5s --start-period=45s CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8001/healthz',timeout=3)"
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001", "--no-access-log"]
