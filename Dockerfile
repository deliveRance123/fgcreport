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

# Suppress apt interactive prompts during build
ENV DEBIAN_FRONTEND=noninteractive

# Only curl needed for health checks; psycopg2-binary bundles its own libpq
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
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

# Run DB init first (it retries internally up to 5x if DB isn't ready yet).
# The || true ensures the server still starts even if init_db has a transient issue.
# On a healthy Render deploy with DATABASE_URL set, init_db will always succeed.
CMD ["sh", "-c", "python init_db.py || true; uvicorn app.main:app --host 0.0.0.0 --port ${PORT:-10000}"]
