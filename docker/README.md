# Docker 環境

## 前提条件

- Docker Desktop がインストールされていること
- `ANTHROPIC_API_KEY` が手元にあること

## 初回セットアップ

```bash
# 1. backend/.env を作成
cp backend/.env.example backend/.env

# 2. .env に API キーをセット
#    ANTHROPIC_API_KEY=sk-ant-...

# 3. Laravel アプリケーションキーを生成
docker compose run --rm backend php artisan key:generate

# 4. コンテナを起動
docker compose up --build
```

## 通常起動

```bash
docker compose up
```

| サービス  | URL                       |
|-----------|---------------------------|
| Frontend  | http://localhost:5173      |
| Backend   | http://localhost:8000      |
| Health    | http://localhost:8000/api/health |

## バッチ実行

```bash
docker compose run --rm backend php ../scripts/batch_run.php
```

## コンテナ停止

```bash
docker compose down
```
