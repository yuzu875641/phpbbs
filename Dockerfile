# PHPの公式イメージをベースにする
FROM php:8.1-apache

# 必要なPostgreSQLクライアントライブラリをインストール
RUN apt-get update && apt-get install -y libpq-dev

# 必要なPHP拡張機能をインストール
RUN docker-php-ext-install pdo_pgsql curl

# 動作するディレクトリを/var/www/htmlに設定
WORKDIR /var/www/html

# 全てのコードをコンテナ内にコピー
COPY . .
