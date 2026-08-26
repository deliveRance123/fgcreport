# Stage 1: Build TypeScript
FROM node:18-alpine AS ts-builder
WORKDIR /build
COPY package*.json tsconfig.json ./
RUN npm install
COPY assets/ts ./assets/ts
RUN npx tsc

# Stage 2: Production Python Server
FROM python:3.11-slim
WORKDIR /app

# Install system deps: curl for health checks, libpq-dev for psycopg2
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy application files
COPY app ./app
COPY templates ./templates
COPY assets ./assets
# Copy compiled TypeScript JS files
COPY --from=ts-builder /build/assets/js ./assets/js
COPY init_db.py .

ENV PORT=10000
ENV PYTHONUNBUFFERED=1

EXPOSE 10000

# Startup: run DB init (retries internally if DB isn't ready), then launch server
# Using `|| true` on init_db is intentional only for local dev safety;
# On Render, init_db.py will raise RuntimeError if DB is truly unavailable (no silent swallow)
CMD ["sh", "-c", "python init_db.py && uvicorn app.main:app --host 0.0.0.0 --port ${PORT:-10000}"]
