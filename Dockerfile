# Stage 1: Build TypeScript
FROM node:18-alpine AS ts-builder
WORKDIR /build
COPY package*.json tsconfig.json ./
RUN npm ci
COPY assets/ts ./assets/ts
RUN npx tsc

# Stage 2: Production Python Server
FROM python:3.11-slim
WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy source assets
COPY app ./app
COPY templates ./templates
COPY assets ./assets
# Override assets/js with compiled TS files
COPY --from=ts-builder /build/assets/js ./assets/js

COPY init_db.py .

ENV PORT=10000
ENV PYTHONUNBUFFERED=1

EXPOSE 10000

# Run init_db.py migrations & seeding, then start FastAPI app
CMD python init_db.py && uvicorn app.main:app --host 0.0.0.0 --port $PORT
