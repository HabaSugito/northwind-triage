FROM node:18-alpine

WORKDIR /app

# Install dependencies first (layer cache)
COPY frontend/package.json frontend/package-lock.json* ./
RUN npm ci

# Copy application
COPY frontend/ .

EXPOSE 5173
